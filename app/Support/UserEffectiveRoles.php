<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve all portal roles for a user (primary column + pivot slugs + inferred teacher roles).
 */
class UserEffectiveRoles
{
    /** @return list<string> */
    public static function forUser(User $user): array
    {
        $roles = array_filter([(string) ($user->role ?? '')]);

        try {
            $roles = array_merge($roles, $user->roles()->pluck('slug')->toArray());
        } catch (\Throwable $e) {
            // tenant context — no central user_roles
        }

        if (Schema::hasTable('teachers')) {
            $teacher = DB::table('teachers')->where('user_id', $user->id)->first();
            if ($teacher) {
                $tid = (int) $teacher->id;
                $teacherRoleSlugs = ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'];
                foreach ($teacherRoleSlugs as $slug) {
                    if (in_array($slug, $roles, true) || ($user->role ?? '') === $slug) {
                        $roles[] = 'teacher';
                        break;
                    }
                }
                if (($user->role ?? '') === 'teacher' || in_array('teacher', $roles, true)) {
                    $roles[] = 'teacher';
                }
                // classes.class_teacher_id is assigned at the year-group
                // level (a "class" here is JSS1/SS1/etc, not a specific
                // arm/stream) — that's what "Year Tutor" means: oversight
                // of a whole year group across every one of its arms. The
                // arm-specific "form teacher" (class_arm.class_teacher_id,
                // below) is the actual "Class Teacher". Both were
                // previously inferred as 'class_teacher' from this same
                // classes.class_teacher_id column, which meant a year
                // tutor never actually showed up labeled as one anywhere —
                // Controller::accessibleStudentIds() already scopes a
                // classes.class_teacher_id assignment to every student in
                // that class across all its arms (TEACHER_ROLES treats
                // class_teacher/year_tutor/etc identically for scoping), so
                // this is a labeling fix, not a new access rule.
                if (Schema::hasTable('classes') &&
                    DB::table('classes')->where('class_teacher_id', $tid)->exists()) {
                    $roles[] = 'year_tutor';
                }
                if (Schema::hasTable('class_arm') &&
                    DB::table('class_arm')->where('class_teacher_id', $tid)->exists()) {
                    $roles[] = 'class_teacher';
                }
                if (! empty($teacher->department_id) &&
                    in_array($user->role ?? '', ['hod', 'teacher', 'class_teacher'], true)) {
                    if (($user->role ?? '') === 'hod') {
                        $roles[] = 'hod';
                    }
                }
                if (Schema::hasTable('houses') &&
                    DB::table('houses')->where('house_master_id', $tid)->exists()) {
                    $roles[] = 'housemaster';
                }
            }
        }

        $normalized = [];
        foreach ($roles as $r) {
            $r = strtolower(trim((string) $r));
            if ($r !== '') {
                $normalized[$r] = true;
            }
        }

        return array_keys($normalized);
    }

    public static function appendToUserJson(User $user): User
    {
        $user->setAttribute('roles', self::forUser($user));

        return $user;
    }
}
