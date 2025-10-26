<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'phone',
        'role',
        'status',
        'profile_picture',
        'last_login_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Get the tenant that owns the user
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the teacher profile for this user
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * Get the student profile for this user
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * Get all notifications for this user
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get all activities for this user
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if user is school admin
     */
    public function isSchoolAdmin(): bool
    {
        return $this->role === 'school_admin';
    }

    /**
     * Check if user is teacher
     */
    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    /**
     * Check if user is student
     */
    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /**
     * Check if user is parent
     */
    public function isParent(): bool
    {
        return $this->role === 'parent';
    }

    /**
     * Get user's profile (teacher or student)
     */
    public function getProfile()
    {
        if ($this->isTeacher()) {
            return $this->teacher;
        }

        if ($this->isStudent()) {
            return $this->student;
        }

        return null;
    }

    /**
     * Get user's full name
     */
    public function getFullNameAttribute(): string
    {
        return $this->name;
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the roles for this user
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
                    ->withPivot('assigned_by', 'assigned_at')
                    ->withTimestamps();
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('slug', $role)->exists();
    }

    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('slug', $roles)->exists();
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        return $this->roles()->whereHas('permissions', function ($query) use ($permission) {
            $query->where('name', $permission);
        })->exists();
    }

    /**
     * Check if user has any of the given permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return $this->roles()->whereHas('permissions', function ($query) use ($permissions) {
            $query->whereIn('name', $permissions);
        })->exists();
    }

    /**
     * Check if user has all of the given permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        $userPermissions = $this->getAllPermissions();
        return empty(array_diff($permissions, $userPermissions));
    }

    /**
     * Get all permissions for this user
     */
    public function getAllPermissions(): array
    {
        return $this->roles()
                   ->with('permissions')
                   ->get()
                   ->pluck('permissions')
                   ->flatten()
                   ->pluck('name')
                   ->unique()
                   ->toArray();
    }

    /**
     * Assign role to user
     */
    public function assignRole(string $roleSlug, int $assignedBy = null): void
    {
        $role = Role::where('slug', $roleSlug)->first();

        if ($role && !$this->hasRole($roleSlug)) {
            $this->roles()->attach($role->id, [
                'assigned_by' => $assignedBy ?? auth()->id(),
                'assigned_at' => now(),
            ]);
        }
    }

    /**
     * Remove role from user
     */
    public function removeRole(string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->first();

        if ($role) {
            $this->roles()->detach($role->id);
        }
    }

    /**
     * Sync user roles
     */
    public function syncRoles(array $roleSlugs, int $assignedBy = null): void
    {
        $roleIds = Role::whereIn('slug', $roleSlugs)->pluck('id');

        $this->roles()->syncWithPivotValues($roleIds, [
            'assigned_by' => $assignedBy ?? auth()->id(),
            'assigned_at' => now(),
        ]);
    }
}
