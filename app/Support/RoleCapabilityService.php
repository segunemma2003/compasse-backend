<?php

namespace App\Support;

use App\Models\User;
use App\Support\UserEffectiveRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School-configurable role capabilities (stored in settings.role_capabilities JSON).
 */
class RoleCapabilityService
{
    /** @var array<string, string> */
    public const CAPABILITIES = [
        'student.read'    => 'View student roster (scoped by role)',
        'student.create'  => 'Create / enroll students',
        'student.delete'  => 'Delete students',
        'academic.manage' => 'Manage academic years, classes, departments, subjects',
        'finance.manage'  => 'Manage fees and finance',
        'user.manage'     => 'Manage school users and roles',
        'result.manage'   => 'Generate and publish results',
        'timetable.manage'=> 'Edit school-wide timetable',
        'attendance.manage'=> 'Mark and edit attendance',
        'security.gate'   => 'Gate desk student lookup (search only)',
    ];

    private const LEADERSHIP = ['school_admin', 'admin', 'principal', 'vice_principal'];

    /** @var array<string, array<string, bool>> */
    private const DEFAULTS = [
        'school_admin'    => ['*' => true],
        'admin'           => ['*' => true],
        'principal'       => ['*' => true],
        'vice_principal'  => ['*' => true],
        'hod'             => [
            'student.read' => true, 'result.manage' => true, 'attendance.manage' => true,
        ],
        'year_tutor'      => [
            'student.read' => true, 'result.manage' => true, 'attendance.manage' => true,
        ],
        'class_teacher'   => [
            'student.read' => true, 'result.manage' => true, 'attendance.manage' => true,
        ],
        'subject_teacher' => [
            'student.read' => true, 'result.manage' => true, 'attendance.manage' => true,
        ],
        'teacher'         => [
            'student.read' => true, 'result.manage' => true, 'attendance.manage' => true,
        ],
        'accountant'      => [
            'student.read' => true, 'finance.manage' => true,
        ],
        'librarian'       => ['student.read' => true],
        'nurse'           => ['student.read' => true],
        'housemaster'     => ['student.read' => true, 'attendance.manage' => true],
        'security'        => ['security.gate' => true],
        'student'         => [],
        'guardian'        => [],
        'parent'          => [],
        'staff'           => ['attendance.manage' => true],
        'driver'          => [],
        'caterer'         => [],
        'cleaner'         => [],
    ];

    public static function userCan(User $user, string $capability, ?int $schoolId = null): bool
    {
        if (! isset(self::CAPABILITIES[$capability])) {
            return false;
        }

        $roles = UserEffectiveRoles::forUser($user);
        $matrix = self::matrixForSchool($schoolId);

        foreach ($roles as $role) {
            if (self::roleHas($role, $capability, $matrix)) {
                return true;
            }
        }

        return self::roleHas($user->role, $capability, $matrix);
    }

    /** @return array{capabilities: array<string, string>, roles: array<string, string>, matrix: array<string, array<string, bool>>} */
    public static function payloadForSchool(?int $schoolId): array
    {
        $matrix = self::matrixForSchool($schoolId);

        return [
            'capabilities' => self::CAPABILITIES,
            'roles'        => self::editableRoles(),
            'matrix'       => $matrix,
        ];
    }

    /** @param array<string, array<string, bool>> $matrix */
    public static function saveForSchool(int $schoolId, array $matrix): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $clean = [];
        foreach ($matrix as $role => $caps) {
            if (! is_array($caps)) {
                continue;
            }
            $clean[$role] = [];
            foreach ($caps as $cap => $enabled) {
                if (isset(self::CAPABILITIES[$cap])) {
                    $clean[$role][$cap] = (bool) $enabled;
                }
            }
        }

        DB::table('settings')->updateOrInsert(
            ['school_id' => $schoolId, 'key' => 'role_capabilities'],
            [
                'value'      => json_encode($clean),
                'type'       => 'json',
                'category'   => 'security',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /** @return array<string, array<string, bool>> */
    public static function matrixForSchool(?int $schoolId): array
    {
        $base = self::buildDefaultMatrix();
        if (! $schoolId || ! Schema::hasTable('settings')) {
            return $base;
        }

        $raw = DB::table('settings')
            ->where('school_id', $schoolId)
            ->where('key', 'role_capabilities')
            ->value('value');

        if (! $raw) {
            return $base;
        }

        $overrides = json_decode($raw, true);
        if (! is_array($overrides)) {
            return $base;
        }

        foreach ($overrides as $role => $caps) {
            if (! is_array($caps)) {
                continue;
            }
            if (! isset($base[$role])) {
                $base[$role] = array_fill_keys(array_keys(self::CAPABILITIES), false);
            }
            foreach ($caps as $cap => $enabled) {
                if (isset(self::CAPABILITIES[$cap])) {
                    $base[$role][$cap] = (bool) $enabled;
                }
            }
        }

        return $base;
    }

    /** @return array<string, string> */
    private static function editableRoles(): array
    {
        return [
            'class_teacher'   => 'Class Teacher',
            'subject_teacher' => 'Subject Teacher',
            'teacher'         => 'Teacher',
            'hod'             => 'HOD',
            'year_tutor'      => 'Year Tutor',
            'accountant'      => 'Accountant',
            'librarian'       => 'Librarian',
            'nurse'           => 'Nurse',
            'housemaster'     => 'Housemaster',
            'security'        => 'Security',
            'staff'           => 'Staff',
            'driver'          => 'Driver',
            'caterer'         => 'Caterer',
            'cleaner'         => 'Cleaner',
        ];
    }

    /** @return array<string, array<string, bool>> */
    private static function buildDefaultMatrix(): array
    {
        $allCaps = array_fill_keys(array_keys(self::CAPABILITIES), false);
        $matrix  = [];

        foreach (self::editableRoles() as $slug => $_label) {
            $matrix[$slug] = $allCaps;
            $defaults = self::DEFAULTS[$slug] ?? [];
            if (isset($defaults['*'])) {
                foreach ($matrix[$slug] as $cap => $_) {
                    $matrix[$slug][$cap] = true;
                }
            } else {
                foreach ($defaults as $cap => $enabled) {
                    if ($cap !== '*' && isset($matrix[$slug][$cap])) {
                        $matrix[$slug][$cap] = (bool) $enabled;
                    }
                }
            }
        }

        return $matrix;
    }

    /** @param array<string, array<string, bool>> $matrix */
    private static function roleHas(string $role, string $capability, array $matrix): bool
    {
        $role = strtolower(trim($role));
        if (in_array($role, self::LEADERSHIP, true)) {
            return true;
        }

        if ($role === 'security' && $capability === 'student.read') {
            return false;
        }

        $defaults = self::DEFAULTS[$role] ?? [];
        if (isset($defaults['*'])) {
            return true;
        }

        if (isset($matrix[$role][$capability])) {
            return $matrix[$role][$capability];
        }

        return (bool) ($defaults[$capability] ?? false);
    }
}
