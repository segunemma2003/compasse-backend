<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedPermissions();
        $this->seedRoles();
        $this->assignRolesToUsers();
    }

    /**
     * Seed core permissions.
     */
    protected function seedPermissions(): void
    {
        $systemPermissions = Permission::getSystemPermissions();

        foreach ($systemPermissions as $slug => $description) {
            [$module, $action] = $this->splitSlug($slug);

            Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => Str::title(str_replace(['.', '_'], ' ', $slug)),
                    'description' => $description,
                    'module' => $module,
                    'action' => $action,
                    'resource' => $module,
                    'is_system_permission' => true,
                ]
            );
        }
    }

    /**
     * Seed core roles with default permissions.
     */
    protected function seedRoles(): void
    {
        $defaultRoles = [
            'super_admin' => [
                'name' => 'Super Admin',
                'level' => 100,
                'description' => 'Full access to the entire platform',
                'permissions' => array_keys(Permission::getSystemPermissions()),
            ],
            'school_admin' => [
                'name' => 'School Admin',
                'level' => 90,
                'description' => 'Manages school level operations',
            ],
            'principal' => [
                'name' => 'Principal',
                'level' => 80,
                'description' => 'Oversees academic operations',
            ],
            'teacher' => [
                'name' => 'Teacher',
                'level' => 50,
                'description' => 'Handles classroom activities',
            ],
            'student' => [
                'name' => 'Student',
                'level' => 20,
                'description' => 'Student access',
            ],
        ];

        foreach ($defaultRoles as $slug => $data) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'level' => $data['level'],
                    'is_system_role' => true,
                    'permissions' => $data['permissions'] ?? null,
                    'status' => 'active',
                ]
            );

            if (!empty($data['permissions'])) {
                $permissionIds = Permission::whereIn('slug', $data['permissions'])->pluck('id')->toArray();
                $role->permissions()->sync($permissionIds);
            }
        }
    }

    /**
     * Assign core roles to existing users.
     */
    protected function assignRolesToUsers(): void
    {
        $superAdminRole = Role::where('slug', 'super_admin')->first();

        if (!$superAdminRole) {
            return;
        }

        $superAdminUsers = User::where('role', 'super_admin')->orWhere('email', 'superadmin@compasse.net')->get();

        foreach ($superAdminUsers as $user) {
            if (!$user->roles()->where('role_id', $superAdminRole->id)->exists()) {
                $user->roles()->attach($superAdminRole->id, [
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                ]);
            }
        }
    }

    /**
     * Split permission slug into module and action.
     */
    protected function splitSlug(string $slug): array
    {
        $parts = explode('.', $slug);

        return [
            Arr::get($parts, 0),
            Arr::get($parts, 1),
        ];
    }
}

