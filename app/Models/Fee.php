<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fee extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'student_id',
        'class_id',
        'academic_year_id',
        'term_id',
        'fee_type',
        'amount',
        'amount_paid',
        'balance',
        'due_date',
        'status',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Amount still owed. Uses stored balance if set, otherwise derives from amount - amount_paid.
     */
    public function getRemainingAmount(): float
    {
        if ($this->balance !== null) {
            return (float) $this->balance;
        }
        return max(0, (float) $this->amount - (float) ($this->amount_paid ?? 0));
    }

    /**
     * Summary stats used by FeeController::show().
     */
    public function getStats(): array
    {
        $total   = (float) $this->amount;
        $paid    = (float) ($this->amount_paid ?? 0);
        $balance = $this->getRemainingAmount();

        return [
            'total_amount'   => $total,
            'amount_paid'    => $paid,
            'balance'        => $balance,
            'payment_percent'=> $total > 0 ? round(($paid / $total) * 100, 1) : 0,
            'is_overdue'     => $this->due_date && $this->due_date->isPast() && $balance > 0,
        ];
    }
}

