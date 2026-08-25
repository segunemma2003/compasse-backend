<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Exam;
use App\Models\School;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\RoleCapabilityService;
use App\Support\UserEffectiveRoles;

abstract class Controller
{
    /**
     * Get school from request
     */
    protected function getSchoolFromRequest(Request $request): ?School
    {
        // Try to get from request attributes (set by middleware)
        $school = $request->attributes->get('school');
        if ($school instanceof School) {
            return $school;
        }

        // If we're in a tenant context, get the school from the tenant database
        if (tenancy()->initialized) {
            try {
                // In tenant database, there's typically one school per tenant
                $school = School::first();
                if ($school) {
                    // Cache it in request attributes for subsequent calls
                    $request->attributes->set('school', $school);
                    return $school;
                }
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
            }
        }

        // Try to get from tenant
        $tenant = $request->attributes->get('tenant');
        if ($tenant instanceof Tenant) {
            try {
                // In tenant database, there's typically one school
                $school = School::first();
                if ($school) {
                    $request->attributes->set('school', $school);
                    return $school;
                }
            } catch (\Exception $e) {
                // Table doesn't exist or query failed
            }
        }

        // Try to get from school_id parameter or header
        $schoolId = $request->get('school_id') ?? $request->header('X-School-ID');
        if ($schoolId) {
            try {
                $school = School::find($schoolId);
                if ($school) {
                    $request->attributes->set('school', $school);
                    return $school;
                }
            } catch (\Exception $e) {
                // Table doesn't exist
            }
        }

        return null;
    }

    /**
     * Get school ID from tenant context
     * No need for school_id in request when X-Subdomain is provided
     */
    protected function getSchoolIdFromTenant(Request $request): ?int
    {
        $school = $this->getSchoolFromRequest($request);
        return $school ? $school->id : null;
    }

    /**
     * Safe database operation - handles missing tables
     */
    protected function safeDbOperation(callable $operation, $default = null)
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            return $default;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Row-level scope helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Admin roles that may see all records within their school. */
    private const ADMIN_ROLES = [
        'super_admin', 'school_admin', 'principal', 'vice_principal', 'admin',
    ];

    /** Teaching-staff roles whose access is limited to their assigned classes. */
    private const TEACHER_ROLES = [
        'teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod',
    ];

    /**
     * Operational / specialist roles that may view the whole school directory
     * (students list, health, library, transport, finance lookups).
     */
    private const SCHOOL_WIDE_ROLES = [
        'accountant', 'librarian', 'nurse', 'driver',
        'housemaster', 'staff', 'caterer', 'cleaner',
    ];

    /**
     * Return the set of class IDs the given user may access.
     *
     * - Admin roles  → null  (no restriction; caller should skip the filter)
     * - Teacher roles→ array of class IDs from both class-teacher and subject assignments
     * - Everything else (student, parent…) → empty array (caller handles separately)
     *
     * @return int[]|null
     */
    protected function accessibleClassIds(User $user): ?array
    {
        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return null;
        }

        $effective = UserEffectiveRoles::forUser($user);
        if (array_intersect($effective, self::SCHOOL_WIDE_ROLES) !== []) {
            return null;
        }
        if (in_array($user->role, self::SCHOOL_WIDE_ROLES, true)) {
            return null;
        }

        if (in_array($user->role, self::TEACHER_ROLES, true) ||
            array_intersect($effective, self::TEACHER_ROLES) !== []) {
            $teacher = $user->teacher;
            if (!$teacher) {
                return [];
            }

            // Classes where the teacher is the assigned class teacher
            $asClassTeacher = DB::table('classes')
                ->where('class_teacher_id', $teacher->id)
                ->pluck('id')
                ->toArray();

            // Classes the teacher is assigned to via subject assignments (class_id set directly)
            $viaSubjects = DB::table('teacher_subjects')
                ->where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->whereNotNull('class_id')
                ->pluck('class_id')
                ->toArray();

            // Subject assignments with no class_id pinned on the assignment itself
            // fall back to the subject's own class_id (subjects are class-scoped).
            $viaUnpinnedSubjects = DB::table('teacher_subjects')
                ->join('subjects', 'subjects.id', '=', 'teacher_subjects.subject_id')
                ->where('teacher_subjects.teacher_id', $teacher->id)
                ->where('teacher_subjects.status', 'active')
                ->whereNull('teacher_subjects.class_id')
                ->whereNotNull('subjects.class_id')
                ->pluck('subjects.class_id')
                ->toArray();

            return array_values(array_unique(array_merge($asClassTeacher, $viaSubjects, $viaUnpinnedSubjects)));
        }

