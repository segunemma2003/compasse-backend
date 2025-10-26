<?php

namespace App\Modules\Assessment\Models;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory;

    protected $table = 'questions';

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
        'hints',
        'tags',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answer' => 'array',
        'hints' => 'array',
        'tags' => 'array',
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
     * Check if question is essay/open-ended
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
     * Check if question is matching
     */
    public function isMatching(): bool
    {
        return $this->question_type === 'matching';
    }

    /**
     * Check if question is short answer
     */
    public function isShortAnswer(): bool
    {
        return $this->question_type === 'short_answer';
    }

    /**
     * Check if question is numerical
     */
    public function isNumerical(): bool
    {
        return $this->question_type === 'numerical';
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
     * Get options for multiple choice questions
     */
    public function getOptions(): array
    {
        return $this->options ?? [];
    }

    /**
     * Get correct answer
     */
    public function getCorrectAnswer(): array
    {
        return $this->correct_answer ?? [];
    }

    /**
     * Get hints
     */
    public function getHints(): array
    {
        return $this->hints ?? [];
    }

    /**
     * Get tags
     */
    public function getTags(): array
    {
        return $this->tags ?? [];
    }

    /**
     * Check if answer is correct for multiple choice
     */
    public function isCorrectAnswerMultipleChoice(array $userAnswer): bool
    {
        if (!$this->isMultipleChoice()) {
            return false;
        }

        return $userAnswer === $this->correct_answer;
    }

    /**
     * Check if answer is correct for true/false
     */
    public function isCorrectAnswerTrueFalse(array $userAnswer): bool
    {
        if (!$this->isTrueFalse()) {
            return false;
        }

        return $userAnswer === $this->correct_answer;
    }

    /**
     * Check if answer is correct for fill in the blank
     */
    public function isCorrectAnswerFillBlank(array $userAnswer): bool
    {
        if (!$this->isFillInBlank()) {
            return false;
        }

        $userAnswers = array_map('strtolower', array_map('trim', $userAnswer));
        $correctAnswers = array_map('strtolower', array_map('trim', $this->correct_answer));

        return $userAnswers === $correctAnswers;
    }

    /**
     * Check if answer is correct for numerical
     */
    public function isCorrectAnswerNumerical(array $userAnswer): bool
    {
        if (!$this->isNumerical()) {
            return false;
        }

        $userValue = floatval($userAnswer[0] ?? 0);
        $correctValue = floatval($this->correct_answer[0] ?? 0);
        $tolerance = floatval($this->correct_answer[1] ?? 0.01);

        return abs($userValue - $correctValue) <= $tolerance;
    }

    /**
     * Check if answer is correct based on question type
     */
    public function isCorrectAnswer(array $userAnswer): bool
    {
        switch ($this->question_type) {
            case 'multiple_choice':
                return $this->isCorrectAnswerMultipleChoice($userAnswer);
            case 'true_false':
                return $this->isCorrectAnswerTrueFalse($userAnswer);
            case 'fill_blank':
                return $this->isCorrectAnswerFillBlank($userAnswer);
            case 'numerical':
                return $this->isCorrectAnswerNumerical($userAnswer);
            case 'essay':
            case 'short_answer':
                // These require manual grading
                return false;
            default:
                return false;
        }
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

    /**
     * Get question for CBT display (without correct answers)
     */
    public function getForCBT(): array
    {
        $question = $this->toArray();

        // Remove correct answers for CBT
        unset($question['correct_answer']);

        return $question;
    }
}
