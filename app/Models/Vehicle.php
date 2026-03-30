<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'make', 'model', 'year', 'plate_number',
        'capacity', 'type', 'status', 'insurance_expiry',
        'last_service_date', 'notes',
    ];

    protected $casts = [
        'insurance_expiry'   => 'date',
        'last_service_date'  => 'date',
    ];

    public function school() { return $this->belongsTo(School::class); }
    public function routes() { return $this->hasMany(TransportRoute::class); }
}
