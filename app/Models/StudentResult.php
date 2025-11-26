<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'class_id',
        'term_id',
        'academic_year_id',
        'total_score',
        'average_score',
        'grade',
        'position',
        'out_of',
        'class_average',
        'class_teacher_comment',
        'principal_comment',
        'next_term_begins',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'average_score' => 'decimal:2',
        'class_average' => 'decimal:2',
        'position' => 'integer',
        'out_of' => 'integer',
        'next_term_begins' => 'date',
        'approved_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function subjectResults(): HasMany
    {
        return $this->hasMany(SubjectResult::class);
    }

    public function psychomotorAssessment()
    {
        return PsychomotorAssessment::where('student_id', $this->student_id)
            ->where('term_id', $this->term_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->first();
    }
}

