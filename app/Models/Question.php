<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'subject_id',
        'question_text',
        'question_type',
        'difficulty_level',
        'marks',
        'time_limit_seconds',
        'options',
        'correct_answer',
        'explanation',
        'media_url',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answer' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the exam this question belongs to
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * Get the subject this question belongs to
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Get all answers for this question
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * Get all attempts for this question
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuestionAttempt::class);
    }

    /**
     * Check if question is multiple choice
     */
    public function isMultipleChoice(): bool
    {
        return $this->question_type === 'multiple_choice';
    }

    /**
     * Check if question is true/false
     */
    public function isTrueFalse(): bool
    {
        return $this->question_type === 'true_false';
    }

    /**
     * Check if question is essay
     */
    public function isEssay(): bool
    {
        return $this->question_type === 'essay';
    }

    /**
     * Check if question is fill in the blank
     */
    public function isFillInBlank(): bool
    {
        return $this->question_type === 'fill_blank';
    }

    /**
     * Get question difficulty level
     */
    public function getDifficultyLevel(): string
    {
        return $this->difficulty_level;
    }

    /**
     * Get time limit in minutes
     */
    public function getTimeLimitInMinutes(): float
    {
        return $this->time_limit_seconds / 60;
    }

    /**
     * Check if answer is correct
     */
    public function isCorrectAnswer(array $userAnswer): bool
    {
        if ($this->isMultipleChoice()) {
            return $userAnswer === $this->correct_answer;
        }
        
        if ($this->isTrueFalse()) {
            return $userAnswer === $this->correct_answer;
        }
        
        if ($this->isFillInBlank()) {
            return strtolower(trim($userAnswer[0])) === strtolower(trim($this->correct_answer[0]));
        }
        
        return false;
    }

    /**
     * Get question statistics
     */
    public function getStats(): array
    {
        $totalAttempts = $this->attempts()->count();
        $correctAttempts = $this->attempts()->where('is_correct', true)->count();
        
        return [
            'total_attempts' => $totalAttempts,
            'correct_attempts' => $correctAttempts,
            'accuracy_rate' => $totalAttempts > 0 ? round(($correctAttempts / $totalAttempts) * 100, 2) : 0,
            'average_time' => $this->attempts()->avg('time_taken'),
        ];
    }
}
