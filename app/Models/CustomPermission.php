<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * School-defined permission slugs beyond the built-in set in
 * RoleCapabilityService::CAPABILITIES. Assignable to roles (the matrix) and
 * to individual users the same way as a built-in capability, so an admin can
 * name a new permission for their school's own workflow even where no
 * built-in code gate exists for it yet.
 */
class CustomPermission extends Model
{
    protected $fillable = [
        'school_id',
        'slug',
        'name',
        'description',
        'created_by',
    ];
}
