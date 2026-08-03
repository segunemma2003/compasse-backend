<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherPeriodicReport extends Model
{
    protected $fillable = [
        'school_id',
        'teacher_id',
        'class_id',
        'period_type',
        'period_start',
        'period_end',
        'title',
        'summary',
        'challenges',
        'recommendations',
        'status',
        'created_by',
        'submitted_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'submitted_at' => 'datetime',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}
