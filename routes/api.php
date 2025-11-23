<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
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
use App\Http\Controllers\UserController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\HouseController;
use App\Http\Controllers\SportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\AchievementController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\DashboardController;

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

// Health check route (accessible at /api/health)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'version' => '1.0.0'
    ]);
});

// Database connection diagnostic route
Route::get('/health/db', function () {
    try {
        $config = config('database.connections.mysql');
        $default = config('database.default');

        // Try to get connection info without actually connecting
        $dbInfo = [
            'default_connection' => $default,
            'mysql_config' => [
                'host' => $config['host'] ?? 'not set',
                'port' => $config['port'] ?? 'not set',
                'database' => $config['database'] ?? 'not set',
                'username' => $config['username'] ?? 'not set',
                'unix_socket' => $config['unix_socket'] ?? 'not set',
                'has_password' => !empty($config['password']),
            ],
            'env_vars' => [
                'DB_CONNECTION' => env('DB_CONNECTION', 'not set'),
                'DB_HOST' => env('DB_HOST', 'not set'),
                'DB_PORT' => env('DB_PORT', 'not set'),
                'DB_DATABASE' => env('DB_DATABASE', 'not set'),
                'DB_USERNAME' => env('DB_USERNAME', 'not set'),
                'DB_SOCKET' => env('DB_SOCKET', 'not set'),
                'has_DB_PASSWORD' => !empty(env('DB_PASSWORD')),
            ],
        ];

        // Try to actually connect
        try {
            $pdo = DB::connection('mysql')->getPdo();
            $dbInfo['connection_status'] = 'success';
            $dbInfo['connection_method'] = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            $dbInfo['server_version'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Exception $e) {
            $dbInfo['connection_status'] = 'failed';
            $dbInfo['connection_error'] = $e->getMessage();
            $dbInfo['error_code'] = $e->getCode();
        }

        return response()->json($dbInfo);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Diagnostic failed',
            'message' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : 'hidden'
        ], 500);
    }
});

