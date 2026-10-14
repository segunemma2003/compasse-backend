<?php

namespace App\Models\Classroom;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomPost extends Model
{
    protected $fillable = ['subject_id', 'topic_id', 'type', 'title', 'body', 'links', 'attachments', 'pinned', 'author_id'];

    protected $casts = ['links' => 'array', 'attachments' => 'array', 'pinned' => 'boolean'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
