<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'item_id', 'type', 'quantity', 'remaining_quantity',
        'borrower_id', 'borrower_type', 'borrower_name', 'purpose',
        'expected_return_date', 'returned_at', 'recorded_by', 'notes', 'status',
    ];

    protected $casts = [
        'expected_return_date' => 'date',
        'returned_at'          => 'datetime',
    ];

    public function school()     { return $this->belongsTo(School::class); }
    public function item()       { return $this->belongsTo(InventoryItem::class, 'item_id'); }
    public function borrower()   { return $this->morphTo(); }
    public function recordedBy() { return $this->belongsTo(User::class, 'recorded_by'); }
}
