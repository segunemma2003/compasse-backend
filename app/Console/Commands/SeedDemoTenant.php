<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionTenantJob;
use App\Models\ResultConfiguration;
use App\Models\School;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use App\Http\Controllers\DashboardController;

class SeedDemoTenant extends Command
{
    protected $signature = 'demo:seed {--fresh : Drop and fully recreate the demo tenant before seeding}';

    protected $description = 'Provision (or top up) a fully populated "demoschool" tenant with a working login for every dashboard role';

    private const SUBDOMAIN = 'demoschool';
    private const PASSWORD  = 'Demo@2026!';

    /**
     * role => frontend landing route after login (mirrors SchoolLogin.tsx's roleRoutes /
     * school/Dashboard.tsx's dashboardPathForRole, kept here only for the printed report).
     */
    private const ROLE_LANDING = [
        'school_admin'    => '/school/dashboard',
        'admin'           => '/school/dashboard',
        'principal'       => '/school/dashboard',
        'vice_principal'  => '/school/dashboard',
        'hod'             => '/school/dashboard',
        'teacher'         => '/school/classes',
        'class_teacher'   => '/school/classes',
        'subject_teacher' => '/school/classes',
        'year_tutor'      => '/school/classes',
        'student'         => '/school/my-courses',
        'guardian'        => '/school/my-children',
        'parent'          => '/school/my-children',
        'accountant'      => '/school/finance',
        'librarian'       => '/school/library',
        'nurse'           => '/school/health',
        'driver'          => '/school/transport',
        'security'        => '/school/security',
        'staff'           => '/school/dashboard',
        'cleaner'         => '/school/dashboard',
        'caterer'         => '/school/dashboard',
    ];

    private array $credentials = [];

