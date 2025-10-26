<?php

namespace App\Modules\Assessment\Models;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Answer extends Model
{
    use HasFactory;

    protected $table = 'answers';

    protected $fillable = [
        'exam_attempt_id',
        'question_id',
        'student_id',
        'answer_text',
        'answer_data',
        'is_correct',
        'marks_obtained',
        'time_taken_seconds',
        'grading_status',
        'graded_by',
        'graded_at',
        'feedback',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'answer_data' => 'array',
        'is_correct' => 'boolean',
        'graded_at' => 'datetime',
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
     * Get the teacher who graded this answer
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Teacher::class, 'graded_by');
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
        
        if ($this->question->isShortAnswer()) {
            return $this->answer_text;
        }
        
        if ($this->question->isFillInBlank()) {
            return $this->answer_text;
        }
        
        if ($this->question->isNumerical()) {
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
     * Check if answer is graded
     */
    public function isGraded(): bool
    {
        return $this->grading_status === 'graded';
    }

    /**
     * Check if answer needs manual grading
     */
    public function needsManualGrading(): bool
    {
        return in_array($this->question->question_type, ['essay', 'short_answer']) && 
               $this->grading_status !== 'graded';
    }

    /**
     * Auto-grade answer based on question type
     */
    public function autoGrade(): void
    {
        if ($this->question->isMultipleChoice() || 
            $this->question->isTrueFalse() || 
            $this->question->isFillInBlank() || 
            $this->question->isNumerical()) {
            
            $isCorrect = $this->question->isCorrectAnswer($this->getAnswerData());
            $marksObtained = $isCorrect ? $this->question->marks : 0;
            
            $this->update([
                'is_correct' => $isCorrect,
                'marks_obtained' => $marksObtained,
                'grading_status' => 'graded',
                'graded_at' => now(),
            ]);
        } else {
            // Mark for manual grading
            $this->update([
                'grading_status' => 'pending_manual',
            ]);
        }
    }

    /**
     * Manually grade answer
     */
    public function manualGrade(float $marks, string $feedback = null, int $gradedBy = null): void
    {
        $this->update([
            'marks_obtained' => $marks,
            'is_correct' => $marks > 0,
            'grading_status' => 'graded',
            'graded_by' => $gradedBy,
            'graded_at' => now(),
            'feedback' => $feedback,
        ]);
    }

    /**
     * Get answer for review
     */
    public function getForReview(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question->getForCBT(),
            'answer_text' => $this->answer_text,
            'answer_data' => $this->answer_data,
            'is_correct' => $this->is_correct,
            'marks_obtained' => $this->marks_obtained,
            'time_taken_seconds' => $this->time_taken_seconds,
            'grading_status' => $this->grading_status,
            'feedback' => $this->feedback,
        ];
    }
}
