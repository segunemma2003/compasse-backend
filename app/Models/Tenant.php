<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    /**
     * The primary key type - can be string (UUID) or integer
     * Auto-detected based on database schema
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        // Auto-detect ID type from database schema
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('tenants')) {
                $idType = \Illuminate\Support\Facades\Schema::getColumnType('tenants', 'id');
                if ($idType === 'string' || $idType === 'varchar') {
                    $this->incrementing = false;
                    $this->keyType = 'string';
                }
            }
        } catch (\Exception $e) {
            // Default to string for stancl/tenancy compatibility
            $this->incrementing = false;
            $this->keyType = 'string';
        }
    }

    protected $fillable = [
        'id', // Allow setting ID for UUID support
        'name',
        'domain',
        'subdomain',
        'database_name',
        'database_host',
        'database_port',
        'database_username',
        'database_password',
        'status',
        'subscription_plan',
        'max_schools',
        'max_users',
        'features',
        'settings',
    ];

    protected $casts = [
        'features' => 'array',
        'settings' => 'array',
    ];

    /**
     * Get the schools for the tenant.
     */
    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    /**
     * Get the users for the tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Check if tenant is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get subscription plan.
     */
    public function getSubscriptionPlan(): string
    {
        return $this->subscription_plan ?? 'free';
    }

    /**
     * Check if tenant has a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->features ?? [];
        return in_array($feature, $features);
    }

    /**
     * Get tenant setting.
     */
    public function getSetting(string $key, $default = null)
    {
        $settings = $this->settings ?? [];
        return $settings[$key] ?? $default;
    }

    /**
     * Set tenant setting.
     */
    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
        $this->save();
    }

    /**
     * Get database connection name for tenant
     */
    public function getDatabaseConnectionName(): string
    {
        return 'tenant';
    }
}
