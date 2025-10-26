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
        'fee_type',
        'amount',
        'due_date',
        'status',
        'description',
        'academic_year_id',
        'term_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'due_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
     * Get total amount paid
     */
    public function getTotalPaid(): float
    {
        return $this->payments()->sum('amount');
    }

    /**
     * Get remaining amount
     */
    public function getRemainingAmount(): float
    {
        return $this->amount - $this->getTotalPaid();
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
