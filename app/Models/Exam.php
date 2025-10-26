<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'subject_id',
        'class_id',
        'term_id',
        'academic_year_id',
        'name',
        'description',
        'type',
        'duration_minutes',
        'total_marks',
        'passing_marks',
        'start_date',
        'end_date',
        'is_cbt',
        'cbt_settings',
        'status',
        'created_by',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_cbt' => 'boolean',
        'cbt_settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the exam
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the subject this exam belongs to
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Get the class this exam is for
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * Get the term this exam belongs to
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * Get the academic year this exam belongs to
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the teacher who created this exam
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'created_by');
    }

    /**
     * Get all questions for this exam
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Get all attempts for this exam
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /**
     * Get all results for this exam
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /**
     * Get all students taking this exam
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'exam_students')
                    ->withPivot(['status', 'started_at', 'completed_at', 'score'])
                    ->withTimestamps();
    }

    /**
     * Check if exam is CBT
     */
    public function isCBT(): bool
    {
        return $this->is_cbt;
    }

    /**
     * Check if exam is active
     */
    public function isActive(): bool
    {
        $now = now();
        return $this->status === 'active' &&
               $this->start_date <= $now &&
               $this->end_date >= $now;
    }

    /**
     * Check if exam has started
     */
    public function hasStarted(): bool
    {
        return now() >= $this->start_date;
    }

    /**
     * Check if exam has ended
     */
    public function hasEnded(): bool
    {
        return now() > $this->end_date;
    }

    /**
     * Get exam duration in hours
     */
    public function getDurationInHours(): float
    {
        return $this->duration_minutes / 60;
    }

    /**
     * Get exam statistics
     */
    public function getStats(): array
    {
        return [
            'total_questions' => $this->questions()->count(),
            'total_attempts' => $this->attempts()->count(),
            'total_students' => $this->students()->count(),
            'completed_attempts' => $this->attempts()->where('status', 'completed')->count(),
            'average_score' => $this->results()->avg('total_score'),
            'pass_rate' => $this->calculatePassRate(),
        ];
    }

    /**
     * Calculate pass rate
     */
    protected function calculatePassRate(): float
    {
        $totalResults = $this->results()->count();
        $passedResults = $this->results()->where('total_score', '>=', $this->passing_marks)->count();

        return $totalResults > 0 ? round(($passedResults / $totalResults) * 100, 2) : 0;
    }
}
