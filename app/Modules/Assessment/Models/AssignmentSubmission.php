<?php

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmission extends Model
{
    use HasFactory;

    protected $table = 'assignment_submissions';

    protected $fillable = [
        'assignment_id',
        'student_id',
        'submission_text',
        'attachments',
        'submitted_at',
        'is_late',
        'marks_obtained',
        'feedback',
        'grade',
        'status',
        'graded_at',
        'graded_by',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'attachments' => 'array',
        'is_late' => 'boolean',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class);
    }

    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'graded_by');
    }

    /**
     * Get percentage score
     */
    public function getPercentageScore(): float
    {
        if ($this->marks_obtained === null || $this->assignment->total_marks === 0) {
            return 0;
        }

        return round(($this->marks_obtained / $this->assignment->total_marks) * 100, 2);
    }

    /**
     * Check if submission is on time
     */
    public function isOnTime(): bool
    {
        return !$this->is_late;
    }

    /**
     * Get days late
     */
    public function getDaysLate(): int
    {
        if (!$this->is_late) {
            return 0;
        }

        return $this->submitted_at->diffInDays($this->assignment->due_date);
    }
}
