<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'name', 'description', 'color'];

    public function school() { return $this->belongsTo(School::class); }
    public function items()  { return $this->hasMany(InventoryItem::class, 'category_id'); }
}
