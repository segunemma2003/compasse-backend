<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Models\Student;
use App\Support\DashboardPayloadBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class GuardianController extends Controller
{
    /**
     * This route group is shared by admin roles ("manage all guardians") and
     * parent/guardian roles ("view my own record") — role middleware alone
     * can't tell those apart, so every method below that touches a specific
     * Guardian must call this first. Returns null for admin-tier callers.
     */
    private function assertOwnGuardian(Request $request, Guardian $guardian): ?JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['guardian', 'parent'], true)) {
            return null;
        }
        if ($guardian->user_id !== $user->id) {
            return $this->forbiddenResponse('You may only manage your own guardian profile.');
        }
        return null;
    }

    /**
     * Get all guardians
     */
    public function index(Request $request): JsonResponse
    {
        if (in_array($request->user()->role, ['guardian', 'parent'], true)) {
            return $this->forbiddenResponse('Use /guardians/me/students to view your own record.');
        }

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
    public function show(Request $request, Guardian $guardian): JsonResponse
    {
        if ($denied = $this->assertOwnGuardian($request, $guardian)) {
            return $denied;
        }

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
        if (in_array($request->user()->role, ['guardian', 'parent'], true)) {
            return $this->forbiddenResponse();
        }

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

            // Generate temporary email if not provided
            $tempEmail = $request->email ?? "temp.guardian.{$schoolId}." . time() . "@temp.samschool.com";

            // Create guardian record first with temporary email
            $guardianData = $request->except(['user_id']);
            $guardianData['school_id'] = $schoolId;
            $guardianData['email'] = $tempEmail;
            $guardianData['status'] = $guardianData['status'] ?? 'active';
            
            $guardian = Guardian::create($guardianData);

            // Auto-generate proper email and username with guardian ID
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

            // Update guardian with proper generated email
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
        
        // Extract domain from school website or use subdomain
        if ($school && $school->website) {
            // Remove http://, https://, www. from website
            $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $school->website);
            // Remove trailing slash
            $domain = rtrim($domain, '/');
        } else {
            // Fallback to subdomain
            $domain = ($school->tenant?->subdomain ?? 'school') . '.compasse.net';
        }
        
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
        
        return "{$cleanFirstName}.{$cleanLastName}{$guardianId}@{$domain}";
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
        if ($denied = $this->assertOwnGuardian($request, $guardian)) {
            return $denied;
        }

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
    public function destroy(Request $request, Guardian $guardian): JsonResponse
    {
        if (in_array($request->user()->role, ['guardian', 'parent'], true)) {
            return $this->forbiddenResponse();
        }

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
        if (in_array($request->user()->role, ['guardian', 'parent'], true)) {
            return $this->forbiddenResponse('Contact the school office to link a child to your account.');
        }

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
        if (in_array($request->user()->role, ['guardian', 'parent'], true)) {
            return $this->forbiddenResponse('Contact the school office to unlink a child from your account.');
        }

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
    public function getStudents(Request $request, Guardian $guardian): JsonResponse
    {
        if ($denied = $this->assertOwnGuardian($request, $guardian)) {
            return $denied;
        }

        $students = $guardian->students()->with(['user', 'class', 'arm'])->get();

        return response()->json([
            'students' => $students
        ]);
    }

    /**
     * Get the logged-in guardian's own students.
     * Guardian.id != User.id, so the guardian portal must resolve its own
     * Guardian row from the auth'd user rather than reusing the user id.
     */
    public function getMyStudents(Request $request): JsonResponse
    {
        $guardian = Guardian::where('user_id', $request->user()->id)->first();

        if (! $guardian) {
            return response()->json(['students' => []]);
        }

        $students = $guardian->students()->with(['user', 'class', 'arm'])->get();

        return response()->json([
            'students' => $students
        ]);
    }

    /**
     * Ward activity feed for parents (attendance, fees, recent results, announcements).
     */
    public function getWardActivity(Request $request, int $studentId): JsonResponse
    {
        $guardian = Guardian::where('user_id', $request->user()->id)->first();
        if (! $guardian) {
            return response()->json(['error' => 'Guardian profile not found'], 404);
        }

        $linked = $guardian->students()->where('students.id', $studentId)->exists();
        if (! $linked) {
            return response()->json(['error' => 'Student not linked to your account'], 403);
        }

        $student = Student::with(['user', 'class', 'arm'])->findOrFail($studentId);

        $recentAttendance = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('attendanceable_id', $studentId)
            ->where('attendanceable_type', 'App\\Models\\Student')
            ->orderByDesc('date')
            ->limit(14)
            ->get(['date', 'status', 'notes']);

        $feeRows = \Illuminate\Support\Facades\DB::table('fees')
            ->where('student_id', $studentId)
            ->orderByDesc('due_date')
            ->limit(10)
            ->get(['fee_type', 'amount', 'amount_paid', 'balance', 'status', 'due_date']);

        $recentPayments = \Illuminate\Support\Facades\DB::table('payments')
            ->where('student_id', $studentId)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['amount', 'status', 'payment_date', 'notes', 'created_at']);

        $assignments = [];
        if ($student->class_id && \Illuminate\Support\Facades\Schema::hasTable('assignments')) {
            $assignments = \Illuminate\Support\Facades\DB::table('assignments')
                ->where('class_id', $student->class_id)
                ->whereIn('status', ['active', 'published', 'open', 'pending'])
                ->orderByDesc('due_date')
                ->limit(8)
                ->get(['title', 'due_date', 'status']);
        }

        $performance = DashboardPayloadBuilder::parentRecentPerformance([$studentId], 6);

        return response()->json([
            'student' => [
                'id'                => $student->id,
                'name'              => $student->user?->name ?? trim("{$student->first_name} {$student->last_name}"),
                'class_name'        => $student->class?->name,
                'arm_name'          => $student->arm?->name,
                'admission_number'  => $student->admission_number,
            ],
            'attendance_recent' => $recentAttendance,
            'fees'              => $feeRows,
            'payments'          => $recentPayments,
            'assignments'       => $assignments,
            'performance'       => $performance,
            'messages_hint'     => 'Use Messages or Communication to contact teachers about this ward.',
        ]);
    }

    /**
     * Get guardian's notifications
     */
    public function getNotifications(Request $request, Guardian $guardian): JsonResponse
    {
        if ($denied = $this->assertOwnGuardian($request, $guardian)) {
            return $denied;
        }

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
    public function getMessages(Request $request, Guardian $guardian): JsonResponse
    {
        if ($denied = $this->assertOwnGuardian($request, $guardian)) {
            return $denied;
        }

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
    public function getPayments(Request $request, Guardian $guardian): JsonResponse
    {
        if ($denied = $this->assertOwnGuardian($request, $guardian)) {
            return $denied;
        }

        $payments = $guardian->payments()
                            ->orderBy('created_at', 'desc')
                            ->paginate(20);

        return response()->json([
            'payments' => $payments
        ]);
    }
}
