<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'user_id',
        'admission_number',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'username',
        'phone',
        'address',
        'date_of_birth',
        'gender',
        'blood_group',
        'parent_name',
        'parent_phone',
        'parent_email',
        'emergency_contact',
        'admission_date',
        'class_id',
        'arm_id',
        'status',
        'profile_picture',
        'medical_info',
        'transport_info',
        'hostel_info',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
        'medical_info' => 'array',
        'transport_info' => 'array',
        'hostel_info' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the school that owns the student
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the user account for this student
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the class this student belongs to
     */
    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * Get the arm this student belongs to
     */
    public function arm(): BelongsTo
    {
        return $this->belongsTo(Arm::class);
    }

    /**
     * Get all subjects for this student
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'student_subjects');
    }

    /**
     * Get all attendance records for this student
     */
    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get all exam results for this student
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /**
     * Get all assignments for this student
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Get all guardians for this student
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'guardian_students')
                    ->withPivot(['relationship', 'is_primary', 'emergency_contact'])
                    ->withTimestamps();
    }

    /**
     * Get primary guardian (main contact)
     */
    public function primaryGuardian(): ?Guardian
    {
        return $this->guardians()->wherePivot('is_primary', true)->first();
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    /**
     * Get student's current class and arm
     */
    public function getCurrentClass(): string
    {
        if ($this->class && $this->arm) {
            return $this->class->name . ' ' . $this->arm->name;
        }

        return $this->class ? $this->class->name : 'Not Assigned';
    }

    /**
     * Get student's age
     */
    public function getAge(): int
    {
        return $this->date_of_birth ? $this->date_of_birth->age : 0;
    }

    /**
     * Check if student is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get student's academic performance
     */
    public function getAcademicPerformance(): array
    {
        $results = $this->results()->with('exam')->get();

        return [
            'total_exams' => $results->count(),
            'average_score' => $results->avg('total_score'),
            'highest_score' => $results->max('total_score'),
            'lowest_score' => $results->min('total_score'),
        ];
    }

    /**
     * Generate unique admission number for student
     */
    public static function generateAdmissionNumber(int $schoolId, int $classId = null): string
    {
        $school = School::find($schoolId);
        if (!$school) {
            throw new \Exception('School not found');
        }

        // Get school abbreviation (first 2-3 letters of school name)
        $schoolAbbr = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $school->name), 0, 3));

        // Get current year
        $currentYear = date('Y');

        // Get class abbreviation if class is provided
        $classAbbr = '';
        if ($classId) {
            $class = ClassModel::find($classId);
            if ($class) {
                $classAbbr = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $class->name), 0, 2));
            }
        }

        // Generate sequence number
        $lastStudent = self::where('school_id', $schoolId)
            ->where('admission_number', 'like', $schoolAbbr . $currentYear . '%')
            ->orderBy('admission_number', 'desc')
            ->first();

        $sequence = 1;
        if ($lastStudent && $lastStudent->admission_number) {
            $lastSequence = (int) substr($lastStudent->admission_number, -4);
            $sequence = $lastSequence + 1;
        }

        // Format: SCHOOL_ABBR + YEAR + CLASS_ABBR + 4-digit sequence
        // Example: ABC2025SS001, XYZ2025JS001
        $admissionNumber = $schoolAbbr . $currentYear . $classAbbr . str_pad($sequence, 3, '0', STR_PAD_LEFT);

        // Ensure uniqueness
        while (self::where('admission_number', $admissionNumber)->exists()) {
            $sequence++;
            $admissionNumber = $schoolAbbr . $currentYear . $classAbbr . str_pad($sequence, 3, '0', STR_PAD_LEFT);
        }

        return $admissionNumber;
    }

    /**
     * Generate email for student using school domain
     * Pattern: firstname.lastname{student_id}@schoolurl
     */
    public static function generateStudentEmail(string $firstName, string $lastName, int $schoolId, int $studentId = null): string
    {
        $school = School::find($schoolId);
        if (!$school) {
            throw new \Exception('School not found');
        }

        // Extract domain from school website or use subdomain
        if ($school->website) {
            // Remove http://, https://, www. from website
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website);
            // Remove trailing slash
            $domain = rtrim($domain, '/');
        } else {
            // Fallback to subdomain
            $tenant = $school->tenant;
            $domain = $tenant ? $tenant->subdomain . '.samschool.com' : self::getSchoolDomain($school->name);
        }

        // Clean names (remove special characters, convert to lowercase)
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));

        // Generate email with student ID
        // If studentId is provided, use it immediately
        if ($studentId) {
            return $cleanFirstName . '.' . $cleanLastName . $studentId . '@' . $domain;
        }

        // Otherwise, generate temporary email and update later
        // For initial creation, we'll use a timestamp placeholder
        return $cleanFirstName . '.' . $cleanLastName . time() . '@' . $domain;
    }

    /**
     * Generate username for student
     */
    public static function generateStudentUsername(string $firstName, string $lastName): string
    {
        // Clean names (remove special characters, convert to lowercase)
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));

        // Generate base username
        $baseUsername = $cleanFirstName . '.' . $cleanLastName;

        // Check if username exists and add number if needed
        $username = $baseUsername;
        $counter = 1;

        while (self::where('username', $username)->exists() || User::where('username', $username)->exists()) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Get school domain from school name
     */
    private static function getSchoolDomain(string $schoolName): string
    {
        // Convert school name to domain format
        $domain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $schoolName));

        // Add .samschool.com suffix
        return $domain . '.samschool.com';
    }

    /**
     * Create student with auto-generated admission number, email, and username
     * Auto-generates: firstname.lastname{id}@schoolurl with password Password@123
     */
    public static function createWithAutoGeneration(array $data): self
    {
        // Generate admission number
        $data['admission_number'] = $data['admission_number'] ?? self::generateAdmissionNumber($data['school_id'], $data['class_id'] ?? null);

        // Generate temporary email and username (will be updated with ID after creation)
        $tempEmail = self::generateStudentEmail($data['first_name'], $data['last_name'], $data['school_id']);
        $data['email'] = $tempEmail;
        $data['username'] = self::generateStudentUsername($data['first_name'], $data['last_name']);

        // Set default values
        $data['status'] = $data['status'] ?? 'active';
        $data['admission_date'] = $data['admission_date'] ?? now();

        // Create student first
        $student = self::create($data);

        // Now generate final email with student ID
        $finalEmail = self::generateStudentEmail($data['first_name'], $data['last_name'], $data['school_id'], $student->id);

        // Update student email with ID-based email
        $student->update(['email' => $finalEmail]);

        // Create user account for student with final email
        $user = User::create([
            'name' => $student->getFullNameAttribute(),
            'email' => $finalEmail,
            'username' => $student->username,
            'password' => \Hash::make('Password@123'), // Standard password for all students
            'role' => 'student',
            'status' => 'active',
        ]);

        // Link user to student
        $student->update(['user_id' => $user->id]);

        return $student->fresh();
    }

    /**
     * Get student's full name
     */
    public function getNameAttribute(): string
    {
        return $this->getFullNameAttribute();
    }
}
