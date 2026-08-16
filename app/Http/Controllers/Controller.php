<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\School;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
}
