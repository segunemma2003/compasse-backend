<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class JobApplicant extends Model
{
    protected $fillable = [
        'school_id',
        'job_opening_id',
        'access_token',
        'first_name',
        'last_name',
        'email',
        'phone',
        'cover_letter',
        'qualifications',
        'years_of_experience',
        'resume_path',
        'status',
        'decision_notes',
        'reviewed_by',
        'reviewed_at',
        'onboarded_user_id',
        'onboarded_at',
    ];

    protected $casts = [
        'reviewed_at'  => 'datetime',
        'onboarded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (JobApplicant $applicant) {
            $applicant->access_token ??= (string) Str::uuid();
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
