<?php

namespace App\Models\Wellbeing;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WellbeingReferral extends Model
{
    protected $fillable = ['student_id', 'referred_to', 'reason', 'urgency', 'status', 'referred_by', 'assigned_to', 'outcome'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }
}
