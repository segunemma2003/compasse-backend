<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_result_id',
        'subject_id',
        'ca_total',
        'exam_score',
        'total_score',
        'grade',
        'teacher_remark',
        'position',
        'highest_score',
        'lowest_score',
        'class_average',
    ];

    protected $casts = [
        'ca_total' => 'decimal:2',
        'exam_score' => 'decimal:2',
        'total_score' => 'decimal:2',
        'class_average' => 'decimal:2',
        'position' => 'integer',
        'highest_score' => 'integer',
        'lowest_score' => 'integer',
    ];

    public function studentResult(): BelongsTo
    {
        return $this->belongsTo(StudentResult::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}

