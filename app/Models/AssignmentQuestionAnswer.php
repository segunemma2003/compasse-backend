<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentQuestionAnswer extends Model
{
    protected $fillable = [
        'assignment_id',
        'question_id',
        'student_id',
        'answer_data',
        'is_correct',
        'marks_obtained',
        'graded_by',
        'graded_at',
        'feedback',
    ];

    protected $casts = [
        'answer_data'    => 'array',
        'is_correct'     => 'boolean',
        'marks_obtained' => 'decimal:2',
        'graded_at'      => 'datetime',
    ];

    public function assignment(): BelongsTo { return $this->belongsTo(Assignment::class); }
    public function question(): BelongsTo   { return $this->belongsTo(Question::class); }
    public function student(): BelongsTo    { return $this->belongsTo(Student::class); }
    public function gradedBy(): BelongsTo   { return $this->belongsTo(User::class, 'graded_by'); }
}
