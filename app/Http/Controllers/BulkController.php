<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant;
use App\Models\School;
use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\Guardian;
use App\Models\Exam;
use App\Models\Assignment;
use App\Models\Fee;
use App\Models\Payment;
use App\Models\Attendance;
use App\Models\Staff;
use App\Models\QuestionBank;
use App\Services\TenantService;
use App\Services\BulkOperationService;

class BulkController extends Controller
{
    protected TenantService $tenantService;
    protected BulkOperationService $bulkService;

    public function __construct(TenantService $tenantService, BulkOperationService $bulkService)
    {
        $this->tenantService = $tenantService;
        $this->bulkService = $bulkService;
    }

    /**
     * Bulk register students
     */
    public function bulkRegisterStudents(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'students' => 'required|array|min:1|max:1000',
            'students.*.first_name' => 'required|string|max:255',
            'students.*.last_name' => 'required|string|max:255',
            'students.*.email' => 'required|email|unique:users,email',
            'students.*.phone' => 'nullable|string|max:20',
            'students.*.admission_number' => 'required|string|unique:students,admission_number',
            'students.*.class_id' => 'required|exists:classes,id',
            'students.*.arm_id' => 'required|exists:arms,id',
            'students.*.date_of_birth' => 'required|date',
            'students.*.gender' => 'required|in:male,female,other',
            'students.*.address' => 'nullable|string',
            'guardians' => 'nullable|array',
            'guardians.*.first_name' => 'required_with:guardians|string|max:255',
            'guardians.*.last_name' => 'required_with:guardians|string|max:255',
            'guardians.*.email' => 'required_with:guardians|email',
            'guardians.*.phone' => 'required_with:guardians|string|max:20',
            'guardians.*.relationship' => 'required_with:guardians|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkRegisterStudents(
                $request->students,
                $request->guardians ?? []
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk student registration completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk student registration failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk register teachers
     */
    public function bulkRegisterTeachers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'teachers' => 'required|array|min:1|max:500',
            'teachers.*.first_name' => 'required|string|max:255',
            'teachers.*.last_name' => 'required|string|max:255',
            'teachers.*.email' => 'required|email|unique:users,email',
            'teachers.*.phone' => 'nullable|string|max:20',
            'teachers.*.employee_id' => 'required|string|unique:teachers,employee_id',
            'teachers.*.department_id' => 'required|exists:departments,id',
            'teachers.*.qualification' => 'required|string|max:255',
            'teachers.*.experience_years' => 'nullable|integer|min:0',
            'teachers.*.hire_date' => 'required|date',
            'teachers.*.date_of_birth' => 'required|date',
            'teachers.*.gender' => 'required|in:male,female,other',
            'teachers.*.address' => 'nullable|string',
            'subjects' => 'nullable|array',
            'subjects.*.subject_id' => 'required_with:subjects|exists:subjects,id',
            'classes' => 'nullable|array',
            'classes.*.class_id' => 'required_with:classes|exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkRegisterTeachers(
                $request->teachers,
                $request->subjects ?? [],
                $request->classes ?? []
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk teacher registration completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk teacher registration failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create classes
     */
    public function bulkCreateClasses(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'classes' => 'required|array|min:1|max:100',
            'classes.*.name' => 'required|string|max:255',
            'classes.*.description' => 'nullable|string',
            'classes.*.academic_year_id' => 'required|exists:academic_years,id',
            'classes.*.term_id' => 'required|exists:terms,id',
            'classes.*.arms' => 'nullable|array',
            'classes.*.arms.*.name' => 'required_with:arms|string|max:255',
            'classes.*.arms.*.description' => 'nullable|string',
            'classes.*.arms.*.capacity' => 'nullable|integer|min:1',
            'classes.*.arms.*.class_teacher_id' => 'nullable|exists:teachers,id',
            'classes.*.subjects' => 'nullable|array',
            'classes.*.subjects.*' => 'exists:subjects,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkCreateClasses($request->classes);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk class creation completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk class creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk class creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create subjects
     */
    public function bulkCreateSubjects(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subjects' => 'required|array|min:1|max:200',
            'subjects.*.name' => 'required|string|max:255',
            'subjects.*.code' => 'required|string|max:20|unique:subjects,code',
            'subjects.*.description' => 'nullable|string',
            'subjects.*.department_id' => 'required|exists:departments,id',
            'subjects.*.classes' => 'nullable|array',
            'subjects.*.classes.*' => 'exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkCreateSubjects($request->subjects);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk subject creation completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk subject creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk subject creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create exams
     */
    public function bulkCreateExams(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'exams' => 'required|array|min:1|max:100',
            'exams.*.name' => 'required|string|max:255',
            'exams.*.description' => 'nullable|string',
            'exams.*.subject_id' => 'required|exists:subjects,id',
            'exams.*.class_id' => 'required|exists:classes,id',
            'exams.*.teacher_id' => 'required|exists:teachers,id',
            'exams.*.type' => 'required|in:cbt,written,oral,practical',
            'exams.*.duration_minutes' => 'required|integer|min:1',
            'exams.*.total_marks' => 'required|numeric|min:1',
            'exams.*.passing_marks' => 'required|numeric|min:0',
            'exams.*.start_date' => 'required|date|after:now',
            'exams.*.end_date' => 'required|date|after:start_date',
            'exams.*.is_cbt' => 'boolean',
            'exams.*.cbt_settings' => 'nullable|array',
            'exams.*.question_settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkCreateExams($request->exams);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk exam creation completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk exam creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk exam creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create assignments
     */
    public function bulkCreateAssignments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'assignments' => 'required|array|min:1|max:200',
            'assignments.*.title' => 'required|string|max:255',
            'assignments.*.description' => 'nullable|string',
            'assignments.*.subject_id' => 'required|exists:subjects,id',
            'assignments.*.class_id' => 'required|exists:classes,id',
            'assignments.*.teacher_id' => 'required|exists:teachers,id',
            'assignments.*.due_date' => 'required|date|after:now',
            'assignments.*.total_marks' => 'required|numeric|min:1',
            'assignments.*.instructions' => 'nullable|string',
            'assignments.*.attachments' => 'nullable|array',
            'assignments.*.attachments.*' => 'string|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkCreateAssignments($request->assignments);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk assignment creation completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk assignment creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk assignment creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create fees
     */
    public function bulkCreateFees(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fees' => 'required|array|min:1|max:500',
            'fees.*.name' => 'required|string|max:255',
            'fees.*.description' => 'nullable|string',
            'fees.*.amount' => 'required|numeric|min:0',
            'fees.*.currency' => 'required|string|max:3',
            'fees.*.due_date' => 'required|date|after:now',
            'fees.*.fee_type' => 'required|in:tuition,transport,hostel,library,exam,other',
            'fees.*.is_mandatory' => 'boolean',
            'fees.*.classes' => 'nullable|array',
            'fees.*.classes.*' => 'exists:classes,id',
            'fees.*.students' => 'nullable|array',
            'fees.*.students.*' => 'exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkCreateFees($request->fees);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk fee creation completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk fee creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk fee creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk mark attendance
     */
    public function bulkMarkAttendance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attendance_records' => 'required|array|min:1|max:1000',
            'attendance_records.*.attendanceable_id' => 'required|integer',
            'attendance_records.*.attendanceable_type' => 'required|in:student,teacher,staff',
            'attendance_records.*.date' => 'required|date',
            'attendance_records.*.status' => 'required|in:present,absent,late,excused',
            'attendance_records.*.check_in_time' => 'nullable|date_format:H:i:s',
            'attendance_records.*.check_out_time' => 'nullable|date_format:H:i:s',
            'attendance_records.*.notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkMarkAttendance($request->attendance_records);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk attendance marking completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk attendance marking failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk attendance marking failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update student results
     */
    public function bulkUpdateResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'results' => 'required|array|min:1|max:1000',
            'results.*.student_id' => 'required|exists:students,id',
            'results.*.exam_id' => 'required|exists:exams,id',
            'results.*.subject_id' => 'required|exists:subjects,id',
            'results.*.marks_obtained' => 'required|numeric|min:0',
            'results.*.total_marks' => 'required|numeric|min:1',
            'results.*.grade' => 'nullable|string|max:5',
            'results.*.remarks' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $results = $this->bulkService->bulkUpdateResults($request->results);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk result update completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk result update failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk result update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk send notifications
     */
    public function bulkSendNotifications(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notifications' => 'required|array|min:1|max:1000',
            'notifications.*.title' => 'required|string|max:255',
            'notifications.*.message' => 'required|string|max:1000',
            'notifications.*.type' => 'required|in:info,warning,error,success',
            'notifications.*.recipients' => 'required|array|min:1',
            'notifications.*.recipients.*.user_id' => 'required|exists:users,id',
            'notifications.*.channels' => 'required|array',
            'notifications.*.channels.*' => 'in:database,email,sms,push',
            'notifications.*.scheduled_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $results = $this->bulkService->bulkSendNotifications($request->notifications);

            return response()->json([
                'success' => true,
                'message' => 'Bulk notifications sent',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk notification sending failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk notification sending failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk import from CSV
     */
    public function bulkImportFromCSV(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
            'type' => 'required|in:students,teachers,classes,subjects,exams,assignments,fees',
            'mapping' => 'required|array',
            'skip_header' => 'boolean',
            'validate_data' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $results = $this->bulkService->bulkImportFromCSV(
                $request->file('file'),
                $request->type,
                $request->mapping,
                $request->skip_header ?? true,
                $request->validate_data ?? true
            );

            return response()->json([
                'success' => true,
                'message' => 'Bulk import completed',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk CSV import failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk import failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get bulk operation status
     */
    public function getBulkOperationStatus(Request $request, string $operationId): JsonResponse
    {
        try {
            $status = $this->bulkService->getOperationStatus($operationId);

            return response()->json([
                'success' => true,
                'data' => $status
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get operation status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel bulk operation
     */
    public function cancelBulkOperation(Request $request, string $operationId): JsonResponse
    {
        try {
            $result = $this->bulkService->cancelOperation($operationId);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Operation cancelled successfully' : 'Operation could not be cancelled'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel operation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create staff
     */
    public function bulkCreateStaff(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'staff' => 'required|array|min:1|max:500',
            'staff.*.first_name' => 'required|string|max:255',
            'staff.*.last_name' => 'required|string|max:255',
            'staff.*.middle_name' => 'nullable|string|max:255',
            'staff.*.department_id' => 'required|exists:departments,id',
            'staff.*.position' => 'required|string|max:255',
            'staff.*.date_of_birth' => 'required|date',
            'staff.*.gender' => 'required|in:male,female,other',
            'staff.*.phone' => 'nullable|string|max:20',
            'staff.*.address' => 'nullable|string|max:500',
            'staff.*.qualification' => 'nullable|string|max:255',
            'staff.*.hire_date' => 'required|date',
            'staff.*.salary' => 'nullable|numeric|min:0',
            'staff.*.employment_type' => 'nullable|in:full_time,part_time,contract,intern',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'success' => false,
                    'message' => 'School not found in tenant context'
                ], 400);
            }

            $created = [];
            $failed = [];

            foreach ($request->staff as $index => $staffData) {
                try {
                    // Auto-generate credentials
                    $employeeId = $this->generateEmployeeId($schoolId);
                    $email = $this->generateStaffEmail(
                        $staffData['first_name'],
                        $staffData['last_name'],
                        $schoolId,
                        null
                    );
                    $username = $this->generateUsername($staffData['first_name'], $staffData['last_name']);

                    // Create user first
                    $user = User::create([
                        'name' => trim($staffData['first_name'] . ' ' . ($staffData['middle_name'] ?? '') . ' ' . $staffData['last_name']),
                        'email' => $email,
                        'password' => bcrypt('Password@123'),
                        'role' => 'staff',
                        'status' => 'active',
                        'email_verified_at' => now(),
                    ]);

                    // Create staff
                    $staff = Staff::create(array_merge($staffData, [
                        'school_id' => $schoolId,
                        'user_id' => $user->id,
                        'employee_id' => $employeeId,
                        'email' => $email,
                        'username' => $username,
                        'status' => 'active',
                    ]));

                    $created[] = [
                        'staff' => $staff->load(['user', 'department']),
                        'login_credentials' => [
                            'email' => $email,
                            'username' => $username,
                            'password' => 'Password@123',
                        ]
                    ];

                } catch (\Exception $e) {
                    $failed[] = [
                        'index' => $index,
                        'data' => $staffData,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk staff creation completed',
                'summary' => [
                    'total' => count($request->staff),
                    'created' => count($created),
                    'failed' => count($failed),
                ],
                'data' => [
                    'created' => $created,
                    'failed' => $failed,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk staff creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk staff creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create guardians/parents
     */
    public function bulkCreateGuardians(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'guardians' => 'required|array|min:1|max:500',
            'guardians.*.first_name' => 'required|string|max:255',
            'guardians.*.last_name' => 'required|string|max:255',
            'guardians.*.phone' => 'required|string|max:20',
            'guardians.*.occupation' => 'nullable|string|max:255',
            'guardians.*.address' => 'nullable|string|max:500',
            'guardians.*.students' => 'nullable|array',
            'guardians.*.students.*.student_id' => 'required|exists:students,id',
            'guardians.*.students.*.relationship' => 'required|string|max:255',
            'guardians.*.students.*.is_primary' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'success' => false,
                    'message' => 'School not found in tenant context'
                ], 400);
            }

            $created = [];
            $failed = [];

            foreach ($request->guardians as $index => $guardianData) {
                try {
                    // Auto-generate credentials
                    $email = $this->generateGuardianEmail(
                        $guardianData['first_name'],
                        $guardianData['last_name'],
                        $schoolId,
                        null
                    );
                    $username = $this->generateUsername($guardianData['first_name'], $guardianData['last_name']);

                    // Create user first
                    $user = User::create([
                        'name' => $guardianData['first_name'] . ' ' . $guardianData['last_name'],
                        'email' => $email,
                        'password' => bcrypt('Password@123'),
                        'role' => 'guardian',
                        'status' => 'active',
                        'email_verified_at' => now(),
                    ]);

                    // Create guardian
                    $guardian = Guardian::create([
                        'school_id' => $schoolId,
                        'user_id' => $user->id,
                        'first_name' => $guardianData['first_name'],
                        'last_name' => $guardianData['last_name'],
                        'email' => $email,
                        'username' => $username,
                        'phone' => $guardianData['phone'],
                        'occupation' => $guardianData['occupation'] ?? null,
                        'address' => $guardianData['address'] ?? null,
                        'status' => 'active',
                    ]);

                    // Attach students if provided
                    if (isset($guardianData['students']) && is_array($guardianData['students'])) {
                        foreach ($guardianData['students'] as $studentData) {
                            $guardian->students()->attach($studentData['student_id'], [
                                'relationship' => $studentData['relationship'],
                                'is_primary' => $studentData['is_primary'] ?? false,
                                'emergency_contact' => true,
                            ]);
                        }
                    }

                    $created[] = [
                        'guardian' => $guardian->load(['user', 'students']),
                        'login_credentials' => [
                            'email' => $email,
                            'username' => $username,
                            'password' => 'Password@123',
                        ]
                    ];

                } catch (\Exception $e) {
                    $failed[] = [
                        'index' => $index,
                        'data' => $guardianData,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk guardian creation completed',
                'summary' => [
                    'total' => count($request->guardians),
                    'created' => count($created),
                    'failed' => count($failed),
                ],
                'data' => [
                    'created' => $created,
                    'failed' => $failed,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk guardian creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk guardian creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create questions for question bank
     */
    public function bulkCreateQuestions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'questions' => 'required|array|min:1|max:1000',
            'questions.*.subject_id' => 'required|exists:subjects,id',
            'questions.*.class_id' => 'required|exists:classes,id',
            'questions.*.term_id' => 'required|exists:terms,id',
            'questions.*.academic_year_id' => 'required|exists:academic_years,id',
            'questions.*.question_type' => 'required|in:multiple_choice,true_false,short_answer,essay,fill_in_blank,matching,ordering',
            'questions.*.question' => 'required|string',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answer' => 'required',
            'questions.*.explanation' => 'nullable|string',
            'questions.*.difficulty' => 'nullable|in:easy,medium,hard',
            'questions.*.marks' => 'nullable|integer|min:1',
            'questions.*.tags' => 'nullable|array',
            'questions.*.topic' => 'nullable|string|max:255',
            'questions.*.hints' => 'nullable|string',
            'questions.*.attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'success' => false,
                    'message' => 'School not found in tenant context'
                ], 400);
            }

            $created = [];
            $failed = [];

            foreach ($request->questions as $index => $questionData) {
                try {
                    $question = QuestionBank::create(array_merge($questionData, [
                        'school_id' => $schoolId,
                        'created_by' => auth()->id(),
                        'status' => 'active',
                        'usage_count' => 0,
                        'difficulty' => $questionData['difficulty'] ?? 'medium',
                        'marks' => $questionData['marks'] ?? 1,
                    ]));

                    $created[] = $question;

                } catch (\Exception $e) {
                    $failed[] = [
                        'index' => $index,
                        'data' => $questionData,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk question creation completed',
                'summary' => [
                    'total' => count($request->questions),
                    'created' => count($created),
                    'failed' => count($failed),
                ],
                'data' => [
                    'created' => $created,
                    'failed' => $failed,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk question creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Bulk question creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Helper methods for credential generation
    private function generateEmployeeId(int $schoolId): string
    {
        $school = School::find($schoolId);
        $prefix = $school ? strtoupper(substr($school->name, 0, 3)) : 'SCH';
        $lastStaff = Staff::where('school_id', $schoolId)
            ->orderBy('id', 'desc')
            ->first();
        $number = $lastStaff ? (intval(substr($lastStaff->employee_id, -4)) + 1) : 1;
        return $prefix . 'STF' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    private function generateStaffEmail(string $firstName, string $lastName, int $schoolId, ?int $staffId): string
    {
        $school = School::find($schoolId);
        if (!$school) {
            throw new \Exception('School not found');
        }

        $domain = 'samschool.com';
        if ($school->website) {
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website);
            $domain = rtrim($domain, '/');
        } elseif ($school->tenant) {
            $domain = $school->tenant->subdomain . '.samschool.com';
        }

        $baseEmail = strtolower($firstName . '.' . $lastName);
        $email = $baseEmail . ($staffId ? $staffId : '') . '@' . $domain;

        return $email;
    }

    private function generateGuardianEmail(string $firstName, string $lastName, int $schoolId, ?int $guardianId): string
    {
        $school = School::find($schoolId);
        if (!$school) {
            throw new \Exception('School not found');
        }

        $domain = 'samschool.com';
        if ($school->website) {
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website);
            $domain = rtrim($domain, '/');
        } elseif ($school->tenant) {
            $domain = $school->tenant->subdomain . '.samschool.com';
        }

        $baseEmail = strtolower($firstName . '.' . $lastName);
        $email = $baseEmail . ($guardianId ? $guardianId : '') . '@' . $domain;

        return $email;
    }

    private function generateUsername(string $firstName, string $lastName): string
    {
        return strtolower($firstName . '.' . $lastName . rand(100, 999));
    }
}
