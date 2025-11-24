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
use Illuminate\Support\Facades\DB;

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
        try {
        $cacheKey = "teachers:list:" . md5(serialize($request->all()));
        $cached = $this->cacheService->get($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

            // Check if teachers table exists and build query
            try {
                // Use DB facade to check if table exists first
                $tableExists = false;
                try {
                    $tableExists = \Illuminate\Support\Facades\Schema::hasTable('teachers');
                } catch (\Exception $e) {
                    // Schema check failed, assume table doesn't exist
                    $tableExists = false;
                }

                if (!$tableExists) {
                    return response()->json([
                        'teachers' => [
                            'data' => [],
                            'current_page' => 1,
                            'per_page' => 15,
                            'total' => 0
                        ]
                    ]);
                }

                // Try to build query - this might still fail if table structure is wrong
        $query = Teacher::query();
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
                return response()->json([
                    'teachers' => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 0
                    ]
                ]);
            }

        if ($request->has('department_id')) {
                try {
            $query->where('department_id', $request->department_id);
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
        }

        if ($request->has('status')) {
                try {
            $query->where('status', $request->status);
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
        }

        if ($request->has('search')) {
                try {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
                } catch (\Exception $e) {
                    // Columns don't exist
                }
            }

            // Try to paginate without relationships first to avoid relationship errors
            try {
                $teachers = $query->paginate($request->get('per_page', 15));
            } catch (\Exception $e) {
                // If pagination fails, try using DB facade directly
                try {
                    $teachers = $query->paginate($request->get('per_page', 15));
                } catch (\Exception $e2) {
                    // If pagination fails, try using DB facade directly
                    try {
                        // Build query using DB facade
                        $dbQuery = DB::table('teachers');

                        // Apply filters
                        if ($request->has('department_id')) {
                            try {
                                if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'department_id')) {
                                    $dbQuery->where('department_id', $request->department_id);
                                }
                            } catch (\Exception $e) {}
                        }

                        if ($request->has('status')) {
                            try {
                                if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'status')) {
                                    $dbQuery->where('status', $request->status);
                                }
                            } catch (\Exception $e) {}
                        }

                        if ($request->has('search')) {
                            try {
                                $search = $request->search;
                                $dbQuery->where(function($q) use ($search) {
                                    if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'first_name')) {
                                        $q->where('first_name', 'like', "%{$search}%");
                                    }
                                    if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'last_name')) {
                                        $q->orWhere('last_name', 'like', "%{$search}%");
                                    }
                                    if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'employee_id')) {
                                        $q->orWhere('employee_id', 'like', "%{$search}%");
                                    }
                                    if (\Illuminate\Support\Facades\Schema::hasColumn('teachers', 'email')) {
                                        $q->orWhere('email', 'like', "%{$search}%");
                                    }
                                });
                            } catch (\Exception $e) {}
                        }

                        $perPage = $request->get('per_page', 15);
                        $page = $request->get('page', 1);
                        $offset = ($page - 1) * $perPage;

                        $total = $dbQuery->count();
                        $teachers = $dbQuery->offset($offset)
                            ->limit($perPage)
                            ->get();

                        $teachers = new \Illuminate\Pagination\LengthAwarePaginator(
                            $teachers,
                            $total,
                            $perPage,
                            $page,
                            ['path' => $request->url(), 'query' => $request->query()]
                        );
                    } catch (\Exception $e3) {
                        // Table doesn't exist or query failed completely
                        return response()->json([
                            'teachers' => [
                                'data' => [],
                                'current_page' => 1,
                                'per_page' => 15,
                                'total' => 0
                            ]
                        ]);
                    }
                }
            }

        $response = [
            'teachers' => $teachers
        ];

        $this->cacheService->set($cacheKey, $response, 300); // 5 minutes cache

        return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'teachers' => [
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0
                ]
            ]);
        }
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
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:50',
            'email' => 'nullable|email|unique:teachers,email',
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
            DB::beginTransaction();

            // Auto-get school_id from tenant context
            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school from tenant context'
                ], 400);
            }

            // Auto-generate employee_id
            $employeeId = $this->generateTeacherEmployeeId($schoolId);

            // Generate temporary email if not provided (will be updated with proper one after getting ID)
            $tempEmail = $request->email ?? "temp.{$employeeId}@temp.samschool.com";

            // Create teacher record with temporary email
            $teacherData = $request->except(['user_id', 'employee_id']);
            $teacherData['school_id'] = $schoolId;
            $teacherData['employee_id'] = $employeeId;
            $teacherData['email'] = $tempEmail;
            $teacherData['status'] = $teacherData['status'] ?? 'active';
            
            $teacher = Teacher::create($teacherData);

            // Generate proper email and username with teacher ID
            $email = $request->email ?? $this->generateTeacherEmail(
                $request->first_name,
                $request->last_name,
                $teacher->id,
                $schoolId
            );

            $username = $this->generateTeacherUsername(
                $request->first_name,
                $request->last_name,
                $teacher->id
            );

            // Update teacher with proper generated email
            if (!$request->email) {
                $teacher->update(['email' => $email]);
            }

            // Create user account for teacher
            $user = \App\Models\User::create([
                'name' => trim("{$request->title} {$request->first_name} {$request->last_name}"),
                'email' => $email,
                'username' => $username,
                'password' => \Hash::make('Password@123'), // Default password
                'role' => 'teacher',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            // Link user to teacher
            $teacher->update(['user_id' => $user->id]);

            DB::commit();

            // Clear cache
            $this->cacheService->invalidateByPattern("teachers:*");

            return response()->json([
                'message' => 'Teacher created successfully',
                'teacher' => $teacher->load(['user', 'department']),
                'login_credentials' => [
                    'email' => $email,
                    'username' => $username,
                    'password' => 'Password@123',
                    'role' => 'teacher',
                    'note' => 'Teacher should change password on first login'
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create teacher',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate teacher employee ID
     */
    private function generateTeacherEmployeeId(int $schoolId): string
    {
        $year = date('Y');
        $lastTeacher = Teacher::where('school_id', $schoolId)
                             ->orderBy('id', 'desc')
                             ->first();
        
        $nextNumber = $lastTeacher ? ((int) substr($lastTeacher->employee_id, -4) + 1) : 1;
        
        return "TCH{$year}" . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate teacher email
     */
    private function generateTeacherEmail(string $firstName, string $lastName, int $teacherId, int $schoolId): string
    {
        $school = \App\Models\School::find($schoolId);
        $subdomain = $school->tenant->subdomain ?? 'school';
        
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
        
        return "{$cleanFirstName}.{$cleanLastName}{$teacherId}@{$subdomain}.samschool.com";
    }

    /**
     * Generate teacher username
     */
    private function generateTeacherUsername(string $firstName, string $lastName, int $teacherId): string
    {
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
        
        return "{$cleanFirstName}.{$cleanLastName}{$teacherId}";
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
