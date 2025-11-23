<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Result extends Model
{
    protected $fillable = [
        'student_id',
        'exam_id',
        'subject_id',
        'score',
        'total_marks',
        'grade',
        'remarks',
        'status'
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'total_marks' => 'decimal:2'
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
