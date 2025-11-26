<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CAScore extends Model
{
    use HasFactory;

    protected $table = 'ca_scores';

    protected $fillable = [
        'continuous_assessment_id',
        'student_id',
        'score',
        'remarks',
        'recorded_by',
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function continuousAssessment(): BelongsTo
    {
        return $this->belongsTo(ContinuousAssessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'recorded_by');
    }
}

