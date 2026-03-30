<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HostelAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'room_id', 'student_id', 'academic_year_id', 'term_id',
        'allocated_at', 'vacated_at', 'status', 'amount_paid', 'payment_status', 'notes',
    ];

    protected $casts = [
        'allocated_at' => 'date',
        'vacated_at'   => 'date',
        'amount_paid'  => 'decimal:2',
    ];

    public function school()       { return $this->belongsTo(School::class); }
    public function room()         { return $this->belongsTo(HostelRoom::class, 'room_id'); }
    public function student()      { return $this->belongsTo(Student::class); }
    public function academicYear() { return $this->belongsTo(AcademicYear::class); }
    public function term()         { return $this->belongsTo(Term::class); }
}
