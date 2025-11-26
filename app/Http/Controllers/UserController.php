<?php

namespace App\Http\Controllers;

use App\Models\User;
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
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:super_admin,school_admin,teacher,student,parent,guardian,admin,staff,hod,year_tutor,class_teacher,subject_teacher,principal,vice_principal,accountant,librarian,driver,security,cleaner,caterer,nurse',
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
            'role' => 'sometimes|in:super_admin,school_admin,teacher,student,parent,guardian,admin,staff,hod,year_tutor,class_teacher,subject_teacher,principal,vice_principal,accountant,librarian,driver,security,cleaner,caterer,nurse',
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
    public function destroy($id): JsonResponse
    {
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

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Activate user
     */
    public function activate($id): JsonResponse
    {
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
    public function suspend($id): JsonResponse
    {
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
}

