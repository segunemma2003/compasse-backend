<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin management of the central question bank.
 *
 * All operations target the CENTRAL (mysql) database — no tenant context.
 * Routes are protected by [auth:sanctum, role:super_admin].
 *
 * Covers:
 *   • question_bank_subjects   CRUD
 *   • question_bank_questions  CRUD + bulk create
 *   • question_bank_subscriptions  CRUD (assign/revoke school access)
 */
class SuperAdminQuestionBankController extends Controller
{
    private const PER_PAGE = 50;

    // =========================================================================
    // SUBJECTS
    // =========================================================================

    public function subjectIndex(Request $request): JsonResponse
    {
        $query = DB::table('question_bank_subjects')
            ->orderBy('category')
            ->orderBy('name');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return response()->json([
            'subjects' => $query->get(),
        ]);
    }

    public function subjectStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'code'        => ['required', 'string', 'max:20', 'unique:question_bank_subjects,code'],
            'description' => ['nullable', 'string'],
            'category'    => ['required', 'in:core,sciences,arts,social,vocational,languages,religious,other'],
        ]);

        $id = DB::table('question_bank_subjects')->insertGetId(array_merge($validated, [
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json([
            'subject' => DB::table('question_bank_subjects')->find($id),
        ], 201);
    }

    public function subjectUpdate(Request $request, int $id): JsonResponse
    {
        $subject = DB::table('question_bank_subjects')->find($id);
        if (! $subject) {
            return response()->json(['error' => 'Subject not found'], 404);
        }

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:120'],
            'code'        => ['sometimes', 'string', 'max:20', "unique:question_bank_subjects,code,{$id}"],
            'description' => ['nullable', 'string'],
            'category'    => ['sometimes', 'in:core,sciences,arts,social,vocational,languages,religious,other'],
            'status'      => ['sometimes', 'in:active,inactive'],
        ]);

        DB::table('question_bank_subjects')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json([
            'subject' => DB::table('question_bank_subjects')->find($id),
        ]);
    }

    public function subjectDestroy(int $id): JsonResponse
    {
        $count = DB::table('question_bank_questions')->where('subject_id', $id)->count();
        if ($count > 0) {
            return response()->json([
                'error'   => 'Cannot delete subject',
                'message' => "This subject has {$count} questions. Archive it instead.",
            ], 422);
        }

        DB::table('question_bank_subjects')->where('id', $id)->delete();
        return response()->json(['message' => 'Subject deleted.']);
    }

    // =========================================================================
    // QUESTIONS
    // =========================================================================

    public function questionIndex(Request $request): JsonResponse
    {
        $query = DB::table('question_bank_questions as q')
            ->join('question_bank_subjects as s', 'q.subject_id', '=', 's.id')
            ->select('q.*', 's.name as subject_name', 's.code as subject_code');

        if ($request->filled('subject_id'))      { $query->where('q.subject_id', $request->subject_id); }
        if ($request->filled('class_level'))     { $query->where('q.class_level', $request->class_level); }
        if ($request->filled('curriculum_type')) { $query->where('q.curriculum_type', $request->curriculum_type); }
        if ($request->filled('difficulty_level')){ $query->where('q.difficulty_level', $request->difficulty_level); }
        if ($request->filled('question_type'))   { $query->where('q.question_type', $request->question_type); }
        if ($request->filled('topic'))           { $query->where('q.topic', 'like', '%' . $request->topic . '%'); }
        if ($request->filled('academic_year'))   { $query->where('q.academic_year', $request->academic_year); }
        if ($request->filled('status'))          { $query->where('q.status', $request->status); }
        else                                     { $query->where('q.status', 'active'); }
        if ($request->filled('search')) {
            $query->where('q.question_text', 'like', '%' . $request->search . '%');
        }

        $total     = (clone $query)->count();
        $perPage   = min((int) $request->get('per_page', self::PER_PAGE), 200);
        $page      = max((int) $request->get('page', 1), 1);
        $questions = $query->orderByDesc('q.created_at')
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

    public function questionShow(int $id): JsonResponse
    {
        $q = DB::table('question_bank_questions as q')
            ->join('question_bank_subjects as s', 'q.subject_id', '=', 's.id')
            ->select('q.*', 's.name as subject_name')
            ->where('q.id', $id)
            ->first();

        if (! $q) {
            return response()->json(['error' => 'Question not found'], 404);
        }

        return response()->json(['question' => $q]);
    }

    public function questionStore(Request $request): JsonResponse
    {
        $validated = $this->validateQuestion($request);
        $id = DB::table('question_bank_questions')->insertGetId(array_merge($validated, [
            'created_by' => Auth::id(),
            'usage_count'=> 0,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json([
            'question' => DB::table('question_bank_questions')->find($id),
        ], 201);
    }

    /**
     * Bulk-create questions (up to 500 at once).
     */
    public function questionBulkStore(Request $request): JsonResponse
    {
        $request->validate([
            'questions'   => ['required', 'array', 'min:1', 'max:500'],
            'questions.*' => ['array'],
        ]);

        $now     = now();
        $userId  = Auth::id();
        $rows    = [];
        $errors  = [];

        foreach ($request->questions as $idx => $item) {
            try {
                $v = $this->validateQuestion(new Request($item));
                $rows[] = array_merge($v, [
                    'created_by'  => $userId,
                    'usage_count' => 0,
                    'status'      => 'active',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $errors[$idx] = $e->errors();
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'error'   => 'Some questions failed validation',
                'errors'  => $errors,
            ], 422);
        }

        // JSON-encode fields before bulk insert
        $rows = array_map(function ($r) {
            $r['options']        = isset($r['options'])        ? json_encode($r['options'])        : null;
            $r['correct_answer'] = isset($r['correct_answer']) ? json_encode($r['correct_answer']) : null;
            $r['tags']           = isset($r['tags'])           ? json_encode($r['tags'])           : null;
            return $r;
        }, $rows);

        DB::table('question_bank_questions')->insert($rows);

        return response()->json([
            'message'  => count($rows) . ' questions created.',
            'inserted' => count($rows),
        ], 201);
    }

    public function questionUpdate(Request $request, int $id): JsonResponse
    {
        $q = DB::table('question_bank_questions')->find($id);
        if (! $q) {
            return response()->json(['error' => 'Question not found'], 404);
        }

        $validated = $this->validateQuestion($request, partial: true);

        if (isset($validated['options']))        { $validated['options']        = json_encode($validated['options']); }
        if (isset($validated['correct_answer'])) { $validated['correct_answer'] = json_encode($validated['correct_answer']); }
        if (isset($validated['tags']))           { $validated['tags']           = json_encode($validated['tags']); }

        DB::table('question_bank_questions')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json([
            'question' => DB::table('question_bank_questions')->find($id),
        ]);
    }

    public function questionDestroy(int $id): JsonResponse
    {
        $used = DB::table('question_bank_imports')->where('question_id', $id)->count();
        if ($used > 0) {
            // Soft-archive instead of hard delete
            DB::table('question_bank_questions')->where('id', $id)->update([
                'status'     => 'archived',
                'updated_at' => now(),
            ]);
            return response()->json(['message' => 'Question archived (it has been imported by schools).']);
        }

        DB::table('question_bank_questions')->where('id', $id)->delete();
        return response()->json(['message' => 'Question deleted.']);
    }

    // =========================================================================
    // SUBSCRIPTIONS
    // =========================================================================

    public function subscriptionIndex(Request $request): JsonResponse
    {
        $query = DB::table('question_bank_subscriptions as qs')
            ->join('tenants as t', 'qs.tenant_id', '=', 't.id')
            ->join('question_bank_subjects as s', 'qs.subject_id', '=', 's.id')
            ->select(
                'qs.*',
                't.name as tenant_name',
                's.name as subject_name',
                's.code as subject_code'
            );

        if ($request->filled('tenant_id')) { $query->where('qs.tenant_id', $request->tenant_id); }
        if ($request->filled('subject_id')){ $query->where('qs.subject_id', $request->subject_id); }
        if ($request->filled('status'))    { $query->where('qs.status', $request->status); }

        return response()->json(['subscriptions' => $query->orderByDesc('qs.created_at')->get()]);
    }

    public function subscriptionStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id'       => ['required', 'string', 'exists:tenants,id'],
            'subject_id'      => ['required', 'integer', 'exists:question_bank_subjects,id'],
            'class_level'     => ['nullable', 'in:nursery,primary,junior_secondary,senior_secondary,tertiary'],
            'curriculum_type' => ['nullable', 'in:waec,neco,nabteb,common_entrance,jamb,primary_school,cambridge,custom'],
            'expires_at'      => ['nullable', 'date', 'after:today'],
        ]);

        // Check for duplicate
        $exists = DB::table('question_bank_subscriptions')
            ->where('tenant_id',       $validated['tenant_id'])
            ->where('subject_id',      $validated['subject_id'])
            ->where('class_level',     $validated['class_level'] ?? null)
            ->where('curriculum_type', $validated['curriculum_type'] ?? null)
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'This subscription already exists.'], 409);
        }

        $id = DB::table('question_bank_subscriptions')->insertGetId(array_merge($validated, [
            'status'              => 'active',
            'questions_imported'  => 0,
            'subscribed_at'       => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]));

        return response()->json([
            'subscription' => DB::table('question_bank_subscriptions')->find($id),
        ], 201);
    }

    public function subscriptionUpdate(Request $request, int $id): JsonResponse
    {
        $sub = DB::table('question_bank_subscriptions')->find($id);
        if (! $sub) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        $validated = $request->validate([
            'status'     => ['sometimes', 'in:active,suspended,expired'],
            'expires_at' => ['nullable', 'date'],
        ]);

        DB::table('question_bank_subscriptions')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json([
            'subscription' => DB::table('question_bank_subscriptions')->find($id),
        ]);
    }

    public function subscriptionDestroy(int $id): JsonResponse
    {
        DB::table('question_bank_subscriptions')->where('id', $id)->delete();
        return response()->json(['message' => 'Subscription removed.']);
    }

    /**
     * Overview: question counts by subject + level.
     */
    public function stats(): JsonResponse
    {
        $bySubject = DB::table('question_bank_questions as q')
            ->join('question_bank_subjects as s', 'q.subject_id', '=', 's.id')
            ->select('s.id', 's.name', 's.code', DB::raw('COUNT(*) as question_count'))
            ->where('q.status', 'active')
            ->groupBy('s.id', 's.name', 's.code')
            ->orderByDesc('question_count')
            ->get();

        $byLevel = DB::table('question_bank_questions')
            ->select('class_level', DB::raw('COUNT(*) as question_count'))
            ->where('status', 'active')
            ->groupBy('class_level')
            ->get();

        $byCurriculum = DB::table('question_bank_questions')
            ->select('curriculum_type', DB::raw('COUNT(*) as question_count'))
            ->where('status', 'active')
            ->groupBy('curriculum_type')
            ->get();

        return response()->json([
            'total_questions'    => DB::table('question_bank_questions')->where('status', 'active')->count(),
            'total_subjects'     => DB::table('question_bank_subjects')->where('status', 'active')->count(),
            'total_subscriptions'=> DB::table('question_bank_subscriptions')->where('status', 'active')->count(),
            'by_subject'         => $bySubject,
            'by_level'           => $byLevel,
            'by_curriculum'      => $byCurriculum,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared validation
    // ─────────────────────────────────────────────────────────────────────────

    private function validateQuestion(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'subject_id'      => [$required, 'integer', 'exists:question_bank_subjects,id'],
            'class_level'     => [$required, 'in:nursery,primary,junior_secondary,senior_secondary,tertiary'],
            'curriculum_type' => [$required, 'in:waec,neco,nabteb,common_entrance,jamb,primary_school,cambridge,custom'],
            'academic_year'   => ['nullable', 'string', 'max:20'],
            'topic'           => ['nullable', 'string', 'max:255'],
            'subtopic'        => ['nullable', 'string', 'max:255'],
            'question_type'   => [$required, 'in:multiple_choice,true_false,short_answer,essay,fill_in_blank,matching,ordering'],
            'question_text'   => [$required, 'string'],
            'options'         => ['nullable', 'array'],
            'correct_answer'  => [$required, 'present'],
            'explanation'     => ['nullable', 'string'],
            'difficulty_level'=> ['nullable', 'in:easy,medium,hard'],
            'marks'           => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'media_url'       => ['nullable', 'string', 'max:500'],
            'tags'            => ['nullable', 'array'],
        ]);
    }
}
