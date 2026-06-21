<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $fillable = [
        'title',
        'description',
        'instructions',
        'subject_id',
        'class_id',
        'teacher_id',
        'term_id',
        'academic_year_id',
        'due_date',
        'total_marks',
        'status',
        'attachments',
        'assignment_type',
        'submission_type',
        'max_file_size',
        'allowed_file_types',
        'is_group_assignment',
        'max_group_size',
    ];

    protected $casts = [
        'due_date' => 'date',
        'attachments' => 'array',
        'allowed_file_types' => 'array',
        'is_group_assignment' => 'boolean',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function class()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function submissions()
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
