<?php

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;

class ElabSubmission extends Model
{
    protected $fillable = ['activity_id', 'student_id', 'answers', 'observations', 'submitted_at', 'score', 'feedback', 'graded_by'];

    protected $casts = ['answers' => 'array', 'submitted_at' => 'datetime', 'score' => 'float'];
}
