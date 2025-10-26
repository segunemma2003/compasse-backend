<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Livestream extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'teacher_id',
        'class_id',
        'subject_id',
        'title',
        'description',
        'meeting_link',
        'meeting_id',
        'meeting_password',
        'start_time',
        'end_time',
        'duration_minutes',
        'status',
        'recording_url',
        'attendance_taken',
        'max_participants',
        'is_recurring',
        'recurrence_pattern',
        'created_by',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'attendance_taken' => 'boolean',
        'is_recurring' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(LivestreamAttendance::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if livestream is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' &&
               now()->between($this->start_time, $this->end_time);
    }

    /**
     * Check if livestream is upcoming
     */
    public function isUpcoming(): bool
    {
        return $this->status === 'scheduled' && $this->start_time->isFuture();
    }

    /**
     * Get attendance rate
     */
    public function getAttendanceRate(): float
    {
        $totalStudents = $this->class->students()->count();
        $attendedStudents = $this->attendees()->where('status', 'present')->count();

        return $totalStudents > 0 ? round(($attendedStudents / $totalStudents) * 100, 2) : 0;
    }
}
