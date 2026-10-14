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
        'assignment_id',
        'ca_id',
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

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function continuousAssessment(): BelongsTo
    {
        return $this->belongsTo(ContinuousAssessment::class, 'ca_id');
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
     * Check if answer is correct. Compares option texts ignoring case and spacing;
     * multiple choice needs exactly the correct set, fill-in-the-blank accepts any listed answer.
     */
    public function isCorrectAnswer(array $userAnswer): bool
    {
        $given = array_values(array_unique(array_filter(array_map(fn ($v) => self::normaliseAnswer($v), $userAnswer), fn ($v) => $v !== '')));
        $correct = $this->correctAnswers();
        if ($given === [] || $correct === []) {
            return false;
        }

        if ($this->isFillInBlank()) {
            return in_array($given[0], $correct, true);
        }

        if ($this->isMultipleChoice() || $this->isTrueFalse()) {
            sort($given);
            sort($correct);

            return $given === $correct;
        }

        return false;
    }

    /**
     * The correct answers as normalised option values, whatever shape they were saved in:
     * "text", ["text"], an option letter ("B"), or only is_correct flags on the options.
     */
    public function correctAnswers(): array
    {
        $raw = $this->correct_answer;
        $raw = is_array($raw) ? $raw : (($raw === null || $raw === '') ? [] : [$raw]);
        $values = $this->optionValues();
        $normalisedValues = array_map(fn ($v) => self::normaliseAnswer($v), $values);

        $out = [];
        foreach ($raw as $v) {
            if (! is_scalar($v) || trim((string) $v) === '') {
                continue;
            }
            $v = trim((string) $v);
            // A lone letter that isn't itself an option means "option B".
            if ($values && ! in_array(self::normaliseAnswer($v), $normalisedValues, true) && preg_match('/^[A-Ha-h]$/', $v)) {
                $v = $values[ord(strtoupper($v)) - 65] ?? $v;
            }
            $out[] = self::normaliseAnswer($v);
        }

        if ($out === []) {
            foreach ((array) ($this->options ?? []) as $i => $o) {
                if (is_array($o) && ! empty($o['is_correct']) && isset($values[$i])) {
                    $out[] = self::normaliseAnswer($values[$i]);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** What the exam page sends for each option: its text, or its letter when the option is image-only. */
    public function optionValues(): array
    {
        if ($this->isTrueFalse()) {
            return ['True', 'False'];
        }
        $values = [];
        foreach (array_values((array) ($this->options ?? [])) as $i => $o) {
            $text = is_array($o) ? trim((string) ($o['option_text'] ?? $o['text'] ?? $o['label'] ?? '')) : trim((string) $o);
            $values[] = $text !== '' ? $text : chr(65 + $i);
        }

        return $values;
    }

    public static function normaliseAnswer($value): string
    {
        return is_scalar($value) ? mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value))) : '';
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
