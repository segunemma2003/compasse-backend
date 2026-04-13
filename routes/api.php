<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DropdownController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\ClassLevelController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\ArmController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\TermController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\JobMonitorController;
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
use App\Http\Controllers\QuestionBankController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\HouseController;
use App\Http\Controllers\SportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\AchievementController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\GradingSystemController;
use App\Http\Controllers\ContinuousAssessmentController;
use App\Http\Controllers\PsychomotorAssessmentController;
use App\Http\Controllers\ScoreboardController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\AnalyticsController;

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

// Health check routes (accessible at /api/health and /api/v1/health)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'version' => '1.0.0'
    ]);
});
Route::prefix('v1')->get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'version' => '1.0.0'
    ]);
}); 
Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => 'ok', 'timestamp' => now(), 'version' => '1.0.0']);
    });
});

// Database diagnostic route — super admin only, never public
Route::get('/health/db', function () {
    try {
        $pdo = DB::connection('mysql')->getPdo();
        return response()->json([
            'connection_status' => 'success',
            'server_version'    => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'connection_status' => 'failed',
            'error_code'        => $e->getCode(),
        ], 500);
    }
})->middleware(['auth:sanctum', 'role:super_admin']);

// Public routes (no tenant required)
Route::prefix('v1')->group(function () {
    // Public school lookup by subdomain/tenant name (no auth required)
    Route::get('schools/by-subdomain/{subdomain}', [SchoolController::class, 'getByUrlSubdomain']);
    Route::get('schools/by-subdomain', [SchoolController::class, 'getByUrlSubdomain']);
    Route::get('schools/subdomain/{subdomain}', [SchoolController::class, 'getBySubdomain']);

    // Public landing page endpoints (no auth)
    Route::get('schools/landing-page/templates', [LandingPageController::class, 'getTemplates']);
    Route::get('public/{subdomain}', [LandingPageController::class, 'publicLandingPage']);

    // Public tenant verification (no auth required)
    Route::post('tenants/verify', [TenantController::class, 'verify']);
    Route::get('tenants/verify', [TenantController::class, 'verify']);

    // Super Admin only routes (no tenant required)
    Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        // Tenant management
        Route::apiResource('tenants', TenantController::class);
        Route::get('tenants/{tenant}/stats',            [TenantController::class, 'stats']);
        Route::get('tenants/{tenant}/provision-status', [TenantController::class, 'provisionStatus']);
        Route::post('tenants/{tenant}/reprovision',     [TenantController::class, 'reprovision']);
        Route::post('tenants/{tenant}/run-migrations',  [TenantController::class, 'runMigrations']);
        Route::post('tenants/{tenant}/sync-school',      [TenantController::class, 'syncSchool']);
        Route::post('tenants/{tenant}/seed-school',      [TenantController::class, 'seedSchool']);
        Route::post('tenants/{tenant}/resend-welcome',   [TenantController::class, 'resendWelcome']);
        Route::post('tenants/{tenant}/send-mail',        [TenantController::class, 'sendMail']);

        // School management (create, delete, list all) - SuperAdmin specific paths
        Route::post('schools', [SchoolController::class, 'store']);
        Route::get('schools', [SchoolController::class, 'index']);
        Route::get('admin/schools/{school}', [SchoolController::class, 'show']); // SuperAdmin: GET school details
        Route::put('admin/schools/{school}', [SchoolController::class, 'update']); // SuperAdmin: UPDATE school
        Route::get('admin/schools/{school}/stats', [SchoolController::class, 'stats']); // SuperAdmin: GET school stats
        Route::get('admin/schools/{school}/dashboard', [SchoolController::class, 'dashboard']); // SuperAdmin: GET school dashboard
        Route::delete('schools/{school}', [SchoolController::class, 'destroy']);

        // School management actions (SuperAdmin specific)
        Route::post('admin/schools/{school}/suspend', [SchoolController::class, 'suspend']);
        Route::post('admin/schools/{school}/activate', [SchoolController::class, 'activate']);
        Route::post('admin/schools/{school}/send-email', [SchoolController::class, 'sendEmail']);
        Route::post('admin/schools/{school}/reset-admin-password', [SchoolController::class, 'resetAdminPassword']);
        Route::get('admin/schools/{school}/users-count', [SchoolController::class, 'usersCount']);
        Route::get('admin/schools/{school}/activity-logs', [SchoolController::class, 'activityLogs']);
        Route::get('admin/schools/{school}/modules', [SchoolController::class, 'getModules']);
        Route::put('admin/schools/{school}/modules', [SchoolController::class, 'updateModules']);

        // Super Admin: manage signatures for any school
        Route::get('admin/schools/{schoolId}/signatures',                    [\App\Http\Controllers\SchoolSignatureController::class, 'index']);
        Route::post('admin/schools/{schoolId}/signatures',                   [\App\Http\Controllers\SchoolSignatureController::class, 'upsert']);
        Route::delete('admin/schools/{schoolId}/signatures/{signatureId}',   [\App\Http\Controllers\SchoolSignatureController::class, 'delete']);

        // Super Admin Dashboard
        Route::get('dashboard/super-admin', [DashboardController::class, 'superAdmin']);

        // Super Admin Subscription Management
        Route::get('admin/subscriptions', [SubscriptionController::class, 'adminIndex']);
        Route::post('admin/schools/{school}/subscriptions', [SubscriptionController::class, 'adminCreate']);
        Route::put('admin/subscriptions/{subscription}', [SubscriptionController::class, 'adminUpdate']);
        Route::post('admin/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'adminCancel']);
        Route::post('admin/subscriptions/{subscription}/extend', [SubscriptionController::class, 'adminExtend']);
        Route::get('admin/plans', [SubscriptionController::class, 'getPlans']);

        // Email logs
        Route::get('admin/email-logs', [EmailLogController::class, 'index']);

        // Job / queue monitoring
        Route::get('admin/jobs/stats',                [JobMonitorController::class, 'stats']);
        Route::get('admin/jobs/pending',              [JobMonitorController::class, 'pendingJobs']);
        Route::get('admin/jobs/completed',            [JobMonitorController::class, 'completedJobs']);
        Route::get('admin/jobs/failed',               [JobMonitorController::class, 'failedJobs']);
        Route::post('admin/jobs/failed/retry-all',    [JobMonitorController::class, 'retryAll']);
        Route::delete('admin/jobs/failed',            [JobMonitorController::class, 'clearFailed']);
        Route::post('admin/jobs/failed/{uuid}/retry', [JobMonitorController::class, 'retryJob']);
        Route::delete('admin/jobs/failed/{uuid}',     [JobMonitorController::class, 'deleteJob']);
        Route::get('admin/scheduler-logs',            [JobMonitorController::class, 'schedulerLogs']);

        // Expiring subscriptions (active, ending within 7 days)
        Route::get('admin/subscriptions/expiring', function () {
            $subs = \App\Models\Subscription::with(['school', 'plan'])
                ->where('status', 'active')
                ->where('end_date', '>', now())
                ->where('end_date', '<=', now()->addDays(7)->endOfDay())
                ->orderBy('end_date')
                ->get()
                ->map(fn ($s) => [
                    'id'          => $s->id,
                    'school_id'   => $s->school_id,
                    'school_name' => $s->school?->name,
                    'plan'        => $s->plan?->name,
                    'end_date'    => $s->end_date,
                    'days_left'   => (int) now()->diffInDays($s->end_date, false),
                ]);

            return response()->json(['subscriptions' => $subs]);
        });

        // Super Admin Plan CRUD
        Route::get('plans/all', [PlanController::class, 'index']);
        Route::post('plans', [PlanController::class, 'store']);
        Route::get('plans/{plan}', [PlanController::class, 'show']);
        Route::put('plans/{plan}', [PlanController::class, 'update']);
        Route::delete('plans/{plan}', [PlanController::class, 'destroy']);

        // ── Central Question Bank (Super Admin) ──────────────��────────────
        Route::prefix('admin/question-bank')->group(function () {
            // Overview stats
            Route::get('stats', [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'stats']);

            // Subjects
            Route::get('subjects',          [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subjectIndex']);
            Route::post('subjects',         [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subjectStore']);
            Route::put('subjects/{id}',     [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subjectUpdate']);
            Route::delete('subjects/{id}',  [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subjectDestroy']);

            // Questions
            Route::get('questions',             [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'questionIndex']);
            Route::post('questions',            [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'questionStore']);
            Route::post('questions/bulk',       [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'questionBulkStore']);
            Route::get('questions/{id}',        [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'questionShow']);
            Route::put('questions/{id}',        [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'questionUpdate']);
            Route::delete('questions/{id}',     [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'questionDestroy']);

            // School subscriptions to the bank
            Route::get('subscriptions',         [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subscriptionIndex']);
            Route::post('subscriptions',        [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subscriptionStore']);
            Route::put('subscriptions/{id}',    [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subscriptionUpdate']);
            Route::delete('subscriptions/{id}', [\App\Http\Controllers\SuperAdminQuestionBankController::class, 'subscriptionDestroy']);
        });
    });

    // Authentication routes
    Route::prefix('auth')->group(function () {
        // Rate-limited unauthenticated endpoints: max 5 attempts per minute per IP
        Route::middleware(['throttle:5,1'])->group(function () {
            Route::post('login', [AuthController::class, 'login']);
            Route::post('register', [AuthController::class, 'register']);
            Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
            Route::post('reset-password', [AuthController::class, 'resetPassword']);
        });

        Route::post('logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum']);
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware(['auth:sanctum']);
        Route::post('refresh-token', [AuthController::class, 'refresh'])->middleware(['auth:sanctum']);
    });

    // =========================================================================
    // TENANT ROUTES
    // tenant middleware switches the DB connection before Sanctum authenticates,
    // so tokens are always looked up in the right database.
    //
    // Role groups (innermost wins for write routes):
    //   $admin  = school_admin, principal, vice_principal, admin
    //   $staff  = $admin + teacher, class_teacher, subject_teacher, year_tutor, hod
    //   $finance= school_admin, principal, accountant, admin
    // =========================================================================
    Route::middleware(['tenant', 'auth:sanctum'])->group(function () {

        // ── Auth ─────────────────────────────────────────────────────────────
        Route::get('auth/me', [AuthController::class, 'me']);

        // ── Universal (every authenticated tenant user) ───────────────────
        Route::get('roles', [UserController::class, 'getRoles']);
        Route::get('dashboard/stats', [DashboardController::class, 'getStats']);
        Route::get('dashboard', [DashboardController::class, 'admin']);
        Route::prefix('dashboard')->group(function () {
            Route::get('admin',         [DashboardController::class, 'admin']);
            Route::get('teacher',       [DashboardController::class, 'teacher']);
            Route::get('student',       [DashboardController::class, 'student']);
            Route::get('parent',        [DashboardController::class, 'parent']);
            Route::get('finance',       [DashboardController::class, 'finance']);
            Route::get('accountant',    [DashboardController::class, 'accountant']);
            Route::get('librarian',     [DashboardController::class, 'librarian']);
            Route::get('driver',        [DashboardController::class, 'driver']);
            Route::get('principal',     [DashboardController::class, 'principal']);
            Route::get('vice-principal',[DashboardController::class, 'vicePrincipal']);
            Route::get('hod',           [DashboardController::class, 'hod']);
            Route::get('nurse',         [DashboardController::class, 'nurse']);
            Route::get('security',      [DashboardController::class, 'security']);
        });

        // School read (everyone)
        Route::get('schools/me',               [SchoolController::class, 'getMySchool']);

        // Must be registered before schools/{school} or "landing-page" is treated as a route-model key.
        Route::middleware(['role:school_admin,principal,vice_principal,admin'])->group(function () {
            Route::get('schools/landing-page',               [LandingPageController::class, 'show']);
            Route::put('schools/landing-page',               [LandingPageController::class, 'update']);
            Route::post('schools/landing-page/upload-asset', [LandingPageController::class, 'uploadAsset']);

            // ── Digital Signatures ─────────────────────────────────────────────
            Route::get('signatures/active', [\App\Http\Controllers\SignatureController::class, 'active']);
            Route::apiResource('signatures', \App\Http\Controllers\SignatureController::class)
                ->except(['show']);
        });

        Route::get('schools/{school}',          [SchoolController::class, 'show']);
        Route::get('schools/{school}/stats',    [SchoolController::class, 'stats']);
        Route::get('schools/{school}/dashboard',[SchoolController::class, 'dashboard']);
        Route::get('schools/{school}/organogram',[SchoolController::class, 'organogram']);

        // Own profile picture
        Route::post('users/me/profile-picture',   [UserController::class, 'uploadProfilePicture']);
        Route::delete('users/me/profile-picture', [UserController::class, 'deleteProfilePicture']);

        // Subscription read (everyone)
        Route::prefix('subscriptions')->group(function () {
            Route::get('/',                         [SubscriptionController::class, 'index']);
            Route::get('plans',                     [SubscriptionController::class, 'getPlans']);
            Route::get('modules',                   [SubscriptionController::class, 'getModules']);
            Route::get('status',                    [SubscriptionController::class, 'getSubscriptionStatus']);
            Route::get('{id}',                      [SubscriptionController::class, 'show']);
            Route::get('modules/{module}/access',   [SubscriptionController::class, 'checkModuleAccess']);
            Route::get('features/{feature}/access', [SubscriptionController::class, 'checkFeatureAccess']);
            Route::get('school/modules',            [SubscriptionController::class, 'getSchoolModules']);
            Route::get('school/limits',             [SubscriptionController::class, 'getSchoolLimits']);
        });

        // File uploads (everyone)
        Route::prefix('uploads')->group(function () {
            Route::get('presigned-urls',       [FileUploadController::class, 'getPresignedUrls']);
            Route::post('upload',              [FileUploadController::class, 'uploadFile']);
            Route::post('upload/multiple',     [FileUploadController::class, 'uploadMultipleFiles']);
            Route::delete('{key}',             [FileUploadController::class, 'deleteFile']);
        });

        // Communication (everyone)
        Route::prefix('communication')->group(function () {
            Route::apiResource('messages', MessageController::class);
            Route::put('messages/{id}/read',          [MessageController::class, 'markAsRead']);
            Route::apiResource('notifications', NotificationController::class);
            Route::put('notifications/{id}/read',     [NotificationController::class, 'markAsRead']);
            Route::put('notifications/read-all',      [NotificationController::class, 'markAllAsRead']);
        });
        Route::middleware(['module:sms_integration'])->group(function () {
            Route::post('communication/sms/send',  [SMSController::class, 'send']);
            Route::post('communication/sms/bulk',  [SMSController::class, 'bulkSend']);
            Route::get('communication/sms/logs',   [SMSController::class, 'logs']);
        });
        Route::middleware(['module:email_integration'])->group(function () {
            Route::post('communication/email/send',  [EmailController::class, 'send']);
            Route::post('communication/email/bulk',  [EmailController::class, 'bulkSend']);
            Route::get('communication/email/logs',   [EmailController::class, 'logs']);
        });

        // Settings read (everyone)
        Route::get('settings',        [SettingController::class, 'index']);
        Route::get('settings/school', [SettingController::class, 'getSchoolSettings']);

        // Announcements read (everyone)
        Route::get('announcements',               [AnnouncementController::class, 'index']);
        Route::get('announcements/{announcement}', [AnnouncementController::class, 'show']);

        // Timetable read (everyone)
        Route::get('timetable',                      [TimetableController::class, 'index']);
        Route::get('timetable/class/{class_id}',     [TimetableController::class, 'getClassTimetable']);
        Route::get('timetable/teacher/{teacher_id}', [TimetableController::class, 'getTeacherTimetable']);

        // Stories read + react (everyone)
        Route::prefix('stories')->group(function () {
            Route::get('/',                              [StoryController::class, 'index']);
            Route::get('{story}',                        [StoryController::class, 'show']);
            Route::post('{story}/react',                 [StoryController::class, 'react']);
            Route::delete('{story}/unreact',             [StoryController::class, 'unreact']);
            Route::post('{story}/comments',              [StoryController::class, 'comment']);
            Route::delete('{story}/comments/{comment}',  [StoryController::class, 'deleteComment']);
            Route::post('{story}/share',                 [StoryController::class, 'share']);
        });

        // Houses / Sports read (everyone)
        Route::get('houses/competitions',        [HouseController::class, 'getCompetitions']);
        Route::get('houses/{house}/members',     [HouseController::class, 'getMembers']);
        Route::get('houses/{house}/points',      [HouseController::class, 'getPoints']);
        Route::get('houses',                     [HouseController::class, 'index']);
        Route::get('houses/{house}',             [HouseController::class, 'show']);
        Route::get('sports/activities',          [SportController::class, 'getActivities']);
        Route::get('sports/teams',               [SportController::class, 'getTeams']);
        Route::get('sports/events',              [SportController::class, 'getEvents']);

        // Achievements read (everyone)
        Route::get('achievements',                         [AchievementController::class, 'index']);
        Route::get('achievements/{achievement}',           [AchievementController::class, 'show']);
        Route::get('achievements/student/{student_id}',    [AchievementController::class, 'getStudentAchievements']);

        // Library browse + borrow (everyone)
        Route::prefix('library')->group(function () {
            Route::get('books',              [LibraryController::class, 'getBooks']);
            Route::get('books/{id}',         [LibraryController::class, 'getBook']);
            Route::get('digital-resources',  [LibraryController::class, 'getDigitalResources']);
            Route::get('stats',              [LibraryController::class, 'getStats']);
            Route::get('borrowed',           [LibraryController::class, 'getBorrowed']);
            Route::post('borrow',            [LibraryController::class, 'borrow']);
            Route::post('return',            [LibraryController::class, 'returnBook']);
            Route::post('lost',              [LibraryController::class, 'markLost']);
        });

        // Events read (everyone)
        Route::middleware(['module:event_management'])->group(function () {
            Route::get('events/upcoming', [EventController::class, 'getUpcoming']);
            Route::get('events',          [EventController::class, 'index']);
            Route::get('events/{event}',  [EventController::class, 'show']);
            Route::get('calendars',       [CalendarController::class, 'index']);
            Route::get('calendars/{calendar}', [CalendarController::class, 'show']);
        });

        // CBT take exam + quizzes (students + staff)
        Route::middleware(['module:cbt'])->group(function () {
            Route::prefix('assessments')->group(function () {
                Route::prefix('cbt')->group(function () {
                    Route::get('{exam}/questions',               [QuestionController::class, 'getCBTQuestions']);
                    Route::post('submit',                        [QuestionController::class, 'submitCBTAnswers']);
                    Route::get('session/{sessionId}/status',     [QuestionController::class, 'getCBTSessionStatus']);
                    Route::get('session/{sessionId}/results',    [QuestionController::class, 'getCBTResults']);
                });
                Route::post('cbt/start',                         [CBTController::class, 'start']);
                Route::post('cbt/submit',                        [CBTController::class, 'submit']);
                Route::post('cbt/submit-answer',                 [CBTController::class, 'submitAnswer']);
                Route::get('cbt/{exam}/questions',               [CBTController::class, 'getQuestions']);
                Route::get('cbt/attempts/{attempt}/status',      [CBTController::class, 'getAttemptStatus']);
                Route::post('assignments/{assignment}/submit',   [AssignmentController::class, 'submit']);

                // Results read (controller scopes by role)
                Route::prefix('results')->group(function () {
                    Route::get('/student/{studentId}/{termId}/{academicYearId}', [ResultController::class, 'getStudentResult']);
                    Route::get('/student/{studentId}',                           [ResultController::class, 'getStudentResults']);
                });
                Route::prefix('report-cards')->group(function () {
                    Route::get('/{studentId}/{termId}/{academicYearId}',       [ReportCardController::class, 'getReportCard']);
                    Route::get('/{studentId}/{termId}/{academicYearId}/pdf',   [ReportCardController::class, 'generatePDF']);
                    Route::get('/{studentId}/{termId}/{academicYearId}/print', [ReportCardController::class, 'getPrintableReportCard']);
                });
                // Scoreboards read
                Route::prefix('scoreboards')->group(function () {
                    Route::get('/class/{classId}',          [ScoreboardController::class, 'getScoreboard']);
                    Route::get('/top-performers',           [ScoreboardController::class, 'getTopPerformers']);
                    Route::get('/subject/{subjectId}/toppers', [ScoreboardController::class, 'getSubjectToppers']);
                    Route::get('/class-comparison',         [ScoreboardController::class, 'getClassComparison']);
                });
                // Grading system read
                Route::prefix('grading-systems')->group(function () {
                    Route::get('/',               [GradingSystemController::class, 'index']);
                    Route::get('/default',        [GradingSystemController::class, 'getDefault']);
                    Route::post('/calculate-grade',[GradingSystemController::class, 'getGradeForScore']);
                });
            });
        });

        // Quizzes attempt (everyone)
        Route::get('quizzes',              [QuizController::class, 'index']);
        Route::get('quizzes/{quiz}',       [QuizController::class, 'show']);
        Route::get('quizzes/{quiz}/questions', [QuizController::class, 'getQuestions']);
        Route::get('quizzes/{quiz}/attempts',  [QuizController::class, 'getAttempts']);
        Route::post('quizzes/{quiz}/attempt',  [QuizController::class, 'startAttempt']);
        Route::post('quizzes/{quiz}/submit',   [QuizController::class, 'submit']);
        Route::get('quizzes/{quiz}/results',   [QuizController::class, 'getResults']);

        // Grades read (everyone)
        Route::get('grades/student/{student_id}', [GradeController::class, 'getStudentGrades']);
        Route::get('grades/class/{class_id}',     [GradeController::class, 'getClassGrades']);

        // Guardians — parents manage their own records
        Route::middleware(['role:school_admin,principal,vice_principal,admin,parent,guardian'])->group(function () {
            Route::apiResource('guardians', GuardianController::class);
            Route::prefix('guardians')->group(function () {
                Route::post('{guardian}/assign-student',    [GuardianController::class, 'assignStudent']);
                Route::delete('{guardian}/remove-student',  [GuardianController::class, 'removeStudent']);
                Route::get('{guardian}/students',           [GuardianController::class, 'getStudents']);
                Route::get('{guardian}/notifications',      [GuardianController::class, 'getNotifications']);
                Route::get('{guardian}/messages',           [GuardianController::class, 'getMessages']);
                Route::get('{guardian}/payments',           [GuardianController::class, 'getPayments']);
            });
        });

        // Livestream — view/join (everyone); create/manage (staff+)
        Route::middleware(['module:livestream'])->group(function () {
            Route::prefix('livestreams')->group(function () {
                Route::get('/',                          [LivestreamController::class, 'index']);
                Route::get('{livestream}',               [LivestreamController::class, 'show']);
                Route::post('{livestream}/join',         [LivestreamController::class, 'join']);
                Route::post('{livestream}/leave',        [LivestreamController::class, 'leave']);
                Route::get('{livestream}/attendance',    [LivestreamController::class, 'attendance']);
            });
            Route::middleware(['role:school_admin,principal,vice_principal,admin,teacher,class_teacher,subject_teacher,year_tutor,hod'])->group(function () {
                Route::prefix('livestreams')->group(function () {
                    Route::post('/',                     [LivestreamController::class, 'store']);
                    Route::put('{livestream}',           [LivestreamController::class, 'update']);
                    Route::delete('{livestream}',        [LivestreamController::class, 'destroy']);
                    Route::post('{livestream}/start',    [LivestreamController::class, 'start']);
                    Route::post('{livestream}/end',      [LivestreamController::class, 'end']);
                });
            });
        });

        // ── DROPDOWNS ─────────────────────────────────────────────────────
        // Any authenticated tenant user may call this — it powers every
        // create/edit form with a single request instead of ~12 individual ones.
        Route::get('dropdowns', [DropdownController::class, 'all']);

        // ── ACADEMIC STAFF ────────────────────────────────────────────────
        // school_admin, principal, vice_principal, admin,
        // teacher, class_teacher, subject_teacher, year_tutor, hod
        Route::middleware(['role:school_admin,principal,vice_principal,admin,teacher,class_teacher,subject_teacher,year_tutor,hod'])->group(function () {

            // Users read (staff can look up colleagues)
            Route::get('users',       [UserController::class, 'index']);
            Route::get('users/{user}',[UserController::class, 'show']);

            // Academic read
            Route::middleware(['module:academic_management'])->group(function () {
                Route::get('academic-years',                  [AcademicYearController::class, 'index']);
                Route::get('academic-years/{academicYear}',   [AcademicYearController::class, 'show']);
                Route::get('terms',                           [TermController::class, 'index']);
                Route::get('terms/{term}',                    [TermController::class, 'show']);
                Route::get('departments',                     [DepartmentController::class, 'index']);
                Route::get('departments/{department}',        [DepartmentController::class, 'show']);
                Route::get('class-levels',                    [ClassLevelController::class, 'index']);
                Route::get('classes',                         [ClassController::class, 'index']);
                Route::get('classes/{class}',                 [ClassController::class, 'show']);
                Route::get('classes/{class}/students',        [ClassController::class, 'getStudents']);
                Route::get('subjects',                        [SubjectController::class, 'index']);
                Route::get('subjects/{subject}',              [SubjectController::class, 'show']);
                Route::get('arms',                            [ArmController::class, 'index']);
                Route::get('arms/{arm}',                      [ArmController::class, 'show']);
                Route::get('arms/class/{classId}',            [ArmController::class, 'getByClass']);
                Route::get('arms/{armId}/students',           [ArmController::class, 'getStudents']);
            });

            // Students read (row-level scoping enforced in controller)
            Route::middleware(['module:student_management'])->group(function () {
                Route::get('students',                        [StudentController::class, 'index']);
                Route::get('students/{student}',              [StudentController::class, 'show']);
                Route::get('students/{student}/attendance',   [StudentController::class, 'attendance']);
                Route::get('students/{student}/results',      [StudentController::class, 'results']);
                Route::get('students/{student}/assignments',  [StudentController::class, 'assignments']);
                Route::get('students/{student}/subjects',     [StudentController::class, 'subjects']);
            });

            // Teachers read
            Route::middleware(['module:teacher_management'])->group(function () {
                Route::get('teachers',                        [TeacherController::class, 'index']);
                Route::get('teachers/{teacher}',              [TeacherController::class, 'show']);
                Route::get('teachers/{teacher}/classes',      [TeacherController::class, 'classes']);
                Route::get('teachers/{teacher}/subjects',     [TeacherController::class, 'subjects']);
                Route::get('teachers/{teacher}/students',     [TeacherController::class, 'students']);
            });

            // Exams + Assignments CRUD (own), Results generate+view (scoped in controller)
            Route::middleware(['module:cbt'])->group(function () {
                Route::prefix('assessments')->group(function () {
                    Route::apiResource('exams', ExamController::class);
                    Route::apiResource('assignments', AssignmentController::class)->except(['destroy']);
                    Route::get('assignments/{assignment}/submissions', [AssignmentController::class, 'getSubmissions']);
                    Route::put('assignments/{assignment}/grade',       [AssignmentController::class, 'grade']);

                    // CA
                    Route::prefix('continuous-assessments')->group(function () {
                        Route::get('/',                      [ContinuousAssessmentController::class, 'index']);
                        Route::post('/',                     [ContinuousAssessmentController::class, 'store']);
                        Route::put('/{id}',                  [ContinuousAssessmentController::class, 'update']);
                        Route::delete('/{id}',               [ContinuousAssessmentController::class, 'destroy']);
                        Route::post('/{id}/record-scores',   [ContinuousAssessmentController::class, 'recordScores']);
                        Route::get('/{id}/scores',           [ContinuousAssessmentController::class, 'getScores']);
                        Route::get('/student/{studentId}/scores', [ContinuousAssessmentController::class, 'getStudentScores']);
                    });

                    // Psychomotor
                    Route::prefix('psychomotor-assessments')->group(function () {
                        Route::get('/{studentId}/{termId}/{academicYearId}', [PsychomotorAssessmentController::class, 'show']);
                        Route::post('/',                                     [PsychomotorAssessmentController::class, 'store']);
                        Route::post('/bulk',                                 [PsychomotorAssessmentController::class, 'bulkStore']);
                        Route::get('/class/{classId}',                       [PsychomotorAssessmentController::class, 'getByClass']);
                        Route::delete('/{id}',                               [PsychomotorAssessmentController::class, 'destroy']);
                    });

                    // Results generate + view class (scoped in controller)
                    Route::prefix('results')->group(function () {
                        Route::post('/generate',             [ResultController::class, 'generateResults']);
                        Route::get('/class/{classId}',       [ResultController::class, 'getClassResults']);
                        Route::post('/{resultId}/comments',  [ResultController::class, 'addComments']);
                    });
                    Route::apiResource('results', ResultController::class);

                    // CBT question creation
                    Route::post('cbt/{exam}/questions/create', [QuestionController::class, 'createCBTQuestions']);

                    // Analytics
                    Route::prefix('analytics')->group(function () {
                        Route::get('/school',                        [AnalyticsController::class, 'getSchoolAnalytics']);
                        Route::get('/class/{classId}',               [AnalyticsController::class, 'getClassAnalytics']);
                        Route::get('/subject/{subjectId}',           [AnalyticsController::class, 'getSubjectAnalytics']);
                        Route::get('/student/{studentId}/trend',     [AnalyticsController::class, 'getStudentTrend']);
                        Route::get('/comparative',                   [AnalyticsController::class, 'getComparativeAnalytics']);
                        Route::get('/student/{studentId}/prediction',[AnalyticsController::class, 'getPrediction']);
                    });
                });

                // Question bank
                Route::prefix('question-bank')->group(function () {
                    Route::get('/',                   [QuestionBankController::class, 'index']);
                    Route::post('/',                  [QuestionBankController::class, 'store']);
                    Route::get('statistics',          [QuestionBankController::class, 'statistics']);
                    Route::get('for-exam',            [QuestionBankController::class, 'getQuestionsForExam']);
                    Route::get('{questionBank}',      [QuestionBankController::class, 'show']);
                    Route::put('{questionBank}',      [QuestionBankController::class, 'update']);
                    Route::delete('{questionBank}',   [QuestionBankController::class, 'destroy']);
                    Route::post('{questionBank}/duplicate', [QuestionBankController::class, 'duplicate']);
                });

                // Quizzes write
                Route::post('quizzes',              [QuizController::class, 'store']);
                Route::put('quizzes/{quiz}',        [QuizController::class, 'update']);
                Route::delete('quizzes/{quiz}',     [QuizController::class, 'destroy']);
                Route::post('quizzes/{quiz}/questions', [QuizController::class, 'addQuestion']);

                // Grades write
                Route::post('grades',               [GradeController::class, 'store']);
                Route::put('grades/{grade}',        [GradeController::class, 'update']);
                Route::delete('grades/{grade}',     [GradeController::class, 'destroy']);

                // Results generation (outer prefix)
                Route::prefix('results')->group(function () {
                    Route::post('mid-term/generate',    [ResultController::class, 'generateMidTermResults']);
                    Route::post('end-term/generate',    [ResultController::class, 'generateEndOfTermResults']);
                    Route::post('annual/generate',      [ResultController::class, 'generateAnnualResults']);
                    Route::get('student/{studentId}',   [ResultController::class, 'getStudentResults']);
                    Route::get('class/{classId}',       [ResultController::class, 'getClassResults']);
                });
            });

            // Attendance mark + view class (scoped in controller)
            Route::middleware(['module:attendance_management'])->group(function () {
                Route::prefix('attendance')->group(function () {
                    Route::get('/',                          [AttendanceController::class, 'index']);
                    Route::get('reports',                    [AttendanceController::class, 'reports']);
                    Route::get('students',                   [AttendanceController::class, 'students']);
                    Route::get('teachers',                   [AttendanceController::class, 'teachers']);
                    Route::get('class/{class_id}',           [AttendanceController::class, 'getClassAttendance']);
                    Route::get('student/{student_id}',       [AttendanceController::class, 'getStudentAttendance']);
                    Route::post('mark',                      [AttendanceController::class, 'mark']);
                    Route::put('{id}',                       [AttendanceController::class, 'update']);
                    Route::delete('{id}',                    [AttendanceController::class, 'destroy']);
                    Route::get('{id}',                       [AttendanceController::class, 'show']);
                });
            });

            // Timetable full CRUD (staff schedule their own timetable)
            Route::get('timetable',              [TimetableController::class, 'index']);
            Route::get('timetable/{timetable}',  [TimetableController::class, 'show']);
            Route::post('timetable',             [TimetableController::class, 'store']);
            Route::put('timetable/{timetable}',  [TimetableController::class, 'update']);
            Route::delete('timetable/{timetable}',[TimetableController::class, 'destroy']);

            // Reports (staff can view academic/attendance/performance)
            Route::get('reports/academic',     [ReportController::class, 'academic']);
            Route::get('reports/attendance',   [ReportController::class, 'attendance']);
            Route::get('reports/performance',  [ReportController::class, 'performance']);
        });

        // ── SCHOOL ADMINISTRATION ─────────────────────────────────────────
        // school_admin, principal, vice_principal, admin
        Route::middleware(['role:school_admin,principal,vice_principal,admin'])->group(function () {

            // School write
            Route::put('schools/me',         [SchoolController::class, 'updateMySchool']);
            Route::put('schools/{school}',   [SchoolController::class, 'update']);

            // User management (CRUD + roles)
            Route::post('users',                          [UserController::class, 'store']);
            Route::put('users/{user}',                    [UserController::class, 'update']);
            Route::delete('users/{user}',                 [UserController::class, 'destroy']);
            Route::post('users/{user}/activate',          [UserController::class, 'activate']);
            Route::post('users/{user}/suspend',           [UserController::class, 'suspend']);
            Route::post('users/{user}/assign-role',       [UserController::class, 'assignRole']);
            Route::post('users/{user}/remove-role',       [UserController::class, 'removeRole']);
            Route::post('users/{id}/send-credentials',    [UserController::class, 'sendCredentials']);
            Route::post('users/{id}/profile-picture',     [UserController::class, 'uploadProfilePicture']);
            Route::delete('users/{id}/profile-picture',   [UserController::class, 'deleteProfilePicture']);

            // Settings write
            Route::put('settings',        [SettingController::class, 'update']);
            Route::put('settings/school', [SettingController::class, 'updateSchoolSettings']);

            // Subscription management
            Route::post('subscriptions/create',                    [SubscriptionController::class, 'createSubscription']);
            Route::put('subscriptions/{subscription}/upgrade',     [SubscriptionController::class, 'upgradeSubscription']);
            Route::post('subscriptions/{subscription}/renew',      [SubscriptionController::class, 'renewSubscription']);
            Route::delete('subscriptions/{subscription}/cancel',   [SubscriptionController::class, 'cancelSubscription']);

            // Staff management
            Route::apiResource('staff', StaffController::class);

            // Announcements write
            Route::post('announcements',                          [AnnouncementController::class, 'store']);
            Route::put('announcements/{announcement}',            [AnnouncementController::class, 'update']);
            Route::delete('announcements/{announcement}',         [AnnouncementController::class, 'destroy']);
            Route::post('announcements/{announcement}/publish',   [AnnouncementController::class, 'publish']);

            // Stories write + analytics
            Route::post('stories/',          [StoryController::class, 'store']);
            Route::put('stories/{story}',    [StoryController::class, 'update']);
            Route::delete('stories/{story}', [StoryController::class, 'destroy']);
            Route::get('stories/{story}/analytics', [StoryController::class, 'analytics']);

            // Houses + Sports write
            Route::post('houses',                    [HouseController::class, 'store']);
            Route::put('houses/{house}',             [HouseController::class, 'update']);
            Route::delete('houses/{house}',          [HouseController::class, 'destroy']);
            Route::post('houses/{house}/points',     [HouseController::class, 'addPoints']);
            Route::post('sports/activities',         [SportController::class, 'createActivity']);
            Route::put('sports/activities/{id}',     [SportController::class, 'updateActivity']);
            Route::delete('sports/activities/{id}',  [SportController::class, 'deleteActivity']);
            Route::post('sports/teams',              [SportController::class, 'createTeam']);
            Route::post('sports/events',             [SportController::class, 'createEvent']);

            // Achievements write
            Route::post('achievements',              [AchievementController::class, 'store']);
            Route::put('achievements/{achievement}', [AchievementController::class, 'update']);
            Route::delete('achievements/{achievement}',[AchievementController::class, 'destroy']);

            // Library management (admin can also manage books)
            Route::post('library/books',             [LibraryController::class, 'addBook']);
            Route::put('library/books/{id}',         [LibraryController::class, 'updateBook']);
            Route::delete('library/books/{id}',      [LibraryController::class, 'deleteBook']);
            Route::post('library/digital-resources', [LibraryController::class, 'addDigitalResource']);
            Route::get('library/members',            [LibraryController::class, 'getMembers']);

            // Academic write
            Route::middleware(['module:academic_management'])->group(function () {
                Route::apiResource('academic-years', AcademicYearController::class)->except(['index','show']);
                Route::apiResource('terms',          TermController::class)->except(['index','show']);
                Route::apiResource('departments',    DepartmentController::class)->except(['index','show']);
                Route::apiResource('class-levels',   ClassLevelController::class)->except(['index','show']);
                Route::apiResource('classes',        ClassController::class)->except(['index','show']);
                Route::apiResource('subjects',       SubjectController::class)->except(['index','show']);
                // Subject enrollment
                Route::get('subjects/{subject}/students',              [SubjectController::class, 'enrolledStudents']);
                Route::post('subjects/{subject}/enroll',               [SubjectController::class, 'enroll']);
                Route::delete('subjects/{subject}/students/{studentId}',[SubjectController::class, 'unenroll']);
                Route::apiResource('arms',           ArmController::class)->except(['index','show']);
                Route::post('arms/assign-to-class',  [ArmController::class, 'assignToClass']);
                Route::post('arms/remove-from-class',[ArmController::class, 'removeFromClass']);
            });

            // Students write
            Route::middleware(['module:student_management'])->group(function () {
                Route::post('students',                          [StudentController::class, 'store']);
                Route::put('students/{student}',                 [StudentController::class, 'update']);
                Route::delete('students/{student}',              [StudentController::class, 'destroy']);
                Route::post('students/generate-admission-number',[StudentController::class, 'generateAdmissionNumber']);
                Route::post('students/generate-credentials',     [StudentController::class, 'generateCredentials']);
            });

            // Teachers write
            Route::middleware(['module:teacher_management'])->group(function () {
                Route::post('teachers',             [TeacherController::class, 'store']);
                Route::put('teachers/{teacher}',    [TeacherController::class, 'update']);
                Route::delete('teachers/{teacher}', [TeacherController::class, 'destroy']);
            });

            // Assessment admin (approve/publish results, promotions, bulk report cards)
            Route::middleware(['module:cbt'])->group(function () {
                Route::prefix('assessments')->group(function () {
                    Route::post('results/{resultId}/approve', [ResultController::class, 'approveResult']);
                    Route::post('results/publish',            [ResultController::class, 'publishResults']);
                    Route::post('report-cards/bulk-download', [ReportCardController::class, 'bulkDownload']);
                    Route::post('report-cards/{studentId}/{termId}/{academicYearId}/email', [ReportCardController::class, 'emailReportCard']);
                    Route::post('scoreboards/refresh',        [ScoreboardController::class, 'manualRefresh']);
                    Route::prefix('grading-systems')->group(function () {
                        Route::post('/',       [GradingSystemController::class, 'store']);
                        Route::put('/{id}',    [GradingSystemController::class, 'update']);
                        Route::delete('/{id}', [GradingSystemController::class, 'destroy']);
                    });
                    Route::prefix('promotions')->group(function () {
                        Route::get('/',              [PromotionController::class, 'index']);
                        Route::post('/promote',      [PromotionController::class, 'promoteStudent']);
                        Route::post('/bulk-promote', [PromotionController::class, 'bulkPromote']);
                        Route::post('/auto-promote', [PromotionController::class, 'autoPromote']);
                        Route::post('/graduate',     [PromotionController::class, 'graduateStudents']);
                        Route::get('/statistics',    [PromotionController::class, 'getStatistics']);
                        Route::delete('/{id}',       [PromotionController::class, 'destroy']);
                    });
                    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy']);
                });
                Route::prefix('results')->group(function () {
                    Route::post('publish',   [ResultController::class, 'publishResults']);
                    Route::post('unpublish', [ResultController::class, 'unpublishResults']);
                });
            });

            // ── Result Configurations (school type-based result templates) ──
            Route::prefix('result-configurations')->group(function () {
                Route::get('/',                      [\App\Http\Controllers\ResultConfigurationController::class, 'index']);
                Route::get('presets',                [\App\Http\Controllers\ResultConfigurationController::class, 'presets']);
                Route::get('for-class/{classId}',    [\App\Http\Controllers\ResultConfigurationController::class, 'forClass']);
                Route::post('/',                     [\App\Http\Controllers\ResultConfigurationController::class, 'store']);
                Route::post('{sectionType}/preset',  [\App\Http\Controllers\ResultConfigurationController::class, 'applyPreset']);
                Route::put('{id}',                   [\App\Http\Controllers\ResultConfigurationController::class, 'update']);
                Route::delete('{id}',                [\App\Http\Controllers\ResultConfigurationController::class, 'destroy']);
                Route::get('{sectionType}',          [\App\Http\Controllers\ResultConfigurationController::class, 'show']);
            });

            // ── School Signatures (principal / bursar / admin signatories) ──
            // schoolId is passed explicitly so the same routes work from the
            // super-admin context (via /admin/schools/{school}/signatures) as well.
            Route::prefix('schools/{schoolId}/signatures')->group(function () {
                Route::get('/',                    [\App\Http\Controllers\SchoolSignatureController::class, 'index']);
                Route::post('/',                   [\App\Http\Controllers\SchoolSignatureController::class, 'upsert']);
                Route::delete('{signatureId}',     [\App\Http\Controllers\SchoolSignatureController::class, 'delete']);
            });

            // ── Central Question Bank (school-side) ──────────────────────────
            // Schools browse and import from the super-admin-managed central bank.
            Route::prefix('central-question-bank')->group(function () {
                Route::get('subjects',         [\App\Http\Controllers\SchoolQuestionBankController::class, 'subjects']);
                Route::get('questions',        [\App\Http\Controllers\SchoolQuestionBankController::class, 'browse']);
                Route::get('questions/{id}',   [\App\Http\Controllers\SchoolQuestionBankController::class, 'show']);
                Route::post('import',          [\App\Http\Controllers\SchoolQuestionBankController::class, 'importToExam']);
                Route::get('import-history',   [\App\Http\Controllers\SchoolQuestionBankController::class, 'importHistory']);
            });

            // Reports full (admin also sees financial report)
            Route::get('reports/financial',        [ReportController::class, 'financial']);
            Route::get('{type}/export',            [ReportController::class, 'export']);

            // Bulk operations
            Route::prefix('bulk')->group(function () {
                Route::post('students/register',    [BulkController::class, 'bulkRegisterStudents']);
                Route::post('teachers/register',    [BulkController::class, 'bulkRegisterTeachers']);
                Route::post('staff/create',         [BulkController::class, 'bulkCreateStaff']);
                Route::post('guardians/create',     [BulkController::class, 'bulkCreateGuardians']);
                Route::post('classes/create',       [BulkController::class, 'bulkCreateClasses']);
                Route::post('subjects/create',      [BulkController::class, 'bulkCreateSubjects']);
                Route::post('exams/create',         [BulkController::class, 'bulkCreateExams']);
                Route::post('assignments/create',   [BulkController::class, 'bulkCreateAssignments']);
                Route::post('questions/create',     [BulkController::class, 'bulkCreateQuestions']);
                Route::post('fees/create',          [BulkController::class, 'bulkCreateFees']);
                Route::post('attendance/mark',      [BulkController::class, 'bulkMarkAttendance']);
                Route::post('results/update',       [BulkController::class, 'bulkUpdateResults']);
                Route::post('notifications/send',   [BulkController::class, 'bulkSendNotifications']);
                Route::post('import/csv',           [BulkController::class, 'bulkImportFromCSV']);
                Route::get('operations/{operationId}/status',  [BulkController::class, 'getBulkOperationStatus']);
                Route::delete('operations/{operationId}/cancel',[BulkController::class, 'cancelBulkOperation']);
            });

            // Hostel
            Route::middleware(['module:hostel_management'])->group(function () {
                Route::prefix('hostel')->group(function () {
                    Route::apiResource('rooms',       HostelRoomController::class);
                    Route::post('allocations/{id}/vacate', [HostelAllocationController::class, 'vacate']);
                    Route::apiResource('allocations', HostelAllocationController::class);
                    Route::apiResource('maintenance', HostelMaintenanceController::class);
                });
            });

            // Inventory
            Route::middleware(['module:inventory_management'])->group(function () {
                Route::prefix('inventory')->group(function () {
                    Route::apiResource('categories',  InventoryCategoryController::class);
                    Route::apiResource('items',       InventoryItemController::class);
                    Route::post('transactions/checkout',               [InventoryTransactionController::class, 'checkout']);
                    Route::post('transactions/{transaction}/return',   [InventoryTransactionController::class, 'returnItem']);
                    Route::apiResource('transactions', InventoryTransactionController::class);
                });
            });

            // Events write
            Route::middleware(['module:event_management'])->group(function () {
                Route::post('events',              [EventController::class, 'store']);
                Route::put('events/{event}',       [EventController::class, 'update']);
                Route::delete('events/{event}',    [EventController::class, 'destroy']);
                Route::post('calendars',           [CalendarController::class, 'store']);
                Route::put('calendars/{calendar}', [CalendarController::class, 'update']);
                Route::delete('calendars/{calendar}',[CalendarController::class, 'destroy']);
            });
        });

        // ── FINANCE ───────────────────────────────────────────────────────
        // school_admin, principal, accountant, admin
        Route::middleware(['role:school_admin,principal,accountant,admin', 'module:fee_management'])->group(function () {
            Route::prefix('financial')->group(function () {
                Route::get('fees/structure',           [FeeController::class, 'getFeeStructure']);
                Route::post('fees/structure',          [FeeController::class, 'createFeeStructure']);
                Route::put('fees/structure/{id}',      [FeeController::class, 'updateFeeStructure']);
                Route::get('fees/student/{student_id}',[FeeController::class, 'getStudentFees']);
                Route::post('fees/{fee}/pay',          [FeeController::class, 'pay']);
                Route::get('summary',                  [FeeController::class, 'summary']);
                Route::get('revenue-chart',            [FeeController::class, 'revenueChart']);
                Route::get('fee-types',                [FeeController::class, 'feeTypes']);
                Route::apiResource('fees',             FeeController::class);
                Route::apiResource('payments',         PaymentController::class);
                Route::get('payments/student/{student_id}', [PaymentController::class, 'getStudentPayments']);
                Route::get('payments/receipt/{id}',       [PaymentController::class, 'getReceipt']);
                Route::get('payments/receipt/{id}/print', [PaymentController::class, 'printReceipt']);
                Route::apiResource('expenses',         ExpenseController::class);
                Route::apiResource('payroll',          PayrollController::class);
                Route::get('payroll/{payroll}/pay-stub',[PayrollController::class, 'payStub']);

                // ── Fee Voucher (print-ready HTML) ────────────────────────────
                Route::get('fees/voucher/{studentId}', [FeeController::class, 'feeVoucher']);

                // ── Invoices ──────────────────────────────────────────────────
                Route::apiResource('invoices', \App\Http\Controllers\InvoiceController::class)
                    ->except(['create', 'edit']);
                Route::post('invoices/{id}/cancel',  [\App\Http\Controllers\InvoiceController::class, 'cancel']);
                Route::get('invoices/{id}/print',    [\App\Http\Controllers\InvoiceController::class, 'printInvoice']);
            });
        });

        // ── LIBRARY MANAGEMENT ────────────────────────────────────────────
        // librarian can also manage books (admin covered above)
        Route::middleware(['role:librarian'])->group(function () {
            Route::post('library/books',             [LibraryController::class, 'addBook']);
            Route::put('library/books/{id}',         [LibraryController::class, 'updateBook']);
            Route::delete('library/books/{id}',      [LibraryController::class, 'deleteBook']);
            Route::post('library/digital-resources', [LibraryController::class, 'addDigitalResource']);
            Route::get('library/members',            [LibraryController::class, 'getMembers']);
        });

        // ── HEALTH MANAGEMENT ─────────────────────────────────────────────
        Route::middleware(['role:school_admin,principal,admin,nurse', 'module:health_management'])->group(function () {
            Route::prefix('health')->group(function () {
                Route::apiResource('records',      HealthRecordController::class);
                Route::apiResource('appointments', HealthAppointmentController::class);
                Route::apiResource('medications',  MedicationController::class);
            });
        });

        // ── TRANSPORT ─────────────────────────────────────────────────────
        Route::middleware(['role:school_admin,principal,admin,driver', 'module:transport_management'])->group(function () {
            Route::prefix('transport')->group(function () {
                Route::apiResource('vehicles',  VehicleController::class);
                Route::apiResource('drivers',   DriverController::class);
                Route::apiResource('routes',    TransportRouteController::class);
                Route::get('routes/{route}/students',   [TransportRouteController::class, 'getStudents']);
                Route::post('routes/{route}/students',  [TransportRouteController::class, 'assignStudent']);
                Route::delete('routes/{route}/students',[TransportRouteController::class, 'removeStudent']);
                Route::post('secure-pickup/verify',     [SecurePickupController::class, 'verify']);
                Route::apiResource('secure-pickup',     SecurePickupController::class);

                // Trip management (driver logs; admin views all)
                Route::get('trips',                    [\App\Http\Controllers\TransportTripController::class, 'index']);
                Route::post('trips',                   [\App\Http\Controllers\TransportTripController::class, 'store']);
                Route::get('trips/{trip}',             [\App\Http\Controllers\TransportTripController::class, 'show']);
                Route::put('trips/{trip}',             [\App\Http\Controllers\TransportTripController::class, 'update']);
                Route::post('trips/{trip}/start',      [\App\Http\Controllers\TransportTripController::class, 'start']);
                Route::post('trips/{trip}/complete',   [\App\Http\Controllers\TransportTripController::class, 'complete']);
                Route::post('trips/{trip}/attendance', [\App\Http\Controllers\TransportTripController::class, 'recordAttendance']);
                Route::get('trips/{trip}/attendance',  [\App\Http\Controllers\TransportTripController::class, 'getAttendance']);
            });
        });

        // ── SECURITY ──────────────────────────────────────────────────────
        Route::middleware(['role:school_admin,principal,admin,security'])->group(function () {
            Route::prefix('security')->group(function () {
                Route::get('visitors',               [\App\Http\Controllers\SecurityController::class, 'visitorIndex']);
                Route::post('visitors',              [\App\Http\Controllers\SecurityController::class, 'visitorStore']);
                Route::put('visitors/{id}',          [\App\Http\Controllers\SecurityController::class, 'visitorUpdate']);
                Route::post('visitors/{id}/exit',    [\App\Http\Controllers\SecurityController::class, 'visitorExit']);

                Route::get('gate-passes',            [\App\Http\Controllers\SecurityController::class, 'gatePassIndex']);
                Route::post('gate-passes',           [\App\Http\Controllers\SecurityController::class, 'gatePassStore']);
                Route::post('gate-passes/{id}/use',  [\App\Http\Controllers\SecurityController::class, 'gatePassUse']);

                Route::get('incidents',              [\App\Http\Controllers\SecurityController::class, 'incidentIndex']);
                Route::post('incidents',             [\App\Http\Controllers\SecurityController::class, 'incidentStore']);
                Route::put('incidents/{id}',         [\App\Http\Controllers\SecurityController::class, 'incidentUpdate']);
                Route::post('incidents/{id}/resolve',[\App\Http\Controllers\SecurityController::class, 'incidentResolve']);

                Route::get('access-logs',            [\App\Http\Controllers\SecurityController::class, 'accessLogIndex']);
                Route::post('access-logs',           [\App\Http\Controllers\SecurityController::class, 'accessLogStore']);
            });
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
