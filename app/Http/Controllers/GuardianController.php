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
            'user_id' => 'required|exists:users,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:guardians,email',
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
            $guardian = Guardian::create($request->all());

            return response()->json([
                'message' => 'Guardian created successfully',
                'guardian' => $guardian->load('user')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create guardian',
                'message' => $e->getMessage()
            ], 500);
        }
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