// Public routes (no tenant required)
Route::prefix('v1')->group(function () {
    // Public school lookup by subdomain/tenant name (no auth required)
    Route::get('schools/by-subdomain/{subdomain}', [SchoolController::class, 'getByUrlSubdomain']);
    Route::get('schools/by-subdomain', [SchoolController::class, 'getByUrlSubdomain']);
    Route::get('schools/subdomain/{subdomain}', [SchoolController::class, 'getBySubdomain']);

    // Public tenant verification (no auth required)
    Route::post('tenants/verify', [TenantController::class, 'verify']);
    Route::get('tenants/verify', [TenantController::class, 'verify']);

    // Tenant management (super admin only)
    Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        Route::apiResource('tenants', TenantController::class);
        Route::get('tenants/{tenant}/stats', [TenantController::class, 'stats']);
    });

    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('logout', [AuthController::class, 'logout'])->middleware(['tenant', 'auth:sanctum']);
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware(['tenant', 'auth:sanctum']);
        Route::post('refresh-token', [AuthController::class, 'refresh'])->middleware(['tenant', 'auth:sanctum']);
        Route::get('me', [AuthController::class, 'me'])->middleware(['tenant', 'auth:sanctum']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // Tenant-specific routes (require tenant middleware)
    // Note: tenant middleware runs first to switch to tenant database before Sanctum authenticates
    // This ensures Sanctum tokens are looked up in the correct database
    Route::middleware(['tenant', 'auth:sanctum'])->group(function () {

        // School management (always available)
        Route::apiResource('schools', SchoolController::class)->only(['store', 'show', 'update']);
        Route::get('schools', [SchoolController::class, 'index']);
        Route::delete('schools/{school}', [SchoolController::class, 'destroy']);
        Route::get('schools/{school}/stats', [SchoolController::class, 'stats']);
        Route::get('schools/{school}/dashboard', [SchoolController::class, 'dashboard']);
        Route::get('schools/{school}/organogram', [SchoolController::class, 'organogram']);

        // User Management
        Route::apiResource('users', UserController::class);
        Route::post('users/{user}/activate', [UserController::class, 'activate']);
        Route::post('users/{user}/suspend', [UserController::class, 'suspend']);

        // Subscription management
        Route::prefix('subscriptions')->group(function () {
            Route::get('/', [SubscriptionController::class, 'index']);
            Route::get('plans', [SubscriptionController::class, 'getPlans']);
            Route::get('modules', [SubscriptionController::class, 'getModules']);
            Route::get('status', [SubscriptionController::class, 'getSubscriptionStatus']);
            Route::get('{id}', [SubscriptionController::class, 'show']);
            Route::post('create', [SubscriptionController::class, 'createSubscription']);
            Route::put('{subscription}/upgrade', [SubscriptionController::class, 'upgradeSubscription']);
            Route::post('{subscription}/renew', [SubscriptionController::class, 'renewSubscription']);
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
            Route::post('upload/multiple', [FileUploadController::class, 'uploadMultipleFiles']);
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
                Route::get('assignments/{assignment}/submissions', [AssignmentController::class, 'getSubmissions']);
                Route::post('assignments/{assignment}/submit', [AssignmentController::class, 'submit']);
                Route::put('assignments/{assignment}/grade', [AssignmentController::class, 'grade']);
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

                // Quiz System (separate from CBT)
                Route::apiResource('quizzes', QuizController::class);
                Route::prefix('quizzes')->group(function () {
                    Route::get('{quiz}/questions', [QuizController::class, 'getQuestions']);
                    Route::post('{quiz}/questions', [QuizController::class, 'addQuestion']);
                    Route::get('{quiz}/attempts', [QuizController::class, 'getAttempts']);
                    Route::post('{quiz}/attempt', [QuizController::class, 'startAttempt']);
                    Route::post('{quiz}/submit', [QuizController::class, 'submit']);
                    Route::get('{quiz}/results', [QuizController::class, 'getResults']);
                });

                // Grades System
                Route::apiResource('grades', GradeController::class);
                Route::prefix('grades')->group(function () {
                    Route::get('student/{student_id}', [GradeController::class, 'getStudentGrades']);
                    Route::get('class/{class_id}', [GradeController::class, 'getClassGrades']);
                });

                // Timetable Management
                Route::apiResource('timetable', TimetableController::class);
                Route::prefix('timetable')->group(function () {
                    Route::get('class/{class_id}', [TimetableController::class, 'getClassTimetable']);
                    Route::get('teacher/{teacher_id}', [TimetableController::class, 'getTeacherTimetable']);
                });

                // Announcements
                Route::apiResource('announcements', AnnouncementController::class);
                Route::post('announcements/{announcement}/publish', [AnnouncementController::class, 'publish']);

                // Library Management
                Route::prefix('library')->group(function () {
                    Route::get('books', [LibraryController::class, 'getBooks']);
                    Route::get('books/{id}', [LibraryController::class, 'getBook']);
                    Route::post('books', [LibraryController::class, 'addBook']);
                    Route::put('books/{id}', [LibraryController::class, 'updateBook']);
                    Route::delete('books/{id}', [LibraryController::class, 'deleteBook']);
                    Route::get('borrowed', [LibraryController::class, 'getBorrowed']);
                    Route::post('borrow', [LibraryController::class, 'borrow']);
                    Route::post('return', [LibraryController::class, 'returnBook']);
                    Route::get('digital-resources', [LibraryController::class, 'getDigitalResources']);
                    Route::post('digital-resources', [LibraryController::class, 'addDigitalResource']);
                    Route::get('members', [LibraryController::class, 'getMembers']);
                    Route::get('stats', [LibraryController::class, 'getStats']);
                });

                // Houses System
                Route::apiResource('houses', HouseController::class);
                Route::prefix('houses')->group(function () {
                    Route::get('{house}/members', [HouseController::class, 'getMembers']);
                    Route::post('{house}/points', [HouseController::class, 'addPoints']);
                    Route::get('{house}/points', [HouseController::class, 'getPoints']);
                    Route::get('competitions', [HouseController::class, 'getCompetitions']);
                });

                // Sports Management
                Route::prefix('sports')->group(function () {
                    Route::get('activities', [SportController::class, 'getActivities']);
                    Route::post('activities', [SportController::class, 'createActivity']);
                    Route::put('activities/{id}', [SportController::class, 'updateActivity']);
                    Route::delete('activities/{id}', [SportController::class, 'deleteActivity']);
                    Route::get('teams', [SportController::class, 'getTeams']);
                    Route::post('teams', [SportController::class, 'createTeam']);
                    Route::get('events', [SportController::class, 'getEvents']);
                    Route::post('events', [SportController::class, 'createEvent']);
                });

                // Staff Management
                Route::apiResource('staff', StaffController::class);

                // Achievements
                Route::apiResource('achievements', AchievementController::class);
                Route::get('achievements/student/{student_id}', [AchievementController::class, 'getStudentAchievements']);

                // Settings
                Route::get('settings', [SettingController::class, 'index']);
                Route::put('settings', [SettingController::class, 'update']);
                Route::get('settings/school', [SettingController::class, 'getSchoolSettings']);
                Route::put('settings/school', [SettingController::class, 'updateSchoolSettings']);

                // Role-specific Dashboards
                Route::prefix('dashboard')->group(function () {
                    Route::get('admin', [DashboardController::class, 'admin']);
                    Route::get('teacher', [DashboardController::class, 'teacher']);
                    Route::get('student', [DashboardController::class, 'student']);
                    Route::get('parent', [DashboardController::class, 'parent']);
                    Route::get('super-admin', [DashboardController::class, 'superAdmin']);
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
                Route::put('messages/{id}/read', [MessageController::class, 'markAsRead']);
                Route::apiResource('notifications', NotificationController::class);
                Route::put('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
                Route::put('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
                Route::post('sms/send', [SMSController::class, 'send']);
                Route::post('email/send', [EmailController::class, 'send']);
            });
        });

        // Financial Module
        Route::middleware(['module:fee_management'])->group(function () {
            Route::prefix('financial')->group(function () {
                Route::apiResource('fees', FeeController::class);
                Route::post('fees/{fee}/pay', [FeeController::class, 'pay']);
                Route::get('fees/student/{student_id}', [FeeController::class, 'getStudentFees']);
                Route::get('fees/structure', [FeeController::class, 'getFeeStructure']);
                Route::post('fees/structure', [FeeController::class, 'createFeeStructure']);
                Route::put('fees/structure/{id}', [FeeController::class, 'updateFeeStructure']);
                Route::apiResource('payments', PaymentController::class);
                Route::get('payments/student/{student_id}', [PaymentController::class, 'getStudentPayments']);
                Route::get('payments/receipt/{id}', [PaymentController::class, 'getReceipt']);
                Route::apiResource('expenses', ExpenseController::class);
                Route::apiResource('payroll', PayrollController::class);
            });
        });

        // Administrative Modules
        Route::middleware(['module:attendance_management'])->group(function () {
            Route::prefix('attendance')->group(function () {
                Route::get('/', [AttendanceController::class, 'index']);
                Route::get('reports', [AttendanceController::class, 'reports']);
                Route::get('students', [AttendanceController::class, 'students']);
                Route::get('teachers', [AttendanceController::class, 'teachers']);
                Route::get('class/{class_id}', [AttendanceController::class, 'getClassAttendance']);
                Route::get('student/{student_id}', [AttendanceController::class, 'getStudentAttendance']);
                Route::post('mark', [AttendanceController::class, 'mark']);
                Route::put('{id}', [AttendanceController::class, 'update']);
                Route::delete('{id}', [AttendanceController::class, 'destroy']);
                Route::get('{id}', [AttendanceController::class, 'show']);
            });
        });

        Route::middleware(['module:transport_management'])->group(function () {
            Route::prefix('transport')->group(function () {
                Route::apiResource('routes', TransportRouteController::class);
                Route::apiResource('vehicles', VehicleController::class);
                Route::apiResource('drivers', DriverController::class);
                Route::get('students', [TransportRouteController::class, 'getStudents']);
                Route::post('assign', [TransportRouteController::class, 'assignStudent']);
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
                Route::post('checkout', [InventoryTransactionController::class, 'checkout']);
                Route::post('return', [InventoryTransactionController::class, 'return']);
            });
        });

        Route::middleware(['module:event_management'])->group(function () {
            Route::prefix('events')->group(function () {
                Route::apiResource('events', EventController::class);
                Route::get('upcoming', [EventController::class, 'getUpcoming']);
                Route::apiResource('calendars', CalendarController::class);
            });
        });

                // Reports routes
                Route::prefix('reports')->group(function () {
                    Route::get('academic', [ReportController::class, 'academic']);
                    Route::get('financial', [ReportController::class, 'financial']);
                    Route::get('attendance', [ReportController::class, 'attendance']);
                    Route::get('performance', [ReportController::class, 'performance']);
                    Route::get('{type}/export', [ReportController::class, 'export']);
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

// Super Admin Analytics (outside tenant middleware, inside v1 prefix)
Route::prefix('v1')->middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::prefix('super-admin')->group(function () {
        Route::get('analytics', [DashboardController::class, 'superAdmin']);
        Route::get('database', function () {
            return response()->json([
                'status' => 'healthy',
                'connections' => [
                    'main' => config('database.connections.mysql.database'),
                    'tenants' => \App\Models\Tenant::count()
                ]
            ]);
        });
        Route::get('security', function () {
            return response()->json([
                'security_logs' => [],
                'active_sessions' => 0,
                'failed_logins' => 0
            ]);
        });
    });
});
