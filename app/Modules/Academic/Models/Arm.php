<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Models\ClassModel;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Arm extends Model
{
    use HasFactory;

    protected $table = 'arms';

    protected $fillable = [
        'class_id',
        'name',
        'description',
        'capacity',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the class that owns the arm
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * Get all students in this arm
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'arm_id');
    }

    /**
     * Get arm statistics
     */
    public function getStats(): array
    {
        return [
            'total_students' => $this->students()->count(),
            'capacity_utilization' => $this->capacity > 0 ?
                round(($this->students()->count() / $this->capacity) * 100, 2) : 0,
        ];
    }

    /**
     * Check if arm is full
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
