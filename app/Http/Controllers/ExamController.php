<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Question;
use App\Models\School;
use App\Models\Teacher;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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

        $exams = $query->withCount('questions')
                      ->orderBy('start_date', 'desc')
                      ->paginate($request->get('per_page', 15));

        $exams->getCollection()->transform(function (Exam $exam) {
            $exam->setAttribute('title', $exam->name);
            $exam->setAttribute('pass_mark', $exam->passing_marks);

            return $exam;
        });

        $response = [
            'exams' => $exams
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'exams' => [
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0
                ]
            ]);
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
     * Publish exam
     */
    public function publish(Exam $exam): JsonResponse
    {
        try {
            $exam->update(['status' => 'active']);

            // Clear cache
            $this->cacheService->invalidateExamCache($exam->id);

            return response()->json([
                'message' => 'Exam published successfully',
                'exam' => $exam
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to publish exam',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get exam questions
     */
    public function questions(Exam $exam): JsonResponse
    {
        $questions = $exam->questions()
                         ->with(['answers'])
                         ->orderBy('id')
                         ->get();

        return response()->json([
            'exam' => $exam,
            'questions' => $questions
        ]);
    }

    /**
     * Get exam attempts
     */
    public function attempts(Exam $exam): JsonResponse
    {
        $attempts = $exam->examAttempts()
                         ->with(['student.user'])
                         ->orderBy('start_time', 'desc')
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
