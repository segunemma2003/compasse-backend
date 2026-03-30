<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthAppointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'student_id', 'doctor_name', 'appointment_date',
        'appointment_time', 'reason', 'status', 'diagnosis',
        'prescription', 'follow_up_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'follow_up_date'   => 'date',
    ];

    public function school()     { return $this->belongsTo(School::class); }
    public function student()    { return $this->belongsTo(Student::class); }
    public function createdBy()  { return $this->belongsTo(User::class, 'created_by'); }
}
