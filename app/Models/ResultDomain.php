<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultDomain extends Model
{
    protected $fillable = [
        'result_configuration_id',
        'name',
        'color',
        'display_order',
    ];

    public function resultConfiguration(): BelongsTo
    {
        return $this->belongsTo(ResultConfiguration::class);
    }

    public function strands(): HasMany
    {
        return $this->hasMany(ResultStrand::class)->orderBy('display_order');
    }

    public function studentComments(): HasMany
    {
        return $this->hasMany(StudentDomainComment::class);
    }
}
