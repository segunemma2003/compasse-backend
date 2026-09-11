<?php

namespace App\Modules\Financial\Models;

use App\Models\School;
use App\Models\Student;
use App\Models\Guardian;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
     * Generate a unique payment_reference for payments the caller didn't
     * supply one for (e.g. cash) — the column is NOT NULL and unique, so
     * leaving it null (as both FeeController::pay() and
     * PaymentController::store() used to) crashed the insert outright
     * with "Column 'payment_reference' cannot be null" on every payment
     * that didn't come with an external reference already.
     */
    public static function generateReference(int $schoolId): string
    {
        do {
            $reference = 'PAY-' . $schoolId . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
        } while (self::where('payment_reference', $reference)->exists());

        return $reference;
    }

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
        // payments.status is an enum of pending/confirmed/failed/refunded —
        // 'successful' has never been a real value (see the FeeController::
        // pay() fix in the same change), so this always returned false.
        return $this->status === 'confirmed';
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
