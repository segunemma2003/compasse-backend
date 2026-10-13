<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReminder extends Model
{
    public const AUDIENCES = ['everyone', 'parents', 'class_parents', 'staff', 'teachers', 'students'];

    protected $fillable = [
        'event_id', 'audience', 'class_id', 'subject', 'message',
        'send_at', 'status', 'recipients_count', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
