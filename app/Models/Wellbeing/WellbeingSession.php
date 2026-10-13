<?php

namespace App\Models\Wellbeing;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WellbeingSession extends Model
{
    public const KINDS = ['therapy', 'support', 'counselling'];

    protected $fillable = ['student_id', 'kind', 'staff_user_id', 'session_date', 'duration_minutes', 'focus', 'notes', 'next_steps'];

    protected $casts = ['session_date' => 'date:Y-m-d'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
