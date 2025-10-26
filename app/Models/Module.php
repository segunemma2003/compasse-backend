<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'category',
        'features',
        'requirements',
        'is_active',
        'is_core',
        'sort_order',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'features' => 'array',
        'requirements' => 'array',
        'is_active' => 'boolean',
        'is_core' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all plans that include this module
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_modules');
    }

    /**
     * Check if module is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if module is core
     */
    public function isCore(): bool
    {
        return $this->is_core;
    }

    /**
     * Get module features
     */
    public function getFeatures(): array
    {
        return $this->features ?? [];
    }

    /**
     * Get module requirements
     */
    public function getRequirements(): array
    {
        return $this->requirements ?? [];
    }

    /**
     * Check if module has a feature
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->getFeatures();
        return in_array($feature, $features);
    }

    /**
     * Get module summary
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'category' => $this->category,
            'features' => $this->getFeatures(),
            'requirements' => $this->getRequirements(),
            'is_active' => $this->isActive(),
            'is_core' => $this->isCore(),
        ];
    }
}
