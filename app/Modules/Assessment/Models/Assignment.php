<?php

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use HasFactory;

    protected $table = 'assignments';

    protected $fillable = [
        'school_id',
        'subject_id',
        'class_id',
        'teacher_id',
        'title',
        'description',
        'instructions',
        'due_date',
        'total_marks',
        'assignment_type',
        'attachments',
        'submission_type',
        'max_file_size',
        'allowed_file_types',
        'is_group_assignment',
        'max_group_size',
        'status',
        'created_by',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'attachments' => 'array',
        'allowed_file_types' => 'array',
        'is_group_assignment' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(\App\Models\School::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Subject::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ClassModel::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Teacher::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Check if assignment is overdue
     */
    public function isOverdue(): bool
    {
        return $this->due_date->isPast();
    }

    /**
     * Get days until due
     */
    public function getDaysUntilDue(): int
    {
        return max(0, now()->diffInDays($this->due_date, false));
    }

    /**
     * Get submission rate
     */
    public function getSubmissionRate(): float
    {
        $totalStudents = $this->class->students()->count();
        $submissions = $this->submissions()->count();

        return $totalStudents > 0 ? round(($submissions / $totalStudents) * 100, 2) : 0;
    }

    /**
     * Get average score
     */
    public function getAverageScore(): float
    {
        return $this->submissions()
                   ->where('status', 'graded')
                   ->avg('marks_obtained') ?? 0;
    }
}
