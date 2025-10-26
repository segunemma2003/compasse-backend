<?php

namespace App\Modules\Administrative\Models;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    use HasFactory;

    protected $table = 'drivers';

    protected $fillable = [
        'school_id',
        'name',
        'phone',
        'email',
        'license_number',
        'license_expiry',
        'address',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'license_expiry' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the driver
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get all vehicles assigned to this driver
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Get all routes assigned to this driver
     */
    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }

    /**
     * Get all trips for this driver
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * Check if driver is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if license is expired
     */
    public function isLicenseExpired(): bool
    {
        return $this->license_expiry < now();
    }

    /**
     * Get driver statistics
     */
    public function getStats(): array
    {
        return [
            'total_vehicles' => $this->vehicles()->count(),
            'total_routes' => $this->routes()->count(),
            'total_trips' => $this->trips()->count(),
            'status' => $this->status,
        ];
    }
}
