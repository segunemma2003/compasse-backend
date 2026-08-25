<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPaymentIntent extends Model
{
    protected $fillable = [
        'school_id',
        'plan_id',
        'subscription_id',
        'amount',
        'currency',
        'reference',
        'provider',
        'status',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta'   => 'array',
    ];

    /**
     * Subscriptions/plans live in the central database — this table follows them
     * so an intent can be resolved and completed from the async gateway webhook,
     * which has no tenant session to infer the connection from.
     */
    public function getConnectionName(): string
    {
        return (string) config('tenancy.database.central_connection', parent::getConnectionName());
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
