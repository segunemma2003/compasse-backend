<?php

namespace App\Models\Wellbeing;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SenProfile extends Model
{
    protected $fillable = ['student_id', 'needs', 'support_level', 'summary', 'strategies', 'assigned_user_ids', 'active', 'created_by'];

    protected $casts = ['needs' => 'array', 'assigned_user_ids' => 'array', 'active' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
