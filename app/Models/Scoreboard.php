<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scoreboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'term_id',
        'academic_year_id',
        'rankings',
        'class_average',
        'total_students',
        'pass_rate',
        'last_updated',
    ];

    protected $casts = [
        'rankings' => 'array',
        'class_average' => 'decimal:2',
        'total_students' => 'integer',
        'pass_rate' => 'integer',
        'last_updated' => 'datetime',
    ];

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}

