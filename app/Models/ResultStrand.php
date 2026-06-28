<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultStrand extends Model
{
    protected $fillable = [
        'result_domain_id',
        'name',
        'display_order',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ResultDomain::class, 'result_domain_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(ResultIndicator::class)->orderBy('display_order');
    }
}
