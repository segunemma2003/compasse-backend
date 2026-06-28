<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultIndicator extends Model
{
    protected $fillable = [
        'result_strand_id',
        'name',
        'display_order',
    ];

    public function strand(): BelongsTo
    {
        return $this->belongsTo(ResultStrand::class, 'result_strand_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(StudentIndicatorGrade::class);
    }
}
