<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'website',
        'logo',
        'settings',
        'tenant_id',
        'principal_id',
        'vice_principal_id',
        'academic_year',
        'term',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the school
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the principal of the school
     */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'principal_id');
    }

    /**
     * Get the vice principal of the school
     */
    public function vicePrincipal(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'vice_principal_id');
    }

    /**
     * Get all teachers in the school
     */
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    /**
     * Get all students in the school
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Get all classes in the school
     */
    public function classes(): HasMany
    {
        return $this->hasMany(ClassModel::class);
    }

    /**
     * Get all subjects in the school
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /**
     * Get all departments in the school
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Get all academic years for the school
     */
    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    /**
     * Get all terms for the school
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    /**
     * Get the subscription for the school
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Get current academic year
     */
    public function getCurrentAcademicYear(): ?AcademicYear
    {
        return $this->academicYears()
                    ->where('is_current', true)
                    ->first();
    }

    /**
     * Get current term
     */
    public function getCurrentTerm(): ?Term
    {
        return $this->terms()
                    ->where('is_current', true)
                    ->first();
    }

    /**
     * Get school statistics
     */
    public function getStats(): array
    {
        return [
            'teachers' => $this->teachers()->count(),
            'students' => $this->students()->count(),
            'classes' => $this->classes()->count(),
            'subjects' => $this->subjects()->count(),
            'departments' => $this->departments()->count(),
        ];
    }
}
