<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'from_class_id',
        'to_class_id',
        'academic_year_id',
        'status',
        'reason',
        'approved_by',
        'promoted_at',
    ];

    protected $casts = [
        'promoted_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'from_class_id');
    }

    public function toClass(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'to_class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

