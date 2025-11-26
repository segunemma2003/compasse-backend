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
        foreach ($this->grade_boundaries as $boundary) {
            if ($score >= $boundary['min'] && $score <= $boundary['max']) {
                return [
                    'grade' => $boundary['grade'],
                    'remark' => $boundary['remark']
                ];
            }
        }
        return ['grade' => 'F', 'remark' => 'Fail'];
    }

    /**
     * Check if score is passing
     */
    public function isPassing(float $score): bool
    {
        return $score >= $this->pass_mark;
    }
}

