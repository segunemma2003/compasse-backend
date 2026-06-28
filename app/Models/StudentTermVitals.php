<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTermVitals extends Model
{
    protected $fillable = [
        'student_id',
        'academic_year_id',
        'term_id',
        'days_school_opened',
        'days_attended',
        'height_beginning',
        'height_end',
        'weight_beginning',
        'weight_end',
        'homework_rating',
        'punctuality_rating',
        'recorded_by',
    ];

    protected $casts = [
        'height_beginning' => 'float',
        'height_end'       => 'float',
        'weight_beginning' => 'float',
        'weight_end'       => 'float',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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
