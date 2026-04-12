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

    /**
     * Return all active signatures for a school, keyed by role.
     */
    public static function activeForSchool(int $schoolId): \Illuminate\Support\Collection
    {
        return static::where('school_id', $schoolId)
            ->where('active', true)
            ->get()
            ->keyBy('role');
    }
}
