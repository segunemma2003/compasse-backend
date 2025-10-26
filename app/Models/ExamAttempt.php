<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'student_id',
        'started_at',
        'completed_at',
        'status',
        'total_score',
        'percentage',
        'time_taken_minutes',
        'ip_address',
        'user_agent',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the exam this attempt belongs to
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * Get the student who made this attempt
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get all answers for this attempt
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * Get all question attempts for this exam attempt
     */
    public function questionAttempts(): HasMany
    {
        return $this->hasMany(QuestionAttempt::class);
    }

    /**
     * Check if attempt is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if attempt is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Check if attempt is abandoned
     */
    public function isAbandoned(): bool
    {
        return $this->status === 'abandoned';
    }

    /**
     * Check if attempt passed
     */
    public function isPassed(): bool
    {
        return $this->percentage >= $this->exam->passing_marks;
    }

    /**
     * Get attempt duration in minutes
     */
    public function getDurationInMinutes(): int
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInMinutes($this->completed_at);
        }
        
        return 0;
    }

    /**
     * Get time remaining in minutes
     */
    public function getTimeRemaining(): int
    {
        if (!$this->started_at) {
            return $this->exam->duration_minutes;
        }
        
        $elapsed = $this->started_at->diffInMinutes(now());
        $remaining = $this->exam->duration_minutes - $elapsed;
        
        return max(0, $remaining);
    }

    /**
     * Check if time has expired
     */
    public function hasTimeExpired(): bool
    {
        return $this->getTimeRemaining() <= 0;
    }

    /**
     * Get attempt score breakdown
     */
    public function getScoreBreakdown(): array
    {
        $totalQuestions = $this->exam->questions()->count();
        $correctAnswers = $this->answers()->where('is_correct', true)->count();
        $incorrectAnswers = $this->answers()->where('is_correct', false)->count();
        $unanswered = $totalQuestions - $this->answers()->count();
        
        return [
            'total_questions' => $totalQuestions,
            'correct_answers' => $correctAnswers,
            'incorrect_answers' => $incorrectAnswers,
            'unanswered' => $unanswered,
            'score_percentage' => $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100, 2) : 0,
        ];
    }

    /**
     * Auto-submit if time expired
     */
    public function autoSubmitIfExpired(): void
    {
        if ($this->isInProgress() && $this->hasTimeExpired()) {
            $this->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
