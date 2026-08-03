<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolMeeting extends Model
{
    protected $fillable = [
        'school_id',
        'host_user_id',
        'meeting_type',
        'title',
        'description',
        'stream_provider',
        'recording_required',
        'recording_url',
        'recording_status',
        'meeting_link',
        'meeting_id',
        'meeting_password',
        'mux_live_stream_id',
        'mux_playback_id',
        'mux_stream_key',
        'mux_rtmp_url',
        'teacher_id',
        'class_id',
        'subject_id',
        'start_time',
        'end_time',
        'duration_minutes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_time'         => 'datetime',
        'end_time'           => 'datetime',
        'recording_required' => 'boolean',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SchoolMeetingParticipant::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function isJoinable(): bool
    {
        return in_array($this->status, ['scheduled', 'active'], true)
            && $this->meeting_link
            && $this->status !== 'provisioning';
    }
}
