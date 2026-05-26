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
use App\Http\Controllers\DashboardController;
use App\Jobs\SendEmailJob;

class TeacherController extends Controller
{
    protected $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get all teachers with optional filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $cacheKey = 'teachers:list:' . md5(serialize($request->all()));
        $cached   = $this->cacheService->get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }

        try {
            $query = Teacher::query()->with(['department:id,name', 'user:id,email']);

            if ($request->filled('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('first_name',   'like', "%{$s}%")
                      ->orWhere('last_name',  'like', "%{$s}%")
                      ->orWhere('employee_id','like', "%{$s}%")
                      ->orWhere('email',      'like', "%{$s}%");
                });
            }

            $perPage  = min((int) $request->get('per_page', 20), 200);
            $teachers = $query->orderBy('first_name')->paginate($perPage);

            // Basic summary counts (no extra query — derived from paginator total).
            $total   = $teachers->total();
            $active  = Teacher::where('status', 'active')->count();
            $inactive = $total - $active;

            $response = [
                'teachers' => $teachers,
                'summary'  => ['total' => $total, 'active' => $active, 'inactive' => $inactive],
            ];

            $this->cacheService->set($cacheKey, $response, 300);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'error'    => 'Failed to load teachers',
                'message'  => $e->getMessage(),
                'teachers' => ['data' => [], 'current_page' => 1, 'per_page' => 20, 'total' => 0],
            ], 500);
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

        $teacher->load(['user', 'department', 'subjects', 'classes']);

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
        // Normalise: accept date_joined as an alias for employment_date
        if (!$request->filled('employment_date') && $request->filled('date_joined')) {
            $request->merge(['employment_date' => $request->input('date_joined')]);
        }

        $validator = Validator::make($request->all(), [
            'first_name'          => 'required|string|max:255',
            'last_name'           => 'required|string|max:255',
            'middle_name'         => 'nullable|string|max:255',
            'title'               => 'nullable|string|max:50',
            'email'               => 'nullable|email|unique:teachers,email',
            'phone'               => 'nullable|string|max:20',
            'address'             => 'nullable|string|max:500',
            'date_of_birth'       => 'nullable|date',
            'gender'              => 'nullable|in:male,female,other',
            'qualification'       => 'nullable|string|max:255',
            'specialization'      => 'nullable|string|max:255',
            'experience_years'    => 'nullable|integer|min:0',
            'salary'              => 'nullable|numeric|min:0',
            'employment_date'     => 'required|date',
            'employment_type'     => 'nullable|in:full_time,part_time,contract',
            'department_id'       => 'nullable|exists:departments,id',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_account_name'   => 'nullable|string|max:255',
            'bio'                 => 'nullable|string|max:1000',
            'profile_picture'     => 'nullable|string|max:2048',
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

            // Default password = surname (lowercase, alphanumeric only)
            $defaultPassword = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $request->last_name)) ?: 'teacher123';

            // Create user account for teacher
            $user = \App\Models\User::create([
                'name' => trim("{$request->title} {$request->first_name} {$request->last_name}"),
                'email' => $email,
                'username' => $username,
                'password' => \Hash::make($defaultPassword),
                'role' => 'teacher',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            // Link user to teacher
            $teacher->update(['user_id' => $user->id]);

            DB::commit();

            // Clear local teacher cache and bust the shared dashboard cache
            $this->cacheService->invalidateByPattern("teachers:*");
            DashboardController::bustCache();

            // Queue credentials email to teacher
            $body = "Hello {$request->first_name},\n\n"
                . "Your teacher account has been created.\n\n"
                . "Login Email: {$email}\n"
                . "Password: {$defaultPassword}\n\n"
                . "Please log in and change your password.\n\n"
                . "Regards,\nSchool Administration";

            $school = $this->getSchoolFromRequest($request);
            SendEmailJob::dispatch(
                to:       $email,
                subject:  'Your Teacher Login Credentials',
                body:     $body,
                schoolId: $school ? (string) $school->id : null,
                type:     'credentials',
            )->onQueue('emails');

            return response()->json([
                'message' => 'Teacher created successfully',
                'teacher' => $teacher->load(['user', 'department']),
                'login_credentials' => [
                    'email'    => $email,
                    'username' => $username,
                    'password' => $defaultPassword,
                    'role'     => 'teacher',
                    'note'     => 'Password is surname in lowercase. Teacher should change it on first login.',
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
        $school  = \App\Models\School::find($schoolId) ?? \App\Models\School::first();
        $prefix  = $school ? strtoupper(trim($school->code ?? '')) : '';
        if ($prefix === '') $prefix = 'SCH';

        $mm   = date('m');
        $yyyy = date('Y');
        $count = Teacher::where('school_id', $schoolId)->count();
        $next  = $count + 1;

        $candidate = "{$prefix}/TE/{$mm}/{$yyyy}/" . str_pad($next, 4, '0', STR_PAD_LEFT);
        while (Teacher::where('employee_id', $candidate)->exists()) {
            $next++;
            $candidate = "{$prefix}/TE/{$mm}/{$yyyy}/" . str_pad($next, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /**
     * Generate teacher email
     */
    private function generateTeacherEmail(string $firstName, string $lastName, int $teacherId, int $schoolId): string
    {
        $school = \App\Models\School::find($schoolId);
        
        // Extract domain from school website or use subdomain.centraldomain
        if ($school && $school->website) {
            // Remove http://, https://, www. from website
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website);
            // Remove trailing slash and any path
            $domain = rtrim(explode('/', $domain)[0], '/');
        } else {
            $tenant = $school ? $school->tenant : null;
            $centralDomain = collect(config('tenancy.central_domains', ['compasse.net']))
                ->reject(fn ($d) => in_array($d, ['127.0.0.1', 'localhost']) || str_starts_with($d, 'api.') || str_starts_with($d, 'www.'))
                ->first() ?? 'compasse.net';
            $domain = ($tenant->subdomain ?? 'school') . '.' . $centralDomain;
        }
        
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
        
        return "{$cleanFirstName}.{$cleanLastName}{$teacherId}@{$domain}";
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
            'first_name'          => 'sometimes|string|max:255',
            'last_name'           => 'sometimes|string|max:255',
            'middle_name'         => 'nullable|string|max:255',
            'title'               => 'nullable|string|max:50',
            'email'               => 'sometimes|email|unique:teachers,email,' . $teacher->id,
            'phone'               => 'nullable|string|max:20',
            'address'             => 'nullable|string|max:500',
            'date_of_birth'       => 'nullable|date',
            'gender'              => 'nullable|in:male,female,other',
            'qualification'       => 'nullable|string|max:255',
            'specialization'      => 'nullable|string|max:255',
            'experience_years'    => 'nullable|integer|min:0',
            'salary'              => 'nullable|numeric|min:0',
            'employment_date'     => 'sometimes|date',
            'employment_type'     => 'nullable|in:full_time,part_time,contract',
            'department_id'       => 'nullable|exists:departments,id',
            'status'              => 'sometimes|in:active,inactive,suspended',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_account_name'   => 'nullable|string|max:255',
            'bio'                 => 'nullable|string|max:1000',
            'profile_picture'     => 'nullable|string|max:2048',
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
     * Get teacher's subjects (with pivot class_id).
     */
    public function subjects(Teacher $teacher): JsonResponse
    {
        $subjects = $teacher->subjects()
            ->with(['class:id,name'])
            ->withPivot(['class_id', 'status'])
            ->get();

        return response()->json([
            'teacher'  => $teacher,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Assign a subject (+ optional class) to a teacher.
     * Multiple teachers can share the same subject+class.
     * POST /teachers/{teacher}/subjects
     */
    public function assignSubject(Request $request, Teacher $teacher): JsonResponse
    {
        $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'class_id'   => 'nullable|exists:classes,id',
        ]);

        $subjectId = $request->subject_id;
        $classId   = $request->class_id;

        // Prevent exact duplicate for this teacher.
        $exists = DB::table('teacher_subjects')
            ->where('teacher_id',  $teacher->id)
            ->where('subject_id',  $subjectId)
            ->where('class_id',    $classId)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This subject is already assigned to this teacher for the selected class.',
            ]);
        }

        DB::table('teacher_subjects')->insert([
            'teacher_id' => $teacher->id,
            'subject_id' => $subjectId,
            'class_id'   => $classId,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cacheService->invalidateTeacherCache($teacher->id);

        return response()->json(['message' => 'Subject assigned successfully.'], 201);
    }

    /**
     * Remove a specific subject assignment from a teacher.
     * DELETE /teachers/{teacher}/subjects/{subject}?class_id=X
     */
    public function removeSubject(Request $request, Teacher $teacher, Subject $subject): JsonResponse
    {
        $classId = $request->query('class_id');

        $query = DB::table('teacher_subjects')
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $subject->id);

        if ($classId !== null) {
            $query->where('class_id', $classId ?: null);
        }

        $deleted = $query->delete();

        if (! $deleted) {
            return response()->json(['error' => 'Assignment not found.'], 404);
        }

        $this->cacheService->invalidateTeacherCache($teacher->id);

        return response()->json(['message' => 'Subject removed successfully.']);
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
            'experience_years' => $teacher->experience_years,
            'role' => $teacher->getRole(),
        ];
    }
}
