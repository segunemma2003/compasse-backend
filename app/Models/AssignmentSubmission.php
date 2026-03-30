<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssignmentSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id', 'student_id', 'submission_text', 'attachments',
        'submitted_at', 'is_late', 'status',
        'marks_obtained', 'feedback', 'grade', 'graded_at', 'graded_by',
    ];

    protected $casts = [
        'attachments'    => 'array',
        'submitted_at'   => 'datetime',
        'graded_at'      => 'datetime',
        'is_late'        => 'boolean',
        'marks_obtained' => 'decimal:2',
    ];

    public function assignment() { return $this->belongsTo(Assignment::class); }
    public function student()    { return $this->belongsTo(Student::class); }
    public function gradedBy()   { return $this->belongsTo(User::class, 'graded_by'); }
}
