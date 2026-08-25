<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicantExamAttempt extends Model
{
    protected $fillable = [
        'applicant_id',
        'admission_exam_id',
        'started_at',
        'submitted_at',
        'score',
        'status',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'submitted_at' => 'datetime',
        'score'        => 'float',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(AdmissionExam::class, 'admission_exam_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ApplicantExamAnswer::class, 'attempt_id');
    }
}
