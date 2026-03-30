<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SecurePickup extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'student_id', 'authorized_name', 'authorized_phone',
        'relationship', 'authorized_photo', 'pickup_code', 'status', 'notes',
    ];

    public function school()  { return $this->belongsTo(School::class); }
    public function student() { return $this->belongsTo(Student::class); }

    public static function generatePickupCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('pickup_code', $code)->exists());

        return $code;
    }
}
