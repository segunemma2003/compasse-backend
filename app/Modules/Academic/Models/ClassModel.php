<?php

namespace App\Modules\Academic\Models;

use App\Models\School;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassModel extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'school_id',
        'name',
        'level',
        'class_teacher_id',
        'capacity',
        'description',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the class
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the class teacher
     */
    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'class_teacher_id');
    }

    /**
     * Get all students in this class
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    /**
     * Get all arms for this class
     */
    public function arms(): HasMany
    {
        return $this->hasMany(Arm::class, 'class_id');
    }

    /**
     * Get all subjects for this class
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'class_id');
    }

    /**
     * Get all attendance records for this class
     */
    public function attendance(): HasMany
    {
        return $this->hasMany(\App\Modules\Administrative\Models\Attendance::class, 'class_id');
    }

    /**
     * Get class statistics
     */
    public function getStats(): array
    {
        return [
            'total_students' => $this->students()->count(),
            'total_arms' => $this->arms()->count(),
            'total_subjects' => $this->subjects()->count(),
            'capacity_utilization' => $this->capacity > 0 ?
                round(($this->students()->count() / $this->capacity) * 100, 2) : 0,
        ];
    }

    /**
     * Check if class is full
     */
    public function isFull(): bool
    {
        return $this->capacity > 0 && $this->students()->count() >= $this->capacity;
    }

    /**
     * Get available capacity
     */
    public function getAvailableCapacity(): int
    {
        return max(0, $this->capacity - $this->students()->count());
    }
}
