<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'slug',
        'description',
        'level',
        'is_system_role',
        'permissions',
        'status',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system_role' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id')
                    ->withPivot('assigned_by', 'assigned_at')
                    ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    /**
     * Check if role has permission
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    /**
     * Check if role has any of the given permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return !empty(array_intersect($permissions, $this->permissions ?? []));
    }

    /**
     * Check if role has all of the given permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->permissions ?? []));
    }

    /**
     * Get role level hierarchy
     */
    public function getLevelHierarchy(): array
    {
        return [
            'super_admin' => 100,
            'school_admin' => 90,
            'principal' => 80,
            'vice_principal' => 70,
            'head_of_department' => 60,
            'teacher' => 50,
            'librarian' => 40,
            'accountant' => 40,
            'nurse' => 40,
            'driver' => 30,
            'guard' => 30,
            'student' => 20,
            'guardian' => 10,
        ];
    }

    /**
     * Check if role can manage another role
     */
    public function canManageRole(Role $otherRole): bool
    {
        $hierarchy = $this->getLevelHierarchy();
        $thisLevel = $hierarchy[$this->slug] ?? 0;
        $otherLevel = $hierarchy[$otherRole->slug] ?? 0;

        return $thisLevel > $otherLevel;
    }

    /**
     * Get default permissions for role
     */
    public function getDefaultPermissions(): array
    {
        $defaultPermissions = [
            'super_admin' => [
                'tenant.create', 'tenant.read', 'tenant.update', 'tenant.delete',
                'school.create', 'school.read', 'school.update', 'school.delete',
                'user.create', 'user.read', 'user.update', 'user.delete',
                'role.create', 'role.read', 'role.update', 'role.delete',
                'permission.read', 'subscription.manage', 'system.settings'
            ],
            'school_admin' => [
                'school.read', 'school.update',
                'user.create', 'user.read', 'user.update', 'user.delete',
                'student.create', 'student.read', 'student.update', 'student.delete',
                'teacher.create', 'teacher.read', 'teacher.update', 'teacher.delete',
                'class.create', 'class.read', 'class.update', 'class.delete',
                'subject.create', 'subject.read', 'subject.update', 'subject.delete',
                'exam.create', 'exam.read', 'exam.update', 'exam.delete',
                'result.create', 'result.read', 'result.update', 'result.delete',
                'attendance.read', 'attendance.update',
                'report.read', 'subscription.read'
            ],
            'principal' => [
                'school.read', 'school.update',
                'user.read', 'user.update',
                'student.read', 'student.update',
                'teacher.read', 'teacher.update',
                'class.read', 'class.update',
                'subject.read', 'subject.update',
                'exam.read', 'exam.update',
                'result.read', 'result.update',
                'attendance.read', 'attendance.update',
                'report.read'
            ],
            'vice_principal' => [
                'school.read',
                'user.read',
                'student.read', 'student.update',
                'teacher.read', 'teacher.update',
                'class.read', 'class.update',
                'subject.read', 'subject.update',
                'exam.read', 'exam.update',
                'result.read', 'result.update',
                'attendance.read', 'attendance.update',
                'report.read'
            ],
            'head_of_department' => [
                'user.read',
                'student.read',
                'teacher.read',
                'class.read',
                'subject.read', 'subject.update',
                'exam.read', 'exam.update',
                'result.read', 'result.update',
                'attendance.read',
                'report.read'
            ],
            'teacher' => [
                'student.read',
                'class.read',
                'subject.read',
                'exam.create', 'exam.read', 'exam.update',
                'result.create', 'result.read', 'result.update',
                'attendance.read', 'attendance.update',
                'assignment.create', 'assignment.read', 'assignment.update',
                'livestream.create', 'livestream.read', 'livestream.update'
            ],
            'student' => [
                'student.read',
                'class.read',
                'subject.read',
                'exam.read',
                'result.read',
                'assignment.read',
                'livestream.read'
            ],
            'guardian' => [
                'student.read',
                'result.read',
                'attendance.read',
                'payment.read', 'payment.create'
            ],
            'librarian' => [
                'library.read', 'library.create', 'library.update', 'library.delete',
                'book.read', 'book.create', 'book.update', 'book.delete'
            ],
            'accountant' => [
                'fee.read', 'fee.create', 'fee.update', 'fee.delete',
                'payment.read', 'payment.create', 'payment.update',
                'expense.read', 'expense.create', 'expense.update', 'expense.delete',
                'payroll.read', 'payroll.create', 'payroll.update',
                'report.read'
            ],
            'nurse' => [
                'health.read', 'health.create', 'health.update',
                'student.read',
                'medication.read', 'medication.create', 'medication.update'
            ],
            'driver' => [
                'transport.read',
                'student.read'
            ],
            'guard' => [
                'attendance.read', 'attendance.update',
                'student.read'
            ]
        ];

        return $defaultPermissions[$this->slug] ?? [];
    }
}
