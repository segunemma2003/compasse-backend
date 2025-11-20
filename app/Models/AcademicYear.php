<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'start_date',
        'end_date',
        'is_current',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the academic year
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get all terms for this academic year
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class, 'academic_year_id');
    }

    /**
     * Get all students for this academic year
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'academic_year_id');
    }

    /**
     * Get all results for this academic year
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class, 'academic_year_id');
    }

    /**
     * Get academic year duration in days
     */
    public function getDurationInDays(): int
    {
        return $this->start_date && $this->end_date ?
            $this->start_date->diffInDays($this->end_date) : 0;
    }

    /**
     * Check if academic year is active
     */
    public function isActive(): bool
    {
        $now = now();
        return $this->start_date <= $now && $this->end_date >= $now;
    }

    /**
     * Get academic year statistics
     */
    public function getStats(): array
    {
        return [
            'total_terms' => $this->terms()->count(),
            'total_students' => $this->students()->count(),
            'total_results' => $this->results()->count(),
            'duration_days' => $this->getDurationInDays(),
        ];
    }
}
