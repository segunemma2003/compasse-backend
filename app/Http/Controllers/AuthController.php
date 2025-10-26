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
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'tenant_id' => 'nullable|exists:tenants,id',
            'school_id' => 'nullable|exists:schools,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $credentials = $request->only('email', 'password');
        $tenantId = $request->tenant_id;
        $schoolId = $request->school_id;

        // If tenant_id is provided, switch to tenant database
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if (!$tenant) {
                return response()->json([
                    'error' => 'Tenant not found'
                ], 404);
            }

            // Switch to tenant database
            app(TenantService::class)->switchToTenant($tenant);
        }

        // If school_id is provided, get tenant from school
        if ($schoolId) {
            $school = \App\Models\School::find($schoolId);
            if ($school) {
                app(TenantService::class)->switchToTenant($school->tenant);
            }
        }

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'error' => 'Invalid credentials'
            ], 401);
        }

        $user = Auth::user();

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Create token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load(['tenant']),
            'token' => $token,
            'token_type' => 'Bearer'
        ]);
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
        $user = $request->user()->load(['tenant']);

        return response()->json([
            'user' => $user
        ]);
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
