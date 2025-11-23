<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $fillable = [
        'title',
        'description',
        'subject_id',
        'class_id',
        'teacher_id',
        'due_date',
        'total_marks',
        'status',
        'attachments'
    ];

    protected $casts = [
        'due_date' => 'date',
        'attachments' => 'array'
    ];

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

// Create a simple AssignmentSubmission model placeholder
class_alias(Assignment::class, 'App\Models\AssignmentSubmission');
