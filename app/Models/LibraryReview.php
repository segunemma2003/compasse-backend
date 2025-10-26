<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'book_id',
        'reviewer_id',
        'reviewer_type',
        'rating',
        'title',
        'comment',
        'is_approved',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'book_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->morphTo('reviewer');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get rating stars
     */
    public function getStarsAttribute(): string
    {
        return str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating);
    }

    /**
     * Get rating description
     */
    public function getRatingDescriptionAttribute(): string
    {
        $descriptions = [
            1 => 'Poor',
            2 => 'Fair',
            3 => 'Good',
            4 => 'Very Good',
            5 => 'Excellent',
        ];

        return $descriptions[$this->rating] ?? 'Unknown';
    }

    /**
     * Approve review
     */
    public function approve(int $approvedBy = null): void
    {
        $this->update([
            'is_approved' => true,
            'approved_by' => $approvedBy ?? auth()->id(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Reject review
     */
    public function reject(): void
    {
        $this->update([
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }
}
