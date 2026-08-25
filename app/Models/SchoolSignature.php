<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SchoolSignature extends Model
{
    protected $table = 'school_signatures';

    protected $fillable = [
        'school_id',
        'role',
        'teacher_id',
        'name',
        'signature_path',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Full URL for the signature image.
     * Handles both S3 full URLs (from SignatureController) and
     * local public-disk relative paths (from SchoolSignatureController).
     */
    public function getSignatureUrlAttribute(): ?string
    {
        if (! $this->signature_path) {
            return null;
        }
        // Already a full URL (S3 or any absolute URL)
        if (str_starts_with($this->signature_path, 'http')) {
            return $this->signature_path;
        }
        // Relative path stored by SchoolSignatureController on the public disk
        return Storage::disk('public')->url($this->signature_path);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Active, role-level (not teacher-specific) signatures for a school,
     * keyed by role — the school-wide default a report card falls back to.
     */
    public static function activeForSchool(int $schoolId): \Illuminate\Support\Collection
    {
        return static::where('school_id', $schoolId)
            ->where('active', true)
            ->whereNull('teacher_id')
            ->get()
            ->keyBy('role');
    }

    /** One teacher's own active signature, if they've uploaded one. */
    public static function forTeacher(int $schoolId, int $teacherId): ?self
    {
        return static::where('school_id', $schoolId)
            ->where('teacher_id', $teacherId)
            ->where('active', true)
            ->first();
    }

    /**
     * Signatures for a specific report card: the school's role-level
     * defaults, with the 'class_teacher' slot swapped for that class's
     * actual teacher's own signature when they have one — instead of every
     * class showing the same shared "class teacher" image regardless of who
     * actually teaches it.
     */
    public static function resolveForReportCard(int $schoolId, ?int $classTeacherId): \Illuminate\Support\Collection
    {
        $signatures = static::activeForSchool($schoolId);

        if ($classTeacherId) {
            $personal = static::forTeacher($schoolId, $classTeacherId);
            if ($personal) {
                $signatures->put('class_teacher', $personal);
            }
        }

        return $signatures;
    }
}
