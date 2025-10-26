<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'slug',
        'description',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(LibraryCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(LibraryCategory::class, 'parent_id');
    }

    public function books(): HasMany
    {
        return $this->hasMany(LibraryBook::class, 'category_id');
    }

    /**
     * Get all descendants
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get category hierarchy
     */
    public function getHierarchy(): array
    {
        $hierarchy = [];
        $current = $this;

        while ($current) {
            array_unshift($hierarchy, $current);
            $current = $current->parent;
        }

        return $hierarchy;
    }

    /**
     * Get full category path
     */
    public function getFullPath(): string
    {
        $hierarchy = $this->getHierarchy();
        return implode(' > ', array_column($hierarchy, 'name'));
    }
}
