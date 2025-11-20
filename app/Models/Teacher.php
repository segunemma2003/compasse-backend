<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'user_id',
        'employee_id',
        'title',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'address',
        'date_of_birth',
        'gender',
        'qualification',
        'specialization',
        'experience_years',
        'salary',
        'employment_date',
        'status',
        'profile_picture',
        'bio',
        'subjects',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'employment_date' => 'date',
        'subjects' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the teacher
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the user account for this teacher
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the department this teacher belongs to
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get all classes taught by this teacher
     */
    public function classes(): HasMany
    {
        return $this->hasMany(ClassModel::class, 'class_teacher_id');
    }

    /**
     * Get all subjects taught by this teacher
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subjects');
    }

    /**
     * Get all students taught by this teacher
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_teacher_id');
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->title . ' ' . $this->first_name . ' ' . $this->last_name);
    }

    /**
     * Check if teacher is principal
     */
    public function isPrincipal(): bool
    {
        return $this->school && $this->school->principal_id === $this->id;
    }

    /**
     * Check if teacher is vice principal
     */
    public function isVicePrincipal(): bool
    {
        return $this->school && $this->school->vice_principal_id === $this->id;
    }

    /**
     * Check if teacher is head of department
     */
    public function isHeadOfDepartment(): bool
    {
        return $this->department && $this->department->head_id === $this->id;
    }

    /**
     * Get teacher's role in school
     */
    public function getRole(): string
    {
        if ($this->isPrincipal()) {
            return 'Principal';
        }

        if ($this->isVicePrincipal()) {
            return 'Vice Principal';
        }

        if ($this->isHeadOfDepartment()) {
            return 'Head of Department';
        }

        return 'Teacher';
    }
}
