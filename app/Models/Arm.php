<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Arm extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
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
     * Get the school that owns the arm
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get all classes that use this arm (many-to-many)
     */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(ClassModel::class, 'class_arm')
            ->withPivot(['capacity', 'class_teacher_id', 'status'])
            ->withTimestamps();
    }

    /**
     * Get all students in this arm
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'arm_id');
    }

    /**
     * Get arm statistics for a specific class
     */
    public function getStatsForClass($classId): array
    {
        $pivot = $this->classes()->where('class_id', $classId)->first()?->pivot;
        $studentsCount = $this->students()->where('class_id', $classId)->count();
        $capacity = $pivot?->capacity ?? 30;

        return [
            'total_students' => $studentsCount,
            'capacity' => $capacity,
            'capacity_utilization' => $capacity > 0 ?
                round(($studentsCount / $capacity) * 100, 2) : 0,
            'available_capacity' => max(0, $capacity - $studentsCount),
        ];
    }

    /**
     * Check if arm is full for a specific class
     */
    public function isFullForClass($classId): bool
    {
        $stats = $this->getStatsForClass($classId);
        return $stats['available_capacity'] === 0;
    }
}
