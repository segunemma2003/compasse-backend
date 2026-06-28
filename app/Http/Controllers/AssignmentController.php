<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentQuestionAnswer;
use App\Models\AssignmentSubmission;
use App\Models\Question;
use App\Models\Student;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssignmentController extends Controller
{
    protected $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get all assignments
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cacheKey = "assignments:list:" . md5(serialize($request->all()));
            $cached = $this->cacheService->get($cacheKey);

            if ($cached) {
                return response()->json($cached);
            }

            $query = Assignment::query();

            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->has('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }

            if ($request->has('teacher_id')) {
                $query->where('teacher_id', $request->teacher_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $assignments = $query->orderBy('due_date', 'desc')
                               ->paginate($request->get('per_page', 15));

            $response = [
                'assignments' => $assignments
            ];

            $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'assignments' => [
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0
                ]
            ]);
        }
    }

    /**
     * Get assignment details
     */
    public function show(Assignment $assignment): JsonResponse
    {
        try {
            $cacheKey = "assignment:{$assignment->id}:details";
            $cached = $this->cacheService->get($cacheKey);

            if ($cached) {
                return response()->json($cached);
            }

            // Load only existing relationships
            $assignment->load(['subject', 'class', 'teacher']);

            $response = [
                'assignment' => $assignment,
            ];

            $this->cacheService->set($cacheKey, $response, 600); // 10 minutes cache

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Assignment not found',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Create new assignment
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id'      => 'required|exists:subjects,id',
            'class_id'        => 'required|exists:classes,id',
            'teacher_id'      => 'nullable|exists:teachers,id',
            'term_id'         => 'nullable|exists:terms,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string',
            'instructions'    => 'nullable|string',
            'due_date'        => 'required|date|after_or_equal:today',
            'total_marks'     => 'required|numeric|min:1',
            'assignment_type' => 'nullable|in:homework,project,essay,research,lab_report',
            'submission_type' => 'nullable|in:file_upload,text,file_and_text',
            'attachments'     => 'nullable|array',
            'attachments.*'   => 'string',
            'max_file_size'   => 'nullable|integer|min:1',
            'allowed_file_types'  => 'nullable|array',
            'is_group_assignment' => 'boolean',
            'max_group_size'      => 'nullable|integer|min:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'    => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Auto-resolve teacher from the authenticated user when not provided
            $teacherId = $request->teacher_id;
            if (!$teacherId && auth()->check()) {
                $teacher   = \Illuminate\Support\Facades\DB::table('teachers')
                    ->where('user_id', auth()->id())
                    ->first();
                $teacherId = $teacher?->id;
            }

            $assignment = Assignment::create(array_merge($request->all(), [
                'teacher_id'      => $teacherId,
                'assignment_type' => $request->assignment_type ?? 'homework',
                'submission_type' => $request->submission_type ?? 'text',
                'description'     => $request->description ?? '',
            ]));

            // Clear cache
            $this->cacheService->invalidateByPattern("assignments:*");

            return response()->json([
                'message' => 'Assignment created successfully',
                'assignment' => $assignment->load(['subject', 'class', 'teacher'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create assignment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update assignment
     */
    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'sometimes|exists:subjects,id',
            'class_id' => 'sometimes|exists:classes,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'term_id' => 'nullable|exists:terms,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'instructions' => 'nullable|string',
            'due_date' => 'sometimes|date',
            'total_marks' => 'sometimes|numeric|min:1',
            'assignment_type' => 'nullable|in:homework,project,essay,research,lab_report',
            'submission_type' => 'nullable|in:file_upload,text,file_and_text',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'max_file_size' => 'nullable|integer|min:1',
            'allowed_file_types' => 'nullable|array',
            'is_group_assignment' => 'boolean',
            'max_group_size' => 'nullable|integer|min:2',
            'status' => 'sometimes|in:draft,published,closed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $assignment->update($request->all());

            // Clear cache
            $this->cacheService->invalidateByPattern("assignment:{$assignment->id}:*");

            return response()->json([
                'message' => 'Assignment updated successfully',
                'assignment' => $assignment->load(['subject', 'class', 'teacher'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update assignment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete assignment
     */
    public function destroy(Assignment $assignment): JsonResponse
    {
        try {
            $assignment->delete();

            // Clear cache
            $this->cacheService->invalidateByPattern("assignment:{$assignment->id}:*");

            return response()->json([
                'message' => 'Assignment deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete assignment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit assignment
     */
    public function submit(Request $request, Assignment $assignment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'submission_text' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'is_late' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $user = auth()->user();
        $ownStudentId = $this->ownStudentId($user);
        if ($ownStudentId !== null && $ownStudentId !== (int) $request->student_id) {
            return $this->forbiddenResponse('You can only submit your own assignments.');
        }

        try {
            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'student_id' => $request->student_id,
                'submission_text' => $request->submission_text,
                'attachments' => $request->attachments ?? [],
                'submitted_at' => now(),
                'is_late' => $request->boolean('is_late', now()->gt($assignment->due_date)),
                'status' => 'submitted',
            ]);

            return response()->json([
                'message' => 'Assignment submitted successfully',
                'submission' => $submission
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to submit assignment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Grade an assignment submission.
     * Accepts submission_id + score (or marks_obtained) in the request body.
     */
    public function grade(Request $request, Assignment $assignment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'submission_id'  => 'required|exists:assignment_submissions,id',
            'score'          => 'nullable|numeric|min:0|max:' . $assignment->total_marks,
            'marks_obtained' => 'nullable|numeric|min:0|max:' . $assignment->total_marks,
            'feedback'       => 'nullable|string',
            'grade'          => 'nullable|string|max:10',
            'status'         => 'sometimes|in:graded,needs_revision',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'    => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $submission = AssignmentSubmission::findOrFail($request->submission_id);
            $marks      = $request->score ?? $request->marks_obtained;

            $submission->update([
                'marks_obtained' => $marks,
                'feedback'       => $request->feedback,
                'grade'          => $request->grade,
                'status'         => $request->status ?? 'graded',
                'graded_at'      => now(),
                'graded_by'      => auth()->id(),
            ]);

            return response()->json([
                'message'    => 'Assignment graded successfully',
                'submission' => $submission,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to grade assignment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assignment submissions (route name: getSubmissions)
     */
    public function getSubmissions(Assignment $assignment): JsonResponse
    {
        $submissions = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->with('student')
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json([
            'assignment'  => $assignment,
            'submissions' => $submissions,
            'statistics'  => $this->getAssignmentStatistics($assignment),
        ]);
    }

    // ── Question-based assignment methods ─────────────────────────────────────

    public function listQuestions(Assignment $assignment): JsonResponse
    {
        return response()->json([
            'assignment' => $assignment->only(['id', 'title', 'total_marks', 'status']),
            'questions'  => $assignment->questions()->with('subject:id,name')->get(),
        ]);
    }

    public function addQuestion(Request $request, Assignment $assignment): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'question_text'   => 'required|string',
            'question_type'   => 'required|in:multiple_choice,true_false,essay,fill_blank',
            'marks'           => 'required|numeric|min:0',
            'options'         => 'nullable|array',
            'options.*.text'  => 'required_with:options|string',
            'correct_answer'  => 'nullable|array',
            'explanation'     => 'nullable|string',
            'difficulty_level'=> 'nullable|in:easy,medium,hard',
            'subject_id'      => 'nullable|exists:subjects,id',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        // For true_false, auto-build options if not provided
        $options = $request->options;
        if ($request->question_type === 'true_false' && empty($options)) {
            $options = [['text' => 'True'], ['text' => 'False']];
        }

        $question = Question::create([
            'assignment_id'   => $assignment->id,
            'exam_id'         => null,
            'subject_id'      => $request->subject_id ?? $assignment->subject_id,
            'question_text'   => $request->question_text,
            'question_type'   => $request->question_type,
            'difficulty_level'=> $request->input('difficulty_level', 'medium'),
            'marks'           => $request->marks,
            'options'         => $options,
            'correct_answer'  => $request->input('correct_answer', []),
            'explanation'     => $request->explanation,
            'status'          => 'active',
        ]);

        return response()->json(['message' => 'Question added', 'question' => $question], 201);
    }

    public function updateQuestion(Request $request, Assignment $assignment, int $questionId): JsonResponse
    {
        $question = Question::where('assignment_id', $assignment->id)->findOrFail($questionId);

        $v = Validator::make($request->all(), [
            'question_text'   => 'sometimes|string',
            'question_type'   => 'sometimes|in:multiple_choice,true_false,essay,fill_blank',
            'marks'           => 'sometimes|numeric|min:0',
            'options'         => 'nullable|array',
            'correct_answer'  => 'nullable|array',
            'explanation'     => 'nullable|string',
            'difficulty_level'=> 'nullable|in:easy,medium,hard',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $question->update($request->only([
            'question_text', 'question_type', 'marks', 'options', 'correct_answer', 'explanation', 'difficulty_level',
        ]));

        return response()->json(['message' => 'Question updated', 'question' => $question->fresh()]);
    }

    public function removeQuestion(Assignment $assignment, int $questionId): JsonResponse
    {
        Question::where('assignment_id', $assignment->id)->findOrFail($questionId)->delete();
        return response()->json(['message' => 'Question removed']);
    }

    /**
     * Student submits answers to assignment questions.
     */
    public function submitAnswers(Request $request, Assignment $assignment): JsonResponse
    {
        if ($assignment->status !== 'published') {
            return response()->json(['error' => 'Assignment is not open for submission'], 403);
        }

        $v = Validator::make($request->all(), [
            'student_id'              => 'required|exists:students,id',
            'answers'                 => 'required|array|min:1',
            'answers.*.question_id'   => 'required|exists:questions,id',
            'answers.*.answer_data'   => 'required|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $now    = now();
        $saved  = 0;

        foreach ($request->answers as $ans) {
            $question = Question::where('assignment_id', $assignment->id)
                ->where('id', $ans['question_id'])
                ->first();

            if (!$question) {
                continue;
            }

            $isCorrect = null;
            $marks     = null;

            if ($question->question_type !== 'essay') {
                $isCorrect = $question->isCorrectAnswer($ans['answer_data']);
                $marks     = $isCorrect ? $question->marks : 0;
            }

            AssignmentQuestionAnswer::updateOrCreate(
                [
                    'assignment_id' => $assignment->id,
                    'question_id'   => $question->id,
                    'student_id'    => $request->student_id,
                ],
                [
                    'answer_data'    => $ans['answer_data'],
                    'is_correct'     => $isCorrect,
                    'marks_obtained' => $marks,
                    'updated_at'     => $now,
                ]
            );

            $saved++;
        }

        return response()->json(['message' => "{$saved} answer(s) submitted"]);
    }

    /**
     * Teacher views all student answers per question.
     */
    public function questionResponses(Assignment $assignment): JsonResponse
    {
        $questions = $assignment->questions()->get();

        $answers = AssignmentQuestionAnswer::where('assignment_id', $assignment->id)
            ->with(['student.user:id,first_name,last_name', 'question:id,question_text,marks,correct_answer'])
            ->get()
            ->groupBy('question_id');

        $result = $questions->map(fn($q) => [
            'question'  => $q,
            'responses' => $answers->get($q->id, collect())->map(fn($a) => [
                'student_id'     => $a->student_id,
                'student_name'   => $a->student?->user?->first_name . ' ' . $a->student?->user?->last_name,
                'answer_data'    => $a->answer_data,
                'is_correct'     => $a->is_correct,
                'marks_obtained' => $a->marks_obtained,
                'feedback'       => $a->feedback,
                'graded_at'      => $a->graded_at,
            ]),
        ]);

        return response()->json([
            'assignment' => $assignment->only(['id', 'title', 'total_marks']),
            'questions'  => $result,
        ]);
    }

    /**
     * Teacher grades open-ended answers (or overrides auto-grade).
     * Body: { grades: [ { answer_id, marks_obtained, feedback } ] }
     */
    public function gradeQuestions(Request $request, Assignment $assignment): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'grades'                  => 'required|array|min:1',
            'grades.*.student_id'     => 'required|exists:students,id',
            'grades.*.question_id'    => 'required|exists:questions,id',
            'grades.*.marks_obtained' => 'required|numeric|min:0',
            'grades.*.feedback'       => 'nullable|string',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $now    = now();
        $userId = Auth::id();

        foreach ($request->grades as $g) {
            AssignmentQuestionAnswer::updateOrCreate(
                [
                    'assignment_id' => $assignment->id,
                    'question_id'   => $g['question_id'],
                    'student_id'    => $g['student_id'],
                ],
                [
                    'marks_obtained' => $g['marks_obtained'],
                    'feedback'       => $g['feedback'] ?? null,
                    'graded_by'      => $userId,
                    'graded_at'      => $now,
                ]
            );
        }

        return response()->json(['message' => count($request->grades) . ' grade(s) saved']);
    }

    /**
     * Get assignment statistics
     */
    protected function getAssignmentStatistics(Assignment $assignment): array
    {
        $submissions = AssignmentSubmission::where('assignment_id', $assignment->id);
        $total       = $submissions->count();
        $graded      = (clone $submissions)->where('status', 'graded')->count();
        $avgScore    = (clone $submissions)->whereNotNull('marks_obtained')->avg('marks_obtained') ?? 0;

        return [
            'total_submissions'  => $total,
            'graded_submissions' => $graded,
            'pending_grading'    => $total - $graded,
            'average_score'      => round((float) $avgScore, 1),
            'completion_rate'    => 0,
        ];
    }
}
