<?php

namespace App\Modules\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a student's fee breakdown (e.g. "Tuition" — 50,000). Copied
 * from the class FeeStructure's items when the fee is generated; edited
 * directly here when a student's fee is individually customized.
 */
class FeeItem extends Model
{
    protected $fillable = [
        'fee_id',
        'name',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }
}
