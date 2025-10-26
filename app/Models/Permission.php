<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'module',
        'action',
        'resource',
        'is_system_permission',
    ];

    protected $casts = [
        'is_system_permission' => 'boolean',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id');
    }

    /**
     * Get all system permissions
     */
    public static function getSystemPermissions(): array
    {
        return [
            // Tenant Management
            'tenant.create' => 'Create new tenants',
            'tenant.read' => 'View tenant information',
            'tenant.update' => 'Update tenant information',
            'tenant.delete' => 'Delete tenants',

            // School Management
            'school.create' => 'Create new schools',
            'school.read' => 'View school information',
            'school.update' => 'Update school information',
            'school.delete' => 'Delete schools',

            // User Management
            'user.create' => 'Create new users',
            'user.read' => 'View user information',
            'user.update' => 'Update user information',
            'user.delete' => 'Delete users',

            // Role Management
            'role.create' => 'Create new roles',
            'role.read' => 'View role information',
            'role.update' => 'Update role information',
            'role.delete' => 'Delete roles',

            // Permission Management
            'permission.read' => 'View permissions',

            // Student Management
            'student.create' => 'Create new students',
            'student.read' => 'View student information',
            'student.update' => 'Update student information',
            'student.delete' => 'Delete students',

            // Teacher Management
            'teacher.create' => 'Create new teachers',
            'teacher.read' => 'View teacher information',
            'teacher.update' => 'Update teacher information',
            'teacher.delete' => 'Delete teachers',

            // Class Management
            'class.create' => 'Create new classes',
            'class.read' => 'View class information',
            'class.update' => 'Update class information',
            'class.delete' => 'Delete classes',

            // Subject Management
            'subject.create' => 'Create new subjects',
            'subject.read' => 'View subject information',
            'subject.update' => 'Update subject information',
            'subject.delete' => 'Delete subjects',

            // Exam Management
            'exam.create' => 'Create new exams',
            'exam.read' => 'View exam information',
            'exam.update' => 'Update exam information',
            'exam.delete' => 'Delete exams',

            // Result Management
            'result.create' => 'Create new results',
            'result.read' => 'View result information',
            'result.update' => 'Update result information',
            'result.delete' => 'Delete results',

            // Attendance Management
            'attendance.read' => 'View attendance records',
            'attendance.update' => 'Update attendance records',

            // Report Management
            'report.read' => 'View reports',

            // Subscription Management
            'subscription.read' => 'View subscription information',
            'subscription.manage' => 'Manage subscriptions',

            // System Settings
            'system.settings' => 'Manage system settings',

            // File Management
            'file.upload' => 'Upload files',
            'file.download' => 'Download files',
            'file.delete' => 'Delete files',

            // Communication
            'message.send' => 'Send messages',
            'message.read' => 'Read messages',
            'notification.send' => 'Send notifications',

            // Financial Management
            'fee.create' => 'Create fees',
            'fee.read' => 'View fees',
            'fee.update' => 'Update fees',
            'fee.delete' => 'Delete fees',
            'payment.create' => 'Process payments',
            'payment.read' => 'View payments',
            'payment.update' => 'Update payments',

            // Library Management
            'library.read' => 'View library',
            'library.create' => 'Add library items',
            'library.update' => 'Update library items',
            'library.delete' => 'Delete library items',
            'book.borrow' => 'Borrow books',
            'book.return' => 'Return books',

            // Livestream Management
            'livestream.create' => 'Create livestreams',
            'livestream.read' => 'View livestreams',
            'livestream.update' => 'Update livestreams',
            'livestream.delete' => 'Delete livestreams',
            'livestream.join' => 'Join livestreams',

            // Assignment Management
            'assignment.create' => 'Create assignments',
            'assignment.read' => 'View assignments',
            'assignment.update' => 'Update assignments',
            'assignment.delete' => 'Delete assignments',
            'assignment.submit' => 'Submit assignments',
            'assignment.grade' => 'Grade assignments',
        ];
    }

    /**
     * Get permissions by module
     */
    public static function getPermissionsByModule(): array
    {
        $permissions = self::getSystemPermissions();
        $grouped = [];

        foreach ($permissions as $permission => $description) {
            $parts = explode('.', $permission);
            $module = $parts[0];

            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }

            $grouped[$module][$permission] = $description;
        }

        return $grouped;
    }

    /**
     * Get module permissions
     */
    public function getModulePermissions(string $module): array
    {
        return $this->where('module', $module)->get();
    }

    /**
     * Check if permission is system permission
     */
    public function isSystemPermission(): bool
    {
        return $this->is_system_permission;
    }

    /**
     * Get permission action
     */
    public function getAction(): string
    {
        $parts = explode('.', $this->name);
        return $parts[1] ?? '';
    }

    /**
     * Get permission resource
     */
    public function getResource(): string
    {
        $parts = explode('.', $this->name);
        return $parts[0] ?? '';
    }
}
