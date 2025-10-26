<?php

namespace App\Modules\Communication\Models;

use App\Models\School;
use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Guardian;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $table = 'messages';

    protected $fillable = [
        'school_id',
        'sender_id',
        'recipient_id',
        'recipient_type',
        'subject',
        'content',
        'message_type',
        'priority',
        'status',
        'sent_at',
        'read_at',
        'attachments',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'attachments' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the message
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the sender of this message
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Get the recipient of this message
     */
    public function recipient(): BelongsTo
    {
        switch ($this->recipient_type) {
            case 'student':
                return $this->belongsTo(Student::class, 'recipient_id');
            case 'teacher':
                return $this->belongsTo(Teacher::class, 'recipient_id');
            case 'guardian':
                return $this->belongsTo(Guardian::class, 'recipient_id');
            default:
                return $this->belongsTo(User::class, 'recipient_id');
        }
    }

    /**
     * Get all replies to this message
     */
    public function replies(): HasMany
    {
        return $this->hasMany(MessageReply::class);
    }

    /**
     * Check if message is read
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Check if message is sent
     */
    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * Check if message is draft
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Get priority description
     */
    public function getPriorityDescription(): string
    {
        $descriptions = [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'urgent' => 'Urgent',
        ];

        return $descriptions[$this->priority] ?? 'Normal';
    }

    /**
     * Get message type description
     */
    public function getMessageTypeDescription(): string
    {
        $descriptions = [
            'general' => 'General',
            'academic' => 'Academic',
            'financial' => 'Financial',
            'attendance' => 'Attendance',
            'emergency' => 'Emergency',
        ];

        return $descriptions[$this->message_type] ?? 'General';
    }
}
