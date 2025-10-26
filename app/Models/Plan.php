<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'price',
        'currency',
        'billing_cycle',
        'trial_days',
        'features',
        'limits',
        'modules',
        'is_active',
        'is_popular',
        'sort_order',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'features' => 'array',
        'limits' => 'array',
        'modules' => 'array',
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all subscriptions for this plan
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Check if plan is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if plan is popular
     */
    public function isPopular(): bool
    {
        return $this->is_popular;
    }

    /**
     * Get price per month
     */
    public function getPricePerMonth(): float
    {
        switch ($this->billing_cycle) {
            case 'monthly':
                return $this->price;
            case 'yearly':
                return $this->price / 12;
            case 'quarterly':
                return $this->price / 3;
            default:
                return $this->price;
        }
    }

    /**
     * Get price per year
     */
    public function getPricePerYear(): float
    {
        switch ($this->billing_cycle) {
            case 'monthly':
                return $this->price * 12;
            case 'yearly':
                return $this->price;
            case 'quarterly':
                return $this->price * 4;
            default:
                return $this->price;
        }
    }

    /**
     * Check if plan has a module
     */
    public function hasModule(string $module): bool
    {
        $modules = $this->modules ?? [];
        return in_array($module, $modules);
    }

    /**
     * Check if plan has a feature
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->features ?? [];
        return in_array($feature, $features);
    }

    /**
     * Get limit for a feature
     */
    public function getLimit(string $limit): int
    {
        $limits = $this->limits ?? [];
        return $limits[$limit] ?? 0;
    }

    /**
     * Get plan modules
     */
    public function getModules(): array
    {
        return $this->modules ?? [];
    }

    /**
     * Get plan features
     */
    public function getFeatures(): array
    {
        return $this->features ?? [];
    }

    /**
     * Get plan limits
     */
    public function getLimits(): array
    {
        return $this->limits ?? [];
    }

    /**
     * Get plan summary
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing_cycle' => $this->billing_cycle,
            'price_per_month' => $this->getPricePerMonth(),
            'price_per_year' => $this->getPricePerYear(),
            'trial_days' => $this->trial_days,
            'modules' => $this->getModules(),
            'features' => $this->getFeatures(),
            'limits' => $this->getLimits(),
            'is_active' => $this->isActive(),
            'is_popular' => $this->isPopular(),
        ];
    }
}
