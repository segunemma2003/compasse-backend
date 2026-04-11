<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * School-side access to the central question bank.
 *
 * Routes live under the tenant middleware (so the default connection is the
 * tenant DB) but we explicitly use DB::connection('mysql') to reach the
 * central question bank tables.
 *
 * Access is restricted to tenants that have an active question_bank_subscription
 * for the requested subject + level combination.
 */
class SchoolQuestionBankController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * List subjects the school is subscribed to.
     */
    public function subjects(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

        $subjects = DB::connection('mysql')
            ->table('question_bank_subscriptions as qs')
            ->join('question_bank_subjects as s', 'qs.subject_id', '=', 's.id')
            ->select('s.*', 'qs.class_level', 'qs.curriculum_type', 'qs.expires_at', 'qs.questions_imported')
            ->where('qs.tenant_id', $tenantId)
            ->where('qs.status', 'active')
            ->where(function ($q) {
                $q->whereNull('qs.expires_at')->orWhere('qs.expires_at', '>', now());
            })
            ->orderBy('s.category')
            ->orderBy('s.name')
            ->get();

        return response()->json(['subjects' => $subjects]);
    }

    /**
     * Browse questions from the central bank (scoped to subscriptions).
     */
    public function browse(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

        // Build a subquery of subscribed (subject_id, class_level, curriculum_type) tuples
        $subs = DB::connection('mysql')
            ->table('question_bank_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get(['subject_id', 'class_level', 'curriculum_type']);

        if ($subs->isEmpty()) {
            return response()->json([
                'questions' => [],
                'total'     => 0,
                'message'   => 'No active question bank subscriptions.',
            ]);
        }

        $query = DB::connection('mysql')
            ->table('question_bank_questions as q')
            ->join('question_bank_subjects as s', 'q.subject_id', '=', 's.id')
            ->select('q.id', 'q.subject_id', 'q.class_level', 'q.curriculum_type', 'q.academic_year',
                     'q.topic', 'q.subtopic', 'q.question_type', 'q.question_text',
                     'q.options', 'q.difficulty_level', 'q.marks', 'q.media_url', 'q.tags',
                     // Intentionally EXCLUDE correct_answer and explanation for browsing
                     's.name as subject_name', 's.code as subject_code')
            ->where('q.status', 'active');

        // Scope query to subscribed combos
        $query->where(function ($outer) use ($subs) {
            foreach ($subs as $sub) {
                $outer->orWhere(function ($inner) use ($sub) {
                    $inner->where('q.subject_id', $sub->subject_id);
                    if ($sub->class_level) {
                        $inner->where('q.class_level', $sub->class_level);
                    }
                    if ($sub->curriculum_type) {
                        $inner->where('q.curriculum_type', $sub->curriculum_type);
                    }
                });
            }
        });

        // Apply filters
        if ($request->filled('subject_id'))       { $query->where('q.subject_id', $request->subject_id); }
        if ($request->filled('class_level'))      { $query->where('q.class_level', $request->class_level); }
        if ($request->filled('curriculum_type'))  { $query->where('q.curriculum_type', $request->curriculum_type); }
        if ($request->filled('difficulty_level')) { $query->where('q.difficulty_level', $request->difficulty_level); }
        if ($request->filled('question_type'))    { $query->where('q.question_type', $request->question_type); }
        if ($request->filled('topic'))            { $query->where('q.topic', 'like', '%' . $request->topic . '%'); }
        if ($request->filled('academic_year'))    { $query->where('q.academic_year', $request->academic_year); }
        if ($request->filled('search')) {
            $query->where('q.question_text', 'like', '%' . $request->search . '%');
        }

        $total   = (clone $query)->count();
        $perPage = min((int) $request->get('per_page', self::PER_PAGE), 200);
        $page    = max((int) $request->get('page', 1), 1);

        $questions = $query->orderByDesc('q.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'questions'    => $questions,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * Get full detail of one central question (including correct_answer).
     * Validates the school is subscribed before revealing the answer.
     */
    public function show(int $id): JsonResponse
    {
        $tenantId = $this->tenantId();

        $q = DB::connection('mysql')
            ->table('question_bank_questions')
            ->find($id);

        if (! $q || $q->status === 'archived') {
            return response()->json(['error' => 'Question not found'], 404);
        }

        // Access check
        if (! $this->hasAccess($tenantId, $q->subject_id, $q->class_level, $q->curriculum_type)) {
            return response()->json([
                'error'   => 'Access denied',
                'message' => 'Your school is not subscribed to this question set.',
            ], 403);
        }

        return response()->json(['question' => $q]);
    }

    /**
     * Import selected questions from the central bank into a local exam.
     *
     * The questions are COPIED as new rows in the tenant's `questions` table
     * so they become fully local (editable, no external dependency during exam).
     */
    public function importToExam(Request $request): JsonResponse
    {
        $request->validate([
            'exam_id'      => ['required', 'integer'],
            'question_ids' => ['required', 'array', 'min:1', 'max:200'],
            'question_ids.*' => ['integer'],
        ]);

        $tenantId   = $this->tenantId();
        $examId     = $request->exam_id;

        // Verify exam exists in tenant DB
        $exam = DB::table('exams')->find($examId);
        if (! $exam) {
            return response()->json(['error' => 'Exam not found'], 404);
        }

        // Fetch central questions
        $centralQs = DB::connection('mysql')
            ->table('question_bank_questions')
            ->whereIn('id', $request->question_ids)
            ->where('status', 'active')
            ->get();

        if ($centralQs->isEmpty()) {
            return response()->json(['error' => 'No valid questions found'], 422);
        }

        $imported  = 0;
        $skipped   = 0;
        $now       = now();

        foreach ($centralQs as $cq) {
            // Verify subscription
            if (! $this->hasAccess($tenantId, $cq->subject_id, $cq->class_level, $cq->curriculum_type)) {
                $skipped++;
                continue;
            }

            // Find matching local subject (by code or name)
            $localSubject = null;
            $centralSub   = DB::connection('mysql')
                ->table('question_bank_subjects')
                ->find($cq->subject_id);

            if ($centralSub) {
                $localSubject = DB::table('subjects')
                    ->where(function ($q) use ($centralSub) {
                        $q->where('code', $centralSub->code)
                          ->orWhere('name', $centralSub->name);
                    })
                    ->where('class_id', $exam->class_id ?? 0)
                    ->first()
                    ?? DB::table('subjects')
                        ->where(function ($q) use ($centralSub) {
                            $q->where('code', $centralSub->code)
                              ->orWhere('name', $centralSub->name);
                        })
                        ->first();
            }

            // Insert into tenant questions table
            DB::table('questions')->insert([
                'exam_id'          => $examId,
                'subject_id'       => $localSubject?->id ?? $exam->subject_id,
                'question_text'    => $cq->question_text,
                'question_type'    => $this->mapQuestionType($cq->question_type),
                'difficulty_level' => $cq->difficulty_level ?? 'medium',
                'marks'            => $cq->marks ?? 1,
                'options'          => $cq->options,
                'correct_answer'   => $cq->correct_answer,
                'explanation'      => $cq->explanation,
                'media_url'        => $cq->media_url,
                'status'           => 'active',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            // Log the import in central DB
            DB::connection('mysql')->table('question_bank_imports')->insert([
                'tenant_id'   => $tenantId,
                'question_id' => $cq->id,
                'exam_id'     => $examId,
                'imported_at' => $now,
                'imported_by' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            // Increment usage counter on central question
            DB::connection('mysql')
                ->table('question_bank_questions')
                ->where('id', $cq->id)
                ->increment('usage_count', 1, ['last_used_at' => $now]);

            $imported++;
        }

        // Update subscription import count
        if ($imported > 0) {
            DB::connection('mysql')
                ->table('question_bank_subscriptions')
                ->where('tenant_id', $tenantId)
                ->increment('questions_imported', $imported);
        }

        return response()->json([
            'message'  => "{$imported} question(s) imported into exam #{$examId}.",
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
    }

    /**
     * Show import history for this school.
     */
    public function importHistory(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

        $history = DB::connection('mysql')
            ->table('question_bank_imports as i')
            ->join('question_bank_questions as q', 'i.question_id', '=', 'q.id')
            ->join('question_bank_subjects as s', 'q.subject_id', '=', 's.id')
            ->select('i.id', 'i.exam_id', 'i.imported_at', 's.name as subject_name', 'q.topic', 'q.question_type')
            ->where('i.tenant_id', $tenantId)
            ->orderByDesc('i.imported_at')
            ->paginate(50);

        return response()->json(['history' => $history]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function tenantId(): string
    {
        // The TenantMiddleware stores the tenant in request attributes and in
        // tenancy()->tenant; we read from the helper which is always set.
        return tenancy()->tenant?->id
            ?? request()->attributes->get('tenant')?->id
            ?? '';
    }

    private function hasAccess(string $tenantId, int $subjectId, ?string $classLevel, ?string $curriculumType): bool
    {
        return DB::connection('mysql')
            ->table('question_bank_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('subject_id', $subjectId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) use ($classLevel) {
                $q->whereNull('class_level')->orWhere('class_level', $classLevel);
            })
            ->where(function ($q) use ($curriculumType) {
                $q->whereNull('curriculum_type')->orWhere('curriculum_type', $curriculumType);
            })
            ->exists();
    }

    /**
     * Map central question type enum to tenant questions table enum values.
     * (The local 'questions' table uses slightly different values.)
     */
    private function mapQuestionType(string $type): string
    {
        return match ($type) {
            'fill_in_blank' => 'fill_blank',
            default         => $type,
        };
    }
}
