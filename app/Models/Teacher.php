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
        'department_id',
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
        'employment_type',
        'status',
        'profile_picture',
        'bio',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'subjects',
        'created_at',
        'updated_at',
    ];

    protected $appends = ['attendance_code'];

    protected $casts = [
        'date_of_birth' => 'date',
        'employment_date' => 'date',
        'subjects' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Human-readable attendance code: {SCHOOL_CODE}/TE/{MM}/{YYYY}/{padded_id}
     * Returns the stored employee_id if it already follows this pattern,
     * otherwise derives it from the teacher's employment date + DB id.
     */
    public function getAttendanceCodeAttribute(): string
    {
        // Return stored employee_id if it already has the structured format
        if ($this->employee_id && str_contains($this->employee_id, '/TE/')) {
            return $this->employee_id;
        }

        static $schoolCode = null;
        if ($schoolCode === null) {
            try {
                $schoolCode = strtoupper(trim(School::value('code') ?? ''));
                if ($schoolCode === '') $schoolCode = 'SCH';
            } catch (\Exception) {
                $schoolCode = 'SCH';
            }
        }

        $date = $this->employment_date ?? $this->created_at ?? now();
        $mm   = (is_string($date) ? \Carbon\Carbon::parse($date) : $date)->format('m');
        $yyyy = (is_string($date) ? \Carbon\Carbon::parse($date) : $date)->format('Y');

        return $schoolCode . '/TE/' . $mm . '/' . $yyyy . '/' . str_pad($this->id, 4, '0', STR_PAD_LEFT);
    }

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
