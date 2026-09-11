<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'student_id',
        'guardian_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'status',
        'payment_terms',
        'notes',
        'billing_address',
        'shipping_address',
        'created_by',
        'sent_at',
        'paid_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'billing_address' => 'array',
        'shipping_address' => 'array',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate unique invoice number
     */
    public static function generateInvoiceNumber(int $schoolId): string
    {
        $year = date('Y');
        $month = date('m');
        $prefix = "INV-{$schoolId}-{$year}{$month}";

        $lastInvoice = self::where('school_id', $schoolId)
                          ->where('invoice_number', 'like', "{$prefix}%")
                          ->orderBy('invoice_number', 'desc')
                          ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate total amount
     */
    public function calculateTotal(): float
    {
        // Not currently called anywhere, but was summing a column named
        // 'total' that has never existed on invoice_items (see
        // InvoiceItem::$fillable comment) — would have thrown "Unknown
        // column 'total'" the moment anything invoked it.
        $subtotal = $this->items()->sum('total_price');
        $taxAmount = $this->tax_amount ?? 0;
        $discountAmount = $this->discount_amount ?? 0;

        return round($subtotal + $taxAmount - $discountAmount, 2);
    }

    /**
     * Get paid amount
     */
    public function getPaidAmount(): float
    {
        // payments.status is an enum of pending/confirmed/failed/refunded —
        // there is no 'completed' value, so this always summed to 0 (every
        // invoice looked permanently unpaid, on top of the missing
        // payments.invoice_id column crashing this query outright).
        return $this->payments()->where('status', 'confirmed')->sum('amount');
    }

    /**
     * Get outstanding amount
     */
    public function getOutstandingAmount(): float
    {
        return round($this->total_amount - $this->getPaidAmount(), 2);
    }

    /**
     * Check if invoice is paid
     */
    public function isPaid(): bool
    {
        return $this->getOutstandingAmount() <= 0;
    }

    /**
     * Check if invoice is overdue
     */
    public function isOverdue(): bool
    {
        // getRawOriginal(), not $this->status: "status" is itself an accessor
        // (getStatusAttribute() below) that calls isOverdue() as part of
        // computing its result — reading the accessor here would call back
        // into itself forever. This only ever surfaced once the two other
        // bugs in this same call chain (missing payments.invoice_id column,
        // wrong payment status value) stopped throwing first and masking it.
        return $this->getRawOriginal('status') === 'sent' &&
               $this->due_date->isPast() &&
               !$this->isPaid();
    }

    /**
     * Get days overdue
     */
    public function getDaysOverdue(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return $this->due_date->diffInDays(now());
    }

    /**
     * Mark as sent
     */
    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark as paid
     */
    public function markAsPaid(): void
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    /**
     * Cancel invoice
     */
    public function cancel(?string $reason = null): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * Get invoice status
     */
    public function getStatusAttribute($value): string
    {
        if ($this->isPaid()) {
            return 'paid';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return $value;
    }
}
