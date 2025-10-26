<?php

namespace App\Modules\Administrative\Models;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory;

    protected $table = 'vehicles';

    protected $fillable = [
        'school_id',
        'registration_number',
        'vehicle_type',
        'make',
        'model',
        'year',
        'capacity',
        'driver_id',
        'status',
        'insurance_expiry',
        'license_expiry',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'insurance_expiry' => 'date',
        'license_expiry' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the vehicle
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the driver assigned to this vehicle
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Get all routes using this vehicle
     */
    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }

    /**
     * Get all trips for this vehicle
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * Check if vehicle is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if insurance is expired
     */
    public function isInsuranceExpired(): bool
    {
        return $this->insurance_expiry < now();
    }

    /**
     * Check if license is expired
     */
    public function isLicenseExpired(): bool
    {
        return $this->license_expiry < now();
    }

    /**
     * Get vehicle statistics
     */
    public function getStats(): array
    {
        return [
            'total_routes' => $this->routes()->count(),
            'total_trips' => $this->trips()->count(),
            'capacity' => $this->capacity,
            'status' => $this->status,
        ];
    }
}
