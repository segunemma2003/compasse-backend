<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        // Validate required fields (tenant context can come from headers or body)
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'tenant_id' => 'nullable|string', // Can be in header or body
            'school_id' => 'nullable|integer', // Can be in header or body
            'school_name' => 'nullable|string', // Can be in header or body
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Check both headers and body for tenant context (headers take precedence)
        $tenantId = $request->header('X-Tenant-ID') ?? $request->input('tenant_id');
        $schoolId = $request->header('X-School-ID') ?? $request->input('school_id');
        $schoolName = $request->header('X-School-Name') ?? $request->input('school_name');
        
        $tenant = null;
        $school = null;

        // Method 1: Resolve from tenant_id (header or body)
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if (!$tenant) {
                return response()->json([
                    'error' => 'Tenant not found'
                ], 404);
            }
        }

        // Method 2: Resolve from school_id (header or body)
        if ($schoolId) {
            $school = \App\Models\School::find($schoolId);

            if (!$school) {
                return response()->json([
                    'error' => 'School not found'
                ], 404);
            }

            if ($tenant && $school->tenant_id !== $tenant->id) {
                return response()->json([
                    'error' => 'Tenant mismatch',
                    'message' => 'Provided school does not belong to the specified tenant.'
                ], 422);
            }

            $tenant = $tenant ?? $school->tenant;
        }

        // Method 3: Resolve from school_name (header or body)
        if (!$tenant && $schoolName) {
            $school = \App\Models\School::where('name', $schoolName)->first();
            
            if ($school && $school->tenant) {
                $tenant = $school->tenant;
            } elseif ($school) {
                return response()->json([
                    'error' => 'School has no tenant',
                    'message' => 'The specified school is not associated with any tenant.'
                ], 404);
            } else {
                return response()->json([
                    'error' => 'School not found',
                    'message' => "No school found with name: {$schoolName}"
                ], 404);
            }
        }

        // If tenant is provided, switch to tenant database before authentication
        if ($tenant && $tenant->database_name) {
            // Switch to tenant database for authentication
            $tenantService = app(\App\Services\TenantService::class);
            $tenantService->switchToTenant($tenant);
            
            // Purge the connection to ensure fresh connection
            \Illuminate\Support\Facades\DB::purge(config('database.default'));
        }

        $credentials = [
            'email' => $request->email,
            'password' => $request->password,
        ];

        // Note: tenant_id is not needed in credentials for tenant DB users
        // Each tenant database is already isolated

        if (!Auth::attempt($credentials)) {
            // Revert to main database if login fails
            if ($tenant) {
                \Illuminate\Support\Facades\Config::set('database.default', 'mysql');
                \Illuminate\Support\Facades\DB::setDefaultConnection('mysql');
                \Illuminate\Support\Facades\DB::purge('mysql');
            }
            return response()->json([
                'error' => 'Invalid credentials'
            ], 401);
        }

        $user = Auth::user();

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Create token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Load tenant relationship only if user has tenant_id (main DB users)
        // Tenant DB users don't have tenant_id, so skip loading it
        $userData = $user->toArray();
        if (isset($user->tenant_id)) {
            $user->load(['tenant']);
            $userData = $user->toArray();
        }
        
        $response = [
            'message' => 'Login successful',
            'user' => $userData,
            'token' => $token,
            'token_type' => 'Bearer'
        ];

        if ($tenant) {
            $response['tenant'] = $tenant;
        }

        if ($school) {
            $response['school'] = $school;
        }

        return response()->json($response);
    }

    /**
     * Register new user
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:super_admin,school_admin,teacher,student,parent,guardian,admin,staff,hod,year_tutor,class_teacher,subject_teacher,principal,vice_principal,accountant,librarian,driver,security,cleaner,caterer,nurse',
            'tenant_id' => 'nullable|exists:tenants,id',
            'school_id' => 'nullable|exists:schools,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Get or create default tenant
            $tenant = $request->tenant_id ? Tenant::find($request->tenant_id) : Tenant::first();
            if (!$tenant) {
                $tenant = Tenant::create([
                    'name' => 'Default School District',
                    'domain' => 'default.school.com',
                    'database_name' => 'default_school_db',
                    'database_host' => 'localhost',
                    'database_port' => 3306,
                    'status' => 'active'
                ]);
            }

            // Create user without switching to tenant database for now
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => $request->role,
                'status' => 'active',
            ]);

            // Create profile based on role (skip for now to avoid tenant issues)
            // if ($request->role === 'teacher') {
            //     $this->createTeacherProfile($user, $request);
            // } elseif ($request->role === 'student') {
            //     $this->createStudentProfile($user, $request);
            // }

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'message' => 'Registration successful',
                'user' => $user->load(['tenant']),
                'token' => $token,
                'token_type' => 'Bearer'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Registration failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful'
        ]);
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer'
        ]);
    }

    /**
     * Get current user
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Load tenant relationship only if user has tenant_id (main DB users)
        // Tenant DB users don't have tenant_id, so skip loading it
        if (isset($user->tenant_id)) {
            $user->load(['tenant']);
        }

        return response()->json([
            'user' => $user
        ]);
    }

    /**
     * Request password reset
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'tenant_id' => 'nullable|exists:tenants,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $tenant = null;
            if ($request->tenant_id) {
                $tenant = Tenant::find($request->tenant_id);
                if ($tenant && $tenant->database_name) {
                    $tenantService = app(\App\Services\TenantService::class);
                    $tenantService->switchToTenant($tenant);
                }
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                // Return success even if user not found (security best practice)
                return response()->json([
                    'message' => 'If the email exists, a password reset link has been sent.'
                ], 200);
            }

            // Generate password reset token
            $token = \Illuminate\Support\Str::random(64);
            
            // Store token in password_reset_tokens table
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now()
                ]
            );

            // In production, send email with reset link
            // For now, return token (remove in production)
            return response()->json([
                'message' => 'Password reset token generated',
                'token' => $token, // Remove this in production - send via email instead
                'reset_url' => url("/api/v1/auth/reset-password?token={$token}&email=" . urlencode($user->email))
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to process password reset request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
            'tenant_id' => 'nullable|exists:tenants,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $tenant = null;
            if ($request->tenant_id) {
                $tenant = Tenant::find($request->tenant_id);
                if ($tenant && $tenant->database_name) {
                    $tenantService = app(\App\Services\TenantService::class);
                    $tenantService->switchToTenant($tenant);
                }
            }

            // Get password reset record
            $resetRecord = \Illuminate\Support\Facades\DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$resetRecord) {
                return response()->json([
                    'error' => 'Invalid or expired reset token'
                ], 400);
            }

            // Check if token is valid (within 60 minutes)
            $createdAt = \Carbon\Carbon::parse($resetRecord->created_at);
            if ($createdAt->addMinutes(60)->isPast()) {
                \Illuminate\Support\Facades\DB::table('password_reset_tokens')
                    ->where('email', $request->email)
                    ->delete();
                
                return response()->json([
                    'error' => 'Reset token has expired'
                ], 400);
            }

            // Verify token
            if (!Hash::check($request->token, $resetRecord->token)) {
                return response()->json([
                    'error' => 'Invalid reset token'
                ], 400);
            }

            // Update user password
            $user = User::where('email', $request->email)->first();
            if (!$user) {
                return response()->json([
                    'error' => 'User not found'
                ], 404);
            }

            $user->update([
                'password' => Hash::make($request->password)
            ]);

            // Delete reset token
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return response()->json([
                'message' => 'Password reset successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to reset password',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create teacher profile
     */
    protected function createTeacherProfile(User $user, Request $request): void
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'qualification' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'employment_date' => 'required|date',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        \App\Models\Teacher::create([
            'school_id' => $request->school_id,
            'user_id' => $user->id,
            'employee_id' => $request->employee_id,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'title' => $request->title,
            'email' => $user->email,
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'qualification' => $request->qualification,
            'specialization' => $request->specialization,
            'employment_date' => $request->employment_date,
            'department_id' => $request->department_id,
        ]);
    }

    /**
     * Create student profile
     */
    protected function createStudentProfile(User $user, Request $request): void
    {
        $validator = Validator::make($request->all(), [
            'admission_number' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'admission_date' => 'required|date',
            'class_id' => 'nullable|exists:classes,id',
            'arm_id' => 'nullable|exists:arms,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        \App\Models\Student::create([
            'school_id' => $request->school_id,
            'user_id' => $user->id,
            'admission_number' => $request->admission_number,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'email' => $user->email,
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'parent_name' => $request->parent_name,
            'parent_phone' => $request->parent_phone,
            'parent_email' => $request->parent_email,
            'admission_date' => $request->admission_date,
            'class_id' => $request->class_id,
            'arm_id' => $request->arm_id,
        ]);
    }
}
