<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Term attendance totals entered by a class teacher (see the migration). */
class TermAttendanceSummary extends Model
{
    protected $fillable = [
        'school_id', 'student_id', 'term_id', 'academic_year_id',
        'days_opened', 'days_present', 'days_absent', 'recorded_by',
    ];

    protected $casts = [
        'days_opened'  => 'integer',
        'days_present' => 'integer',
        'days_absent'  => 'integer',
    ];
}
