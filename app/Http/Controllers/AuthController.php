<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class AuthController extends Controller
{
    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'       => 'required|email',
            'password'    => 'required|string',
            'tenant_id'   => 'nullable|string',
            'school_id'   => 'nullable|integer',
            'school_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'    => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        // Start with the central database for tenant/school lookups.
        if (config('database.default') !== 'mysql') {
            Config::set('database.default', 'mysql');
        }

        $tenantId  = $request->header('X-Tenant-ID') ?? $request->input('tenant_id');
        $schoolId  = $request->header('X-School-ID') ?? $request->input('school_id');
        $schoolName = $request->header('X-School-Name') ?? $request->input('school_name');
        $subdomain = $request->header('X-Subdomain') ?? $request->input('subdomain');

        $tenant = null;
        $school = null;

        try {
            if ($subdomain && !$tenantId) {
                $tenant = Tenant::on('mysql')->where('subdomain', $subdomain)->first();
                if (!$tenant) {
                    // Generic message — do not reveal whether the subdomain exists.
                    return response()->json([
                        'error'   => 'Invalid credentials',
                        'message' => 'The provided credentials are incorrect.',
                    ], 401);
                }
            }

            if ($tenantId) {
                $tenant = Tenant::on('mysql')->find($tenantId);
                if (!$tenant) {
                    return response()->json([
                        'error'   => 'Invalid credentials',
                        'message' => 'The provided credentials are incorrect.',
                    ], 401);
                }
            }

            if ($schoolId) {
                if ($tenant) {
                    app(\App\Services\TenantService::class)->switchToTenant($tenant);
                    DB::purge(config('database.default'));
                    $school = \App\Models\School::find($schoolId);
                } else {
                    $school = \App\Models\School::on('mysql')->find($schoolId);
                }

                if (!$school) {
                    return response()->json([
                        'error'   => 'Invalid credentials',
                        'message' => 'The provided credentials are incorrect.',
                    ], 401);
                }

                if ($tenant && $school->tenant_id !== $tenant->id) {
                    return response()->json([
                        'error'   => 'Invalid credentials',
                        'message' => 'The provided credentials are incorrect.',
                    ], 401);
                }

                $tenant = $tenant ?? $school->tenant;
            }

            if (!$tenant && $schoolName) {
                $school = \App\Models\School::on('mysql')->where('name', $schoolName)->first();
                if ($school && $school->tenant) {
                    $tenant = $school->tenant;
                } else {
                    return response()->json([
                        'error'   => 'Invalid credentials',
                        'message' => 'The provided credentials are incorrect.',
                    ], 401);
                }
            }

        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'error'   => 'Service unavailable',
                'message' => 'Unable to process the request. Please try again.',
            ], 500);
        }

        // Switch to the tenant database before authentication so Sanctum
        // looks up the token in the correct database.
        if ($tenant) {
            app(\App\Services\TenantService::class)->switchToTenant($tenant);
            DB::purge(config('database.default'));
        } else {
            if (config('database.default') !== 'mysql') {
                Config::set('database.default', 'mysql');
            }
        }

        try {
            if (!Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
                return response()->json([
                    'error'   => 'Invalid credentials',
                    'message' => 'The provided email or password is incorrect.',
                ], 401);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'error'   => 'Service unavailable',
                'message' => 'Unable to process the request. Please try again.',
            ], 500);
        }

        $user = Auth::user();
        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('auth-token')->plainTextToken;

        if (isset($user->tenant_id)) {
            $user->load(['tenant']);
        }

        $response = [
            'message'    => 'Login successful',
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'Bearer',
        ];

        if ($tenant) {
            // Only expose safe tenant fields — never include database credentials.
            $response['tenant'] = [
                'id'        => $tenant->id,
                'name'      => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'status'    => $tenant->status,
            ];
        }

        if ($school) {
            $response['school'] = $school;
        }

        return response()->json($response);
    }

    /**
     * Register new user (tenant-scoped; super_admin cannot be self-assigned).
     */
    public function register(Request $request): JsonResponse
    {
        // Resolve tenant first so we can validate the email against the right DB.
        $tenantId = $request->header('X-Tenant-ID') ?? $request->input('tenant_id');
        $tenant   = null;

        if ($tenantId) {
            $tenant = Tenant::on('mysql')->find($tenantId);
            if (!$tenant) {
                return response()->json([
                    'error'   => 'Tenant not found',
                    'message' => 'The specified organization could not be found.',
                ], 404);
            }
            // Switch to tenant DB so all subsequent queries (including unique:users,email) run there.
            app(\App\Services\TenantService::class)->switchToTenant($tenant);
        }

        $validator = Validator::make($request->all(), [
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email',
            'password'  => 'required|string|min:8|confirmed',
            'phone'     => 'nullable|string|max:20',
            // super_admin accounts are provisioned only through the tenant setup flow.
            'role'      => 'required|in:school_admin,teacher,student,parent,guardian,admin,staff,hod,year_tutor,class_teacher,subject_teacher,principal,vice_principal,accountant,librarian,driver,security,cleaner,caterer,nurse',
            'tenant_id' => 'nullable|string',
            'school_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'    => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::create([
                'tenant_id' => $tenant?->id,
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
                'phone'     => $request->phone,
                'role'      => $request->role,
                'status'    => 'active',
            ]);

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'message'    => 'Registration successful',
                'user'       => $user,
                'token'      => $token,
                'token_type' => 'Bearer',
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Registration failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successful']);
    }

    /**
     * Refresh the current token only — does not invalidate other sessions.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        // Delete only the token used for this request, not all tokens.
        $user->currentAccessToken()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Get current user
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (isset($user->tenant_id)) {
            $user->load(['tenant']);
        }

        return response()->json(['user' => $user]);
    }

    /**
     * Send a password reset link to the user's email address.
     * The token is never returned in the API response.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'     => 'required|email',
            'tenant_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'    => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        // Switch to the tenant database so the password broker finds the right user record.
        $tenantId = $request->header('X-Tenant-ID') ?? $request->input('tenant_id');
        if ($tenantId) {
            $tenant = Tenant::on('mysql')->find($tenantId);
            if ($tenant) {
                app(\App\Services\TenantService::class)->switchToTenant($tenant);
            }
        }

        // Use Laravel's built-in password broker which sends a signed email link.
        Password::sendResetLink($request->only('email'));

        // Always return the same message to prevent email enumeration.
        return response()->json([
            'message' => 'If an account with that email exists, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset the user's password using the emailed token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'tenant_id'             => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'    => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $tenantId = $request->header('X-Tenant-ID') ?? $request->input('tenant_id');
        if ($tenantId) {
            $tenant = Tenant::on('mysql')->find($tenantId);
            if ($tenant) {
                app(\App\Services\TenantService::class)->switchToTenant($tenant);
            }
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // Revoke all existing tokens to force re-login with the new password.
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully.']);
        }

        return response()->json([
            'error'   => 'Invalid or expired reset token',
            'message' => 'The reset token is invalid or has expired. Please request a new one.',
        ], 400);
    }
}
