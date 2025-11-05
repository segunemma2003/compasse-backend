<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\TermController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\CBTController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\LivestreamController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SMSController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\TransportRouteController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\SecurePickupController;
use App\Http\Controllers\HostelRoomController;
use App\Http\Controllers\HostelAllocationController;
use App\Http\Controllers\HostelMaintenanceController;
use App\Http\Controllers\HealthRecordController;
use App\Http\Controllers\HealthAppointmentController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InventoryCategoryController;
use App\Http\Controllers\InventoryTransactionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\BulkController;
use App\Http\Controllers\QuestionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no tenant required)
Route::prefix('v1')->group(function () {
    // Public school lookup by subdomain
    Route::get('schools/subdomain/{subdomain}', [SchoolController::class, 'getBySubdomain']);

    // Tenant management (super admin only)
    Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        Route::apiResource('tenants', TenantController::class);
        Route::get('tenants/{tenant}/stats', [TenantController::class, 'stats']);
    });

    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');
        Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    });

    // Tenant-specific routes (require tenant middleware)
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

        // School management (always available)
        Route::apiResource('schools', SchoolController::class)->only(['show', 'update']);
        Route::get('schools/{school}/stats', [SchoolController::class, 'stats']);
        Route::get('schools/{school}/dashboard', [SchoolController::class, 'dashboard']);
        Route::get('schools/{school}/organogram', [SchoolController::class, 'organogram']);

        // Subscription management
        Route::prefix('subscriptions')->group(function () {
            Route::get('plans', [SubscriptionController::class, 'getPlans']);
            Route::get('modules', [SubscriptionController::class, 'getModules']);
            Route::get('status', [SubscriptionController::class, 'getSubscriptionStatus']);
            Route::post('create', [SubscriptionController::class, 'createSubscription']);
            Route::put('{subscription}/upgrade', [SubscriptionController::class, 'upgradeSubscription']);
            Route::delete('{subscription}/cancel', [SubscriptionController::class, 'cancelSubscription']);
            Route::get('modules/{module}/access', [SubscriptionController::class, 'checkModuleAccess']);
            Route::get('features/{feature}/access', [SubscriptionController::class, 'checkFeatureAccess']);
            Route::get('school/modules', [SubscriptionController::class, 'getSchoolModules']);
            Route::get('school/limits', [SubscriptionController::class, 'getSchoolLimits']);
        });

        // File upload routes
        Route::prefix('uploads')->group(function () {
            Route::get('presigned-urls', [FileUploadController::class, 'getPresignedUrls']);
            Route::post('upload', [FileUploadController::class, 'uploadFile']);
            Route::delete('{key}', [FileUploadController::class, 'deleteFile']);
        });

        // Academic Management Module
        Route::middleware(['module:academic_management'])->group(function () {
            Route::apiResource('academic-years', AcademicYearController::class);
            Route::apiResource('terms', TermController::class);
            Route::apiResource('departments', DepartmentController::class);
            Route::apiResource('classes', ClassController::class);
            Route::apiResource('subjects', SubjectController::class);
        });

        // Student Management Module
        Route::middleware(['module:student_management'])->group(function () {
            Route::apiResource('students', StudentController::class);
            Route::prefix('students')->group(function () {
                Route::get('{student}/attendance', [StudentController::class, 'attendance'])->middleware('permission:attendance.read');
                Route::get('{student}/results', [StudentController::class, 'results'])->middleware('permission:result.read');
                Route::get('{student}/assignments', [StudentController::class, 'assignments'])->middleware('permission:assignment.read');
                Route::get('{student}/subjects', [StudentController::class, 'subjects'])->middleware('permission:subject.read');

                // Student credential generation
                Route::post('generate-admission-number', [StudentController::class, 'generateAdmissionNumber']);
                Route::post('generate-credentials', [StudentController::class, 'generateCredentials']);
            });
        });

        // Teacher Management Module
        Route::middleware(['module:teacher_management'])->group(function () {
            Route::apiResource('teachers', TeacherController::class);
            Route::prefix('teachers')->group(function () {
                Route::get('{teacher}/classes', [TeacherController::class, 'classes']);
                Route::get('{teacher}/subjects', [TeacherController::class, 'subjects']);
                Route::get('{teacher}/students', [TeacherController::class, 'students']);
            });
        });

        // Guardian Management
        Route::apiResource('guardians', GuardianController::class);
        Route::prefix('guardians')->group(function () {
            Route::post('{guardian}/assign-student', [GuardianController::class, 'assignStudent']);
            Route::delete('{guardian}/remove-student', [GuardianController::class, 'removeStudent']);
            Route::get('{guardian}/students', [GuardianController::class, 'getStudents']);
            Route::get('{guardian}/notifications', [GuardianController::class, 'getNotifications']);
            Route::get('{guardian}/messages', [GuardianController::class, 'getMessages']);
            Route::get('{guardian}/payments', [GuardianController::class, 'getPayments']);
        });

                // Assessment Module (CBT, Exams, Results)
                Route::middleware(['module:cbt'])->group(function () {
                    Route::prefix('assessments')->group(function () {
                        Route::apiResource('exams', ExamController::class);
                        Route::apiResource('assignments', AssignmentController::class);
                        Route::apiResource('results', ResultController::class);

                        // CBT Routes
                        Route::prefix('cbt')->group(function () {
                            Route::get('{exam}/questions', [QuestionController::class, 'getCBTQuestions']);
                            Route::post('submit', [QuestionController::class, 'submitCBTAnswers']);
                            Route::get('session/{sessionId}/status', [QuestionController::class, 'getCBTSessionStatus']);
                            Route::get('session/{sessionId}/results', [QuestionController::class, 'getCBTResults']);
                            Route::post('{exam}/questions/create', [QuestionController::class, 'createCBTQuestions']);
                        });

                        // Legacy CBT routes for backward compatibility
                        Route::post('cbt/start', [CBTController::class, 'start']);
                        Route::post('cbt/submit', [CBTController::class, 'submit']);
                        Route::post('cbt/submit-answer', [CBTController::class, 'submitAnswer']);
                        Route::get('cbt/{exam}/questions', [CBTController::class, 'getQuestions']);
                        Route::get('cbt/attempts/{attempt}/status', [CBTController::class, 'getAttemptStatus']);
                    });
                });

        // Livestream Module
        Route::middleware(['module:livestream'])->group(function () {
            Route::prefix('livestreams')->group(function () {
                Route::apiResource('livestreams', LivestreamController::class);
                Route::post('{livestream}/join', [LivestreamController::class, 'join']);
                Route::post('{livestream}/leave', [LivestreamController::class, 'leave']);
                Route::get('{livestream}/attendance', [LivestreamController::class, 'attendance']);
                Route::post('{livestream}/start', [LivestreamController::class, 'start']);
                Route::post('{livestream}/end', [LivestreamController::class, 'end']);
            });
        });

        // Communication Module
        Route::middleware(['module:sms_integration', 'module:email_integration'])->group(function () {
            Route::prefix('communication')->group(function () {
                Route::apiResource('messages', MessageController::class);
                Route::apiResource('notifications', NotificationController::class);
                Route::post('sms/send', [SMSController::class, 'send']);
                Route::post('email/send', [EmailController::class, 'send']);
            });
        });

        // Financial Module
        Route::middleware(['module:fee_management'])->group(function () {
            Route::prefix('financial')->group(function () {
                Route::apiResource('fees', FeeController::class);
                Route::apiResource('payments', PaymentController::class);
                Route::apiResource('expenses', ExpenseController::class);
                Route::apiResource('payroll', PayrollController::class);
            });
        });

        // Administrative Modules
        Route::middleware(['module:attendance_management'])->group(function () {
            Route::prefix('attendance')->group(function () {
                Route::get('students', [AttendanceController::class, 'students']);
                Route::get('teachers', [AttendanceController::class, 'teachers']);
                Route::post('mark', [AttendanceController::class, 'mark']);
                Route::get('reports', [AttendanceController::class, 'reports']);
            });
        });

        Route::middleware(['module:transport_management'])->group(function () {
            Route::prefix('transport')->group(function () {
                Route::apiResource('routes', TransportRouteController::class);
                Route::apiResource('vehicles', VehicleController::class);
                Route::apiResource('drivers', DriverController::class);
                Route::get('pickup/secure', [SecurePickupController::class, 'index']);
            });
        });

        Route::middleware(['module:hostel_management'])->group(function () {
            Route::prefix('hostel')->group(function () {
                Route::apiResource('rooms', HostelRoomController::class);
                Route::apiResource('allocations', HostelAllocationController::class);
                Route::apiResource('maintenance', HostelMaintenanceController::class);
            });
        });

        Route::middleware(['module:health_management'])->group(function () {
            Route::prefix('health')->group(function () {
                Route::apiResource('records', HealthRecordController::class);
                Route::apiResource('appointments', HealthAppointmentController::class);
                Route::apiResource('medications', MedicationController::class);
            });
        });

        Route::middleware(['module:inventory_management'])->group(function () {
            Route::prefix('inventory')->group(function () {
                Route::apiResource('items', InventoryItemController::class);
                Route::apiResource('categories', InventoryCategoryController::class);
                Route::apiResource('transactions', InventoryTransactionController::class);
            });
        });

        Route::middleware(['module:event_management'])->group(function () {
            Route::prefix('events')->group(function () {
                Route::apiResource('events', EventController::class);
                Route::apiResource('calendars', CalendarController::class);
            });
        });

                // Reports routes
                Route::prefix('reports')->group(function () {
                    Route::get('academic', [ReportController::class, 'academic']);
                    Route::get('financial', [ReportController::class, 'financial']);
                    Route::get('attendance', [ReportController::class, 'attendance']);
                    Route::get('performance', [ReportController::class, 'performance']);
                });

                // Result Generation routes
                Route::prefix('results')->group(function () {
                    Route::post('mid-term/generate', [ResultController::class, 'generateMidTermResults']);
                    Route::post('end-term/generate', [ResultController::class, 'generateEndOfTermResults']);
                    Route::post('annual/generate', [ResultController::class, 'generateAnnualResults']);
                    Route::get('student/{studentId}', [ResultController::class, 'getStudentResults']);
                    Route::get('class/{classId}', [ResultController::class, 'getClassResults']);
                    Route::post('publish', [ResultController::class, 'publishResults']);
                    Route::post('unpublish', [ResultController::class, 'unpublishResults']);
                });

                // Bulk Operations routes
                Route::prefix('bulk')->group(function () {
                    // Student bulk operations
                    Route::post('students/register', [BulkController::class, 'bulkRegisterStudents']);
                    Route::post('teachers/register', [BulkController::class, 'bulkRegisterTeachers']);
                    Route::post('classes/create', [BulkController::class, 'bulkCreateClasses']);
                    Route::post('subjects/create', [BulkController::class, 'bulkCreateSubjects']);
                    Route::post('exams/create', [BulkController::class, 'bulkCreateExams']);
                    Route::post('assignments/create', [BulkController::class, 'bulkCreateAssignments']);
                    Route::post('fees/create', [BulkController::class, 'bulkCreateFees']);
                    Route::post('attendance/mark', [BulkController::class, 'bulkMarkAttendance']);
                    Route::post('results/update', [BulkController::class, 'bulkUpdateResults']);
                    Route::post('notifications/send', [BulkController::class, 'bulkSendNotifications']);
                    Route::post('import/csv', [BulkController::class, 'bulkImportFromCSV']);

                    // Bulk operation management
                    Route::get('operations/{operationId}/status', [BulkController::class, 'getBulkOperationStatus']);
                    Route::delete('operations/{operationId}/cancel', [BulkController::class, 'cancelBulkOperation']);
                });
    });
});

// Health check route
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'version' => '1.0.0'
    ]);
});
