<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Guardian extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'user_id',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'address',
        'occupation',
        'employer',
        'relationship_to_student',
        'emergency_contact',
        'profile_picture',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the guardian
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the user account for this guardian
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all students under this guardian's care
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_students')
                    ->withPivot(['relationship', 'is_primary', 'emergency_contact'])
                    ->withTimestamps();
    }

    /**
     * Get all notifications for this guardian
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'guardian_id');
    }

    /**
     * Get all messages for this guardian
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'guardian_id');
    }

    /**
     * Get all payments made by this guardian
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'guardian_id');
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    /**
     * Get primary students (where guardian is primary contact)
     */
    public function primaryStudents(): BelongsToMany
    {
        return $this->students()->wherePivot('is_primary', true);
    }

    /**
     * Get emergency contact students
     */
    public function emergencyStudents(): BelongsToMany
    {
        return $this->students()->wherePivot('emergency_contact', true);
    }

    /**
     * Check if guardian is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get guardian's students' academic performance
     */
    public function getStudentsPerformance(): array
    {
        $students = $this->students()->with(['results', 'attendance'])->get();

        $performance = [];
        foreach ($students as $student) {
            $performance[] = [
                'student' => $student,
                'academic_performance' => $student->getAcademicPerformance(),
                'attendance_rate' => $this->calculateAttendanceRate($student),
            ];
        }

        return $performance;
    }

    /**
     * Calculate attendance rate for a student
     */
    protected function calculateAttendanceRate(Student $student): float
    {
        $totalDays = $student->attendance()->count();
        $presentDays = $student->attendance()->where('status', 'present')->count();

        return $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 0;
    }
}
