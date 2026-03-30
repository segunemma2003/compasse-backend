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
        'start_date'   => 'date',
        'end_date'     => 'date',
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
