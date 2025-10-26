<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'total',
        'tax_rate',
        'tax_amount',
        'discount_rate',
        'discount_amount',
        'item_type',
        'item_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Calculate total for item
     */
    public function calculateTotal(): float
    {
        $subtotal = $this->quantity * $this->unit_price;
        $discountAmount = $subtotal * ($this->discount_rate / 100);
        $afterDiscount = $subtotal - $discountAmount;
        $taxAmount = $afterDiscount * ($this->tax_rate / 100);

        return round($afterDiscount + $taxAmount, 2);
    }

    /**
     * Calculate tax amount
     */
    public function calculateTaxAmount(): float
    {
        $subtotal = $this->quantity * $this->unit_price;
        $discountAmount = $subtotal * ($this->discount_rate / 100);
        $afterDiscount = $subtotal - $discountAmount;

        return round($afterDiscount * ($this->tax_rate / 100), 2);
    }

    /**
     * Calculate discount amount
     */
    public function calculateDiscountAmount(): float
    {
        $subtotal = $this->quantity * $this->unit_price;

        return round($subtotal * ($this->discount_rate / 100), 2);
    }
}
