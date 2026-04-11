<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerLog extends Model
{
    protected $fillable = [
        'command',
        'status',
        'output',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
