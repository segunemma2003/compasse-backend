<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class GuardianController extends Controller
{
    /**
     * Get all guardians
     */
    public function index(Request $request): JsonResponse
    {
        $query = Guardian::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $guardians = $query->with(['user', 'students'])
                          ->paginate($request->get('per_page', 15));

        return response()->json([
            'guardians' => $guardians
        ]);
    }

    /**
     * Get guardian details
     */
    public function show(Guardian $guardian): JsonResponse
    {
        $guardian->load(['user', 'students.user', 'students.class', 'students.arm']);

        return response()->json([
            'guardian' => $guardian,
            'students_performance' => $guardian->getStudentsPerformance()
        ]);
    }

    /**
     * Create new guardian
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:guardians,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'occupation' => 'nullable|string|max:255',
            'employer' => 'nullable|string|max:255',
            'relationship_to_student' => 'nullable|string|max:255',
            'emergency_contact' => 'nullable|string|max:20',
            'profile_picture' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            \DB::beginTransaction();

            // Auto-get school_id from tenant context
            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school from tenant context'
                ], 400);
            }

            // Create guardian record first (without user_id)
            $guardianData = $request->except(['user_id']);
            $guardianData['school_id'] = $schoolId;
            $guardianData['status'] = $guardianData['status'] ?? 'active';
            
            $guardian = Guardian::create($guardianData);

            // Auto-generate email and username if not provided
            $email = $request->email ?? $this->generateGuardianEmail(
                $request->first_name,
                $request->last_name,
                $guardian->id,
                $schoolId
            );

            $username = $this->generateGuardianUsername(
                $request->first_name,
                $request->last_name,
                $guardian->id
            );

            // Update guardian with generated email
            if (!$request->email) {
                $guardian->update(['email' => $email]);
            }

            // Create user account for guardian
            $user = \App\Models\User::create([
                'name' => trim("{$request->first_name} {$request->last_name}"),
                'email' => $email,
                'username' => $username,
                'password' => \Hash::make('Password@123'), // Default password
                'role' => 'guardian',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            // Link user to guardian
            $guardian->update(['user_id' => $user->id]);

            \DB::commit();

            return response()->json([
                'message' => 'Guardian created successfully',
                'guardian' => $guardian->load('user'),
                'login_credentials' => [
                    'email' => $email,
                    'username' => $username,
                    'password' => 'Password@123',
                    'role' => 'guardian',
                    'note' => 'Guardian should change password on first login'
                ]
            ], 201);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'error' => 'Failed to create guardian',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate guardian email
     */
    private function generateGuardianEmail(string $firstName, string $lastName, int $guardianId, int $schoolId): string
    {
        $school = \App\Models\School::find($schoolId);
        $subdomain = $school->tenant->subdomain ?? 'school';
        
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
        
        return "{$cleanFirstName}.{$cleanLastName}{$guardianId}@{$subdomain}.samschool.com";
    }

    /**
     * Generate guardian username
     */
    private function generateGuardianUsername(string $firstName, string $lastName, int $guardianId): string
    {
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
        
        return "{$cleanFirstName}.{$cleanLastName}{$guardianId}";
    }

    /**
     * Update guardian
     */
    public function update(Request $request, Guardian $guardian): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'sometimes|email|unique:guardians,email,' . $guardian->id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'occupation' => 'nullable|string|max:255',
            'employer' => 'nullable|string|max:255',
            'relationship_to_student' => 'nullable|string|max:255',
            'emergency_contact' => 'nullable|string|max:20',
            'profile_picture' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $guardian->update($request->all());

            return response()->json([
                'message' => 'Guardian updated successfully',
                'guardian' => $guardian->load('user')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update guardian',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete guardian
     */
    public function destroy(Guardian $guardian): JsonResponse
    {
        try {
            $guardian->delete();

            return response()->json([
                'message' => 'Guardian deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete guardian',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign student to guardian
     */
    public function assignStudent(Request $request, Guardian $guardian): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'relationship' => 'required|string|max:255',
            'is_primary' => 'boolean',
            'emergency_contact' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $guardian->students()->attach($request->student_id, [
                'relationship' => $request->relationship,
                'is_primary' => $request->is_primary ?? false,
                'emergency_contact' => $request->emergency_contact ?? false,
            ]);

            return response()->json([
                'message' => 'Student assigned to guardian successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to assign student',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove student from guardian
     */
    public function removeStudent(Request $request, Guardian $guardian): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $guardian->students()->detach($request->student_id);

            return response()->json([
                'message' => 'Student removed from guardian successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to remove student',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get guardian's students
     */
    public function getStudents(Guardian $guardian): JsonResponse
    {
        $students = $guardian->students()->with(['user', 'class', 'arm'])->get();

        return response()->json([
            'students' => $students
        ]);
    }

    /**
     * Get guardian's notifications
     */
    public function getNotifications(Guardian $guardian): JsonResponse
    {
        $notifications = $guardian->notifications()
                                ->orderBy('created_at', 'desc')
                                ->paginate(20);

        return response()->json([
            'notifications' => $notifications
        ]);
    }

    /**
     * Get guardian's messages
     */
    public function getMessages(Guardian $guardian): JsonResponse
    {
        $messages = $guardian->messages()
                            ->orderBy('created_at', 'desc')
                            ->paginate(20);

        return response()->json([
            'messages' => $messages
        ]);
    }

    /**
     * Get guardian's payments
     */
    public function getPayments(Guardian $guardian): JsonResponse
    {
        $payments = $guardian->payments()
                            ->orderBy('created_at', 'desc')
                            ->paginate(20);

        return response()->json([
            'payments' => $payments
        ]);
    }
}
