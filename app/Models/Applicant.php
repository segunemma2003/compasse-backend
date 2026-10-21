<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Applicant extends Model
{
    protected $fillable = [
        'school_id',
        'admission_cycle_id',
        'access_token',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'email',
        'phone',
        'parent_name',
        'parent_phone',
        'parent_email',
        'previous_school',
        'class_id',
        'student_id',
        'status',
        'exam_score',
        'decision_notes',
        'reviewed_by',
        'reviewed_at',
        'custom_fields',
        'documents',
        'offered_at',
        'enrolled_at',
        'payment_confirmed_at',
        'resumption_date',
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'documents'     => 'array',
        'date_of_birth' => 'date',
        'reviewed_at'   => 'datetime',
        'exam_score'    => 'float',
        'offered_at'    => 'datetime',
        'enrolled_at'   => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'resumption_date' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::creating(function (Applicant $applicant) {
            $applicant->access_token ??= (string) Str::uuid();
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Academic\Models\ClassModel::class, 'class_id');
    }

    /** Set once approval has converted this applicant into an enrolled Student. */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function examAttempts(): HasMany
    {
        return $this->hasMany(ApplicantExamAttempt::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
