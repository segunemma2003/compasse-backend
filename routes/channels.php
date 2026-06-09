<?php

use App\Models\BulkUpload;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

// Private channel scoped to a single bulk upload — only the upload owner
// or a school admin can subscribe.
Broadcast::channel('bulk-upload.{uploadId}', function ($user, int $uploadId) {
    $upload = BulkUpload::find($uploadId);
    if (!$upload) {
        return false;
    }
    // Upload owner always allowed
    if ($upload->user_id === $user->id) {
        return true;
    }
    // School admins may monitor any upload for their school
    $adminRoles = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'];
    return in_array($user->role, $adminRoles, true);
});

// Private channel scoped to a school — all admins of that school can
// subscribe to receive upload progress events school-wide.
Broadcast::channel('school.{schoolId}', function ($user, int $schoolId) {
    $adminRoles = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'];
    if (!in_array($user->role, $adminRoles, true)) {
        return false;
    }
    // Verify the user belongs to this school
    $teacher = $user->teacher;
    if ($teacher && $teacher->school_id === $schoolId) {
        return ['id' => $user->id, 'name' => $user->name];
    }
    // super_admin can see all schools
    if ($user->role === 'super_admin') {
        return ['id' => $user->id, 'name' => $user->name];
    }
    return false;
});
