<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'student_id', 'name', 'dosage', 'frequency',
        'start_date', 'end_date', 'prescribed_by', 'reason',
        'side_effects', 'notes', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function school()  { return $this->belongsTo(School::class); }
    public function student() { return $this->belongsTo(Student::class); }
}
