<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Answer extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_attempt_id',
        'question_id',
        'student_id',
        'answer_text',
        'answer_data',
        'is_correct',
        'marks_obtained',
        'time_taken_seconds',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'answer_data' => 'array',
        'is_correct' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the exam attempt this answer belongs to
     */
    public function examAttempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    /**
     * Get the question this answer is for
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the student who provided this answer
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get answer text for display
     */
    public function getAnswerText(): string
    {
        if ($this->question->isMultipleChoice()) {
            return $this->answer_text;
        }
        
        if ($this->question->isTrueFalse()) {
            return $this->answer_text;
        }
        
        if ($this->question->isEssay()) {
            return $this->answer_text;
        }
        
        if ($this->question->isFillInBlank()) {
            return $this->answer_text;
        }
        
        return $this->answer_text;
    }

    /**
     * Get answer data for processing
     */
    public function getAnswerData(): array
    {
        return $this->answer_data ?? [];
    }

    /**
     * Check if answer is correct
     */
    public function isCorrect(): bool
    {
        return $this->is_correct;
    }

    /**
     * Get marks obtained
     */
    public function getMarksObtained(): float
    {
        return $this->marks_obtained ?? 0;
    }

    /**
     * Get time taken in minutes
     */
    public function getTimeTakenInMinutes(): float
    {
        return $this->time_taken_seconds / 60;
    }

    /**
     * Auto-grade answer based on question type
     */
    public function autoGrade(): void
    {
        if ($this->question->isMultipleChoice() || $this->question->isTrueFalse()) {
            $isCorrect = $this->question->isCorrectAnswer($this->getAnswerData());
            $this->update([
                'is_correct' => $isCorrect,
                'marks_obtained' => $isCorrect ? $this->question->marks : 0,
            ]);
        }
    }
}
