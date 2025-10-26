<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryBook extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'isbn',
        'title',
        'author',
        'publisher',
        'publication_year',
        'edition',
        'category_id',
        'subcategory_id',
        'description',
        'cover_image',
        'total_copies',
        'available_copies',
        'location',
        'shelf_number',
        'row_number',
        'column_number',
        'price',
        'language',
        'pages',
        'status',
        'is_digital',
        'digital_url',
        'access_level',
        'keywords',
        'tags',
    ];

    protected $casts = [
        'publication_year' => 'integer',
        'total_copies' => 'integer',
        'available_copies' => 'integer',
        'price' => 'decimal:2',
        'pages' => 'integer',
        'is_digital' => 'boolean',
        'keywords' => 'array',
        'tags' => 'array',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LibraryCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(LibraryCategory::class, 'subcategory_id');
    }

    public function borrows(): HasMany
    {
        return $this->hasMany(LibraryBorrow::class, 'book_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(LibraryReview::class, 'book_id');
    }

    /**
     * Check if book is available
     */
    public function isAvailable(): bool
    {
        return $this->available_copies > 0 && $this->status === 'active';
    }

    /**
     * Get availability status
     */
    public function getAvailabilityStatus(): string
    {
        if ($this->available_copies > 0) {
            return 'available';
        } elseif ($this->total_copies > 0) {
            return 'borrowed';
        } else {
            return 'unavailable';
        }
    }

    /**
     * Get borrowing rate
     */
    public function getBorrowingRate(): float
    {
        $totalBorrows = $this->borrows()->count();
        $totalCopies = $this->total_copies;

        return $totalCopies > 0 ? round(($totalBorrows / $totalCopies) * 100, 2) : 0;
    }

    /**
     * Get average rating
     */
    public function getAverageRating(): float
    {
        return $this->reviews()->avg('rating') ?? 0;
    }

    /**
     * Get total reviews count
     */
    public function getReviewsCount(): int
    {
        return $this->reviews()->count();
    }

    /**
     * Check if book is overdue
     */
    public function hasOverdueBorrows(): bool
    {
        return $this->borrows()
                   ->where('status', 'borrowed')
                   ->where('due_date', '<', now())
                   ->exists();
    }

    /**
     * Get overdue borrows
     */
    public function getOverdueBorrows()
    {
        return $this->borrows()
                   ->where('status', 'borrowed')
                   ->where('due_date', '<', now())
                   ->with(['borrower.user'])
                   ->get();
    }

    /**
     * Reserve book
     */
    public function reserve(int $userId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $this->decrement('available_copies');
        return true;
    }

    /**
     * Release book
     */
    public function release(): void
    {
        $this->increment('available_copies');
    }

    /**
     * Search books
     */
    public static function search(string $query, array $filters = [])
    {
        $search = self::query();

        if ($query) {
            $search->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('author', 'like', "%{$query}%")
                  ->orWhere('isbn', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            });
        }

        if (isset($filters['category_id'])) {
            $search->where('category_id', $filters['category_id']);
        }

        if (isset($filters['author'])) {
            $search->where('author', 'like', "%{$filters['author']}%");
        }

        if (isset($filters['publication_year'])) {
            $search->where('publication_year', $filters['publication_year']);
        }

        if (isset($filters['is_digital'])) {
            $search->where('is_digital', $filters['is_digital']);
        }

        if (isset($filters['status'])) {
            $search->where('status', $filters['status']);
        }

        if (isset($filters['available_only']) && $filters['available_only']) {
            $search->where('available_copies', '>', 0);
        }

        return $search->with(['category', 'subcategory'])
                     ->orderBy('title');
    }
}
