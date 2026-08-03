<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlinePaymentIntent extends Model
{
    protected $fillable = [
        'school_id',
        'student_id',
        'fee_id',
        'amount',
        'reference',
        'provider',
        'status',
        'payment_id',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta'   => 'array',
    ];

    public function fee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Financial\Models\Fee::class, 'fee_id');
    }
}
