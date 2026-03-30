<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'category_id', 'name', 'description', 'sku',
        'quantity', 'unit', 'min_quantity', 'unit_price',
        'location', 'supplier', 'status',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
    ];

    public function school()       { return $this->belongsTo(School::class); }
    public function category()     { return $this->belongsTo(InventoryCategory::class, 'category_id'); }
    public function transactions() { return $this->hasMany(InventoryTransaction::class, 'item_id'); }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->min_quantity;
    }
}
