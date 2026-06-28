<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultCheckpoint extends Model
{
    protected $fillable = [
        'result_configuration_id',
        'label',
        'name',
        'display_order',
    ];

    public function resultConfiguration(): BelongsTo
    {
        return $this->belongsTo(ResultConfiguration::class);
    }

    public function studentGrades(): HasMany
    {
        return $this->hasMany(StudentIndicatorGrade::class);
    }
}
