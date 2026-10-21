<?php

namespace App\Support;

use App\Models\ResultConfiguration;
use App\Models\StudentResult;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a school chooses to show on its report cards (Result Settings → Report card), and the
 * parts worked out for each child: height / weight, a summary of grades, automatic comments,
 * and — on the last term of the session — the cumulative (annual) result and promotion status.
 */
class ReportCardExtras
{
    public const DEFAULTS = [
        'show_watermark' => true,       // school logo faintly behind the result
        'show_stamp' => true,           // the school stamp (Settings → Signatures, role "school_stamp")
        'show_vitals' => true,          // height and weight
        'show_summary' => true,         // no. of subjects, distinctions, credits, passes, fails
        'show_class_stats' => true,     // class average / lowest / highest per subject
        'show_term_dates' => true,      // this term ends / next term begins
        'show_grade_legend' => true,
        'show_cumulative' => true,      // last term of the session: 1st / 2nd / 3rd term and annual average
        'show_promotion' => true,
        'large_text' => true,
        // manual: only what staff type · auto: generated when nothing was typed
        'class_teacher_comment_mode' => 'auto',
        'principal_comment_mode' => 'auto',
        // Head of school: about academic performance (by average).
        'principal_comments' => [
            ['min' => 75, 'text' => 'An excellent result. {first_name} has worked very hard — keep it up!'],
            ['min' => 65, 'text' => 'A very good performance. With a little more effort, {first_name} can reach the top.'],
            ['min' => 55, 'text' => 'A good result. {first_name} should keep working steadily to improve further.'],
            ['min' => 45, 'text' => 'A fair performance. {first_name} needs to put in more effort next term.'],
            ['min' => 0, 'text' => '{first_name} must work much harder; we will support this closely next term.'],
        ],
        // Class teacher: about attitude and conduct (by the behaviour / affective ratings, out of 5).
        'class_teacher_comments' => [
            ['min' => 4.5, 'text' => '{first_name} is a delight — well-behaved, attentive and kind to others.'],
            ['min' => 3.5, 'text' => '{first_name} is well-behaved and cooperative, and takes part well in class.'],
            ['min' => 2.5, 'text' => '{first_name} is generally well-behaved but can be more focused in class.'],
            ['min' => 0, 'text' => "{first_name} needs to improve in conduct and attention; we will work on this together."],
        ],
        // What each psychomotor / behaviour rating means (printed beside the rating and as a key).
        'rating_key' => ['5' => 'Excellent', '4' => 'Very good', '3' => 'Good', '2' => 'Fair', '1' => 'Poor'],
        // Comment boxes, in order. class_teacher_comment / principal_comment are filled from the Mark
        // Sheet (or automatically); any other box prints blank to be written by hand.
        'comment_boxes' => [],
        // Signature lines always printed (with the uploaded signature for that role, if any).
        'signature_slots' => [
            ['role' => 'class_teacher', 'label' => 'Class Teacher'],
            ['role' => 'principal', 'label' => 'Head Teacher / Principal'],
        ],
        'show_parent_signature' => false,
        // Promotion (last term): by the annual average and the number of subjects failed.
        'promote_min' => 50,
        'trial_min' => 40,
        'max_failed_subjects' => null,
    ];

    public static function settings(?ResultConfiguration $config): array
    {
        $saved = (array) (($config?->custom_settings ?? [])['report'] ?? []);

        $out = array_merge(self::DEFAULTS, array_filter($saved, fn ($v) => $v !== null));
        $out['rating_key'] = array_filter((array) $out['rating_key'] + self::DEFAULTS['rating_key'], fn ($v) => trim((string) $v) !== '');
        // Comment boxes: the school's list, else the result configuration's comment fields.
        $boxes = array_values(array_filter((array) $out['comment_boxes'], fn ($b) => ! empty($b['label'])));
        $out['comment_boxes'] = $boxes ?: ($config?->comment_fields ?: [
            ['key' => 'class_teacher_comment', 'label' => "Class Teacher's Comment"],
            ['key' => 'principal_comment', 'label' => "Principal's Comment"],
        ]);

        return $out;
    }

