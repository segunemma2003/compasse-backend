<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryBorrow extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'book_id',
        'borrower_id',
        'borrower_type',
        'borrowed_at',
        'due_date',
        'returned_at',
        'status',
        'fine_amount',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'borrowed_at' => 'datetime',
        'due_date' => 'date',
        'returned_at' => 'datetime',
        'approved_at' => 'datetime',
        'fine_amount' => 'decimal:2',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'book_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->morphTo('borrower');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Check if borrow is overdue
     */
    public function isOverdue(): bool
    {
        return $this->status === 'borrowed' && $this->due_date->isPast();
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
     * Calculate fine amount
     */
    public function calculateFine(float $dailyFineRate = 1.0): float
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        $overdueDays = $this->getDaysOverdue();
        return round($overdueDays * $dailyFineRate, 2);
    }

    /**
     * Mark as returned
     */
    public function markAsReturned(): void
    {
        $this->update([
            'status' => 'returned',
            'returned_at' => now(),
        ]);
    }

    /**
     * Extend due date
     */
    public function extendDueDate(int $additionalDays = 7): void
    {
        $this->update([
            'due_date' => $this->due_date->addDays($additionalDays),
        ]);
    }
}
