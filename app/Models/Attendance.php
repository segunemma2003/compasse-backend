<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'attendanceable_id',
        'attendanceable_type',
        'date',
        'status',
        'check_in_time',
        'check_out_time',
        'total_hours',
        'break_duration',
        'overtime_hours',
        'location',
        'device_info',
        'ip_address',
        'notes',
        'marked_by',
        'is_late',
        'late_minutes',
        'is_absent',
        'absence_reason',
        'is_excused',
        'excuse_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'is_late' => 'boolean',
        'is_absent' => 'boolean',
        'is_excused' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function attendanceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /**
     * Get attendance status
     */
    public function getStatusAttribute($value): string
    {
        if ($this->is_absent) {
            return 'absent';
        }

        if ($this->check_in_time && !$this->check_out_time) {
            return 'present';
        }

        if ($this->check_in_time && $this->check_out_time) {
            return 'completed';
        }

        return $value ?? 'pending';
    }

    /**
     * Calculate total hours worked
     */
    public function calculateTotalHours(): float
    {
        if (!$this->check_in_time || !$this->check_out_time) {
            return 0;
        }

        $totalMinutes = $this->check_in_time->diffInMinutes($this->check_out_time);
        $breakMinutes = $this->break_duration ?? 0;

        return round(($totalMinutes - $breakMinutes) / 60, 2);
    }

    /**
     * Check if attendance is late
     */
    public function isLate(): bool
    {
        if (!$this->check_in_time) {
            return false;
        }

        // Define expected check-in time (e.g., 8:00 AM)
        $expectedTime = $this->date->setTime(8, 0);

        return $this->check_in_time->gt($expectedTime);
    }

    /**
     * Get late minutes
     */
    public function getLateMinutes(): int
    {
        if (!$this->isLate()) {
            return 0;
        }

        $expectedTime = $this->date->setTime(8, 0);
        return $this->check_in_time->diffInMinutes($expectedTime);
    }

    /**
     * Check if attendance is overtime
     */
    public function isOvertime(): bool
    {
        $expectedHours = 8; // Standard working hours
        return $this->total_hours > $expectedHours;
    }

    /**
     * Get overtime hours
     */
    public function getOvertimeHours(): float
    {
        if (!$this->isOvertime()) {
            return 0;
        }

        $expectedHours = 8;
        return round($this->total_hours - $expectedHours, 2);
    }
}
