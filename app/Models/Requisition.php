<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff request for money or items: a requisition, or an imprest (cash advance retired later). */
class Requisition extends Model
{
    protected $fillable = [
        'type', 'number', 'title', 'purpose', 'department', 'items', 'amount', 'needed_by', 'status',
        'requested_by', 'approved_by', 'approved_at', 'decision_note', 'amount_approved',
        'disbursed_by', 'disbursed_at', 'disbursement_method', 'expense_id',
        'amount_spent', 'amount_returned', 'retirement_note', 'retirement_items', 'retired_at',
    ];

    protected $casts = [
        'items' => 'array',
        'retirement_items' => 'array',
        'amount' => 'decimal:2',
        'amount_approved' => 'decimal:2',
        'amount_spent' => 'decimal:2',
        'amount_returned' => 'decimal:2',
        'needed_by' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
