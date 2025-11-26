<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContinuousAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'subject_id',
        'class_id',
        'term_id',
        'academic_year_id',
        'teacher_id',
        'name',
        'type',
        'total_marks',
        'assessment_date',
        'description',
        'status',
    ];

    protected $casts = [
        'total_marks' => 'decimal:2',
        'assessment_date' => 'date',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(CAScore::class);
    }
}

