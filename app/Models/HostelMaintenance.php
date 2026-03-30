<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HostelMaintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'room_id', 'title', 'description', 'reported_by',
        'assigned_to', 'priority', 'status', 'reported_at', 'completed_at',
        'cost', 'resolution_notes',
    ];

    protected $casts = [
        'reported_at'  => 'datetime',
        'completed_at' => 'datetime',
        'cost'         => 'decimal:2',
    ];

    public function school()     { return $this->belongsTo(School::class); }
    public function room()       { return $this->belongsTo(HostelRoom::class, 'room_id'); }
    public function reporter()   { return $this->belongsTo(User::class, 'reported_by'); }
    public function assignee()   { return $this->belongsTo(User::class, 'assigned_to'); }
}
