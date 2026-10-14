<?php

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElabActivity extends Model
{
    protected $fillable = ['subject_id', 'topic_id', 'sim_slug', 'title', 'instructions', 'questions', 'due_at', 'author_id'];

    protected $casts = ['questions' => 'array', 'due_at' => 'datetime'];

    public function submissions(): HasMany
    {
        return $this->hasMany(ElabSubmission::class, 'activity_id');
    }
}
