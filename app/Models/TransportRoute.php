<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'vehicle_id', 'driver_id', 'name', 'route_code',
        'description', 'start_point', 'end_point', 'stops',
        'distance_km', 'fare', 'morning_pickup_time',
        'afternoon_dropoff_time', 'status',
    ];

    protected $casts = [
        'stops'    => 'array',
        'fare'     => 'decimal:2',
        'distance_km' => 'decimal:2',
    ];

    public function school()   { return $this->belongsTo(School::class); }
    public function vehicle()  { return $this->belongsTo(Vehicle::class); }
    public function driver()   { return $this->belongsTo(Driver::class); }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_transport_routes', 'route_id', 'student_id')
                    ->withPivot('pickup_stop', 'dropoff_stop')
                    ->withTimestamps();
    }
}
