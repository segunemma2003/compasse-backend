<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\School;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

        if (in_array($user->role, self::TEACHER_ROLES, true)) {
            $teacher = $user->teacher;
            if (!$teacher) {
                return [];
            }

            // Classes where the teacher is the assigned class teacher
            $asClassTeacher = DB::table('classes')
                ->where('class_teacher_id', $teacher->id)
                ->pluck('id')
                ->toArray();

            // Classes the teacher is assigned to via subject assignments
            $viaSubjects = DB::table('teacher_subjects')
                ->where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->whereNotNull('class_id')
                ->pluck('class_id')
                ->toArray();

            return array_values(array_unique(array_merge($asClassTeacher, $viaSubjects)));
        }

        // student, parent, guardian, etc. → handled per-caller
        return [];
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
     * Abort with 403 JSON when a user tries to access data outside their scope.
     */
    protected function forbiddenResponse(string $message = 'Access denied.'): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => 'Forbidden', 'message' => $message], 403);
    }
}
