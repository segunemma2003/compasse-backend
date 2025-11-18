<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\School;
use App\Models\ClassModel;
use App\Models\Arm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller
{
    /**
     * Get all students with pagination and filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Student::with(['school', 'class', 'arm', 'user']);

            // Apply filters
            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->has('arm_id')) {
                $query->where('arm_id', $request->arm_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('admission_number', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $students = $query->paginate($request->get('per_page', 15));

            return response()->json([
                'data' => $students->items(),
                'links' => [
                    'first' => $students->url(1),
                    'last' => $students->url($students->lastPage()),
                    'prev' => $students->previousPageUrl(),
                    'next' => $students->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $students->currentPage(),
                    'from' => $students->firstItem(),
                    'last_page' => $students->lastPage(),
                    'per_page' => $students->perPage(),
                    'to' => $students->lastItem(),
                    'total' => $students->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'links' => [],
                'meta' => [
                    'current_page' => 1,
                    'from' => null,
                    'last_page' => 1,
                    'per_page' => 15,
                    'to' => null,
                    'total' => 0,
                ]
            ]);
        }
    }

    /**
     * Get specific student by ID
     */
    public function show(int $id): JsonResponse
    {
        $student = Student::with(['school', 'class', 'arm', 'user', 'subjects', 'guardian'])
            ->find($id);

        if (!$student) {
            return response()->json([
                'error' => 'Student not found'
            ], 404);
        }

        return response()->json($student);
    }

    /**
     * Create new student with auto-generated admission number, email, and username
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'school_id' => 'required|exists:schools,id',
            'class_id' => 'required|exists:classes,id',
            'arm_id' => 'nullable|exists:arms,id',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'blood_group' => 'nullable|string|max:10',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'emergency_contact' => 'nullable|string|max:20',
            'medical_info' => 'nullable|array',
            'transport_info' => 'nullable|array',
            'hostel_info' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Create student with auto-generation
            $student = Student::createWithAutoGeneration($request->all());

            return response()->json([
                'message' => 'Student created successfully',
                'student' => $student->load(['school', 'class', 'arm', 'user'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Student creation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update student information
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'error' => 'Student not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'class_id' => 'sometimes|exists:classes,id',
            'arm_id' => 'nullable|exists:arms,id',
            'date_of_birth' => 'sometimes|date',
            'gender' => 'sometimes|in:male,female,other',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'blood_group' => 'nullable|string|max:10',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'emergency_contact' => 'nullable|string|max:20',
            'status' => 'sometimes|in:active,inactive,suspended,graduated',
            'medical_info' => 'nullable|array',
            'transport_info' => 'nullable|array',
            'hostel_info' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $student->update($request->all());

            // Update user account if name changed
            if ($request->has('first_name') || $request->has('last_name')) {
                $user = $student->user;
                if ($user) {
                    $user->update([
                        'name' => $student->getFullNameAttribute()
                    ]);
                }
            }

            return response()->json([
                'message' => 'Student updated successfully',
                'student' => $student->load(['school', 'class', 'arm', 'user'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Student update failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete student (soft delete)
     */
    public function destroy(int $id): JsonResponse
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'error' => 'Student not found'
            ], 404);
        }

        try {
            // Soft delete student
            $student->update(['status' => 'inactive']);

            // Deactivate user account
            $user = $student->user;
            if ($user) {
                $user->update(['status' => 'inactive']);
            }

            return response()->json([
                'message' => 'Student deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Student deletion failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get student attendance records
     */
    public function attendance(Request $request, int $id): JsonResponse
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'error' => 'Student not found'
            ], 404);
        }

        $query = $student->attendance();

        // Apply date filters
        if ($request->has('start_date')) {
            $query->whereDate('date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        if ($request->has('month')) {
            $query->whereMonth('date', $request->month);
        }

        if ($request->has('year')) {
            $query->whereYear('date', $request->year);
        }

        $attendance = $query->orderBy('date', 'desc')->get();

        // Calculate summary
        $totalDays = $attendance->count();
        $presentDays = $attendance->where('status', 'present')->count();
        $absentDays = $attendance->where('status', 'absent')->count();
        $attendancePercentage = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 0;

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->getFullNameAttribute(),
                'admission_number' => $student->admission_number
            ],
            'attendance' => $attendance,
            'summary' => [
                'total_days' => $totalDays,
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'attendance_percentage' => $attendancePercentage
            ]
        ]);
    }

    /**
     * Get student academic results
     */
    public function results(Request $request, int $id): JsonResponse
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json([
                'error' => 'Student not found'
            ], 404);
        }

        $query = $student->results()->with(['exam', 'subject']);

        // Apply filters
        if ($request->has('term_id')) {
            $query->whereHas('exam', function ($q) use ($request) {
                $q->where('term_id', $request->term_id);
            });
        }

        if ($request->has('session_id')) {
            $query->whereHas('exam', function ($q) use ($request) {
                $q->where('session_id', $request->session_id);
            });
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        $results = $query->get();

        // Calculate summary
        $totalSubjects = $results->count();
        $averageScore = $results->avg('total_score');
        $overallGrade = $this->calculateGrade($averageScore);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->getFullNameAttribute(),
                'admission_number' => $student->admission_number
            ],
            'results' => $results,
            'summary' => [
                'total_subjects' => $totalSubjects,
                'average_score' => round($averageScore, 2),
                'overall_grade' => $overallGrade,
                'class_position' => $this->calculateClassPosition($student, $results)
            ]
        ]);
    }

    /**
     * Generate admission number for a student
     */
    public function generateAdmissionNumber(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'school_id' => 'required|exists:schools,id',
            'class_id' => 'nullable|exists:classes,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $admissionNumber = Student::generateAdmissionNumber(
                $request->school_id,
                $request->class_id
            );

            return response()->json([
                'admission_number' => $admissionNumber
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Admission number generation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate email and username for a student
     */
    public function generateCredentials(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'school_id' => 'required|exists:schools,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $email = Student::generateStudentEmail(
                $request->first_name,
                $request->last_name,
                $request->school_id
            );

            $username = Student::generateStudentUsername(
                $request->first_name,
                $request->last_name
            );

            return response()->json([
                'email' => $email,
                'username' => $username
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Credential generation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate grade based on score
     */
    private function calculateGrade(float $score): string
    {
        if ($score >= 90) return 'A+';
        if ($score >= 80) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 50) return 'D';
        return 'F';
    }

    /**
     * Calculate class position
     */
    private function calculateClassPosition(Student $student, $results): int
    {
        // This would need to be implemented based on your grading system
        // For now, return a placeholder
        return 1;
    }
}
