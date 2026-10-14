<?php

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;

class ClassroomTopic extends Model
{
    protected $fillable = ['subject_id', 'name', 'position'];
}