    public function handle(TenantService $tenantService): int
    {
        $tenant = Tenant::where('subdomain', self::SUBDOMAIN)->first();

        if ($this->option('fresh') && $tenant) {
            $this->warn('--fresh: dropping existing demo tenant database...');
            $tenantService->deleteTenant($tenant);
            $tenant = null;
        }

        if (!$tenant) {
            $tenant = $this->provisionTenant($tenantService);
        } else {
            $this->info('Demo tenant already exists — topping up data (idempotent).');
        }

        tenancy()->initialize($tenant);
        try {
            $this->seedEverything();
        } finally {
            tenancy()->end();
        }

        $this->call('platform:refresh-student-teacher-stats');

        $this->printCredentials();

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tenant provisioning — reuses the real production path
    // ─────────────────────────────────────────────────────────────────────────

    private function provisionTenant(TenantService $tenantService): Tenant
    {
        $this->info('Provisioning demo tenant (runs real migrations — this can take a minute)...');

        $tenant = Tenant::create([
            'id'                => Str::uuid()->toString(),
            'name'              => 'Demo School',
            'domain'            => null,
            'subdomain'         => self::SUBDOMAIN,
            'database_name'     => now()->format('YmdHis') . '_demoschool',
            'database_host'     => config('database.connections.mysql.host'),
            'database_port'     => config('database.connections.mysql.port'),
            'database_username' => config('database.connections.mysql.username'),
            'database_password' => config('database.connections.mysql.password'),
            'status'            => 'provisioning',
            'subscription_plan' => 'demo',
        ]);

        $schoolData = [
            'name'           => 'Demo School',
            'admin_email'    => 'admin@demoschool.com',
            'admin_password' => self::PASSWORD,
            'admin_name'     => 'Demo Admin',
            'address'        => '1 Demo Close, Lagos',
            'phone'          => '+2348000000000',
            'email'          => 'info@demoschool.com',
        ];

        // Same job production uses on real signups — called synchronously here
        // instead of queued, so this command finishes with the tenant fully live.
        (new ProvisionTenantJob($tenant->id, $schoolData))->handle($tenantService);

        return $tenant->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data — populates exactly what each DashboardController method reads
    // ─────────────────────────────────────────────────────────────────────────

    private function seedEverything(): void
    {
        $school = DB::table('schools')->first();
        $schoolId = $school->id;

        $this->grantFullModuleAccess($schoolId);

        $academicYearId = DB::table('academic_years')->where('is_current', true)->value('id')
            ?? DB::table('academic_years')->value('id');
        $termId = DB::table('terms')->where('is_current', true)->value('id')
            ?? DB::table('terms')->value('id');

        // Target the exact account ProvisionTenantJob creates (schoolData.admin_email
        // below) by email, not by role — TenantSeeder (run automatically during
        // provisioning, before the School row exists) also creates its own stray
        // school_admin under a generic fallback email, and picking "first school_admin
        // by role" would non-deterministically grab that one instead.
        $adminUser = DB::table('users')->where('email', 'admin@demoschool.com')->first();
        $adminUserId = $adminUser->id;
        $this->credentials[] = ['role' => 'school_admin', 'email' => $adminUser->email];

        [$departmentId, $armAId, $armBId, $class1Id, $class2Id] = $this->seedAcademicStructure($schoolId);

        $teacherIds = $this->seedTeachingStaff($schoolId, $departmentId, $class1Id);
        $this->seedSubjects($schoolId, $class1Id, $departmentId, $teacherIds['teacher']);

        $specialistUserIds = $this->seedSpecialistStaff($schoolId);

        [$studentUserId, $guardianUserId, $parentUserId, $studentIds] =
            $this->seedStudentsAndGuardians($schoolId, $class1Id, $class2Id, $armAId, $armBId);

        $this->seedAttendance($studentIds, $teacherIds, $adminUserId);
        $this->seedFinance($schoolId, $academicYearId, $termId, $studentIds, $specialistUserIds, $adminUserId);
        $this->seedLibrary($schoolId, $studentUserId, $teacherIds['teacher_user']);
        $this->seedTransport($schoolId, $specialistUserIds['driver'], $studentIds);
        $this->seedHostel($schoolId, $studentIds[0]);
        $this->seedHealth($schoolId, $studentIds[0]);
        $this->seedInventory($schoolId, $adminUserId);
        $this->seedSecurity($schoolId, $adminUserId);
        $this->seedAnnouncements($schoolId, $adminUserId);
        $this->seedEventsAndCalendar($schoolId, $adminUserId, $class1Id);
        $this->seedDemoLivestream($schoolId, $teacherIds['teacher'], $class1Id, $adminUserId);
        $this->seedDemoMessages($studentUserId, $teacherIds['teacher_user'], $studentIds, $class1Id);

        // ── Nursery / Primary sections + results across all three sections ──
        [$nurseryClassId, $primaryClassId] = $this->seedNurseryAndPrimaryClasses($schoolId, $armAId);
        $this->seedSubjects($schoolId, $primaryClassId, null, $teacherIds['teacher'], [
            ['Mathematics', 'PRY-MTH', null],
            ['English Studies', 'PRY-ENG', null],
            ['Basic Science', 'PRY-SCI', null],
            ['Handwriting', 'PRY-HW', null],
        ]);
        [$nurseryStudentIds, $primaryStudentIds] = $this->seedNurseryAndPrimaryStudents($schoolId, $nurseryClassId, $primaryClassId, $armAId);

        $gradingSystemId = $this->seedGradingAndResultConfigs($schoolId);
        $this->seedResults($schoolId, $termId, $academicYearId, $adminUserId, $teacherIds['teacher'], [
            ['classId' => $class1Id, 'studentIds' => array_slice($studentIds, 0, 8), 'subjectCodes' => ['MTH101', 'ENG101', 'SCI101', 'CMP101', 'SOC101']],
            ['classId' => $class2Id, 'studentIds' => array_slice($studentIds, 8), 'subjectCodes' => ['MTH101', 'ENG101', 'SCI101', 'CMP101', 'SOC101']],
            ['classId' => $primaryClassId, 'studentIds' => $primaryStudentIds, 'subjectCodes' => ['PRY-MTH', 'PRY-ENG', 'PRY-SCI', 'PRY-HW']],
        ], $nurseryClassId, $nurseryStudentIds);

        $this->seedNurseryCheckpointReport($schoolId, $termId, $academicYearId, $nurseryStudentIds);
        $this->seedCbtExam($schoolId, $class1Id, $termId, $academicYearId, $teacherIds['teacher'], array_slice($studentIds, 0, 8));

        $this->reportSampleResults($termId, $academicYearId, $nurseryStudentIds, $primaryStudentIds, $studentIds);
        $this->seedDashboardPresentationData($schoolId, $class1Id, $termId, $academicYearId, $teacherIds['teacher'], $studentIds[0]);
    }

    /**
     * Timetable slots + an open assignment so student/teacher dashboards are not empty.
     */
    private function seedDashboardPresentationData(
        int $schoolId,
        int $classId,
        int $termId,
        int $academicYearId,
        int $teacherId,
        int $primaryStudentId,
    ): void {
        $subjects = DB::table('subjects')->where('class_id', $classId)->orderBy('id')->limit(5)->get();
        $days     = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $slot     = 0;

        foreach ($days as $day) {
            foreach ($subjects as $subject) {
                $start = sprintf('%02d:00:00', 8 + ($slot % 5));
                $end   = sprintf('%02d:45:00', 8 + ($slot % 5));
                $this->upsert('timetables', [
                    'school_id'  => $schoolId,
                    'class_id'   => $classId,
                    'subject_id' => $subject->id,
                    'day_of_week'=> $day,
                ], [
                    'teacher_id'       => $teacherId,
                    'start_time'       => $start,
                    'end_time'         => $end,
                    'room'             => 'Room '.chr(65 + ($slot % 5)),
                    'academic_year_id' => $academicYearId,
                ]);
                $slot++;
            }
        }

        $subjectId = $subjects->first()?->id;
        if ($subjectId) {
            $openAssignmentId = $this->upsert('assignments', [
                'class_id' => $classId,
                'title'    => 'Science project (pending)',
            ], [
                'description'      => 'Prepare a short write-up on the water cycle.',
                'subject_id'       => $subjectId,
                'term_id'          => $termId,
                'academic_year_id' => $academicYearId,
                'teacher_id'       => $teacherId,
                'due_date'         => now()->addDays(10)->toDateString(),
                'total_marks'      => 20,
                'assignment_type'  => 'homework',
                'submission_type'  => 'text',
                'status'           => 'published',
            ]);

            $hasSubmission = DB::table('assignment_submissions')
                ->where('assignment_id', $openAssignmentId)
                ->where('student_id', $primaryStudentId)
                ->exists();

            if (! $hasSubmission && $primaryStudentId > 0) {
                // Leave unsubmitted so the student dashboard shows a pending item.
            }
        }

        DashboardController::bustCache();
    }

    /**
     * Insert-or-update by a unique key and return the row's id.
     */
    private function upsert(string $table, array $uniqueBy, array $values): int
    {
        $existingId = DB::table($table)->where($uniqueBy)->value('id');

        if ($existingId) {
            DB::table($table)->where('id', $existingId)->update(array_merge($values, ['updated_at' => now()]));
            return $existingId;
        }

        return DB::table($table)->insertGetId(array_merge($uniqueBy, $values, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function createUser(string $email, string $name, string $role): int
    {
        return $this->upsert('users', ['email' => $email], [
            'name'              => $name,
            'password'          => Hash::make(self::PASSWORD),
            'role'              => $role,
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Grant every module so no demo API call 403s with "module access denied".
     *
     * Root cause this works around: TenantService::enableDefaultModules()
     * writes the module list onto the Tenant model's `data` column, but
     * SubscriptionService::getSchoolModules() — what module:* middleware
     * actually checks — reads from the School model's `settings` column
     * instead (falling back there whenever there's no active/trial
     * subscription, which the demo tenant never gets one of). Those two
     * writes never meet, so no newly-provisioned tenant's "default modules"
     * actually take effect. This sets `settings.modules` directly (where the
     * check really looks) and busts the 5-minute module-access cache so it's
     * effective immediately instead of only after existing entries expire.
     */
    private function grantFullModuleAccess(int $schoolId): void
    {
        $allModules = [
            'academic_management', 'attendance_management', 'cbt', 'email_integration',
            'event_management', 'fee_management', 'health_management', 'hostel_management',
            'inventory_management', 'livestream', 'sms_integration', 'student_management',
            'teacher_management', 'transport_management', 'staff_management', 'exam_management',
            'library', 'finance', 'communication',
        ];

        $existingSettings = json_decode(DB::table('schools')->where('id', $schoolId)->value('settings') ?? '{}', true) ?? [];
        DB::table('schools')->where('id', $schoolId)->update([
            'settings' => json_encode(array_merge($existingSettings, ['modules' => $allModules])),
        ]);

        $school = School::find($schoolId);
        if ($school) {
            app(SubscriptionService::class)->invalidateCache($school);
        }
    }

    // ── Academic structure ──────────────────────────────────────────────────

    private function seedAcademicStructure(int $schoolId): array
    {
        $departmentId = $this->upsert('departments', ['school_id' => $schoolId, 'name' => 'Sciences'], [
            'description' => 'Science subjects department',
            'status'      => 'active',
        ]);

        $armAId = $this->upsert('arms', ['school_id' => $schoolId, 'name' => 'A'], ['description' => 'Arm A', 'status' => 'active']);
        $armBId = $this->upsert('arms', ['school_id' => $schoolId, 'name' => 'B'], ['description' => 'Arm B', 'status' => 'active']);

        $class1Id = $this->upsert('classes', ['school_id' => $schoolId, 'name' => 'JSS 1'], [
            'level' => 'Junior Secondary', 'capacity' => 40, 'status' => 'active', 'section_type' => 'junior_secondary',
        ]);
        $class2Id = $this->upsert('classes', ['school_id' => $schoolId, 'name' => 'JSS 2'], [
            'level' => 'Junior Secondary', 'capacity' => 40, 'status' => 'active', 'section_type' => 'junior_secondary',
        ]);

        foreach ([[$class1Id, $armAId], [$class1Id, $armBId], [$class2Id, $armAId]] as [$cid, $aid]) {
            $this->upsert('class_arm', ['class_id' => $cid, 'arm_id' => $aid], ['capacity' => 30, 'status' => 'active']);
        }

        return [$departmentId, $armAId, $armBId, $class1Id, $class2Id];
    }

    // ── Teaching staff (each needs its own `teachers` row so /dashboard/teacher
    //    and /dashboard/hod don't 404 looking up the profile) ────────────────

    private function seedTeachingStaff(int $schoolId, int $departmentId, int $class1Id): array
    {
        $roster = [
            'teacher'         => ['Grace Adeyemi', 'TCH-001'],
            'class_teacher'   => ['Michael Obi', 'TCH-002'],
            'subject_teacher' => ['Fatima Bello', 'TCH-003'],
            'year_tutor'      => ['Peter Nwosu', 'TCH-004'],
            'hod'             => ['Chinwe Okafor', 'TCH-005'],
        ];

        $teacherIds = [];
        foreach ($roster as $role => [$name, $employeeId]) {
            $email    = "{$role}@demoschool.com";
            $userId   = $this->createUser($email, $name, $role);
            $this->credentials[] = ['role' => $role, 'email' => $email];

            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $teacherId = $this->upsert('teachers', ['school_id' => $schoolId, 'employee_id' => $employeeId], [
                'user_id'          => $userId,
                'department_id'    => $departmentId,
                'first_name'       => $first,
                'last_name'        => $last,
                'email'            => $email,
                'employment_date'  => now()->subYears(2)->toDateString(),
                'status'           => 'active',
                'qualification'    => 'B.Ed',
                'experience_years' => 5,
            ]);

            $teacherIds[$role] = $teacherId;
            if ($role === 'teacher') {
                $teacherIds['teacher_user'] = $userId;
            }
        }

        DB::table('classes')->where('id', $class1Id)->update(['class_teacher_id' => $teacherIds['teacher']]);
        DB::table('departments')->where('id', $departmentId)->update(['head_id' => $teacherIds['hod']]);

        // principal / vice_principal / admin don't have a profile lookup — plain users are enough.
        // Note: the generic "admin" role uses a distinct email — admin@demoschool.com is
        // reserved for the school_admin account ProvisionTenantJob creates; reusing it here
        // would upsert() into (and silently overwrite) that account instead of creating a new one.
        foreach (['principal' => ['Adaeze Nwankwo', 'principal@demoschool.com'], 'vice_principal' => ['Tunde Bakare', 'vice_principal@demoschool.com'], 'admin' => ['Chioma Eze', 'admin-role@demoschool.com']] as $role => [$name, $email]) {
            $this->createUser($email, $name, $role);
            $this->credentials[] = ['role' => $role, 'email' => $email];
        }

        return $teacherIds;
    }

    private function seedSubjects(int $schoolId, int $classId, ?int $departmentId, int $teacherId, ?array $subjects = null): void
    {
        $subjects ??= [
            ['Mathematics', 'MTH101', null],
            ['English Language', 'ENG101', null],
            ['Basic Science', 'SCI101', $departmentId],
            ['Computer Studies', 'CMP101', $departmentId],
            ['Social Studies', 'SOC101', null],
        ];

        foreach ($subjects as [$name, $code, $deptId]) {
            $this->upsert('subjects', ['school_id' => $schoolId, 'code' => $code], [
                'name'          => $name,
                'class_id'      => $classId,
                'department_id' => $deptId,
                'teacher_id'    => $teacherId,
                'credits'       => 1,
                'status'        => 'active',
            ]);
        }
    }

    // ── Nursery & Primary sections (Secondary already covered by JSS 1/JSS 2) ─

    private function seedNurseryAndPrimaryClasses(int $schoolId, int $armAId): array
    {
        $nurseryClassId = $this->upsert('classes', ['school_id' => $schoolId, 'name' => 'Nursery 1'], [
            'level' => 'Nursery', 'capacity' => 20, 'status' => 'active', 'section_type' => 'nursery',
        ]);
        $primaryClassId = $this->upsert('classes', ['school_id' => $schoolId, 'name' => 'Primary 1'], [
            'level' => 'Primary', 'capacity' => 30, 'status' => 'active', 'section_type' => 'primary',
        ]);

        foreach ([$nurseryClassId, $primaryClassId] as $cid) {
            $this->upsert('class_arm', ['class_id' => $cid, 'arm_id' => $armAId], ['capacity' => 30, 'status' => 'active']);
        }

        return [$nurseryClassId, $primaryClassId];
    }

    private function seedNurseryAndPrimaryStudents(int $schoolId, int $nurseryClassId, int $primaryClassId, int $armAId): array
    {
        $nurseryUserId = $this->createUser('student-nursery@demoschool.com', 'Baby Ade', 'student');
        $this->credentials[] = ['role' => 'student', 'email' => 'student-nursery@demoschool.com'];

        $primaryUserId = $this->createUser('student-primary@demoschool.com', 'Kunle Bello', 'student');
        $this->credentials[] = ['role' => 'student', 'email' => 'student-primary@demoschool.com'];

        $nurseryNames = [['Baby Ade', $nurseryUserId], ['Sadiq Aliyu', null], ['Nkechi Obi', null]];
        $primaryNames = [['Kunle Bello', $primaryUserId], ['Amara Chukwu', null], ['Fatai Rasheed', null]];

        $nurseryStudentIds = $this->upsertStudents($schoolId, 'DEMO-NUR', $nurseryNames, $nurseryClassId, $armAId, 4);
        $primaryStudentIds = $this->upsertStudents($schoolId, 'DEMO-PRY', $primaryNames, $primaryClassId, $armAId, 9);

        return [$nurseryStudentIds, $primaryStudentIds];
    }

    /**
     * Shared by seedStudentsAndGuardians (secondary) and the nursery/primary
     * helper above so the admission-number/email/dob generation stays in one place.
     */
    private function upsertStudents(int $schoolId, string $admissionPrefix, array $names, int $classId, int $armId, int $baseAgeYears = 12): array
    {
        $studentIds = [];
        foreach ($names as $i => [$name, $userId]) {
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $admissionNumber = "{$admissionPrefix}-" . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);

            $studentIds[] = $this->upsert('students', ['school_id' => $schoolId, 'admission_number' => $admissionNumber], [
                'user_id'        => $userId,
                'first_name'     => $first,
                'last_name'      => $last,
                'email'          => Str::slug($name) . '-' . Str::lower($admissionPrefix) . '@demoschool.com',
                'gender'         => $i % 2 === 0 ? 'male' : 'female',
                'date_of_birth'  => now()->subYears($baseAgeYears)->subDays($i)->toDateString(),
                'admission_date' => now()->subYear()->toDateString(),
                'class_id'       => $classId,
                'arm_id'         => $armId,
                'status'         => 'active',
            ]);
        }

        return $studentIds;
    }

    // ── Specialist staff (accountant/librarian/driver/nurse/security/staff/
    //    cleaner/caterer) — dashboards for these are school-wide, no profile
    //    lookup required, but a `staff` directory row makes the Staff page real. ─

    private function seedSpecialistStaff(int $schoolId): array
    {
        $roster = [
            'accountant' => ['Blessing Eze',    'STF-001'],
            'librarian'  => ['Samuel Okon',     'STF-002'],
            'driver'     => ['Ibrahim Musa',    'STF-003'],
            'nurse'      => ['Ngozi Chukwu',    'STF-004'],
            'security'   => ['David Yakubu',    'STF-005'],
            'staff'      => ['Joy Umeh',        'STF-006'],
            'cleaner'    => ['Amaka Nnamdi',    'STF-007'],
            'caterer'    => ['Kemi Alabi',      'STF-008'],
        ];

        $userIds = [];
        foreach ($roster as $role => [$name, $employeeId]) {
            $email  = "{$role}@demoschool.com";
            $userId = $this->createUser($email, $name, $role);
            $this->credentials[] = ['role' => $role, 'email' => $email];
            $userIds[$role] = $userId;

            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $this->upsert('staff', ['school_id' => $schoolId, 'employee_id' => $employeeId], [
                'user_id'         => $userId,
                'first_name'      => $first,
                'last_name'       => $last,
                'email'           => $email,
                'role'            => $role,
                'employment_date' => now()->subYear()->toDateString(),
                'status'          => 'active',
            ]);
        }

        return $userIds;
    }

    // ── Students & guardians ────────────────────────────────────────────────

    private function seedStudentsAndGuardians(int $schoolId, int $class1Id, int $class2Id, int $armAId, int $armBId): array
    {
        $studentUserId = $this->createUser('student@demoschool.com', 'Chidi Eze', 'student');
        $this->credentials[] = ['role' => 'student', 'email' => 'student@demoschool.com'];

        $names = [
            ['Chidi Eze', $studentUserId], ['Amina Sule', null], ['Tobi Ojo', null],
            ['Halima Yusuf', null], ['Emeka Nnaji', null], ['Zainab Lawal', null],
            ['Bayo Fashola', null], ['Ruth Danjuma', null], ['Segun Afolabi', null],
            ['Precious Okoye', null],
        ];

        $studentIds = [];
        foreach ($names as $i => [$name, $userId]) {
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $classId = $i < 8 ? $class1Id : $class2Id;
            $armId   = $i % 2 === 0 ? $armAId : $armBId;
            $admissionNumber = 'DEMO-STU-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);

            $studentIds[] = $this->upsert('students', ['school_id' => $schoolId, 'admission_number' => $admissionNumber], [
                'user_id'        => $userId,
                'first_name'     => $first,
                'last_name'      => $last,
                'email'          => Str::slug($name) . '@demoschool.com',
                'gender'         => $i % 2 === 0 ? 'male' : 'female',
                'date_of_birth'  => now()->subYears(12)->subDays($i)->toDateString(),
                'admission_date' => now()->subYear()->toDateString(),
                'class_id'       => $classId,
                'arm_id'         => $armId,
                'status'         => 'active',
            ]);
        }

        $guardianUserId = $this->createUser('guardian@demoschool.com', 'Ifeoma Eze', 'guardian');
        $this->credentials[] = ['role' => 'guardian', 'email' => 'guardian@demoschool.com'];
        $guardianId = $this->upsert('guardians', ['school_id' => $schoolId, 'email' => 'ifeoma.eze@demoschool.com'], [
            'user_id'                  => $guardianUserId,
            'first_name'               => 'Ifeoma',
            'last_name'                => 'Eze',
            'relationship_to_student'  => 'Mother',
            'status'                   => 'active',
        ]);
        $this->upsert('guardian_students', ['guardian_id' => $guardianId, 'student_id' => $studentIds[0]], [
            'relationship' => 'Mother', 'is_primary' => true, 'emergency_contact' => true,
        ]);

        $parentUserId = $this->createUser('parent@demoschool.com', 'Ahmed Sule', 'parent');
        $this->credentials[] = ['role' => 'parent', 'email' => 'parent@demoschool.com'];
        $parentGuardianId = $this->upsert('guardians', ['school_id' => $schoolId, 'email' => 'ahmed.sule@demoschool.com'], [
            'user_id'                  => $parentUserId,
            'first_name'               => 'Ahmed',
            'last_name'                => 'Sule',
            'relationship_to_student'  => 'Father',
            'status'                   => 'active',
        ]);
        $this->upsert('guardian_students', ['guardian_id' => $parentGuardianId, 'student_id' => $studentIds[1]], [
            'relationship' => 'Father', 'is_primary' => true, 'emergency_contact' => true,
        ]);

        return [$studentUserId, $guardianUserId, $parentUserId, $studentIds];
    }

    // ── Attendance (last 10 days, students + teachers) ─────────────────────

    private function seedAttendance(array $studentIds, array $teacherIds, int $markedBy): void
    {
        $teacherProfileIds = array_values(array_filter($teacherIds, fn ($v, $k) => $k !== 'teacher_user', ARRAY_FILTER_USE_BOTH));

        for ($daysAgo = 0; $daysAgo < 10; $daysAgo++) {
            $date = now()->subDays($daysAgo)->toDateString();

            foreach ($studentIds as $i => $studentId) {
                $status = $daysAgo === 3 && $i === 4 ? 'absent' : ($daysAgo === 1 && $i === 2 ? 'late' : 'present');
                $this->upsert('attendances', [
                    'attendanceable_type' => 'App\\Models\\Student',
                    'attendanceable_id'   => $studentId,
                    'date'                => $date,
                ], ['status' => $status, 'marked_by' => $markedBy]);
            }

            foreach ($teacherProfileIds as $teacherId) {
                $this->upsert('attendances', [
                    'attendanceable_type' => 'App\\Models\\Teacher',
                    'attendanceable_id'   => $teacherId,
                    'date'                => $date,
                ], ['status' => 'present', 'marked_by' => $markedBy]);
            }
        }
    }

    // ── Finance: fee_structures, fees, payments, expenses, payrolls ────────

    private function seedFinance(int $schoolId, ?int $academicYearId, ?int $termId, array $studentIds, array $staffUserIds, int $adminUserId): void
    {
        $this->upsert('fee_structures', ['school_id' => $schoolId, 'name' => 'Tuition Fee'], [
            'fee_type' => 'tuition', 'amount' => 50000, 'academic_year_id' => $academicYearId,
            'term_id' => $termId, 'frequency' => 'termly', 'is_mandatory' => true, 'status' => 'active',
        ]);

        // amount_paid per student: mix of paid / partial / pending / overdue
        $paymentProfiles = [50000, 50000, 25000, 0, 50000, 30000, 0, 50000, 20000, 0];

        foreach ($studentIds as $i => $studentId) {
            $amount     = 50000;
            $amountPaid = $paymentProfiles[$i] ?? 0;
            $balance    = $amount - $amountPaid;
            $overdue    = $i === 9; // last student's fee is overdue
            $status     = $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : ($overdue ? 'overdue' : 'pending'));

            $feeId = $this->upsert('fees', ['school_id' => $schoolId, 'student_id' => $studentId, 'fee_type' => 'tuition', 'term_id' => $termId], [
                'academic_year_id' => $academicYearId,
                'amount'           => $amount,
                'amount_paid'      => $amountPaid,
                'balance'          => $balance,
                'due_date'         => $overdue ? now()->subDays(15)->toDateString() : now()->addDays(15)->toDateString(),
                'status'           => $status,
            ]);

            if ($amountPaid > 0) {
                $this->upsert('payments', ['school_id' => $schoolId, 'student_id' => $studentId, 'fee_id' => $feeId], [
                    'payment_reference' => 'PMT-DEMO-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'amount'            => $amountPaid,
                    'payment_method'    => $i % 2 === 0 ? 'bank_transfer' : 'cash',
                    'payment_date'      => now()->subDays($i + 1)->toDateString(),
                    'notes'             => 'Termly tuition payment',
                    'status'            => 'confirmed',
                    'received_by'       => $staffUserIds['accountant'] ?? $adminUserId,
                ]);
            }
        }

        $expenses = [
            ['Electricity bill', 45000, 'utilities'],
            ['Classroom furniture repair', 30000, 'maintenance'],
            ['Stationery supplies', 15000, 'supplies'],
        ];
        foreach ($expenses as $i => [$description, $amount, $category]) {
            $this->upsert('expenses', ['school_id' => $schoolId, 'description' => $description], [
                'amount'      => $amount,
                'category'    => $category,
                'date'        => now()->subDays(($i + 1) * 7)->toDateString(),
                'recorded_by' => $staffUserIds['accountant'] ?? $adminUserId,
                'status'      => 'approved',
            ]);
        }

        foreach (['teacher' => 180000, 'accountant' => 150000, 'librarian' => 120000] as $role => $basicSalary) {
            $userId = $staffUserIds[$role] ?? null;
            if (!$userId) {
                continue;
            }
            $this->upsert('payrolls', ['school_id' => $schoolId, 'staff_id' => $userId, 'month' => (int) now()->format('n'), 'year' => (int) now()->format('Y')], [
                'academic_year_id' => $academicYearId,
                'basic_salary'     => $basicSalary,
                'allowances'       => 10000,
                'deductions'       => 5000,
                'net_salary'       => $basicSalary + 10000 - 5000,
                'payment_date'     => now()->subDays(5)->toDateString(),
                'status'           => 'paid',
            ]);
        }
    }

    // ── Library ─────────────────────────────────────────────────────────────

    private function seedLibrary(int $schoolId, int $studentUserId, int $teacherUserId): void
    {
        $books = [
            ['Things Fall Apart', 'Chinua Achebe', 'Fiction'],
            ['Introduction to Algebra', 'K. Rees', 'Mathematics'],
            ['Basic Science for Junior Secondary', 'A. Bello', 'Science'],
            ['World History', 'M. Johnson', 'History'],
        ];
        $bookIds = [];
        foreach ($books as [$title, $author, $category]) {
            $bookIds[] = $this->upsert('library_books', ['school_id' => $schoolId, 'title' => $title], [
                'author' => $author, 'category' => $category, 'total_copies' => 3, 'available_copies' => 2, 'status' => 'active',
            ]);
        }

        $this->upsert('library_borrows', ['book_id' => $bookIds[0], 'borrower_type' => 'App\\Models\\User', 'borrower_id' => $studentUserId], [
            'borrowed_at' => now()->subDays(3)->toDateString(),
            'due_date'    => now()->addDays(11)->toDateString(),
            'status'      => 'borrowed',
        ]);

        $this->upsert('library_borrows', ['book_id' => $bookIds[1], 'borrower_type' => 'App\\Models\\User', 'borrower_id' => $teacherUserId], [
            'borrowed_at' => now()->subDays(20)->toDateString(),
            'due_date'    => now()->subDays(5)->toDateString(),
            'status'      => 'borrowed', // overdue
        ]);
    }

    // ── Transport ────────────────────────────────────────────────────────────

    private function seedTransport(int $schoolId, int $driverUserId, array $studentIds): void
    {
        $vehicleId = $this->upsert('vehicles', ['school_id' => $schoolId, 'plate_number' => 'DEMO-123-XY'], [
            'make' => 'Toyota', 'model' => 'Hiace', 'year' => 2020, 'capacity' => 18, 'type' => 'bus', 'status' => 'active',
        ]);

        $driverId = $this->upsert('drivers', ['school_id' => $schoolId, 'license_number' => 'DEMO-LIC-001'], [
            'user_id' => $driverUserId, 'name' => 'Ibrahim Musa', 'phone' => '+2348011111111', 'status' => 'active',
        ]);

        $routeId = $this->upsert('transport_routes', ['school_id' => $schoolId, 'route_code' => 'RT-01'], [
            'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'name' => 'Demo Route 1',
            'start_point' => 'School', 'end_point' => 'Demo Estate', 'fare' => 5000, 'status' => 'active',
        ]);

        foreach (array_slice($studentIds, 0, 2) as $studentId) {
            $this->upsert('student_transport_routes', ['student_id' => $studentId, 'route_id' => $routeId], [
                'pickup_stop' => 'Demo Estate Gate',
            ]);
        }

        $this->upsert('transport_trips', ['driver_id' => $driverId, 'route_id' => $routeId, 'trip_date' => now()->toDateString(), 'trip_type' => 'morning'], [
            'vehicle_id' => $vehicleId, 'status' => 'completed', 'students_count' => 2,
        ]);
    }

    // ── Hostel ───────────────────────────────────────────────────────────────

    private function seedHostel(int $schoolId, int $studentId): void
    {
        $roomId = $this->upsert('hostel_rooms', ['school_id' => $schoolId, 'room_number' => 'D1-101'], [
            'block' => 'Block D1', 'type' => 'double', 'capacity' => 2, 'occupied_count' => 1,
            'price_per_term' => 20000, 'status' => 'occupied',
        ]);

        $this->upsert('hostel_allocations', ['school_id' => $schoolId, 'room_id' => $roomId, 'student_id' => $studentId], [
            'allocated_at' => now()->subMonths(2)->toDateString(), 'status' => 'active',
            'amount_paid' => 20000, 'payment_status' => 'paid',
        ]);
    }

    // ── Health ───────────────────────────────────────────────────────────────

    private function seedHealth(int $schoolId, int $studentId): void
    {
        $this->upsert('health_records', ['school_id' => $schoolId, 'student_id' => $studentId], [
            'blood_group' => 'O+', 'height_cm' => 150.5, 'weight_kg' => 42.0,
            'last_checkup_date' => now()->toDateString(),
        ]);

        $this->upsert('health_appointments', ['school_id' => $schoolId, 'student_id' => $studentId, 'appointment_date' => now()->toDateString()], [
            'reason' => 'Routine checkup', 'status' => 'scheduled',
        ]);

        $this->upsert('medications', ['school_id' => $schoolId, 'student_id' => $studentId, 'name' => 'Vitamin C'], [
            'dosage' => '1 tablet', 'frequency' => 'daily',
            'start_date' => now()->subDays(5)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
        ]);
    }

    // ── Inventory ────────────────────────────────────────────────────────────

    private function seedInventory(int $schoolId, int $recordedBy): void
    {
        $categoryId = $this->upsert('inventory_categories', ['school_id' => $schoolId, 'name' => 'Stationery'], [
            'description' => 'Office and classroom supplies',
        ]);

        $itemId = $this->upsert('inventory_items', ['school_id' => $schoolId, 'category_id' => $categoryId, 'name' => 'A4 Paper Reams'], [
            'quantity' => 8, 'unit' => 'ream', 'min_quantity' => 10, 'unit_price' => 2500, 'status' => 'active',
        ]);

        $this->upsert('inventory_transactions', ['school_id' => $schoolId, 'item_id' => $itemId, 'type' => 'purchase'], [
            'quantity' => 20, 'remaining_quantity' => 8, 'recorded_by' => $recordedBy, 'status' => 'completed',
        ]);
    }

    // ── Security ─────────────────────────────────────────────────────────────

    private function seedSecurity(int $schoolId, int $checkedInBy): void
    {
        $this->upsert('visitors', ['name' => 'Demo Visitor', 'purpose' => 'Meeting with admin'], [
            'entry_time' => now(), 'checked_in_by' => $checkedInBy,
        ]);

        $this->upsert('gate_passes', ['pass_number' => 'GP-DEMO-001'], [
            'type' => 'student_exit', 'issued_to' => 'Chidi Eze', 'person_type' => 'student',
            'reason' => 'Medical appointment', 'valid_from' => now(), 'valid_until' => now()->addHours(4),
            'issued_by' => $checkedInBy,
        ]);

        $this->upsert('security_incidents', ['title' => 'Minor gate scuffle'], [
            'type' => 'other', 'description' => 'Minor disagreement at the gate, resolved on the spot.',
            'severity' => 'low', 'status' => 'resolved', 'reported_time' => now()->subDays(2),
            'reported_by' => $checkedInBy,
        ]);
    }

    // ── Announcements ────────────────────────────────────────────────────────

    private function seedAnnouncements(int $schoolId, int $createdBy): void
    {
        $this->upsert('announcements', ['school_id' => $schoolId, 'title' => 'Term Resumption Notice'], [
            'content'         => 'All parents and students should note that the new term resumes on the 8th.',
            'type'            => 'academic',
            'target_audience' => 'parents',
            'publish_date'    => now()->subDays(2),
            'is_published'    => true,
            'created_by'      => $createdBy,
        ]);

        $this->upsert('announcements', ['school_id' => $schoolId, 'title' => 'PTA Meeting Reminder'], [
            'content'         => 'The termly PTA meeting holds this Saturday at 10am in the main hall.',
            'type'            => 'event',
            'target_audience' => 'parents',
            'publish_date'    => now()->subDay(),
            'is_published'    => true,
            'created_by'      => $createdBy,
        ]);

        $this->upsert('announcements', ['school_id' => $schoolId, 'title' => 'Welcome back — check your messages'], [
            'content'         => 'Open Messages in the sidebar to contact your class teacher and classmates.',
            'type'            => 'general',
            'target_audience' => 'students',
            'publish_date'    => now(),
            'is_published'    => true,
            'created_by'      => $createdBy,
        ]);
    }

    private function seedEventsAndCalendar(int $schoolId, int $createdBy, int $classId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('events')) {
            return;
        }

        $this->upsert('events', ['school_id' => $schoolId, 'title' => 'Inter-House Sports Day'], [
            'description'       => 'Annual sports competition — all houses participate.',
            'event_type'      => 'sports',
            'start_date'      => now()->addDays(14)->toDateString(),
            'end_date'        => now()->addDays(14)->toDateString(),
            'location'        => 'Main field',
            'organizer'       => 'Sports department',
            'target_audience' => 'all',
            'class_id'        => null,
            'is_all_day'      => true,
            'status'          => 'scheduled',
            'created_by'      => $createdBy,
        ]);

        $this->upsert('events', ['school_id' => $schoolId, 'title' => 'PTA Open Day'], [
            'description'       => 'Parents visit classrooms and meet teachers.',
            'event_type'      => 'meeting',
            'start_date'      => now()->addDays(7)->toDateString(),
            'start_time'      => '10:00:00',
            'end_time'        => '14:00:00',
            'location'        => 'School hall',
            'target_audience' => 'parents',
            'class_id'        => $classId,
            'is_all_day'      => false,
            'status'          => 'scheduled',
            'created_by'      => $createdBy,
        ]);

        if (\Illuminate\Support\Facades\Schema::hasTable('calendars')) {
            $this->upsert('calendars', ['school_id' => $schoolId, 'title' => 'Mid-term break'], [
                'description' => 'School closed for mid-term break',
                'date'        => now()->addDays(21)->toDateString(),
                'end_date'    => now()->addDays(25)->toDateString(),
                'type'        => 'holiday',
                'color'       => '#4CAF50',
            ]);
        }
    }

    private function seedDemoLivestream(int $schoolId, int $teacherId, int $classId, int $createdBy): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('livestreams')) {
            return;
        }

        $subjectId = (int) (DB::table('subjects')->where('class_id', $classId)->value('id') ?? 0);
        if ($subjectId === 0) {
            return;
        }

        $start = now()->addHours(2);
        $payload = [
            'teacher_id'       => $teacherId,
            'class_id'         => $classId,
            'subject_id'       => $subjectId,
            'description'      => 'Sample session for testing Livestream / Mux or Meet links.',
            'meeting_link'     => 'https://meet.google.com/demo-compasse-live',
            'meeting_id'       => 'demo-compasse-live',
            'start_time'       => $start,
            'end_time'         => $start->copy()->addMinutes(45),
            'duration_minutes' => 45,
            'status'           => 'scheduled',
            'created_by'       => $createdBy,
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('livestreams', 'stream_provider')) {
            $payload['stream_provider'] = 'meet';
        }
        $this->upsert('livestreams', ['school_id' => $schoolId, 'title' => 'Demo Mathematics live class'], $payload);
    }

    private function seedDemoMessages(int $studentUserId, int $teacherUserId, array $studentIds, int $class1Id): void
    {
        if ($studentUserId <= 0 || $teacherUserId <= 0) {
            return;
        }

        $classmateUserId = (int) (DB::table('students')
            ->where('class_id', $class1Id)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $studentUserId)
            ->value('user_id') ?? 0);

        if ($classmateUserId === 0 && isset($studentIds[1])) {
            $classmateUserId = $this->createUser('amina-demo@demoschool.com', 'Amina Sule', 'student');
            DB::table('students')->where('id', $studentIds[1])->update(['user_id' => $classmateUserId]);
        }

        $threads = [
            [
                'sender_id'   => $teacherUserId,
                'receiver_id' => $studentUserId,
                'subject'     => 'Welcome to JSS 1',
                'body'        => 'Hi Chidi, welcome back! See me after assembly if you have questions about your timetable.',
                'is_read'     => false,
            ],
            [
                'sender_id'   => $studentUserId,
                'receiver_id' => $teacherUserId,
                'subject'     => 'Homework clarification',
                'body'        => 'Good afternoon sir, please could you confirm the Mathematics assignment for this week?',
                'is_read'     => true,
            ],
        ];

        if ($classmateUserId > 0) {
            $threads[] = [
                'sender_id'   => $classmateUserId,
                'receiver_id' => $studentUserId,
                'subject'     => 'Group study',
                'body'        => 'Hey! Are you free tomorrow after school to revise Basic Science together?',
                'is_read'     => false,
            ];
            $threads[] = [
                'sender_id'   => $studentUserId,
                'receiver_id' => $classmateUserId,
                'subject'     => 'Re: Group study',
                'body'        => 'Yes, same classroom at 2pm works for me.',
                'is_read'     => true,
            ];
        }

        foreach ($threads as $thread) {
            $exists = DB::table('messages')
                ->where('sender_id', $thread['sender_id'])
                ->where('receiver_id', $thread['receiver_id'])
                ->where('subject', $thread['subject'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('messages')->insert([
                'sender_id'   => $thread['sender_id'],
                'receiver_id' => $thread['receiver_id'],
                'subject'     => $thread['subject'],
                'body'        => $thread['body'],
                'type'        => 'direct',
                'is_read'     => $thread['is_read'],
                'read_at'     => $thread['is_read'] ? now()->subHours(2) : null,
                'created_at'  => now()->subDays(1),
                'updated_at'  => now()->subDays(1),
            ]);
        }
    }

    // ── Grading system + per-section result configuration ──────────────────
    // Nursery/Primary/Junior-Secondary each need a `result_configurations` row
    // (grade_style + display toggles) for the report-card PDF to render
    // correctly, and letter-graded sections need a default `grading_systems`
    // row. Neither is auto-created for tenants provisioned after the
    // "grading_and_assessment_tables_v2" migration originally seeded them
    // (that migration only back-filled schools that already existed then).

    private function seedGradingAndResultConfigs(int $schoolId): int
    {
        $gradingSystemId = $this->upsert('grading_systems', ['school_id' => $schoolId, 'is_default' => true], [
            'name'             => 'Standard Grading System',
            'description'      => 'Default grading system',
            'grade_boundaries' => json_encode([
                ['min' => 90, 'max' => 100, 'grade' => 'A', 'remark' => 'Excellent'],
                ['min' => 80, 'max' => 89,  'grade' => 'B', 'remark' => 'Very Good'],
                ['min' => 70, 'max' => 79,  'grade' => 'C', 'remark' => 'Good'],
                ['min' => 60, 'max' => 69,  'grade' => 'D', 'remark' => 'Fair'],
                ['min' => 50, 'max' => 59,  'grade' => 'E', 'remark' => 'Pass'],
                ['min' => 0,  'max' => 49,  'grade' => 'F', 'remark' => 'Fail'],
            ]),
            'pass_mark' => 50,
            'status'    => 'active',
        ]);

        foreach (['nursery', 'primary', 'junior_secondary'] as $sectionType) {
            $config = ResultConfiguration::defaultFor($sectionType, $schoolId);
            if ($sectionType !== 'nursery') {
                $config['grading_system_id'] = $gradingSystemId;
            }

            foreach (['assessment_components', 'remark_bands', 'comment_fields', 'custom_settings'] as $jsonField) {
                if (isset($config[$jsonField])) {
                    $config[$jsonField] = json_encode($config[$jsonField]);
                }
            }

            $uniqueBy = ['school_id' => $schoolId, 'section_type' => $sectionType];
            $values = collect($config)->except(array_keys($uniqueBy))->all();
            $this->upsert('result_configurations', $uniqueBy, $values);
        }

        return $gradingSystemId;
    }

    private function gradeFor(float $score): array
    {
        return match (true) {
            $score >= 90 => ['grade' => 'A', 'remark' => 'Excellent'],
            $score >= 80 => ['grade' => 'B', 'remark' => 'Very Good'],
            $score >= 70 => ['grade' => 'C', 'remark' => 'Good'],
            $score >= 60 => ['grade' => 'D', 'remark' => 'Fair'],
            $score >= 50 => ['grade' => 'E', 'remark' => 'Pass'],
            default      => ['grade' => 'F', 'remark' => 'Fail'],
        };
    }

    /**
     * Publish real, downloadable student_results + subject_results directly
     * (skipping the generate/approve/publish HTTP flow — ResultController has
     * no artisan-callable equivalent) for every secondary/primary class, plus
     * a placeholder result for nursery (nursery's real report lives in the
     * checkpoint system — see seedNurseryCheckpointReport()).
     *
     * Also seeds the underlying assessment records each subject_results row
     * is supposed to summarise — continuous_assessments/ca_scores (CA1+CA2
     * splitting ca_total), a written exam + exam_submissions (exam_score),
     * one assignment + graded submissions per class, a psychomotor_assessments
     * row per student, and a scoreboards row per class — so the CA/Exams/
     * Assignments/Psychomotor/Scoreboard screens all show real, consistent
     * data instead of just the final aggregated result.
     *
     * @param array<int, array{classId:int, studentIds:int[], subjectCodes:string[]}> $sections
     */
    private function seedResults(int $schoolId, ?int $termId, ?int $academicYearId, int $adminUserId, int $teacherId, array $sections, int $nurseryClassId, array $nurseryStudentIds): void
    {
        foreach ($sections as $section) {
            $subjectIds = DB::table('subjects')->where('school_id', $schoolId)->whereIn('code', $section['subjectCodes'])->pluck('id', 'code');

            // One CA1/CA2/written-exam trio per subject in this class (not per student).
            $caIdsBySubject = [];
            $examIdBySubject = [];
            foreach ($section['subjectCodes'] as $code) {
                $subjectId = $subjectIds[$code] ?? null;
                if (!$subjectId) {
                    continue;
                }
                $ca1 = $this->upsert('continuous_assessments', ['school_id' => $schoolId, 'subject_id' => $subjectId, 'class_id' => $section['classId'], 'term_id' => $termId, 'name' => '1st CA Test'], [
                    'academic_year_id' => $academicYearId, 'teacher_id' => $teacherId, 'type' => 'test',
                    'total_marks' => 20, 'assessment_date' => now()->subDays(20)->toDateString(), 'status' => 'completed',
                ]);
                $ca2 = $this->upsert('continuous_assessments', ['school_id' => $schoolId, 'subject_id' => $subjectId, 'class_id' => $section['classId'], 'term_id' => $termId, 'name' => '2nd CA Test'], [
                    'academic_year_id' => $academicYearId, 'teacher_id' => $teacherId, 'type' => 'test',
                    'total_marks' => 20, 'assessment_date' => now()->subDays(10)->toDateString(), 'status' => 'completed',
                ]);
                $caIdsBySubject[$code] = [$ca1, $ca2];

                $examIdBySubject[$code] = $this->upsert('exams', ['school_id' => $schoolId, 'subject_id' => $subjectId, 'class_id' => $section['classId'], 'name' => 'Term Exam'], [
                    'term_id' => $termId, 'academic_year_id' => $academicYearId,
                    'type' => 'exam', 'duration_minutes' => 60, 'total_marks' => 60, 'passing_marks' => 30,
                    'start_date' => now()->subDays(3), 'end_date' => now()->subDays(3)->addHour(),
                    'is_cbt' => false, 'status' => 'completed', 'created_by' => $teacherId,
                ]);
            }

            $studentAverages = [];
            $subjectScoresBySubject = array_fill_keys($section['subjectCodes'], []);

            foreach ($section['studentIds'] as $i => $studentId) {
                $subjectScores = [];
                foreach (array_values($section['subjectCodes']) as $j => $code) {
                    $caTotal = min(40, 20 + (($i * 3 + $j * 5) % 21));
                    $examScore = min(60, 30 + (($i * 7 + $j * 11) % 31));
                    $total = $caTotal + $examScore;
                    $subjectScores[$code] = ['ca' => $caTotal, 'exam' => $examScore, 'total' => $total];
                    $subjectScoresBySubject[$code][$studentId] = $total;

                    // CA1/CA2 scores summing to this student's ca_total for this subject.
                    if (isset($caIdsBySubject[$code])) {
                        $ca1Score = min(20, (int) ceil($caTotal / 2));
                        $ca2Score = $caTotal - $ca1Score;
                        $this->upsert('ca_scores', ['continuous_assessment_id' => $caIdsBySubject[$code][0], 'student_id' => $studentId], ['score' => $ca1Score, 'recorded_by' => $teacherId]);
                        $this->upsert('ca_scores', ['continuous_assessment_id' => $caIdsBySubject[$code][1], 'student_id' => $studentId], ['score' => $ca2Score, 'recorded_by' => $teacherId]);
                    }
                    if (isset($examIdBySubject[$code])) {
                        $this->upsert('exam_submissions', ['exam_id' => $examIdBySubject[$code], 'student_id' => $studentId], [
                            'score' => $examScore, 'remarks' => $this->gradeFor($examScore)['remark'], 'recorded_by' => $teacherId,
                        ]);
                    }
                }
                $average = array_sum(array_column($subjectScores, 'total')) / count($subjectScores);
                $studentAverages[$studentId] = ['average' => $average, 'subjects' => $subjectScores];

                // Psychomotor ratings (1-5) — one per student per term.
                $this->upsert('psychomotor_assessments', ['student_id' => $studentId, 'term_id' => $termId, 'academic_year_id' => $academicYearId], [
                    'assessed_by' => $teacherId,
                    'handwriting' => 3 + ($i % 3), 'drawing' => 3 + (($i + 1) % 3), 'sports' => 4,
                    'musical_skills' => 3 + ($i % 2), 'handling_tools' => 4,
                    'punctuality' => 4, 'neatness' => 4, 'politeness' => 5, 'honesty' => 5,
                    'relationship_with_others' => 4, 'self_control' => 3 + ($i % 2), 'attentiveness' => 4,
                    'perseverance' => 4, 'emotional_stability' => 4,
                    'teacher_comment' => 'Shows good social skills and works well both independently and in groups.',
                ]);
            }

            // Rank within this class by average score.
            $ranked = $studentAverages;
            uasort($ranked, fn ($a, $b) => $b['average'] <=> $a['average']);
            $positions = array_flip(array_keys($ranked));
            $classAverage = array_sum(array_column($studentAverages, 'average')) / max(count($studentAverages), 1);

            $rankings = [];
            foreach ($studentAverages as $studentId => $data) {
                $grade = $this->gradeFor($data['average']);
                $resultId = $this->upsert('student_results', [
                    'student_id' => $studentId, 'term_id' => $termId, 'academic_year_id' => $academicYearId, 'result_type' => 'end_term',
                ], [
                    'class_id'               => $section['classId'],
                    'total_score'            => array_sum(array_column($data['subjects'], 'total')),
                    'average_score'          => round($data['average'], 2),
                    'grade'                  => $grade['grade'],
                    'position'               => $positions[$studentId] + 1,
                    'out_of'                 => count($studentAverages),
                    'class_average'          => round($classAverage, 2),
                    'class_teacher_comment'  => "{$grade['remark']}. Keep up the good work.",
                    'principal_comment'      => 'A commendable performance this term.',
                    'status'                 => 'published',
                    'approved_at'            => now()->subDay(),
                    'approved_by'            => $adminUserId,
                ]);

                foreach ($data['subjects'] as $code => $scores) {
                    $subjectId = $subjectIds[$code] ?? null;
                    if (!$subjectId) {
                        continue;
                    }
                    $subjGrade = $this->gradeFor($scores['total']);
                    $subjScores = $subjectScoresBySubject[$code];
                    $this->upsert('subject_results', ['student_result_id' => $resultId, 'subject_id' => $subjectId], [
                        'ca_total'      => $scores['ca'],
                        'exam_score'    => $scores['exam'],
                        'total_score'   => $scores['total'],
                        'grade'         => $subjGrade['grade'],
                        'teacher_remark'=> $subjGrade['remark'],
                        'highest_score' => max($subjScores),
                        'lowest_score'  => min($subjScores),
                        'class_average' => round(array_sum($subjScores) / count($subjScores), 2),
                    ]);
                }

                $student = DB::table('students')->where('id', $studentId)->first();
                $rankings[] = [
                    'position'         => $positions[$studentId] + 1,
                    'student_id'       => $studentId,
                    'student_name'     => trim("{$student->first_name} {$student->last_name}"),
                    'admission_number' => $student->admission_number,
                    'average_score'    => round($data['average'], 2),
                    'total_score'      => round(array_sum(array_column($data['subjects'], 'total')), 2),
                    'grade'            => $grade['grade'],
                ];
            }
            usort($rankings, fn ($a, $b) => $a['position'] <=> $b['position']);

            $this->upsert('scoreboards', ['class_id' => $section['classId'], 'term_id' => $termId, 'academic_year_id' => $academicYearId], [
                'rankings'      => json_encode($rankings),
                'class_average' => round($classAverage, 2),
                'total_students'=> count($studentAverages),
                'pass_rate'     => count($studentAverages) > 0
                                     ? (int) round(count(array_filter($studentAverages, fn ($d) => $d['average'] >= 50)) / count($studentAverages) * 100)
                                     : 0,
                'last_updated'  => now(),
            ]);

            // One assignment for this class, graded for every student.
            $firstSubjectCode = $section['subjectCodes'][0];
            $assignmentId = $this->upsert('assignments', ['class_id' => $section['classId'], 'title' => 'Homework 1'], [
                'description'   => 'Complete the exercises covered in class this week.',
                'subject_id'    => $subjectIds[$firstSubjectCode] ?? null,
                'term_id'       => $termId, 'academic_year_id' => $academicYearId,
                'teacher_id'    => $teacherId, 'due_date' => now()->subDays(5)->toDateString(),
                'total_marks'   => 20, 'assignment_type' => 'homework', 'submission_type' => 'text',
                'status'        => 'published',
            ]);
            foreach ($section['studentIds'] as $i => $studentId) {
                $marks = 12 + ($i % 8);
                $this->upsert('assignment_submissions', ['assignment_id' => $assignmentId, 'student_id' => $studentId], [
                    'submission_text' => 'Submitted homework answers.',
                    'submitted_at'    => now()->subDays(4),
                    'is_late'         => false,
                    'status'          => 'graded',
                    'marks_obtained'  => $marks,
                    'grade'           => $this->gradeFor(($marks / 20) * 100)['grade'],
                    'feedback'        => 'Good effort, well done.',
                    'graded_at'       => now()->subDays(2),
                    'graded_by'       => $adminUserId,
                ]);
            }
        }

        // Nursery: placeholder student_results row (nursery's real report is the
        // checkpoint system — see seedNurseryCheckpointReport()); comments-only
        // per ResultConfiguration::isCommentsOnly(), so no subject scores here.
        foreach ($nurseryStudentIds as $studentId) {
            $this->upsert('student_results', [
                'student_id' => $studentId, 'term_id' => $termId, 'academic_year_id' => $academicYearId, 'result_type' => 'end_term',
            ], [
                'class_id'              => $nurseryClassId,
                'total_score'           => 0,
                'average_score'         => 0,
                'class_teacher_comment' => 'Settling in well and making good progress with numbers and letters.',
                'principal_comment'     => 'A happy, engaged learner.',
                'status'                => 'published',
                'approved_at'           => now()->subDay(),
                'approved_by'           => $adminUserId,
            ]);
        }
    }

    // ── Nursery checkpoint report (domains → strands → indicators × CP1/CP2/CP3) ─
    // This is the "can't be scored in marks" pattern for Nursery/KG: switches the
    // nursery result_configurations row to report_template='checkpoint' and builds
    // the full domain tree + per-student ratings, vitals, and comments that
    // CheckpointReportController::studentReport() reads. Viewed via the frontend's
    // "Checkpoint Reports" page, not the standard report-card PDF route.

    private function seedNurseryCheckpointReport(int $schoolId, ?int $termId, ?int $academicYearId, array $nurseryStudentIds): void
    {
        DB::table('result_configurations')->where('school_id', $schoolId)->where('section_type', 'nursery')->update([
            'report_template' => 'checkpoint', 'updated_at' => now(),
        ]);
        $configId = DB::table('result_configurations')->where('school_id', $schoolId)->where('section_type', 'nursery')->value('id');

        $checkpointIds = [];
        foreach ([['CP1', 'Autumn Term', 1], ['CP2', 'Spring Term', 2], ['CP3', 'Summer Term', 3]] as [$label, $name, $order]) {
            $checkpointIds[$label] = $this->upsert('result_checkpoints', ['result_configuration_id' => $configId, 'label' => $label], [
                'name' => $name, 'display_order' => $order,
            ]);
        }

        $domains = [
            ['Communication and Language', '#7c3aed', [
                ['Listening and Attention', ['Listens attentively in a range of situations', 'Follows two-step instructions', 'Responds with relevant comments and questions']],
                ['Speaking', ['Expresses ideas using full sentences', 'Uses new vocabulary in different contexts']],
            ]],
            ['Physical Development', '#059669', [
                ['Gross Motor Skills', ['Runs, jumps and climbs with confidence', 'Shows good balance when moving']],
                ['Fine Motor Skills', ['Holds a pencil effectively', 'Uses scissors safely']],
            ]],
            ['Personal, Social and Emotional Development', '#d97706', [
                ['Self-Regulation', ['Manages own feelings appropriately', 'Waits for turn patiently']],
                ['Building Relationships', ['Plays cooperatively with peers', 'Shows kindness to others']],
            ]],
            ['Literacy', '#dc2626', [
                ['Reading', ['Enjoys sharing books with an adult', 'Recognises own name in print']],
                ['Writing', ['Makes marks and attempts to write letters', 'Writes some recognisable letters']],
            ]],
            ['Mathematics', '#2563eb', [
                ['Number', ['Counts to 10 reliably', 'Recognises numerals 1-5']],
                ['Shape, Space and Measure', ['Names basic 2D shapes', 'Compares the size of objects']],
            ]],
            ['Understanding the World', '#0891b2', [
                ['The World', ['Talks about own family and community', 'Shows curiosity about the natural world']],
            ]],
            ['Expressive Arts and Design', '#c026d3', [
                ['Creating with Materials', ['Explores colour and texture', 'Engages in pretend play']],
            ]],
        ];

        $indicatorIds = [];
        foreach ($domains as $dOrder => [$domainName, $color, $strands]) {
            $domainId = $this->upsert('result_domains', ['result_configuration_id' => $configId, 'name' => $domainName], [
                'color' => $color, 'display_order' => $dOrder,
            ]);
            foreach ($strands as $sOrder => [$strandName, $indicators]) {
                $strandId = $this->upsert('result_strands', ['result_domain_id' => $domainId, 'name' => $strandName], [
                    'display_order' => $sOrder,
                ]);
                foreach ($indicators as $iOrder => $indicatorName) {
                    $indicatorIds[] = $this->upsert('result_indicators', ['result_strand_id' => $strandId, 'name' => $indicatorName], [
                        'display_order' => $iOrder,
                    ]);
                }
            }
        }

        // Comment-only domains (no strands/indicators) — free-text per student.
        $commentTemplates = [
            'Music'                        => '%s enjoys music sessions and is beginning to join in with songs and rhymes confidently.',
            'French'                       => '%s is picking up simple greetings and colour words in French and enjoys repeating new words.',
            "Class Teacher's Comment"      => '%s has settled in well this term and is making steady progress across all areas of learning.',
            'General Comment'              => '%s is a happy, curious learner who engages well with both adults and peers.',
        ];
        $commentDomainIds = [];
        $order = count($domains);
        foreach (array_keys($commentTemplates) as $name) {
            $commentDomainIds[$name] = $this->upsert('result_domains', ['result_configuration_id' => $configId, 'name' => $name], [
                'color' => '#6b7280', 'display_order' => $order++,
            ]);
        }

        $grades = ['B', 'E', 'P', 'C'];
        foreach ($nurseryStudentIds as $i => $studentId) {
            $student = DB::table('students')->where('id', $studentId)->first();
            $firstName = $student->first_name;

            foreach ($indicatorIds as $j => $indicatorId) {
                $this->upsert('student_indicator_grades', [
                    'student_id' => $studentId, 'result_indicator_id' => $indicatorId,
                    'result_checkpoint_id' => $checkpointIds['CP1'], 'academic_year_id' => $academicYearId,
                ], [
                    'term_id' => $termId,
                    'grade'   => $grades[($i + $j) % count($grades)],
                ]);
            }

            $this->upsert('student_term_vitals', ['student_id' => $studentId, 'academic_year_id' => $academicYearId, 'term_id' => $termId], [
                'days_school_opened' => 60, 'days_attended' => 58 - $i,
                'height_beginning'   => 95.0 + $i, 'height_end' => 97.0 + $i,
                'weight_beginning'   => 14.0 + $i * 0.5, 'weight_end' => 14.5 + $i * 0.5,
                'homework_rating'    => 'Good', 'punctuality_rating' => 'Always',
            ]);

            foreach ($commentTemplates as $domainName => $template) {
                $this->upsert('student_domain_comments', [
                    'student_id' => $studentId, 'result_domain_id' => $commentDomainIds[$domainName],
                    'academic_year_id' => $academicYearId, 'term_id' => $termId,
                ], [
                    'comment'      => sprintf($template, $firstName),
                    'teacher_name' => 'Mrs. Grace Adeyemi',
                ]);
            }
        }
    }

    // ── CBT exam (one demo exam with questions + completed attempts) ────────

    private function seedCbtExam(int $schoolId, int $classId, ?int $termId, ?int $academicYearId, int $teacherId, array $studentIds): void
    {
        $subjectId = DB::table('subjects')->where('school_id', $schoolId)->where('code', 'MTH101')->value('id');
        if (!$subjectId) {
            return;
        }

        $examId = $this->upsert('exams', ['school_id' => $schoolId, 'subject_id' => $subjectId, 'name' => 'Mathematics CBT Quiz'], [
            'class_id' => $classId, 'term_id' => $termId, 'academic_year_id' => $academicYearId,
            'type' => 'quiz', 'duration_minutes' => 20, 'total_marks' => 5, 'passing_marks' => 3,
            'start_date' => now()->subDays(7), 'end_date' => now()->subDays(7)->addMinutes(20),
            'is_cbt' => true, 'status' => 'active', 'created_by' => $teacherId,
        ]);

        $questions = [
            ['What is 7 + 5?', ['9', '11', '12', '13'], '12'],
            ['What is 9 x 3?', ['24', '27', '28', '18'], '27'],
            ['What is 15 - 6?', ['9', '8', '10', '7'], '9'],
            ['What is half of 20?', ['5', '10', '15', '12'], '10'],
            ['Which number is a multiple of 5?', ['22', '27', '30', '33'], '30'],
        ];
        $questionIds = [];
        foreach ($questions as [$text, $options, $correct]) {
            $questionIds[] = $this->upsert('questions', ['exam_id' => $examId, 'question_text' => $text], [
                'subject_id' => $subjectId, 'question_type' => 'multiple_choice', 'difficulty_level' => 'easy',
                'marks' => 1, 'options' => json_encode($options), 'correct_answer' => json_encode($correct),
                'status' => 'active',
            ]);
        }

        foreach (array_slice($studentIds, 0, 3) as $i => $studentId) {
            $attemptId = $this->upsert('exam_attempts', ['exam_id' => $examId, 'student_id' => $studentId], [
                'started_at' => now()->subDays(7), 'completed_at' => now()->subDays(7)->addMinutes(15),
                'status' => 'completed', 'time_taken_minutes' => 15,
            ]);

            $correctCount = 3 + ($i % 3); // 3, 4, or 5 correct out of 5
            $totalScore = 0;
            foreach ($questionIds as $qi => $questionId) {
                $isCorrect = $qi < $correctCount;
                $totalScore += $isCorrect ? 1 : 0;
                $answerGiven = $questions[$qi][2]; // seed the correct answer for simplicity when marked correct
                $this->upsert('answers', ['exam_attempt_id' => $attemptId, 'question_id' => $questionId], [
                    'student_id' => $studentId, 'answer_text' => $isCorrect ? $answerGiven : $questions[$qi][1][0],
                    'is_correct' => $isCorrect, 'marks_obtained' => $isCorrect ? 1 : 0, 'time_taken_seconds' => 60,
                ]);
                $this->upsert('question_attempts', ['exam_attempt_id' => $attemptId, 'question_id' => $questionId], [
                    'student_id' => $studentId, 'is_correct' => $isCorrect, 'time_taken' => 60,
                ]);
            }

            DB::table('exam_attempts')->where('id', $attemptId)->update([
                'total_score' => $totalScore,
                'percentage'  => round($totalScore / count($questionIds) * 100, 2),
            ]);
        }
    }

    private function reportSampleResults(?int $termId, ?int $academicYearId, array $nurseryStudentIds, array $primaryStudentIds, array $secondaryStudentIds): void
    {
        $this->newLine();
        $this->comment('Sample published results (downloadable by ANY logged-in demo user — the report-card route is not role-restricted):');

        $samples = [
            ['Nursery', $nurseryStudentIds[0]],
            ['Primary', $primaryStudentIds[0]],
            ['Secondary (JSS)', $secondaryStudentIds[0]],
        ];

        $rows = array_map(fn ($s) => [
            $s[0],
            $s[1],
            "GET /api/v1/assessments/report-cards/{$s[1]}/{$termId}/{$academicYearId}/pdf",
        ], $samples);

        $this->table(['Section', 'Student ID', 'Report-card PDF endpoint'], $rows);
        $this->comment('(term_id=' . $termId . ', academic_year_id=' . $academicYearId . ' for this run — re-seeding may change these)');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function printCredentials(): void
    {
        $this->newLine();
        $this->info('Demo tenant ready — subdomain: ' . self::SUBDOMAIN);
        $this->info('Login page: https://' . self::SUBDOMAIN . '.{ROOT_DOMAIN}/login  (local: VITE_DEV_SUBDOMAIN=demoschool)');
        $this->newLine();

        $rows = array_map(fn ($c) => [
            $c['role'],
            $c['email'],
            self::PASSWORD,
            self::ROLE_LANDING[$c['role']] ?? '/school/dashboard',
        ], $this->credentials);

        $this->table(['Role', 'Email', 'Password', 'Lands on'], $rows);

        $this->newLine();
        $this->comment('Super admin (platform-wide, not tenant-specific) — already seeded separately:');
        $this->comment('  superadmin@compasse.net / Nigeria@60  at  /compasse_super');
    }
}
