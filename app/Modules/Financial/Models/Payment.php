<?php

namespace App\Modules\Financial\Models;

use App\Models\School;
use App\Models\Student;
use App\Models\Guardian;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'school_id',
        'student_id',
        'guardian_id',
        'fee_id',
        'amount',
        'payment_method',
        'payment_reference',
        'payment_date',
        'status',
        'notes',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the payment
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the student this payment belongs to
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the guardian who made this payment
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /**
     * Get the fee this payment is for
     */
    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    /**
     * Check if payment is successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'successful';
    }

    /**
     * Check if payment is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment is failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get payment method description
     */
    public function getPaymentMethodDescription(): string
    {
        $descriptions = [
            'cash' => 'Cash',
            'card' => 'Credit/Debit Card',
            'bank_transfer' => 'Bank Transfer',
            'mobile_money' => 'Mobile Money',
            'check' => 'Check',
        ];

        return $descriptions[$this->payment_method] ?? 'Unknown';
    }

    /**
     * Get payment summary
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'payment_method' => $this->getPaymentMethodDescription(),
            'payment_reference' => $this->payment_reference,
            'payment_date' => $this->payment_date,
            'status' => $this->status,
            'student' => $this->student->name,
            'guardian' => $this->guardian->name ?? 'N/A',
        ];
    }
}
