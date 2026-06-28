<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\School;
use App\Models\Teacher;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    protected $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get all exams
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cacheKey = "exams:list:" . md5(serialize($request->all()));
            $cached = $this->cacheService->get($cacheKey);

            if ($cached) {
                return response()->json($cached);
            }

            $query = Exam::query();

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_cbt')) {
            $query->where('is_cbt', $request->boolean('is_cbt'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Scope to exam-only questions (shared table also holds assignment/CA questions)
        $exams = $query->withCount(['questions as questions_count' => fn($q) => $q->whereNotNull('exam_id')])
                      ->orderBy('created_at', 'desc')
                      ->paginate($request->get('per_page', 15));

        $exams->getCollection()->transform(function (Exam $exam) {
            $exam->setAttribute('title', $exam->name);
            $exam->setAttribute('pass_mark', $exam->passing_marks);
            return $exam;
        });

        $response = ['exams' => $exams];
        $this->cacheService->set($cacheKey, $response, 300);
        return response()->json($response);

        } catch (\Exception $e) {
            Log::error('ExamController@index failed', [
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return response()->json([
                'error'   => 'Failed to load exams: ' . $e->getMessage(),
                'exams'   => ['data' => [], 'current_page' => 1, 'per_page' => 15, 'total' => 0],
            ], 500);
        }
    }

    /**
     * Get exam details
     */
    public function show(Exam $exam): JsonResponse
    {
        $cacheKey = "exam:{$exam->id}:details";
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $exam->load(['subject', 'class', 'teacher', 'term', 'academicYear', 'questions']);
        $exam->setAttribute('title', $exam->name);
        $exam->setAttribute('pass_mark', $exam->passing_marks);

        $response = [
            'exam' => $exam,
            'statistics' => $this->getExamStatistics($exam),
        ];

        $this->cacheService->set($cacheKey, $response, 600); // 10 minutes cache

        return response()->json($response);
    }

    /**
     * Create new exam
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'type' => 'nullable|in:quiz,test,exam,assignment',
            'duration_minutes' => 'required|integer|min:1|max:480',
            'total_marks' => 'required|numeric|min:1',
            'passing_marks' => 'nullable|numeric|min:0',
            'pass_mark' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_cbt' => 'boolean',
            'cbt_settings' => 'nullable|array',
            'question_settings' => 'nullable|array',
            'grading_settings' => 'nullable|array',
            'security_settings' => 'nullable|array',
            'status' => 'nullable|in:draft,active,completed,cancelled',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'show_result_immediately' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $school = School::first();
        if (! $school) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $name = $request->input('name') ?: $request->input('title');
        if (! $name) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => ['name' => ['Exam title or name is required.']],
            ], 422);
        }

        $teacherId = Teacher::where('user_id', Auth::id())->value('id');
        if (! $teacherId && $request->filled('teacher_id')) {
            $teacherId = (int) $request->teacher_id;
        }
        if (! $teacherId) {
            $teacherId = Teacher::where('school_id', $school->id)->orderBy('id')->value('id');
        }
        if (! $teacherId) {
            return response()->json([
                'error' => 'No teacher record',
                'message' => 'Create a teacher profile or add teachers before creating exams.',
            ], 422);
        }

        $passing = $request->input('passing_marks');
        if ($passing === null && $request->has('pass_mark')) {
            $passing = $request->input('pass_mark');
        }
        if ($passing === null) {
            $passing = 0;
        }

        $status = $request->input('status', 'draft');
        if ($status === 'published') {
            $status = 'active';
        }

        $start = $request->input('start_date');
        $end = $request->input('end_date');
        if (! $start) {
            $start = now();
        } else {
            $start = \Carbon\Carbon::parse($start);
        }
        if (! $end) {
            $end = $start->copy()->addYear();
        } else {
            $end = \Carbon\Carbon::parse($end);
        }

        $description = $request->input('description');
        if ($request->filled('instructions')) {
            $extra = trim((string) $request->input('instructions'));
            $description = $description ? trim($description."\n\n".$extra) : $extra;
        }

        $isCbt = $request->boolean('is_cbt', true);

        $cbtSettings = $request->input('cbt_settings', []);
        if (! is_array($cbtSettings)) {
            $cbtSettings = [];
        }
        if ($request->has('shuffle_questions')) {
            $cbtSettings['shuffle_questions'] = $request->boolean('shuffle_questions');
        }
        if ($request->has('shuffle_options')) {
            $cbtSettings['shuffle_options'] = $request->boolean('shuffle_options');
        }
        if ($request->has('show_result_immediately')) {
            $cbtSettings['show_result_immediately'] = $request->boolean('show_result_immediately');
        }

        try {
            $exam = Exam::create([
                'school_id' => $school->id,
                'subject_id' => $request->subject_id,
                'class_id' => $request->class_id,
                'term_id' => $request->term_id,
                'academic_year_id' => $request->academic_year_id,
                'name' => $name,
                'description' => $description,
                'type' => $request->input('type', 'exam'),
                'duration_minutes' => $request->duration_minutes,
                'total_marks' => $request->total_marks,
                'passing_marks' => $passing,
                'start_date' => $start,
                'end_date' => $end,
                'is_cbt' => $isCbt,
                'cbt_settings' => $cbtSettings ?: null,
                'status' => $status,
                'created_by' => $teacherId,
            ]);

            $this->cacheService->invalidateByPattern('exams:*');

            $exam->load(['subject', 'class', 'teacher']);
            $exam->setAttribute('title', $exam->name);
            $exam->setAttribute('pass_mark', $exam->passing_marks);

            return response()->json([
                'message' => 'Exam created successfully',
                'exam' => $exam,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create exam',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update exam
     */
    public function update(Request $request, Exam $exam): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'sometimes|integer|min:1|max:480',
            'total_marks' => 'sometimes|numeric|min:1',
            'passing_marks' => 'sometimes|numeric|min:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'cbt_settings' => 'nullable|array',
            'question_settings' => 'nullable|array',
            'grading_settings' => 'nullable|array',
            'security_settings' => 'nullable|array',
            'status' => 'sometimes|in:draft,active,completed,cancelled,published',
            'title' => 'sometimes|string|max:255',
            'pass_mark' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only([
                'name', 'description', 'duration_minutes', 'total_marks', 'passing_marks',
                'start_date', 'end_date', 'cbt_settings', 'question_settings', 'grading_settings', 'security_settings',
                'status',
            ]);
            if ($request->filled('title')) {
                $data['name'] = $request->title;
            }
            if ($request->has('pass_mark')) {
                $data['passing_marks'] = $request->pass_mark;
            }
            if (($data['status'] ?? '') === 'published') {
                $data['status'] = 'active';
            }
            $exam->update(array_filter($data, fn ($v) => $v !== null));

            // Clear cache
            $this->cacheService->invalidateExamCache($exam->id);

            $exam->refresh()->load(['subject', 'class', 'teacher']);
            $exam->setAttribute('title', $exam->name);
            $exam->setAttribute('pass_mark', $exam->passing_marks);

            return response()->json([
                'message' => 'Exam updated successfully',
                'exam' => $exam,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update exam',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete exam
     */
    public function destroy(Exam $exam): JsonResponse
    {
        try {
            $exam->delete();

            // Clear cache
            $this->cacheService->invalidateExamCache($exam->id);

            return response()->json([
                'message' => 'Exam deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete exam',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish exam (students can now see it in CBT)
     */
    public function publish(Exam $exam): JsonResponse
    {
        try {
            $exam->update(['status' => 'active']);
            $this->cacheService->invalidateExamCache($exam->id);

            return response()->json([
                'message' => 'Exam published successfully',
                'exam' => $exam->refresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to publish exam', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Revert exam to draft (hide from students)
     */
    public function unpublish(Exam $exam): JsonResponse
    {
        try {
            $exam->update(['status' => 'draft']);
            $this->cacheService->invalidateExamCache($exam->id);

            return response()->json([
                'message' => 'Exam moved back to draft',
                'exam' => $exam->refresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to unpublish exam', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a single attempt with per-question answers (for teacher review).
     */
    public function attemptDetail(Exam $exam, int $attemptId): JsonResponse
    {
        $attempt = $exam->attempts()->with(['student.user'])->findOrFail($attemptId);

        $answers = Answer::where('exam_attempt_id', $attempt->id)
            ->with(['question:id,question_text,question_type,options,correct_answer,marks'])
            ->get()
            ->map(fn($a) => [
                'answer_id'      => $a->id,
                'question_id'    => $a->question_id,
                'question_text'  => $a->question?->question_text,
                'question_type'  => $a->question?->question_type,
                'options'        => $a->question?->options,
                'correct_answer' => $a->question?->correct_answer,
                'full_marks'     => $a->question?->marks,
                'student_answer' => $a->answer_data,
                'answer_text'    => $a->answer_text,
                'is_correct'     => $a->is_correct,
                'marks_obtained' => $a->marks_obtained,
            ]);

        return response()->json([
            'attempt'  => $attempt,
            'answers'  => $answers,
            'summary'  => [
                'total_score'   => $attempt->total_score,
                'percentage'    => $attempt->percentage,
                'total_answers' => $answers->count(),
                'auto_graded'   => $answers->whereNotNull('is_correct')->count(),
                'needs_grading' => $answers->whereNull('is_correct')->count(),
            ],
        ]);
    }

    /**
     * Teacher overrides marks for one or more answers in an attempt.
     * Use this for essay questions or to correct an auto-grade.
     * Automatically recalculates the attempt total_score and percentage.
     *
     * Body: {
     *   "grades": [
     *     { "answer_id": 12, "marks_obtained": 4, "feedback": "Good explanation" },
     *     { "answer_id": 15, "marks_obtained": 2 }
     *   ]
     * }
     */
    public function gradeAttempt(Request $request, Exam $exam, int $attemptId): JsonResponse
    {
        $attempt = $exam->attempts()->findOrFail($attemptId);

        $v = Validator::make($request->all(), [
            'grades'                  => 'required|array|min:1',
            'grades.*.answer_id'      => 'required|exists:answers,id',
            'grades.*.marks_obtained' => 'required|numeric|min:0',
            'grades.*.feedback'       => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        try {
            DB::beginTransaction();

            foreach ($request->grades as $g) {
                $answer = Answer::where('exam_attempt_id', $attempt->id)
                    ->findOrFail($g['answer_id']);

                $maxMarks = $answer->question?->marks ?? PHP_INT_MAX;
                if ($g['marks_obtained'] > $maxMarks) {
                    return response()->json([
                        'error' => "marks_obtained ({$g['marks_obtained']}) exceeds question max ({$maxMarks}) for answer #{$g['answer_id']}",
                    ], 422);
                }

                $answer->update([
                    'marks_obtained' => $g['marks_obtained'],
                    'is_correct'     => $g['marks_obtained'] > 0,
                    'answer_text'    => isset($g['feedback'])
                        ? ($answer->answer_text . "\n[Teacher feedback: " . $g['feedback'] . "]")
                        : $answer->answer_text,
                ]);
            }

            // Recalculate total score from all answers
            $newTotal    = Answer::where('exam_attempt_id', $attempt->id)->sum('marks_obtained');
            $totalMarks  = $exam->total_marks ?: 1;
            $newPct      = round(($newTotal / $totalMarks) * 100, 2);

            $attempt->update([
                'total_score' => $newTotal,
                'percentage'  => $newPct,
            ]);

            DB::commit();

            $this->cacheService->invalidateExamCache($exam->id);

            return response()->json([
                'message'     => count($request->grades) . ' answer(s) graded',
                'attempt_id'  => $attempt->id,
                'total_score' => $newTotal,
                'percentage'  => $newPct,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get exam questions
     */
    /**
     * List all questions for an exam (teacher view — includes options and correct answers).
     */
    public function questions(Exam $exam): JsonResponse
    {
        $questions = $exam->questions()
            ->orderBy('id')
            ->get(['id', 'question_text', 'question_type', 'difficulty_level', 'marks',
                   'options', 'correct_answer', 'explanation', 'time_limit_seconds', 'status']);

        return response()->json([
            'exam'       => $exam->only(['id', 'name', 'total_marks', 'status', 'is_cbt', 'duration_minutes']),
            'questions'  => $questions,
            'total_marks_set' => $questions->sum('marks'),
        ]);
    }

    /**
     * Update a single exam question.
     */
    public function updateQuestion(Request $request, Exam $exam, int $questionId): JsonResponse
    {
        $question = Question::where('exam_id', $exam->id)->findOrFail($questionId);

        $v = Validator::make($request->all(), [
            'question_text'      => 'sometimes|string',
            'question_type'      => 'sometimes|in:multiple_choice,true_false,essay,fill_blank,matching',
            'difficulty_level'   => 'nullable|in:easy,medium,hard',
            'marks'              => 'sometimes|numeric|min:0.1',
            'options'            => 'nullable|array',
            'options.*.text'     => 'required_with:options|string',
            'correct_answer'     => 'nullable|array',
            'explanation'        => 'nullable|string',
            'time_limit_seconds' => 'nullable|integer|min:10',
            'status'             => 'sometimes|in:active,inactive',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        // Auto-build T/F options if not supplied
        if ($request->input('question_type') === 'true_false' && $request->missing('options')) {
            $request->merge(['options' => [['text' => 'True'], ['text' => 'False']]]);
        }

        $question->update($request->only([
            'question_text', 'question_type', 'difficulty_level', 'marks',
            'options', 'correct_answer', 'explanation', 'time_limit_seconds', 'status',
        ]));

        $this->cacheService->invalidateExamCache($exam->id);

        return response()->json(['message' => 'Question updated', 'question' => $question->fresh()]);
    }

    /**
     * Delete a single exam question.
     */
    public function deleteQuestion(Exam $exam, int $questionId): JsonResponse
    {
        $question = Question::where('exam_id', $exam->id)->findOrFail($questionId);
        $question->delete();

        $this->cacheService->invalidateExamCache($exam->id);

        return response()->json(['message' => 'Question deleted']);
    }

    /**
     * Get exam attempts
     */
    public function attempts(Exam $exam): JsonResponse
    {
        $attempts = $exam->attempts()
                         ->with(['student.user'])
                         ->orderBy('started_at', 'desc')
                         ->paginate(20);

        return response()->json([
            'exam' => $exam,
            'attempts' => $attempts
        ]);
    }

    /**
     * Get exam statistics
     */
    protected function getExamStatistics(Exam $exam): array
    {
        $attempts = $exam->attempts();
        $totalAttempts = $attempts->count();
        $completedAttempts = (clone $attempts)->where('status', 'completed')->count();
        $averageScore = (clone $attempts)->where('status', 'completed')->avg('total_score') ?? 0;

        return [
            'total_attempts' => $totalAttempts,
            'completed_attempts' => $completedAttempts,
            'pending_attempts' => $totalAttempts - $completedAttempts,
            'average_score' => round($averageScore, 2),
            'completion_rate' => $totalAttempts > 0 ? round(($completedAttempts / $totalAttempts) * 100, 2) : 0,
        ];
    }
}
