<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\Student;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TeacherController extends Controller
{
    protected $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get all teachers
     */
    public function index(Request $request): JsonResponse
    {
        $cacheKey = "teachers:list:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $query = Teacher::query();

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $teachers = $query->with(['user', 'department', 'subjects', 'classes'])
                          ->paginate($request->get('per_page', 15));

        $response = [
            'teachers' => $teachers
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
    }

    /**
     * Get teacher details
     */
    public function show(Teacher $teacher): JsonResponse
    {
        $cacheKey = "teacher:{$teacher->id}:details";
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $teacher->load(['user', 'department', 'subjects', 'classes', 'students']);

        $response = [
            'teacher' => $teacher,
            'role' => $teacher->getRole(),
            'stats' => $this->getTeacherStats($teacher),
        ];

        $this->cacheService->set($cacheKey, $response, 600); // 10 minutes cache

        return response()->json($response);
    }

    /**
     * Create new teacher
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'employee_id' => 'required|string|unique:teachers,employee_id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:50',
            'email' => 'required|email|unique:teachers,email',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'qualification' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'employment_date' => 'required|date',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $teacher = Teacher::create($request->all());

            // Clear cache
            $this->cacheService->invalidateByPattern("teachers:*");

            return response()->json([
                'message' => 'Teacher created successfully',
                'teacher' => $teacher->load(['user', 'department'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create teacher',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update teacher
     */
    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:50',
            'email' => 'sometimes|email|unique:teachers,email,' . $teacher->id,
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'qualification' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $teacher->update($request->all());

            // Clear cache
            $this->cacheService->invalidateTeacherCache($teacher->id);

            return response()->json([
                'message' => 'Teacher updated successfully',
                'teacher' => $teacher->load(['user', 'department'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update teacher',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete teacher
     */
    public function destroy(Teacher $teacher): JsonResponse
    {
        try {
            $teacher->delete();

            // Clear cache
            $this->cacheService->invalidateTeacherCache($teacher->id);

            return response()->json([
                'message' => 'Teacher deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete teacher',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get teacher's classes
     */
    public function classes(Teacher $teacher): JsonResponse
    {
        $classes = $teacher->classes()
                          ->with(['students', 'subjects'])
                          ->get();

        return response()->json([
            'teacher' => $teacher,
            'classes' => $classes
        ]);
    }

    /**
     * Get teacher's subjects
     */
    public function subjects(Teacher $teacher): JsonResponse
    {
        $subjects = $teacher->subjects()
                           ->with(['class', 'students'])
                           ->get();

        return response()->json([
            'teacher' => $teacher,
            'subjects' => $subjects
        ]);
    }

    /**
     * Get teacher's students
     */
    public function students(Teacher $teacher): JsonResponse
    {
        $students = $teacher->students()
                           ->with(['user', 'class', 'arm'])
                           ->paginate(20);

        return response()->json([
            'teacher' => $teacher,
            'students' => $students
        ]);
    }

    /**
     * Get teacher statistics
     */
    protected function getTeacherStats(Teacher $teacher): array
    {
        return [
            'total_classes' => $teacher->classes()->count(),
            'total_subjects' => $teacher->subjects()->count(),
            'total_students' => $teacher->students()->count(),
            'experience_years' => $teacher->experience_years,
            'role' => $teacher->getRole(),
        ];
    }
}
