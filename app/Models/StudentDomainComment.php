<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDomainComment extends Model
{
    protected $fillable = [
        'student_id',
        'result_domain_id',
        'academic_year_id',
        'term_id',
        'comment',
        'teacher_name',
        'recorded_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ResultDomain::class, 'result_domain_id');
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
