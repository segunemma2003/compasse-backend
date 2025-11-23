<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        $assignments = $query->with(['subject', 'class', 'teacher'])
                           ->orderBy('due_date', 'desc')
                           ->paginate($request->get('per_page', 15));

        $response = [
            'assignments' => $assignments
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
    }

    /**
     * Get assignment details
     */
    public function show(Assignment $assignment): JsonResponse
    {
        $cacheKey = "assignment:{$assignment->id}:details";
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $assignment->load(['subject', 'class', 'teacher', 'submissions.student']);

        $response = [
            'assignment' => $assignment,
            'statistics' => $this->getAssignmentStatistics($assignment),
        ];

        $this->cacheService->set($cacheKey, $response, 600); // 10 minutes cache

        return response()->json($response);
    }

    /**
     * Create new assignment
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'teacher_id' => 'required|exists:teachers,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'instructions' => 'nullable|string',
            'due_date' => 'required|date|after:now',
            'total_marks' => 'required|numeric|min:1',
            'assignment_type' => 'required|in:homework,project,essay,research,lab_report',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'submission_type' => 'required|in:file_upload,text,file_and_text',
            'max_file_size' => 'nullable|integer|min:1',
            'allowed_file_types' => 'nullable|array',
            'is_group_assignment' => 'boolean',
            'max_group_size' => 'nullable|integer|min:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $assignment = Assignment::create($request->all());

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
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'instructions' => 'nullable|string',
            'due_date' => 'sometimes|date',
            'total_marks' => 'sometimes|numeric|min:1',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
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
     * Grade assignment submission
     */
    public function grade(Request $request, Assignment $assignment, AssignmentSubmission $submission): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'marks_obtained' => 'required|numeric|min:0|max:' . $assignment->total_marks,
            'feedback' => 'nullable|string',
            'grade' => 'nullable|string|max:10',
            'status' => 'required|in:graded,needs_revision',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $submission->update([
                'marks_obtained' => $request->marks_obtained,
                'feedback' => $request->feedback,
                'grade' => $request->grade,
                'status' => $request->status,
                'graded_at' => now(),
                'graded_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Assignment graded successfully',
                'submission' => $submission
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to grade assignment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assignment submissions
     */
    public function submissions(Assignment $assignment): JsonResponse
    {
        $submissions = $assignment->submissions()
                                ->with(['student.user'])
                                ->orderBy('submitted_at', 'desc')
                                ->paginate(20);

        return response()->json([
            'assignment' => $assignment,
            'submissions' => $submissions
        ]);
    }

    /**
     * Get assignment statistics
     */
    protected function getAssignmentStatistics(Assignment $assignment): array
    {
        $submissions = $assignment->submissions();
        $totalSubmissions = $submissions->count();
        $gradedSubmissions = $submissions->where('status', 'graded')->count();
        $averageScore = $submissions->where('status', 'graded')->avg('marks_obtained') ?? 0;

        return [
            'total_submissions' => $totalSubmissions,
            'graded_submissions' => $gradedSubmissions,
            'pending_grading' => $totalSubmissions - $gradedSubmissions,
            'average_score' => round($averageScore, 2),
            'completion_rate' => $totalSubmissions > 0 ? round(($gradedSubmissions / $totalSubmissions) * 100, 2) : 0,
        ];
    }
}
