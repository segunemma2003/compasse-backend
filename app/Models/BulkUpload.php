<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkUpload extends Model
{
    protected $fillable = [
        'school_id',
        'user_id',
        'type',
        'status',
        'file_path',
        'file_name',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'errors',
        'meta',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors'       => 'array',
        'meta'         => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $appends = ['progress'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getProgressAttribute(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }
        return (int) round(($this->processed_rows / $this->total_rows) * 100);
    }
}
