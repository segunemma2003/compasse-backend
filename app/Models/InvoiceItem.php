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
        // The real column (see the invoice_items migration) is total_price —
        // InvoiceController::store() has always written total_price, but it
        // was never in this list, so Eloquent's mass-assignment guard
        // silently dropped it and every line item saved with the column's
        // 0 default regardless of quantity/unit_price. The invoice's own
        // subtotal/total_amount were unaffected (the controller sums the
        // raw input, not the saved rows), but any per-item total shown
        // from the saved InvoiceItem was always wrong.
        'total_price',
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
        'total_price' => 'decimal:2',
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
