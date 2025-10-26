<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'description',
        'head_id',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the department
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the head of department
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'head_id');
    }

    /**
     * Get all teachers in this department
     */
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class, 'department_id');
    }

    /**
     * Get all subjects in this department
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'department_id');
    }

    /**
     * Get department statistics
     */
    public function getStats(): array
    {
        return [
            'total_teachers' => $this->teachers()->count(),
            'total_subjects' => $this->subjects()->count(),
        ];
    }
}
