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
}