        // student, parent, guardian, etc. → handled per-caller
        return [];
    }

    /**
     * Classes where $user is literally the (whole-class or arm-level) class
     * teacher — unlike accessibleClassIds(), this deliberately excludes
     * "classes I merely teach a subject in". Use this for listing endpoints
     * (exams/CAs/assignments/question bank) where showing every subject's
     * resources for a class the caller only teaches ONE subject in would leak
     * other teachers' data — accessibleClassIds() is correct for roster/
     * attendance-style "which classes touch me" checks, not for that.
     *
     * @return int[]
     */
    protected function classTeacherOnlyClassIds(User $user): array
    {
        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return [];
        }

        $teacher = $user->teacher;
        if (! $teacher) {
            return [];
        }

        $ids = DB::table('classes')->where('class_teacher_id', $teacher->id)->pluck('id')->all();

        if (Schema::hasTable('class_arm')) {
            $ids = array_merge($ids, DB::table('class_arm')->where('class_teacher_id', $teacher->id)->pluck('class_id')->all());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Subject IDs a teacher may view (assigned subjects + subjects in classes they lead).
     *
     * @return int[]|null null = no restriction (admin / school-wide roles)
     */
    protected function accessibleSubjectIds(User $user): ?array
    {
        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return null;
        }

        $effective = UserEffectiveRoles::forUser($user);
        if (array_intersect($effective, self::SCHOOL_WIDE_ROLES) !== [] || in_array($user->role, self::SCHOOL_WIDE_ROLES, true)) {
            return null;
        }

        if (! in_array($user->role, self::TEACHER_ROLES, true) &&
            array_intersect($effective, self::TEACHER_ROLES) === []) {
            return $this->studentOrGuardianSubjectIds($user);
        }

        $teacher = $user->teacher;
        if (! $teacher) {
            return [];
        }

        $ids = collect(
            DB::table('teacher_subjects')
                ->where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->pluck('subject_id')
        );

        $classIds = DB::table('classes')
            ->where('class_teacher_id', $teacher->id)
            ->pluck('id');

        if ($classIds->isNotEmpty()) {
            $ids = $ids->merge(
                DB::table('subjects')->whereIn('class_id', $classIds)->pluck('id')
            );
        }

        return array_values(array_unique($ids->filter()->map(fn ($id) => (int) $id)->all()));
    }

    /**
     * Subjects visible to a student (their own class's subjects, plus any
     * optional subjects they're individually enrolled in) or a guardian
     * (the union across all their children's classes). Everything else
     * (security, unlinked accounts) correctly falls through to an empty list.
     *
     * @return int[]
     */
    private function studentOrGuardianSubjectIds(User $user): array
    {
        $studentIds = [];
        $classIds   = collect();

        $ownId = $this->ownStudentId($user);
        if ($ownId !== null) {
            $studentIds = [$ownId];
            $classId = DB::table('students')->where('id', $ownId)->value('class_id');
            if ($classId) {
                $classIds->push($classId);
            }
        } else {
            $guardianIds = $this->accessibleStudentIdsForGuardian($user);
            if (! empty($guardianIds)) {
                $studentIds = $guardianIds;
                $classIds = collect(DB::table('students')->whereIn('id', $guardianIds)->pluck('class_id'))->filter();
            }
        }

        if ($classIds->isEmpty() && empty($studentIds)) {
            return [];
        }

        $ids = $classIds->isNotEmpty()
            ? collect(DB::table('subjects')->whereIn('class_id', $classIds->unique())->pluck('id'))
            : collect();

        if (! empty($studentIds) && Schema::hasTable('student_subjects')) {
            $ids = $ids->merge(
                DB::table('student_subjects')
                    ->whereIn('student_id', $studentIds)
                    ->where('status', 'active')
                    ->pluck('subject_id')
            );
        }

        return array_values(array_unique($ids->filter()->map(fn ($id) => (int) $id)->all()));
    }

    /**
     * Student IDs a teacher may access. Admins / school-wide roles → null (no filter).
     *
     * - Class teachers → students in their class (whole class) or class+arm (arm teacher)
     * - Subject teachers → students enrolled in their subjects (student_subjects);
     *   mandatory subjects with no enrollments yet fall back to the subject's class roster
     *
     * @return int[]|null
     */
    protected function accessibleStudentIds(User $user): ?array
    {
        if (in_array($user->role, self::ADMIN_ROLES, true)) {
            return null;
        }

        $effective = UserEffectiveRoles::forUser($user);
        if (array_intersect($effective, self::SCHOOL_WIDE_ROLES) !== [] || in_array($user->role, self::SCHOOL_WIDE_ROLES, true)) {
            return null;
        }

        if (! in_array($user->role, self::TEACHER_ROLES, true) &&
            array_intersect($effective, self::TEACHER_ROLES) === []) {
            return [];
        }

        $teacher = $user->teacher;
        if (! $teacher) {
            return [];
        }

        $studentIds = collect();

        // Whole-class class teacher
        $classTeacherClassIds = DB::table('classes')
            ->where('class_teacher_id', $teacher->id)
            ->pluck('id');

        if ($classTeacherClassIds->isNotEmpty()) {
            $studentIds = $studentIds->merge(
                DB::table('students')->whereIn('class_id', $classTeacherClassIds)->pluck('id')
            );
        }

        // Arm-level class teacher (class_arm pivot)
        if (Schema::hasTable('class_arm')) {
            $armRows = DB::table('class_arm')
                ->where('class_teacher_id', $teacher->id)
                ->get(['class_id', 'arm_id']);

            foreach ($armRows as $row) {
                $q = DB::table('students')->where('class_id', $row->class_id);
                if ($row->arm_id) {
                    $q->where('arm_id', $row->arm_id);
                }
                $studentIds = $studentIds->merge($q->pluck('id'));
            }
        }

        // Subject-teacher roster via enrollment
        $subjectIds = DB::table('teacher_subjects')
            ->where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->pluck('subject_id');

        if ($subjectIds->isNotEmpty() && Schema::hasTable('student_subjects')) {
            $studentIds = $studentIds->merge(
                DB::table('student_subjects')
                    ->whereIn('subject_id', $subjectIds)
                    ->where('status', 'active')
                    ->pluck('student_id')
            );

            // Mandatory subjects with no pivot rows yet → whole class for that subject
            $mandatory = DB::table('subjects')
                ->whereIn('id', $subjectIds)
                ->where(function ($q) {
                    $q->where('is_optional', false)->orWhereNull('is_optional');
                })
                ->whereNotNull('class_id')
                ->get(['id', 'class_id']);

            foreach ($mandatory as $subject) {
                $hasEnrollment = DB::table('student_subjects')
                    ->where('subject_id', $subject->id)
                    ->exists();

                if (! $hasEnrollment) {
                    $studentIds = $studentIds->merge(
                        DB::table('students')->where('class_id', $subject->class_id)->pluck('id')
                    );
                }
            }
        }

        return array_values(array_unique($studentIds->map(fn ($id) => (int) $id)->all()));
    }

    /**
     * The actual teacher responsible for a student's class — arm-level class
     * teacher takes priority over the class-wide one, same precedence as
     * accessibleClassIds(). Used to resolve which teacher's own signature
     * belongs on a given student's report card.
     */
    protected function resolveClassTeacherId(?Student $student): ?int
    {
        if (! $student) {
            return null;
        }

        if ($student->arm_id && Schema::hasTable('class_arm')) {
            $armTeacherId = DB::table('class_arm')
                ->where('class_id', $student->class_id)
                ->where('arm_id', $student->arm_id)
                ->value('class_teacher_id');
            if ($armTeacherId) {
                return (int) $armTeacherId;
            }
        }

        return $student->class?->class_teacher_id;
    }

    /**
     * Whether $user may view/manage a given exam — its subject/questions/
     * attempts/scores. Route-level `role:` middleware only checks that the
     * caller has *some* teacher-tier role; it can't know whether this
     * specific exam belongs to a subject or class they actually teach.
     * Without this, any teacher could view, edit, or delete any other
     * teacher's exam, and see its scores/attempts.
     */
    protected function assertCanManageExam(User $user, Exam $exam): ?\Illuminate\Http\JsonResponse
    {
        return $this->assertCanManageSubjectResource($user, $exam->subject_id, $exam->class_id, 'exam');
    }

    /**
     * Generic version of assertCanManageExam() for any per-subject/per-class
     * teacher resource (exams, assignments, …): denies unless the caller is
     * admin-tier, teaches the resource's subject, or is the class teacher
     * for its class.
     */
    protected function assertCanManageSubjectResource(User $user, ?int $subjectId, ?int $classId, string $label = 'resource'): ?\Illuminate\Http\JsonResponse
    {
        if ($this->accessibleStudentIds($user) === null) {
            return null;
        }

        $teacher = $user->teacher;
        if (! $teacher) {
            return $this->forbiddenResponse('No teacher profile linked to your account.');
        }

        $teachesSubject = $subjectId && DB::table('teacher_subjects')
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $subjectId)
            ->where('status', 'active')
            ->exists();

        $isClassTeacher = $classId && DB::table('classes')
            ->where('id', $classId)
            ->where('class_teacher_id', $teacher->id)
            ->exists();

        // Arm-level class teacher (class_arm pivot) — same precedence rule as
        // accessibleStudentIds()/resolveClassTeacherId(). Without this, an
        // arm-level class teacher (e.g. "JSS1A") is wrongly denied.
        if (! $isClassTeacher && $classId && Schema::hasTable('class_arm')) {
            $isClassTeacher = DB::table('class_arm')
                ->where('class_id', $classId)
                ->where('class_teacher_id', $teacher->id)
                ->exists();
        }

        if (! $teachesSubject && ! $isClassTeacher) {
            return $this->forbiddenResponse("You are not assigned to this {$label}'s subject or class.");
        }

        return null;
    }

    /** Whether $studentId is visible to $user under teacher/guardian scoping rules. */
    protected function studentWithinScope(User $user, int $studentId): bool
    {
        $ownId = $this->ownStudentId($user);
        if ($ownId !== null) {
            return $ownId === $studentId;
        }

        $guardianIds = $this->accessibleStudentIdsForGuardian($user);
        if ($guardianIds !== null) {
            return in_array($studentId, $guardianIds, true);
        }

        $allowed = $this->accessibleStudentIds($user);
        if ($allowed === null) {
            return true;
        }

        return in_array($studentId, $allowed, true);
    }

    /**
     * Whether $classId is visible to $user: their own class (student), a
     * ward's class (guardian), an assigned class (teacher), or unrestricted
     * (admin/school-wide roles).
     */
    protected function classWithinScope(User $user, int $classId): bool
    {
        $ownId = $this->ownStudentId($user);
        if ($ownId !== null) {
            return (int) Student::where('id', $ownId)->value('class_id') === $classId;
        }

        $guardianIds = $this->accessibleStudentIdsForGuardian($user);
        if ($guardianIds !== null) {
            return Student::whereIn('id', $guardianIds)->where('class_id', $classId)->exists();
        }

        $allowed = $this->accessibleClassIds($user);
        if ($allowed === null) {
            return true;
        }

        return in_array($classId, $allowed, true);
    }

    /** Gate/security desk — search-only student access, not school-wide roster. */
    protected function isSecurityGateRole(User $user): bool
    {
        if ($user->role === 'security') {
            return true;
        }
        return in_array('security', UserEffectiveRoles::forUser($user), true);
    }

    /**
     * If $user is a student, return their Student.id; otherwise null.
     */
    protected function ownStudentId(User $user): ?int
    {
        if ($user->role !== 'student') {
            return null;
        }
        return $user->student?->id;
    }

    /**
     * If $user is a guardian/parent, return the student IDs of their own
     * children (via guardian_students — same lookup DashboardController::parent()
     * uses); otherwise null so the caller falls through to admin/teacher scoping.
     *
     * @return int[]|null
     */
    protected function accessibleStudentIdsForGuardian(User $user): ?array
    {
        if (!in_array($user->role, ['guardian', 'parent'], true)) {
            return null;
        }

        $guardianId = DB::table('guardians')->where('user_id', $user->id)->value('id');
        if (!$guardianId) {
            return [];
        }

        return DB::table('guardian_students')->where('guardian_id', $guardianId)->pluck('student_id')->toArray();
    }

    /**
     * Abort with 403 JSON when a user tries to access data outside their scope.
     */
    protected function forbiddenResponse(string $message = 'Access denied.'): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => 'Forbidden', 'message' => $message], 403);
    }

    protected function roleCan(Request $request, string $capability): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        return RoleCapabilityService::userCan(
            $user,
            $capability,
            $this->getSchoolIdFromTenant($request)
        );
    }

    protected function requireCapability(Request $request, string $capability): ?\Illuminate\Http\JsonResponse
    {
        if ($this->roleCan($request, $capability)) {
            return null;
        }

        return $this->forbiddenResponse('You do not have permission for this action.');
    }
}
