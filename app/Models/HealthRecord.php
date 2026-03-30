<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'student_id', 'blood_group', 'height_cm', 'weight_kg',
        'allergies', 'medical_conditions', 'current_medications', 'immunization_records',
        'emergency_contact_name', 'emergency_contact_phone',
        'family_doctor_name', 'family_doctor_phone',
        'last_checkup_date', 'notes',
    ];

    protected $casts = [
        'allergies'            => 'array',
        'medical_conditions'   => 'array',
        'current_medications'  => 'array',
        'immunization_records' => 'array',
        'last_checkup_date'    => 'date',
    ];

    public function school()  { return $this->belongsTo(School::class); }
    public function student() { return $this->belongsTo(Student::class); }

    public function getBmiAttribute(): ?float
    {
        if ($this->height_cm && $this->weight_kg) {
            $heightM = $this->height_cm / 100;
            return round($this->weight_kg / ($heightM * $heightM), 1);
        }
        return null;
    }
}
