<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantExamAnswer extends Model
{
    protected $fillable = [
        'attempt_id',
        'question_id',
        'answer_text',
        'is_correct',
        'marks_awarded',
    ];

    protected $casts = [
        'is_correct'    => 'boolean',
        'marks_awarded' => 'float',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ApplicantExamAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(AdmissionExamQuestion::class, 'question_id');
    }
}
