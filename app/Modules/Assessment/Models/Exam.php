<?php

namespace App\Modules\Assessment\Models;

use App\Models\School;
use App\Models\Subject;
use App\Models\ClassModel;
use App\Models\Term;
use App\Models\AcademicYear;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Exam extends Model
{
    use HasFactory;

    protected $table = 'exams';

    protected $fillable = [
        'school_id',
        'subject_id',
        'class_id',
        'teacher_id',
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
        'question_settings',
        'grading_settings',
        'security_settings',
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
        'question_settings' => 'array',
        'grading_settings' => 'array',
        'security_settings' => 'array',
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
        return $this->belongsToMany(\App\Models\Student::class, 'exam_students')
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
     * Get CBT settings
     */
    public function getCBTSettings(): array
    {
        return $this->cbt_settings ?? [
            'randomize_questions' => false,
            'randomize_options' => false,
            'show_correct_answers' => false,
            'allow_review' => true,
            'time_warning' => 5, // minutes before time expires
            'auto_submit' => true,
            'prevent_copy_paste' => true,
            'fullscreen_mode' => true,
        ];
    }

    /**
     * Get question settings
     */
    public function getQuestionSettings(): array
    {
        return $this->question_settings ?? [
            'allow_skip' => true,
            'allow_mark_for_review' => true,
            'show_question_numbers' => true,
            'show_progress' => true,
            'allow_navigation' => true,
        ];
    }

    /**
     * Get grading settings
     */
    public function getGradingSettings(): array
    {
        return $this->grading_settings ?? [
            'negative_marking' => false,
            'negative_mark_percentage' => 25,
            'partial_credit' => true,
            'auto_grade' => true,
            'manual_review_required' => false,
        ];
    }

    /**
     * Get security settings
     */
    public function getSecuritySettings(): array
    {
        return $this->security_settings ?? [
            'ip_restriction' => false,
            'allowed_ips' => [],
            'browser_restriction' => false,
            'allowed_browsers' => [],
            'prevent_screenshot' => false,
            'session_timeout' => 30, // minutes
        ];
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