    /** "4 — Very good" style label for a rating, from the school's key. */
    public static function ratingMeaning(array $s, $rating): ?string
    {
        return is_numeric($rating) ? ($s['rating_key'][(string) (int) $rating] ?? null) : null;
    }

    /** [height, weight] for the term, if recorded. */
    public static function vitals(int $studentId, ?int $termId, ?int $yearId): array
    {
        if (! Schema::hasTable('student_term_vitals')) {
            return [null, null];
        }
        $v = DB::table('student_term_vitals')->where('student_id', $studentId)->where('term_id', $termId)->where('academic_year_id', $yearId)->first();

        return [$v?->height_end ?? $v?->height_beginning ?? null, $v?->weight_end ?? $v?->weight_beginning ?? null];
    }

    /**
     * Number of subjects and how many fell in each band of the school's grading (Distinction,
     * Credit, Pass, Fail… — the remarks on its grade scale), in scale order.
     *
     * @return array{subjects:int, bands: array<string,int>}
     */
    public static function summary(StudentResult $result, ?array $boundaries, float $passMark): array
    {
        $bands = [];
        $scale = collect($boundaries ?? [])->sortByDesc('min')->values();
        foreach ($scale as $b) {
            $bands[self::bandName($b)] = 0;
        }
        $n = 0;
        foreach ($result->subjectResults as $sr) {
            $score = (float) $sr->total_score;
            $n++;
            $hit = $scale->first(fn ($b) => $score >= (float) ($b['min'] ?? 0));
            $name = $hit ? self::bandName($hit) : ($score >= $passMark ? 'Pass' : 'Fail');
            $bands[$name] = ($bands[$name] ?? 0) + 1;
        }

        return ['subjects' => $n, 'bands' => array_filter($bands, fn ($c, $k) => $c > 0 || in_array($k, ['Distinction', 'Credit', 'Pass', 'Fail'], true), ARRAY_FILTER_USE_BOTH)];
    }

    /** "A1 Excellent" grades count as distinctions, etc.; otherwise the grade's own remark. */
    private static function bandName(array $b): string
    {
        $remark = strtolower((string) ($b['remark'] ?? ''));
        $grade = strtoupper((string) ($b['grade'] ?? ''));

        return match (true) {
            str_contains($remark, 'distinction') || str_contains($remark, 'excellent') || str_starts_with($grade, 'A') => 'Distinction',
            str_contains($remark, 'credit') || str_contains($remark, 'very good') || str_contains($remark, 'good') || str_starts_with($grade, 'B') || str_starts_with($grade, 'C') => 'Credit',
            str_contains($remark, 'fail') || $grade === 'F' || str_starts_with($grade, 'F') => 'Fail',
            default => 'Pass',
        };
    }

    /** A comment from the school's bands for a value (first band whose min the value reaches). */
    public static function fromBands(array $bands, float $value, string $firstName): ?string
    {
        foreach (collect($bands)->sortByDesc('min') as $b) {
            if ($value >= (float) ($b['min'] ?? 0)) {
                return str_replace('{first_name}', $firstName, (string) ($b['text'] ?? ''));
            }
        }

        return null;
    }

