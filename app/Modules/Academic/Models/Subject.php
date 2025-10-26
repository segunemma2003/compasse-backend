<?php

namespace App\Modules\Academic\Models;

use App\Models\School;
use App\Modules\Academic\Models\ClassModel;
use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    use HasFactory;

    protected $table = 'subjects';

    protected $fillable = [
        'school_id',
        'class_id',
        'name',
        'code',
        'description',
        'credits',
        'teacher_id',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the subject
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the class this subject belongs to
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * Get the teacher assigned to this subject
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Get all students taking this subject
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_subjects');
    }

    /**
     * Get all teachers teaching this subject
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'teacher_subjects');
    }

    /**
     * Get all assignments for this subject
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(\App\Modules\Assessment\Models\Assignment::class);
    }

    /**
     * Get all exams for this subject
     */
    public function exams(): HasMany
    {
        return $this->hasMany(\App\Modules\Assessment\Models\Exam::class);
    }

    /**
     * Get all results for this subject
     */
    public function results(): HasMany
    {
        return $this->hasMany(\App\Modules\Assessment\Models\Result::class);
    }

    /**
     * Get subject statistics
     */
    public function getStats(): array
    {
        return [
            'total_students' => $this->students()->count(),
            'total_teachers' => $this->teachers()->count(),
            'total_assignments' => $this->assignments()->count(),
            'total_exams' => $this->exams()->count(),
        ];
    }
}
