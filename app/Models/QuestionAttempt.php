<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'exam_attempt_id',
        'student_id',
        'answer_data',
        'is_correct',
        'time_taken',
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
     * Get the question this attempt is for
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the exam attempt this belongs to
     */
    public function examAttempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    /**
     * Get the student who made this attempt
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Check if attempt is correct
     */
    public function isCorrect(): bool
    {
        return $this->is_correct;
    }

    /**
     * Get time taken in minutes
     */
    public function getTimeTakenInMinutes(): float
    {
        return $this->time_taken / 60;
    }

    /**
     * Get answer data
     */
    public function getAnswerData(): array
    {
        return $this->answer_data ?? [];
    }
}