    /**
     * Comments for the report: what staff typed, else (in auto mode) generated ones — the head's
     * from the average, the class teacher's from the behaviour ratings.
     *
     * @return array<string,string|null> keyed by comment field
     */
    public static function comments(StudentResult $result, ?array $psychReport, array $s, float $average): array
    {
        $first = (string) ($result->student?->first_name ?? 'The pupil');
        // Comments typed on the Mark Sheet (possibly before the result was generated).
        $typed = Schema::hasTable('result_remarks')
            ? DB::table('result_remarks')->where('student_id', $result->student_id)->where('term_id', $result->term_id)->where('academic_year_id', $result->academic_year_id)->first()
            : null;
        $out = [
            'principal_comment' => (trim((string) $result->principal_comment) ?: trim((string) ($typed->principal_comment ?? ''))) ?: null,
            'class_teacher_comment' => (trim((string) $result->class_teacher_comment) ?: trim((string) ($typed->class_teacher_comment ?? ''))) ?: null,
        ];
        if (! $out['principal_comment'] && ($s['principal_comment_mode'] ?? 'auto') === 'auto') {
            $out['principal_comment'] = self::fromBands((array) $s['principal_comments'], $average, $first);
        }
        if (! $out['class_teacher_comment'] && ($s['class_teacher_comment_mode'] ?? 'auto') === 'auto') {
            $ratings = collect(array_merge($psychReport['affective'] ?? [], $psychReport['skills'] ?? []))
                ->pluck('rating')->filter(fn ($r) => is_numeric($r))->map(fn ($r) => (float) $r);
            // No ratings yet: fall back on the average (a well-performing child is usually a focused one).
            $value = $ratings->isNotEmpty() ? $ratings->avg() : ($average >= 70 ? 4.5 : ($average >= 55 ? 3.6 : ($average >= 45 ? 2.6 : 2)));
            $out['class_teacher_comment'] = self::fromBands((array) $s['class_teacher_comments'], (float) $value, $first);
        }

        return $out;
    }

    /** Is this the last term of its session (when the cumulative result is shown)? */
    public static function isLastTerm(?Term $term): bool
    {
        if (! $term) {
            return false;
        }
        $terms = Term::where('academic_year_id', $term->academic_year_id)->orderBy('start_date')->orderBy('id')->get(['id']);

        return $terms->count() >= 2 && (int) $terms->last()->id === (int) $term->id;
    }

    /**
     * Cumulative result for the last term: each subject's total per term, its annual average over
     * the terms it was taken (3 terms → ÷3, 2 → ÷2), the overall annual average, and the promotion
     * decision by the school's criteria.
     */
    public static function cumulative(StudentResult $result, array $s, float $passMark): ?array
    {
        if (! self::isLastTerm($result->term)) {
            return null;
        }
        $terms = Term::where('academic_year_id', $result->academic_year_id)->orderBy('start_date')->orderBy('id')->get(['id', 'name']);
        $results = StudentResult::with('subjectResults.subject')
            ->where('student_id', $result->student_id)->where('academic_year_id', $result->academic_year_id)
            ->where('result_type', $result->result_type ?? 'end_term')
            ->whereIn('term_id', $terms->pluck('id'))->get()->keyBy('term_id');

        $subjects = [];
        foreach ($terms as $t) {
            foreach ($results[$t->id]?->subjectResults ?? [] as $sr) {
                $key = $sr->subject_id;
                $subjects[$key] ??= ['subject' => $sr->subject?->name ?? 'Subject', 'terms' => []];
                $subjects[$key]['terms'][$t->id] = (float) $sr->total_score;
            }
        }
        // Only subjects taken this term appear on this term's report.
        $current = $result->subjectResults->pluck('subject_id')->all();
        $rows = [];
        $failed = 0;
        foreach ($subjects as $sid => $row) {
            if (! in_array($sid, $current, true)) {
                continue;
            }
            $scores = array_values($row['terms']);
            $avg = $scores ? round(array_sum($scores) / count($scores), 1) : null;
            if ($avg !== null && $avg < $passMark) {
                $failed++;
            }
            $rows[] = ['subject' => $row['subject'], 'terms' => array_map(fn ($t) => $row['terms'][$t->id] ?? null, $terms->all()), 'average' => $avg];
        }
        $averages = array_filter(array_column($rows, 'average'), fn ($a) => $a !== null);
        $annual = $averages ? round(array_sum($averages) / count($averages), 1) : null;

        $status = null;
        if ($annual !== null) {
            $maxFail = $s['max_failed_subjects'];
            $tooManyFails = $maxFail !== null && $maxFail !== '' && $failed > (int) $maxFail;
            $status = ($annual >= (float) $s['promote_min'] && ! $tooManyFails) ? 'Promoted'
                : ($annual >= (float) $s['trial_min'] ? 'Promoted on trial' : 'Repeat class');
        }

        return [
            'term_names' => $terms->pluck('name')->all(),
            'rows' => $rows,
            'annual_average' => $annual,
            'failed' => $failed,
            'promotion' => $status,
        ];
    }
}
