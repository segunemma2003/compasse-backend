<?php

namespace App\Support;

use App\Models\CustomPermission;
use App\Models\User;
use App\Support\UserEffectiveRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * School-configurable role capabilities (stored in settings.role_capabilities JSON).
 */
class RoleCapabilityService
{
    /**
     * Built-in capabilities — these are the ones an actual route/controller checks
     * (via CapabilityMiddleware or requireCapability()). Schools can additionally
     * define their own custom permission slugs (see CustomPermission) for their
     * own workflows; those are assignable the same way but aren't wired to a
     * specific code gate unless a developer adds one.
     *
     * @var array<string, string>
     */
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
        'attendance.self_clock_in' => 'Clock themselves in/out for their own attendance',
        'security.gate'   => 'Gate desk student lookup (search only)',
        // Added alongside making 'admin' a dial-able sub-admin tier (see
        // LEADERSHIP below) — each covers the write actions of one
        // operational module that previously had no capability gate at all,
        // only the coarse role: middleware.
        'security.manage'   => 'Manage security/gate logs and incidents',
        'hostel.manage'     => 'Manage hostel rooms, allocations, and maintenance',
        'inventory.manage'  => 'Manage inventory items, categories, and stock',
        'health.manage'     => 'Manage health records and appointments',
        'transport.manage'  => 'Manage transport routes, trips, and drivers',
        'library.manage'    => 'Manage the library catalogue and loans',
        'staff.manage'      => 'Manage non-teaching staff records',
        'settings.manage'   => 'Edit school settings and integrations',
    ];

    // school_admin is the one tier that can never be dialed down — every
    // other role, including 'admin' (the configurable sub-admin tier), is
    // governed by the matrix below and defaults to full access (DEFAULTS'
    // '*' => true) until a school_admin explicitly restricts it in Role
    // Access. Removing 'admin' from this list is the whole mechanism behind
    // "sub-admin": nothing else about how 'admin' behaves changes until a
    // school actually unchecks a box.
    private const LEADERSHIP = ['school_admin', 'principal', 'vice_principal'];

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
        'librarian'       => ['student.read' => true, 'library.manage' => true, 'inventory.manage' => true],
        'nurse'           => ['student.read' => true, 'health.manage' => true, 'inventory.manage' => true],
        'housemaster'     => ['student.read' => true, 'attendance.manage' => true, 'hostel.manage' => true],
        'security'        => ['security.gate' => true, 'security.manage' => true, 'transport.manage' => true],
        'student'         => [],
        'guardian'        => [],
        'parent'          => [],
        'staff'           => ['attendance.manage' => true],
        'driver'          => ['transport.manage' => true, 'inventory.manage' => true],
        'caterer'         => ['health.manage' => true, 'inventory.manage' => true],
        'cleaner'         => ['hostel.manage' => true, 'inventory.manage' => true],
    ];

    public static function userCan(User $user, string $capability, ?int $schoolId = null): bool
    {
        if (! isset(self::allCapabilities($schoolId)[$capability])) {
            return false;
        }

        // An explicit per-user grant/revoke always wins — including over the
        // leadership-always-true shortcut, so a specific principal's access can
        // still be pulled if a school admin deliberately overrides it.
        $override = self::userOverride($user->id, $capability, $schoolId);
        if ($override !== null) {
            return $override;
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

    // -------------------------------------------------------------------------
    // Custom permissions — school-defined slugs beyond the built-in set, so an
    // admin isn't limited to the capabilities a developer thought to predefine.
    // -------------------------------------------------------------------------

    /** Built-in + this school's custom permissions, merged: slug => description. */
    public static function allCapabilities(?int $schoolId): array
    {
        $all = self::CAPABILITIES;

        if ($schoolId && Schema::hasTable('custom_permissions')) {
            foreach (CustomPermission::where('school_id', $schoolId)->get() as $perm) {
                $all[$perm->slug] = $perm->description ?: $perm->name;
            }
        }

        return $all;
    }

    public static function createCustomPermission(int $schoolId, string $name, ?string $description, ?int $createdBy): CustomPermission
    {
        $base = Str::slug($name, '.') ?: Str::slug(Str::random(8), '.');
        $slug = $base;
        $suffix = 1;

        // Never shadow a built-in capability, and keep slugs unique per school.
        while (
            isset(self::CAPABILITIES[$slug])
            || CustomPermission::where('school_id', $schoolId)->where('slug', $slug)->exists()
        ) {
            $slug = $base . '-' . (++$suffix);
        }

        return CustomPermission::create([
            'school_id'   => $schoolId,
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'created_by'  => $createdBy,
        ]);
    }

    public static function deleteCustomPermission(int $schoolId, string $slug): bool
    {
        return (bool) CustomPermission::where('school_id', $schoolId)->where('slug', $slug)->delete();
    }

    // -------------------------------------------------------------------------
    // Per-user overrides — grant or revoke individual capabilities for one
    // teacher/user beyond (or instead of) what their role normally allows.
    // Stored the same way as the role matrix: settings.user_capability_overrides,
    // keyed by user id, JSON-encoded {capability: bool}. Absence of a key means
    // "inherit from role".
    // -------------------------------------------------------------------------

    public static function userOverride(int $userId, string $capability, ?int $schoolId): ?bool
    {
        if (! $schoolId) {
            return null;
        }

        $forUser = self::userOverridesForSchool($schoolId)[(string) $userId] ?? null;
        if (! is_array($forUser) || ! array_key_exists($capability, $forUser)) {
            return null;
        }

        return (bool) $forUser[$capability];
    }

    /** @return array<string, array<string, bool>> keyed by user id (as string) */
    public static function userOverridesForSchool(int $schoolId): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        $raw = DB::table('settings')
            ->where('school_id', $schoolId)
            ->where('key', 'user_capability_overrides')
            ->value('value');

        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Overrides for one user, with every known capability present — true/false
     * for an explicit override, null meaning "inherit from role".
     *
     * @return array<string, bool|null>
     */
    public static function overridesForUser(int $schoolId, int $userId): array
    {
        $forUser = self::userOverridesForSchool($schoolId)[(string) $userId] ?? [];
        $result  = [];

        foreach (self::allCapabilities($schoolId) as $cap => $_label) {
            $result[$cap] = is_array($forUser) && array_key_exists($cap, $forUser)
                ? (bool) $forUser[$cap]
                : null;
        }

        return $result;
    }

    /** @param array<string, bool|null> $overrides capability => true|false|null (null clears the override) */
    public static function saveUserOverrides(int $schoolId, int $userId, array $overrides): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $all = self::userOverridesForSchool($schoolId);
        $known = self::allCapabilities($schoolId);

        $clean = [];
        foreach ($overrides as $cap => $value) {
            if (! isset($known[$cap]) || $value === null) {
                continue;
            }
            $clean[$cap] = (bool) $value;
        }

        if (empty($clean)) {
            unset($all[(string) $userId]);
        } else {
            $all[(string) $userId] = $clean;
        }

        DB::table('settings')->updateOrInsert(
            ['school_id' => $schoolId, 'key' => 'user_capability_overrides'],
            [
                'value'      => json_encode($all),
                'type'       => 'json',
                'category'   => 'security',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /** @return array{capabilities: array<string, string>, roles: array<string, string>, matrix: array<string, array<string, bool>>} */
    public static function payloadForSchool(?int $schoolId): array
    {
        $matrix = self::matrixForSchool($schoolId);

        return [
            'capabilities' => self::allCapabilities($schoolId),
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

        $known = self::allCapabilities($schoolId);
        $clean = [];
        foreach ($matrix as $role => $caps) {
            if (! is_array($caps)) {
                continue;
            }
            $clean[$role] = [];
            foreach ($caps as $cap => $enabled) {
                if (isset($known[$cap])) {
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
        $known = self::allCapabilities($schoolId);
        $base = self::buildDefaultMatrix($known);
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
                $base[$role] = array_fill_keys(array_keys($known), false);
            }
            foreach ($caps as $cap => $enabled) {
                if (isset($known[$cap])) {
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
            // The configurable sub-admin tier (see LEADERSHIP above) — full
            // access by default, restrictable per school like any other role.
            'admin'           => 'Admin (sub-admin)',
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

    /**
     * @param array<string, string> $knownCapabilities built-in + this school's custom permissions
     * @return array<string, array<string, bool>>
     */
    private static function buildDefaultMatrix(array $knownCapabilities): array
    {
        $allCaps = array_fill_keys(array_keys($knownCapabilities), false);
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

        // Deliberately NOT short-circuiting on DEFAULTS[$role]['*'] here
        // (unlike buildDefaultMatrix() below, which does use it — to seed
        // a role's starting matrix values). 'admin' has '*' => true in
        // DEFAULTS same as the LEADERSHIP roles, but unlike them it's
        // reachable here (see the "not LEADERSHIP" comment above): if this
        // returned true on '*' alone, an actual matrix restriction on
        // 'admin' would never take effect at all, defeating the entire
        // point of it being the dial-able sub-admin tier. The matrix -
        // seeded to all-true by buildDefaultMatrix() until a school_admin
        // changes it - is the only thing consulted below.
        if (isset($matrix[$role][$capability])) {
            return $matrix[$role][$capability];
        }

        $defaults = self::DEFAULTS[$role] ?? [];

        return (bool) ($defaults[$capability] ?? false);
    }
}
