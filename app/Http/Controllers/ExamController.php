<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Question;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        $exams = $query->with(['subject', 'class', 'teacher', 'term', 'academicYear'])
                      ->orderBy('start_date', 'desc')
                      ->paginate($request->get('per_page', 15));

        $response = [
            'exams' => $exams
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
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
            'teacher_id' => 'required|exists:teachers,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:cbt,written,oral,practical',
            'duration_minutes' => 'required|integer|min:1|max:480',
            'total_marks' => 'required|numeric|min:1',
            'passing_marks' => 'required|numeric|min:0',
            'start_date' => 'required|date|after:now',
            'end_date' => 'required|date|after:start_date',
            'is_cbt' => 'boolean',
            'cbt_settings' => 'nullable|array',
            'question_settings' => 'nullable|array',
            'grading_settings' => 'nullable|array',
            'security_settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $exam = Exam::create($request->all());

            // Clear cache
            $this->cacheService->invalidateByPattern("exams:*");

            return response()->json([
                'message' => 'Exam created successfully',
                'exam' => $exam->load(['subject', 'class', 'teacher'])
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
            'status' => 'sometimes|in:draft,published,active,completed,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $exam->update($request->all());

            // Clear cache
            $this->cacheService->invalidateExamCache($exam->id);

            return response()->json([
                'message' => 'Exam updated successfully',
                'exam' => $exam->load(['subject', 'class', 'teacher'])
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
            $exam->update(['status' => 'published']);

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
                         ->orderBy('order')
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
        $attempts = $exam->examAttempts();
        $totalAttempts = $attempts->count();
        $completedAttempts = $attempts->where('status', 'submitted')->count();
        $averageScore = $attempts->where('is_graded', true)->avg('score') ?? 0;

        return [
            'total_attempts' => $totalAttempts,
            'completed_attempts' => $completedAttempts,
            'pending_attempts' => $totalAttempts - $completedAttempts,
            'average_score' => round($averageScore, 2),
            'completion_rate' => $totalAttempts > 0 ? round(($completedAttempts / $totalAttempts) * 100, 2) : 0,
        ];
    }
}
