<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HostelRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'room_number', 'block', 'floor', 'type',
        'capacity', 'occupied_count', 'price_per_term', 'amenities', 'status', 'notes',
    ];

    protected $casts = [
        'amenities' => 'array',
        'price_per_term' => 'decimal:2',
    ];

    public function school()       { return $this->belongsTo(School::class); }
    public function allocations()  { return $this->hasMany(HostelAllocation::class, 'room_id'); }
    public function maintenance()  { return $this->hasMany(HostelMaintenance::class, 'room_id'); }

    public function getAvailableBedsAttribute(): int
    {
        return max(0, $this->capacity - $this->occupied_count);
    }
}
