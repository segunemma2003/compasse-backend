<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\DatabaseConfig;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasFactory, HasDatabase, HasDomains;

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
        // Don't cast settings as array - stancl/tenancy stores it in JSON data column
        // Casting it would cause double encoding issues
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

    /**
     * Get database name for stancl/tenancy compatibility
     * This method is required by TenantWithDatabase interface
     */
    public function getDatabaseName(): string
    {
        return $this->database_name ?? '';
    }

    /**
     * Set database name for stancl/tenancy compatibility
     */
    public function setDatabaseName(string $databaseName): void
    {
        $this->database_name = $databaseName;
        $this->save();
    }

    /**
     * Get internal database name for stancl/tenancy
     * Maps our database_name attribute to stancl/tenancy's internal format
     */
    public function getInternal(string $key): mixed
    {
        // Map our custom attributes to stancl/tenancy's internal keys
        $mapping = [
            'db_name' => 'database_name',
            'db_username' => 'database_username',
            'db_password' => 'database_password',
            'db_host' => 'database_host',
            'db_port' => 'database_port',
        ];

        if (isset($mapping[$key])) {
            return $this->getAttribute($mapping[$key]);
        }

        // For settings, get from data column if it exists
        if ($key === 'settings' && $this->attributes['settings'] ?? null) {
            // If settings is stored as JSON string in data column, decode it
            $settings = $this->attributes['settings'];
            if (is_string($settings)) {
                $decoded = json_decode($settings, true);
                return $decoded !== null ? $decoded : [];
            }
            return is_array($settings) ? $settings : [];
        }

        // Fallback to parent's getInternal if it exists
        if (method_exists(parent::class, 'getInternal')) {
            return parent::getInternal($key);
        }

        return null;
    }

    /**
     * Set internal database name for stancl/tenancy
     * Maps stancl/tenancy's internal keys to our custom attributes
     */
    public function setInternal(string $key, mixed $value): void
    {
        // Map stancl/tenancy's internal keys to our custom attributes
        $mapping = [
            'db_name' => 'database_name',
            'db_username' => 'database_username',
            'db_password' => 'database_password',
            'db_host' => 'database_host',
            'db_port' => 'database_port',
        ];

        if (isset($mapping[$key])) {
            $this->setAttribute($mapping[$key], $value);
        } elseif ($key === 'settings') {
            // Store settings as JSON string (stancl/tenancy will handle it in data column)
            $this->setAttribute('settings', is_array($value) ? json_encode($value) : $value);
        } elseif (method_exists(parent::class, 'setInternal')) {
            parent::setInternal($key, $value);
        }
    }

    /**
     * Get settings attribute (decode JSON if needed)
     */
    public function getSettingsAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Set settings attribute (encode as JSON)
     */
    public function setSettingsAttribute($value)
    {
        $this->attributes['settings'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Override save to ensure fields are saved to actual columns, not just data JSON
     */
    public function save(array $options = [])
    {
        $columnFields = [
            'id', 'name', 'domain', 'subdomain', 'database_name', 'database_host',
            'database_port', 'database_username', 'database_password', 'status',
            'subscription_plan', 'max_schools', 'max_users', 'features', 'settings'
        ];

        $saveData = [];
        foreach ($columnFields as $field) {
            if (isset($this->attributes[$field])) {
                $value = $this->attributes[$field];
                $saveData[$field] = $value;
            }
        }

        if ($this->exists) {
            if (!empty($saveData)) {
                $saveData['updated_at'] = $this->freshTimestamp();
                \Illuminate\Support\Facades\DB::table('tenants')
                    ->where('id', $this->id)
                    ->update($saveData);
            }
        } else {
            if (!empty($saveData)) {
                $saveData['created_at'] = $this->freshTimestamp();
                $saveData['updated_at'] = $this->freshTimestamp();

                if (isset($saveData['id'])) {
                    \Illuminate\Support\Facades\DB::table('tenants')->insert($saveData);
                    $this->exists = true;
                    $this->wasRecentlyCreated = true;
                } else {
                    $id = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId($saveData);
                    $this->setAttribute('id', $id);
                    $this->exists = true;
                    $this->wasRecentlyCreated = true;
                }
            }
        }

        return parent::save($options);
    }

    /**
     * Override performInsert to ensure fields are saved to actual columns
     */
    protected function performInsert(\Illuminate\Database\Eloquent\Builder $query)
    {
        $columnFields = [
            'id', 'name', 'domain', 'subdomain', 'database_name', 'database_host',
            'database_port', 'database_username', 'database_password', 'status',
            'subscription_plan', 'max_schools', 'max_users', 'features', 'settings'
        ];

        $insertData = [];
        foreach ($columnFields as $field) {
            if (isset($this->attributes[$field])) {
                $value = $this->attributes[$field];
                if ($value !== null && $value !== '') {
                    $insertData[$field] = $value;
                }
            }
        }

        if (!empty($insertData)) {
            $insertData['created_at'] = $this->freshTimestamp();
            $insertData['updated_at'] = $this->freshTimestamp();

            if (isset($insertData['id'])) {
                \Illuminate\Support\Facades\DB::table('tenants')->insert($insertData);
                $this->exists = true;
                $this->wasRecentlyCreated = true;
                return $this;
            } else {
                $id = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId($insertData);
                $this->setAttribute('id', $id);
                $this->exists = true;
                $this->wasRecentlyCreated = true;
                return $this;
            }
        }

        return parent::performInsert($query);
    }

    /**
     * Override performUpdate to ensure fields are saved to actual columns
     */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        $columnFields = [
            'id', 'name', 'domain', 'subdomain', 'database_name', 'database_host',
            'database_port', 'database_username', 'database_password', 'status',
            'subscription_plan', 'max_schools', 'max_users', 'features', 'settings'
        ];

        $updateData = [];
        foreach ($columnFields as $field) {
            if ($this->isDirty($field) && isset($this->attributes[$field])) {
                $value = $this->attributes[$field];
                if ($value !== null && $value !== '') {
                    $updateData[$field] = $value;
                } else {
                    $updateData[$field] = null;
                }
            }
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = $this->freshTimestamp();
            \Illuminate\Support\Facades\DB::table('tenants')
                ->where('id', $this->id)
                ->update($updateData);
        }

        return parent::performUpdate($query);
    }
}
