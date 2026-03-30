<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'user_id', 'name', 'license_number', 'license_expiry',
        'phone', 'address', 'date_of_birth', 'profile_picture', 'status',
    ];

    protected $casts = [
        'license_expiry' => 'date',
        'date_of_birth'  => 'date',
    ];

    public function school()  { return $this->belongsTo(School::class); }
    public function user()    { return $this->belongsTo(User::class); }
    public function routes()  { return $this->hasMany(TransportRoute::class); }
}
