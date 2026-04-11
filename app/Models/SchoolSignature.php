<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
