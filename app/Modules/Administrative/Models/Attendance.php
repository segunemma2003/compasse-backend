<?php

namespace App\Modules\Administrative\Models;

use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Modules\Academic\Models\ClassModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendance';

    protected $fillable = [
        'school_id',
        'student_id',
        'teacher_id',
        'class_id',
        'date',
        'status',
        'reason',
        'marked_by',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the attendance record
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the student this attendance record belongs to
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the teacher this attendance record belongs to
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Get the class this attendance record belongs to
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * Get the teacher who marked this attendance
     */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'marked_by');
    }

    /**
     * Check if attendance is present
     */
    public function isPresent(): bool
    {
        return $this->status === 'present';
    }

    /**
     * Check if attendance is absent
     */
    public function isAbsent(): bool
    {
        return $this->status === 'absent';
    }

    /**
     * Check if attendance is late
     */
    public function isLate(): bool
    {
        return $this->status === 'late';
    }

    /**
     * Get attendance status description
     */
    public function getStatusDescription(): string
    {
        $descriptions = [
            'present' => 'Present',
            'absent' => 'Absent',
            'late' => 'Late',
            'excused' => 'Excused',
        ];

        return $descriptions[$this->status] ?? 'Unknown';
    }
}
