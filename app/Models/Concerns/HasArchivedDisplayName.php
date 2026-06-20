<?php

namespace App\Models\Concerns;

use App\Models\User;

trait HasArchivedDisplayName
{
    public function getDisplayNameAttribute(): string
    {
        if ($this instanceof User) {
            return $this->isArchived() ? 'Deleted user' : ($this->name ?? 'Deleted user');
        }

        if ($this->isArchived()) {
            return 'Deleted user';
        }

        if (method_exists($this, 'getFullNameAttribute')) {
            return $this->getFullNameAttribute();
        }

        return $this->name ?? 'Deleted user';
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->isArchived();
    }

    public function isArchived(): bool
    {
        if (method_exists($this, 'trashed') && $this->trashed()) {
            return true;
        }

        if ($this instanceof User) {
            return false;
        }

        $user = $this->relationLoaded('user') ? $this->user : $this->user()->withTrashed()->first();

        return $user?->trashed() ?? false;
    }
}
