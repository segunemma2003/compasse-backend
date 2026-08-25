<?php

namespace App\Models;

use App\Modules\Academic\Models\ClassModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdmissionCycle extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'class_id',
        'academic_year_id',
        'description',
        'requires_entrance_exam',
        'opens_at',
        'closes_at',
        'status',
        'created_by',
    ];

    protected $casts = [
        'requires_entrance_exam' => 'boolean',
        'opens_at'  => 'datetime',
        'closes_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function exam(): HasOne
    {
        return $this->hasOne(AdmissionExam::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /**
     * Whether the public registration form should currently accept applications.
     */
    public function isOpen(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }
        $now = now();
        if ($this->opens_at && $now->lt($this->opens_at)) {
            return false;
        }
        if ($this->closes_at && $now->gt($this->closes_at)) {
            return false;
        }

        return true;
    }
}
