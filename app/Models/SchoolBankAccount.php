<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account a school wants fee/invoice payments made into. Shown on
 * invoices/receipts and fee reminder emails so a guardian knows where to
 * pay, without the school needing a payment gateway configured.
 */
class SchoolBankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'bank_name',
        'account_name',
        'account_number',
        'notes',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
