<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolMeetingParticipant extends Model
{
    protected $fillable = [
        'school_meeting_id',
        'user_id',
        'role',
        'invited_at',
        'joined_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'joined_at'  => 'datetime',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(SchoolMeeting::class, 'school_meeting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
