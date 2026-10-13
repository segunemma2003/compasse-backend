<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'title', 'description', 'event_type',
        'start_date', 'end_date', 'start_time', 'end_time',
        'location', 'organizer', 'target_audience', 'class_id',
        'is_all_day', 'status', 'max_participants', 'attachments', 'created_by',
    ];

    protected $casts = [
        // Plain Y-m-d in JSON: the calendar matches days by string, and the
        // default full timestamp ("2026-10-12T00:00:00.000000Z") never
        // matched, so events never showed on the calendar.
        'start_date'   => 'date:Y-m-d',
        'end_date'     => 'date:Y-m-d',
        'is_all_day'   => 'boolean',
        'attachments'  => 'array',
    ];

    public function school()     { return $this->belongsTo(School::class); }
    public function class()      { return $this->belongsTo(ClassModel::class, 'class_id'); }
    public function createdBy()  { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>=', now()->toDateString())
                     ->where('status', '!=', 'cancelled')
                     ->orderBy('start_date');
    }
}
