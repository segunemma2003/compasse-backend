<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ALOC past questions (questions.aloc.com.ng — JAMB/UTME, WASSCE, Post-UTME) into the central
 * question bank, so every school can browse and import them into their CBTs.
 */
class AlocQuestions
{
    public const BASE = 'https://questions.aloc.com.ng/api/v2';

    /** slug => [name, code, category] */
    public const SUBJECTS = [
        'english' => ['English Language', 'ENG', 'core'], 'mathematics' => ['Mathematics', 'MTH', 'core'],
        'biology' => ['Biology', 'BIO', 'sciences'], 'physics' => ['Physics', 'PHY', 'sciences'], 'chemistry' => ['Chemistry', 'CHM', 'sciences'],
        'englishlit' => ['Literature in English', 'LIT', 'arts'], 'government' => ['Government', 'GOV', 'social'], 'economics' => ['Economics', 'ECO', 'social'],
        'geography' => ['Geography', 'GEO', 'social'], 'commerce' => ['Commerce', 'COM', 'social'], 'accounting' => ['Accounting', 'ACC', 'social'],
        'history' => ['History', 'HIS', 'social'], 'civiledu' => ['Civic Education', 'CIV', 'social'], 'crk' => ['Christian Religious Studies', 'CRS', 'religious'],
        'irk' => ['Islamic Religious Studies', 'IRS', 'religious'], 'insurance' => ['Insurance', 'INS', 'vocational'], 'currentaffairs' => ['Current Affairs', 'CAF', 'other'],
    ];

    public const TYPES = ['utme' => ['jamb', 'JAMB / UTME'], 'wassce' => ['waec', 'WASSCE'], 'post-utme' => ['custom', 'POST-UTME']];

    public static function token(?string $given = null): ?string
    {
        if ($given) {
            Cache::forever('aloc_access_token', trim($given));
        }

        return Cache::get('aloc_access_token') ?: config('services.aloc.token');
    }

    private static function subjectId(string $slug): int
    {
        [$name, $code, $cat] = self::SUBJECTS[$slug];
        $row = DB::table('question_bank_subjects')->where('name', $name)->first()
            ?? DB::table('question_bank_subjects')->where('code', $code)->first();
        if ($row) {
            return (int) $row->id;
        }

        return (int) DB::table('question_bank_subjects')->insertGetId(['name' => $name, 'code' => $code, 'category' => $cat, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Fetch `batches` × 40 questions and store the new ones.
     *
     * @return array{added:int, skipped:int, subject_id:int, curriculum:string}
     */
    public static function import(string $slug, string $type, ?int $year, int $batches, int $createdBy, string $token): array
    {
        $subjectId = self::subjectId($slug);
        [$curriculum, $examLabel] = self::TYPES[$type];
        $added = $skipped = 0;
        for ($b = 0; $b < $batches; $b++) {
            $res = Http::withHeaders(['AccessToken' => $token])->acceptJson()->timeout(30)
                ->get(self::BASE . '/m', array_filter(['subject' => $slug, 'type' => $type, 'year' => $year]));
            if (is_array($res->json()) && ($res->json('error') || (int) $res->json('status') >= 400)) {
                throw new \RuntimeException('ALOC: ' . ($res->json('error') ?: 'request refused') . (str_contains(strtolower((string) $res->json('error')), 'token') ? ' — copy the access token again from your ALOC account.' : ''));
            }
            if ($res->status() === 401 || $res->status() === 403) {
                throw new \RuntimeException('ALOC did not accept the access token — copy it again from your ALOC dashboard.');
            }
            if (! $res->successful()) {
                throw new \RuntimeException('ALOC returned ' . $res->status() . ': ' . mb_substr($res->body(), 0, 200));
            }
            $items = $res->json('data') ?? [];
            if (isset($items['question'])) {
                $items = [$items];
            }
            if (! $items) {
                break;
            }
            foreach ($items as $q) {
                $extId = $q['id'] ?? null;
                $tag = 'aloc:' . $slug . ':' . $extId;
                if (! $extId || DB::table('question_bank_questions')->where('tags', 'like', '%"' . $tag . '"%')->exists()) {
                    $skipped++;
                    continue;
                }
                $opts = [];
                foreach ((array) ($q['option'] ?? $q['options'] ?? []) as $k => $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $opts[] = ['key' => strtoupper((string) $k), 'value' => trim($v)];
                    }
                }
                $answer = strtoupper(trim((string) ($q['answer'] ?? '')));
                if (count($opts) < 2 || $answer === '') {
                    $skipped++;
                    continue;
                }
                $yr = (int) ($q['examyear'] ?? 0);
                DB::table('question_bank_questions')->insert([
                    'subject_id' => $subjectId, 'class_level' => 'senior_secondary', 'curriculum_type' => $curriculum,
                    'exam_type' => strtoupper((string) ($q['examtype'] ?? $examLabel)), 'year' => $yr >= 1901 && $yr <= 2155 ? $yr : null,
                    'topic' => ($q['section'] ?? null) ?: null, 'question_type' => 'multiple_choice',
                    'question_text' => trim(strip_tags((string) ($q['question'] ?? ''), '<b><i><u><sup><sub><br>')),
                    'options' => json_encode($opts), 'correct_answer' => json_encode($answer),
                    'explanation' => ($q['solution'] ?? null) ?: null, 'difficulty_level' => 'medium', 'marks' => 1,
                    'media_url' => ($q['image'] ?? null) ?: null, 'tags' => json_encode([$tag, 'aloc', $type]),
                    'usage_count' => 0, 'created_by' => $createdBy, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $added++;
            }
        }

        return ['added' => $added, 'skipped' => $skipped, 'subject_id' => $subjectId, 'curriculum' => $curriculum];
    }

    /** Give every active school access to this subject's questions (senior secondary, this curriculum). */
    public static function grantAllSchools(int $subjectId, string $curriculum): int
    {
        $n = 0;
        foreach (DB::table('tenants')->where('status', 'active')->pluck('id') as $tid) {
            $exists = DB::table('question_bank_subscriptions')->where('tenant_id', $tid)->where('subject_id', $subjectId)
                ->where('class_level', 'senior_secondary')->where('curriculum_type', $curriculum)->exists();
            if (! $exists) {
                DB::table('question_bank_subscriptions')->insert(['tenant_id' => $tid, 'subject_id' => $subjectId, 'class_level' => 'senior_secondary',
                    'curriculum_type' => $curriculum, 'status' => 'active', 'questions_imported' => 0, 'subscribed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            }
        }

        return $n;
    }
}
