<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Jobs\SendEmailJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    /**
     * List users with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Filter by role
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by class — a student in that class, or a teacher assigned
        // to it (year-group class_teacher_id or arm-level class_arm.class_teacher_id).
        if ($request->filled('class_id') && Schema::hasTable('students') && Schema::hasTable('teachers')) {
            $classId = $request->class_id;
            $query->where(function ($q) use ($classId) {
                $q->whereHas('student', fn ($sq) => $sq->where('class_id', $classId));

                if (Schema::hasTable('classes')) {
                    $q->orWhereHas('teacher', function ($tq) use ($classId) {
                        $tq->whereIn('id', function ($sub) use ($classId) {
                            $sub->select('class_teacher_id')
                                ->from('classes')
                                ->where('id', $classId)
                                ->whereNotNull('class_teacher_id');
                        });
                    });
                }

                if (Schema::hasTable('class_arm')) {
                    $q->orWhereHas('teacher', function ($tq) use ($classId) {
                        $tq->whereIn('id', function ($sub) use ($classId) {
                            $sub->select('class_teacher_id')
                                ->from('class_arm')
                                ->where('class_id', $classId)
                                ->whereNotNull('class_teacher_id');
                        });
                    });
                }
            });
        }

        // Filter by department — currently only teachers carry a real
        // department_id FK; staff.department is a free-text field, not
        // linked to the departments table, so it's not included here.
        if ($request->filled('department_id') && Schema::hasTable('teachers')) {
            $departmentId = $request->department_id;
            $query->whereHas('teacher', fn ($tq) => $tq->where('department_id', $departmentId));
        }

        // Load tenant relationship if exists
        if (Schema::hasColumn('users', 'tenant_id')) {
            $query->with('tenant');
        }

        $users = $query->paginate($request->get('per_page', 15));

        return response()->json($users);
    }

    /**
     * Create a new user
     */
    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:super_admin,school_admin,teacher,student,parent,guardian,admin,staff,hod,year_tutor,class_teacher,subject_teacher,principal,vice_principal,accountant,librarian,driver,security,cleaner,caterer,nurse,care_assistant,sen_facilitator,sen_therapist,counsellor',
            'status' => 'nullable|in:active,inactive,suspended',
            'profile_picture' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $userData = [
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => $request->role,
                'status' => $request->status ?? 'active',
                'profile_picture' => $request->profile_picture,
            ];

            // Only add tenant_id if we're in the central database (not in tenant context)
            if (!tenancy()->initialized && Schema::hasColumn('users', 'tenant_id')) {
                $userData['tenant_id'] = tenancy()->tenant?->getTenantKey();
            }

            $user = User::create($userData);

            if ($request->boolean('send_welcome_email', true) && $user->email) {
                $this->queueWelcomeEmail($request, $user, $request->password);
            }

            return response()->json([
                'message' => 'User created successfully',
                'data' => $user->fresh()
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create user',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user details
     */
    public function show($id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        if (Schema::hasColumn('users', 'tenant_id')) {
            $user->load('tenant');
        }

        return response()->json([
            'user' => $user
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'role' => 'sometimes|in:super_admin,school_admin,teacher,student,parent,guardian,admin,staff,hod,year_tutor,class_teacher,subject_teacher,principal,vice_principal,accountant,librarian,driver,security,cleaner,caterer,nurse,care_assistant,sen_facilitator,sen_therapist,counsellor',
            'status' => 'sometimes|in:active,inactive,suspended',
            'profile_picture' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $user->update($request->only([
            'name', 'email', 'phone', 'role', 'status', 'profile_picture'
        ]));

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Delete user
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        // Prevent deleting super admin
        if ($user->role === 'super_admin') {
            return response()->json([
                'error' => 'Cannot delete super admin user'
            ], 403);
        }

        // students/teachers/guardians link back via user_id with no DB-level FK,
        // so clear the pointer here to avoid leaving those rows referencing a deleted user.
        foreach (['students', 'teachers', 'guardians'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                \Illuminate\Support\Facades\DB::table($table)->where('user_id', $user->id)->update(['user_id' => null]);
            }
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Activate user
     */
    public function activate(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        $user->update(['status' => 'active']);

        return response()->json([
            'message' => 'User activated successfully',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Suspend user
     */
    public function suspend(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        // Prevent suspending super admin
        if ($user->role === 'super_admin') {
            return response()->json([
                'error' => 'Cannot suspend super admin user'
            ], 403);
        }

        $user->update(['status' => 'suspended']);

        return response()->json([
            'message' => 'User suspended successfully',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Upload profile picture
     */
    public function uploadProfilePicture(Request $request, $id = null): JsonResponse
    {
        // If no ID provided, use authenticated user
        $userId = $id ?? auth()->id();
        
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'profile_picture' => 'required|string', // S3 URL or base64
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $user->update([
                'profile_picture' => $request->profile_picture
            ]);

            return response()->json([
                'message' => 'Profile picture updated successfully',
                'user' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update profile picture',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete profile picture
     */
    public function deleteProfilePicture($id = null): JsonResponse
    {
        // If no ID provided, use authenticated user
        $userId = $id ?? auth()->id();
        
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        try {
            $user->update([
                'profile_picture' => null
            ]);

            return response()->json([
                'message' => 'Profile picture deleted successfully',
                'user' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete profile picture',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Assign role to user
     */
    public function assignRole(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'required|in:super_admin,school_admin,teacher,student,parent,guardian,admin,staff,hod,year_tutor,class_teacher,subject_teacher,principal,vice_principal,accountant,librarian,driver,security,cleaner,caterer,nurse,care_assistant,sen_facilitator,sen_therapist,counsellor',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Prevent changing super admin role
        if ($user->role === 'super_admin') {
            return response()->json([
                'error' => 'Cannot change super admin role'
            ], 403);
        }

        // Only an actual school_admin may hand out school_admin/super_admin
        // — 'admin' is a configurable sub-admin tier (user.manage is one of
        // the things it can be granted), and having user.manage should not
        // by itself let a sub-admin promote someone (including themselves)
        // above their own tier.
        if (in_array($request->role, ['school_admin', 'super_admin'], true)
            && optional($request->user() ?: auth()->user())->role !== 'school_admin'
        ) {
            return response()->json([
                'error' => 'Only a school admin may assign the school_admin role.',
            ], 403);
        }

        $user->update(['role' => $request->role]);

        return response()->json([
            'message' => 'Role assigned successfully',
            'user' => $user->fresh()
        ]);
    }
    
    /**
     * Remove role from user (set to default staff role)
     */
    public function removeRole(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'error' => 'User not found'
            ], 404);
        }

        // Prevent changing super admin role
        if ($user->role === 'super_admin') {
            return response()->json([
                'error' => 'Cannot remove super admin role'
            ], 403);
        }

        $user->update(['role' => 'staff']);

        return response()->json([
            'message' => 'Role removed, user set to staff',
            'user' => $user->fresh()
        ]);
    }
    
    /**
     * Send (or resend) login credentials to a user via email.
     *
     * For students: resets password to surname-lowercase.
     * For others:   generates a new temporary password.
     * POST /users/{id}/send-credentials
     */
    public function sendCredentials(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json(['error' => 'User not found'], 404);
            }

            if (!$user->email) {
                return response()->json(['error' => 'User has no email address on file'], 422);
            }

            $this->resetAndEmailCredentials($user, $this->getSchoolFromRequest($request));

            return response()->json([
                'message' => 'Credentials sent successfully',
                'email'   => $user->email,
            ]);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('sendCredentials: unexpected error', [
                'user_id' => $id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to send credentials', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /users/bulk-send-credentials  { user_ids: [] }
     *
     * Emails each selected person a fresh password — e.g. to (re)send login
     * details to teachers after a bulk upload. Accounts whose email is an
     * auto-generated placeholder (no real mailbox) or missing are skipped.
     */
    public function bulkSendCredentials(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'user.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'user_ids'   => 'required|array|min:1|max:500',
            'user_ids.*' => 'integer',
        ]);

        $school  = $this->getSchoolFromRequest($request);
        $sent    = 0;
        $skipped = [];
        foreach (User::whereIn('id', $validated['user_ids'])->get() as $user) {
            if (! $user->email || ! filter_var($user->email, FILTER_VALIDATE_EMAIL) || $this->isPlaceholderEmail($user->email)) {
                $skipped[] = $user->name;
                continue;
            }
            $this->resetAndEmailCredentials($user, $school);
            $sent++;
        }

        $missing = count($validated['user_ids']) - $sent - count($skipped);

        return response()->json([
            'message' => "Login details sent to {$sent} " . ($sent === 1 ? 'person' : 'people')
                . ($skipped ? ', ' . count($skipped) . ' skipped (no real email)' : '')
                . ($missing > 0 ? ", {$missing} without a login account" : ''),
            'sent'    => $sent,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Sets a new random password and queues the login email. A random
     * password, not the last-name one this used to set for teachers and
     * students, which anyone who knew the person could guess.
     */
    private function resetAndEmailCredentials(User $user, $school): void
    {
        $newPass = \Illuminate\Support\Str::password(10, letters: true, numbers: true, symbols: false);

        // Use plain string — the 'hashed' cast on User handles bcrypt automatically
        $user->update(['password' => $newPass]);

        $loginUrl = tenant()?->subdomain
            ? 'https://' . tenant()->subdomain . '.' . config('app.root_domain', 'compasse.net') . '/login'
            : null;

        $name = $user->name ?? 'User';
        $body = "Hello {$name},\n\n"
            . "Here are your login credentials:\n\n"
            . "Email: {$user->email}\n"
            . "Password: {$newPass}\n"
            . ($loginUrl ? "Login here: {$loginUrl}\n" : '')
            . "\nPlease log in and change your password immediately.\n\n"
            . "Regards,\n" . ($school?->name ?? 'School Administration');

        try {
            SendEmailJob::dispatch(
                to:       $user->email,
                subject:  'Your Login Credentials',
                body:     $body,
                schoolId: $school ? (string) $school->id : null,
                type:     'credentials',
            )->onQueue('emails');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('sendCredentials: failed to queue email', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Addresses the system made up for people with no email (…@<school>.compasse.net, temp.*). */
    private function isPlaceholderEmail(string $email): bool
    {
        $root = preg_quote(config('app.root_domain', 'compasse.net'), '/');

        return (bool) preg_match("/@([a-z0-9-]+\\.)?{$root}$|^temp\\.|@temp\\.samschool\\.com$/i", $email);
    }

    /**
     * Queue welcome email with login credentials for a newly created user.
     */
    protected function queueWelcomeEmail(Request $request, User $user, string $plainPassword): void
    {
        try {
            $school = $this->getSchoolFromRequest($request);
            $name = $user->name ?? 'User';
            $body = "Hello {$name},\n\n"
                . "Welcome! Your account has been created.\n\n"
                . "Email: {$user->email}\n"
                . "Password: {$plainPassword}\n\n"
                . "Please log in and change your password immediately.\n\n"
                . "Regards,\nSchool Administration";

            SendEmailJob::dispatch(
                to: $user->email,
                subject: 'Welcome — Your Login Credentials',
                body: $body,
                schoolId: $school ? (string) $school->id : null,
                type: 'welcome',
            )->onQueue('emails');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('queueWelcomeEmail failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get available roles
     */
    public function getRoles(): JsonResponse
    {
        $roles = [
            'school_admin' => 'School Administrator',
            'teacher' => 'Teacher',
            'student' => 'Student',
            'parent' => 'Parent',
            'guardian' => 'Guardian',
            'admin' => 'Admin',
            'staff' => 'Staff',
            'hod' => 'Head of Department',
            'year_tutor' => 'Year Tutor',
            'class_teacher' => 'Class Teacher',
            'subject_teacher' => 'Subject Teacher',
            // Same roles, named for both conventions schools use.
            'principal' => 'Principal / Head of School',
            'vice_principal' => 'Vice Principal / Deputy Head',
            'accountant' => 'Accountant',
            'librarian' => 'Librarian',
            'driver' => 'Driver',
            'security' => 'Security',
            'cleaner' => 'Cleaner',
            'caterer' => 'Caterer',
            'nurse' => 'Nurse',
            'care_assistant' => 'Care Assistant',
            'sen_facilitator' => 'SEN Facilitator',
            'sen_therapist' => 'SEN Therapist',
            'counsellor' => 'Counsellor',
        ];

        return response()->json([
            'roles' => $roles
        ]);
    }
}

