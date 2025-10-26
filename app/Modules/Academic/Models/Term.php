<?php

namespace App\Modules\Academic\Models;

use App\Models\School;
use App\Modules\Academic\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    use HasFactory;

    protected $table = 'terms';

    protected $fillable = [
        'school_id',
        'academic_year_id',
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
     * Get the school that owns the term
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the academic year this term belongs to
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get all exams for this term
     */
    public function exams(): HasMany
    {
        return $this->hasMany(\App\Modules\Assessment\Models\Exam::class);
    }

    /**
     * Get all results for this term
     */
    public function results(): HasMany
    {
        return $this->hasMany(\App\Modules\Assessment\Models\Result::class);
    }

    /**
     * Get all assignments for this term
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(\App\Modules\Assessment\Models\Assignment::class);
    }

    /**
     * Get term duration in days
     */
    public function getDurationInDays(): int
    {
        return $this->start_date && $this->end_date ?
            $this->start_date->diffInDays($this->end_date) : 0;
    }

    /**
     * Check if term is active
     */
    public function isActive(): bool
    {
        $now = now();
        return $this->start_date <= $now && $this->end_date >= $now;
    }

    /**
     * Get term statistics
     */
    public function getStats(): array
    {
        return [
            'total_exams' => $this->exams()->count(),
            'total_results' => $this->results()->count(),
            'total_assignments' => $this->assignments()->count(),
            'duration_days' => $this->getDurationInDays(),
        ];
    }
}
