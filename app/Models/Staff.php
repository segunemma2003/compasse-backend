<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = [
        'school_id',
        'user_id',
        'employee_id',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'role',
        'department',
        'employment_date',
        'status',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
    ];

    protected $casts = [
        'employment_date' => 'date',
        'date_of_birth'   => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . ($this->middle_name ? $this->middle_name . ' ' : '') . $this->last_name);
    }
}
