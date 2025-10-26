<?php

namespace App\Modules\Administrative\Models;

use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TransportRoute extends Model
{
    use HasFactory;

    protected $table = 'transport_routes';

    protected $fillable = [
        'school_id',
        'name',
        'description',
        'start_location',
        'end_location',
        'distance_km',
        'estimated_time_minutes',
        'fare_amount',
        'driver_id',
        'vehicle_id',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the route
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the driver assigned to this route
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Get the vehicle assigned to this route
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get all students using this route
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_routes');
    }

    /**
     * Get all stops for this route
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }

    /**
     * Get all trips for this route
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * Check if route is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get route statistics
     */
    public function getStats(): array
    {
        return [
            'total_students' => $this->students()->count(),
            'total_stops' => $this->stops()->count(),
            'total_trips' => $this->trips()->count(),
            'estimated_fare' => $this->fare_amount,
        ];
    }
}
