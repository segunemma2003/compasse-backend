<?php

namespace App\Models\Wellbeing;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareLog extends Model
{
    public const TYPES = ['meal', 'nap', 'nappy', 'toilet', 'medication', 'incident', 'activity', 'note'];

    protected $fillable = ['student_id', 'type', 'details', 'logged_at', 'logged_by', 'shared_with_parent'];

    protected $casts = ['logged_at' => 'datetime', 'shared_with_parent' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
