<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolBranding extends Model
{
    protected $table = 'school_branding';
    protected $fillable = [
        'school_id',
        'logo_path',
        'signature_path',
        'homepage_title',
        'homepage_content',
    ];

    public function sections()
    {
        return $this->hasMany(SchoolHomepageSection::class, 'school_id', 'school_id');
    }
}
