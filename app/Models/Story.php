<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Story extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'school_id',
        'user_id',
        'title',
        'content',
        'type',
        'media',
        'thumbnail',
        'visibility',
        'visible_to_classes',
        'is_pinned',
        'expires_at',
        'is_active',
        'allow_comments',
        'allow_reactions',
        'views_count',
        'reactions_count',
        'comments_count',
        'shares_count',
        'tags',
        'category',
    ];

    protected $casts = [
        'media' => 'array',
        'visible_to_classes' => 'array',
        'tags' => 'array',
        'is_pinned' => 'boolean',
        'is_active' => 'boolean',
        'allow_comments' => 'boolean',
        'allow_reactions' => 'boolean',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['is_expired', 'has_user_viewed', 'user_reaction'];

    /**
     * Get the school that owns the story
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the user who created the story
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get all views for the story
     */
    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    /**
     * Get all reactions for the story
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(StoryReaction::class);
    }

    /**
     * Get all comments for the story
     */
    public function comments(): HasMany
    {
        return $this->hasMany(StoryComment::class);
    }

    /**
     * Get top-level comments (no parent)
     */
    public function topLevelComments(): HasMany
    {
        return $this->hasMany(StoryComment::class)->whereNull('parent_id');
    }

    /**
     * Check if story is expired
     */
    public function getIsExpiredAttribute(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        
        return $this->expires_at->isPast();
    }

    /**
     * Check if current user has viewed the story
     */
    public function getHasUserViewedAttribute(): ?bool
    {
        $user = auth()->user();
        
        if (!$user) {
            return null;
        }
        
        return $this->views()->where('user_id', $user->id)->exists();
    }

    /**
     * Get current user's reaction to the story
     */
    public function getUserReactionAttribute(): ?string
    {
        $user = auth()->user();
        
        if (!$user) {
            return null;
        }
        
        $reaction = $this->reactions()->where('user_id', $user->id)->first();
        
        return $reaction ? $reaction->reaction_type : null;
    }

    /**
     * Increment views count
     */
    public function incrementViews(int $userId): void
    {
        // Create view record if not exists
        $this->views()->firstOrCreate([
            'user_id' => $userId,
        ], [
            'viewed_at' => now(),
        ]);

        // Increment counter
        $this->increment('views_count');
    }

    /**
     * Increment shares count
     */
    public function incrementShares(): void
    {
        $this->increment('shares_count');
    }

    /**
     * Scope: Active stories
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }

    /**
     * Scope: Pinned stories
     */
    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    /**
     * Scope: By visibility
     */
    public function scopeVisibleTo($query, string $role)
    {
        return $query->where(function ($q) use ($role) {
            $q->where('visibility', 'public')
              ->orWhere('visibility', $role);
        });
    }

    /**
     * Scope: By type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Recent stories (within 24 hours)
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}

