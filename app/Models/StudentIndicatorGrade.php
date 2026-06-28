<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentIndicatorGrade extends Model
{
    protected $fillable = [
        'student_id',
        'result_indicator_id',
        'result_checkpoint_id',
        'academic_year_id',
        'term_id',
        'grade',
        'recorded_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ResultIndicator::class, 'result_indicator_id');
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(ResultCheckpoint::class, 'result_checkpoint_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
