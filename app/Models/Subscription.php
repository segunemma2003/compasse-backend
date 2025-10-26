<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'plan_id',
        'status',
        'start_date',
        'end_date',
        'trial_end_date',
        'is_trial',
        'auto_renew',
        'payment_method',
        'billing_cycle',
        'amount',
        'currency',
        'features',
        'limits',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'trial_end_date' => 'datetime',
        'is_trial' => 'boolean',
        'auto_renew' => 'boolean',
        'features' => 'array',
        'limits' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the subscription
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the plan for this subscription
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get all payments for this subscription
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Check if subscription is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->end_date > now();
    }

    /**
     * Check if subscription is in trial
     */
    public function isTrial(): bool
    {
        return $this->is_trial && $this->trial_end_date > now();
    }

    /**
     * Check if subscription is expired
     */
    public function isExpired(): bool
    {
        return $this->end_date <= now();
    }

    /**
     * Check if subscription is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Get days remaining
     */
    public function getDaysRemaining(): int
    {
        if ($this->isTrial()) {
            return now()->diffInDays($this->trial_end_date);
        }

        return now()->diffInDays($this->end_date);
    }

    /**
     * Check if school has access to a module
     */
    public function hasModule(string $module): bool
    {
        if (!$this->isActive() && !$this->isTrial()) {
            return false;
        }

        $features = $this->features ?? [];
        return in_array($module, $features);
    }

    /**
     * Check if school has access to a feature
     */
    public function hasFeature(string $feature): bool
    {
        if (!$this->isActive() && !$this->isTrial()) {
            return false;
        }

        $features = $this->features ?? [];
        return in_array($feature, $features);
    }

    /**
     * Get usage for a limit
     */
    public function getUsage(string $limit): int
    {
        $limits = $this->limits ?? [];
        return $limits[$limit] ?? 0;
    }

    /**
     * Check if limit is exceeded
     */
    public function isLimitExceeded(string $limit, int $currentUsage): bool
    {
        $maxLimit = $this->plan->limits[$limit] ?? 0;
        return $currentUsage >= $maxLimit;
    }

    /**
     * Get subscription status
     */
    public function getStatus(): string
    {
        if ($this->isTrial()) {
            return 'trial';
        }

        if ($this->isActive()) {
            return 'active';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isCancelled()) {
            return 'cancelled';
        }

        return $this->status;
    }

    /**
     * Get subscription summary
     */
    public function getSummary(): array
    {
        return [
            'status' => $this->getStatus(),
            'plan' => $this->plan->name,
            'features' => $this->features ?? [],
            'limits' => $this->limits ?? [],
            'days_remaining' => $this->getDaysRemaining(),
            'is_trial' => $this->isTrial(),
            'auto_renew' => $this->auto_renew,
            'next_billing_date' => $this->end_date,
        ];
    }
}
