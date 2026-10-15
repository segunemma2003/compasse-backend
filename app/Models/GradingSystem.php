<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradingSystem extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'description',
        'grade_boundaries',
        'pass_mark',
        'is_default',
        'status',
    ];

    protected $casts = [
        'grade_boundaries' => 'array',
        'pass_mark' => 'decimal:2',
        'is_default' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get grade based on score
     */
    public function getGrade(float $score): array
    {
        // Highest band whose "from" the score reaches, so decimals between bands
        // (79.5 between 70–79 and 80–100) get the lower band instead of falling through to F.
        $bands = collect($this->grade_boundaries ?? [])->sortByDesc(fn ($b) => (float) ($b['min'] ?? 0))->values();
        foreach ($bands as $boundary) {
            if ($score >= (float) $boundary['min']) {
                return [
                    'grade' => $boundary['grade'],
                    'remark' => $boundary['remark'] ?? null,
                    'point' => $boundary['point'] ?? null,
                ];
            }
        }
        $lowest = $bands->last();

        return $lowest ? ['grade' => $lowest['grade'], 'remark' => $lowest['remark'] ?? null, 'point' => $lowest['point'] ?? null]
            : ['grade' => 'F', 'remark' => 'Fail', 'point' => null];
    }

    /**
     * Check if score is passing
     */
    public function isPassing(float $score): bool
    {
        return $score >= $this->pass_mark;
    }
}

