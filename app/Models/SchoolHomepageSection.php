<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolHomepageSection extends Model
{
    protected $table = 'school_homepage_sections';
    protected $fillable = [
        'school_id',
        'title',
        'content',
        'image_path',
        'order',
    ];

    public function branding()
    {
        return $this->belongsTo(SchoolBranding::class, 'school_id', 'school_id');
    }
}
