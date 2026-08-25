<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOpening extends Model
{
    protected $fillable = [
        'school_id',
        'title',
        'role',
        'department',
        'description',
        'requirements',
        'opens_at',
        'closes_at',
        'status',
        'created_by',
    ];

    protected $casts = [
        'opens_at'  => 'datetime',
        'closes_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(JobApplicant::class);
    }

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
