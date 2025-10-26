<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'livestream_id',
        'student_id',
        'teacher_id',
        'joined_at',
        'left_at',
        'duration_minutes',
        'status',
        'device_info',
        'ip_address',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function livestream(): BelongsTo
    {
        return $this->belongsTo(Livestream::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Calculate attendance duration
     */
    public function getDurationMinutes(): int
    {
        if ($this->joined_at && $this->left_at) {
            return $this->joined_at->diffInMinutes($this->left_at);
        }
        
        return 0;
    }

    /**
     * Check if attendance is complete
     */
    public function isComplete(): bool
    {
        return $this->status === 'completed' && $this->left_at !== null;
    }
}
