<?php

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Result extends Model
{
    use HasFactory;

    protected $table = 'results';

    protected $fillable = [
        'school_id',
        'student_id',
        'exam_id',
        'subject_id',
        'class_id',
        'term_id',
        'academic_year_id',
        'marks_obtained',
        'total_marks',
        'percentage',
        'grade',
        'position',
        'remarks',
        'is_published',
        'created_by',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(\App\Models\School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Subject::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ClassModel::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Term::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AcademicYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Get grade description
     */
    public function getGradeDescription(): string
    {
        $gradeDescriptions = [
            'A+' => 'Excellent',
            'A' => 'Very Good',
            'B+' => 'Good',
            'B' => 'Satisfactory',
            'C+' => 'Average',
            'C' => 'Below Average',
            'D' => 'Poor',
            'F' => 'Fail',
        ];

        return $gradeDescriptions[$this->grade] ?? 'Unknown';
    }

    /**
     * Check if result is passing
     */
    public function isPassing(): bool
    {
        return $this->percentage >= 50;
    }

    /**
     * Get performance level
     */
    public function getPerformanceLevel(): string
    {
        if ($this->percentage >= 90) {
            return 'Outstanding';
        } elseif ($this->percentage >= 80) {
            return 'Excellent';
        } elseif ($this->percentage >= 70) {
            return 'Very Good';
        } elseif ($this->percentage >= 60) {
            return 'Good';
        } elseif ($this->percentage >= 50) {
            return 'Satisfactory';
        } else {
            return 'Needs Improvement';
        }
    }
}
