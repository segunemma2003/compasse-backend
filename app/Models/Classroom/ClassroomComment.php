<?php

namespace App\Models\Classroom;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomComment extends Model
{
    protected $fillable = ['post_id', 'work_type', 'work_id', 'student_id', 'author_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
