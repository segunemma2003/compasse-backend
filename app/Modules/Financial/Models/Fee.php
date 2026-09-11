<?php

namespace App\Modules\Financial\Models;

use App\Models\School;
use App\Models\Student;
use App\Modules\Academic\Models\ClassModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fee extends Model
{
    use HasFactory;

    protected $table = 'fees';

    protected $fillable = [
        'school_id',
        'student_id',
        'class_id',
        'fee_structure_id',
        'is_customized',
        'fee_type',
        'amount',
        // amount_paid/balance were missing here even though the fees table
        // has both columns (see 2026_01_18_000001_create_financial_tables)
        // and FeeController::pay() needs to update them when a payment is
        // recorded — without this, every payment was accepted but never
        // actually applied to the fee's outstanding balance.
        'amount_paid',
        'balance',
        'due_date',
        'status',
        'description',
        'academic_year_id',
        'term_id',
        'last_reminded_at',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'due_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_customized' => 'boolean',
        'last_reminded_at' => 'datetime',
    ];

    /**
     * Get the school that owns the fee
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the student this fee belongs to
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the class this fee belongs to
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * Get all payments for this fee
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The plan this fee was generated from, if any.
     */
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    /**
     * Line-item breakdown (e.g. Tuition, Sports, PTA) summing to `amount`.
     */
    public function items(): HasMany
    {
        return $this->hasMany(FeeItem::class);
    }

    /**
     * Check if fee is paid
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Check if fee is overdue
     */
    public function isOverdue(): bool
    {
        return $this->due_date < now() && $this->status !== 'paid';
    }

    /**
     * Get total amount paid.
     *
     * Reads the stored amount_paid column, which FeeController::pay() keeps
     * in sync on every payment — the source of truth every other read path
     * (summary(), feeBreakdown(), feeVoucher()) already uses via raw SQL.
     * Previously this summed payments()->sum('amount') with no status
     * filter, which would have double-counted against amount_paid once
     * both were tracked, and counted failed/refunded payments as paid.
     */
    public function getTotalPaid(): float
    {
        return (float) $this->amount_paid;
    }

    /**
     * Get remaining amount owed.
     */
    public function getRemainingAmount(): float
    {
        return max(0, (float) $this->balance);
    }

    /**
     * Get fee statistics
     */
    public function getStats(): array
    {
        return [
            'total_amount' => $this->amount,
            'total_paid' => $this->getTotalPaid(),
            'remaining_amount' => $this->getRemainingAmount(),
            'payment_count' => $this->payments()->count(),
            'status' => $this->status,
        ];
    }
}
