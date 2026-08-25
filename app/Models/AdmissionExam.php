<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionExam extends Model
{
    protected $fillable = [
        'admission_cycle_id',
        'title',
        'instructions',
        'duration_minutes',
        'scheduled_start',
        'scheduled_end',
        'status',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end'   => 'datetime',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AdmissionExamQuestion::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ApplicantExamAttempt::class);
    }

    public function totalMarks(): float
    {
        return (float) $this->questions()->sum('marks');
    }

    /**
     * Whether an applicant can currently start/continue this exam — requires
     * both the admin's explicit "active" toggle AND (if set) being inside the
     * scheduled window.
     */
    public function isCurrentlyOpen(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        $now = now();
        if ($this->scheduled_start && $now->lt($this->scheduled_start)) {
            return false;
        }
        if ($this->scheduled_end && $now->gt($this->scheduled_end)) {
            return false;
        }

        return true;
    }
}
