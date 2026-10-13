<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Calendar extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'academic_year_id', 'title', 'description',
        'date', 'end_date', 'type', 'color', 'is_recurring', 'recurrence_rule',
    ];

    protected $casts = [
        'date'         => 'date:Y-m-d',
        'end_date'     => 'date:Y-m-d',
        'is_recurring' => 'boolean',
    ];

    public function school()       { return $this->belongsTo(School::class); }
    public function academicYear() { return $this->belongsTo(AcademicYear::class); }
}
