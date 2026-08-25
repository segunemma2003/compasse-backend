<?php

namespace App\Modules\Financial\Models;

use App\Models\School;
use App\Modules\Academic\Models\ClassModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class's fee plan — a named set of line items (breakdown) whose sum is
 * total_amount, applied to every student in the class to generate their
 * individual `fees` row. Editing a structure's items propagates to every
 * linked fee that hasn't been individually customized (Fee::is_customized).
 */
class FeeStructure extends Model
{
    protected $table = 'fee_structures';

    protected $fillable = [
        'school_id',
        'name',
        'class_id',
        'arm_id',
        'academic_year_id',
        'term_id',
        'total_amount',
        'frequency',
        'description',
        'due_date',
        'is_mandatory',
        'status',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'is_mandatory' => 'boolean',
        'total_amount' => 'decimal:2',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    public function recalculateTotal(): float
    {
        $total = (float) $this->items()->sum('amount');
        $this->update(['total_amount' => $total]);

        return $total;
    }
}
