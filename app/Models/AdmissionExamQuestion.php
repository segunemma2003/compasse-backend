<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionExamQuestion extends Model
{
    protected $fillable = [
        'admission_exam_id',
        'question_text',
        'type',
        'options',
        'correct_option',
        'marks',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'marks'   => 'float',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(AdmissionExam::class, 'admission_exam_id');
    }

    /**
     * Question payload with the answer key stripped — what an applicant sees.
     */
    public function toApplicantArray(): array
    {
        return [
            'id'            => $this->id,
            'question_text' => $this->question_text,
            'type'          => $this->type,
            'options'       => $this->options,
            'marks'         => $this->marks,
        ];
    }
}
