<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\ClassModel;
use App\Models\Subject;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SchoolController extends Controller
{
    /**
     * Get school information
     */
    public function show(School $school): JsonResponse
    {
        $school->load(['principal', 'vicePrincipal', 'departments', 'academicYears', 'terms']);

        return response()->json([
            'school' => $school,
            'stats' => $school->getStats()
        ]);
    }

    /**
     * Update school information
     */
    public function update(Request $request, School $school): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'logo' => 'nullable|string|max:255',
            'principal_id' => 'nullable|exists:teachers,id',
            'vice_principal_id' => 'nullable|exists:teachers,id',
            'academic_year' => 'nullable|string|max:255',
            'term' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $school->update($request->all());

            return response()->json([
                'message' => 'School updated successfully',
                'school' => $school
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update school',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get school statistics
     */
    public function stats(School $school): JsonResponse
    {
        $stats = [
            'teachers' => $school->teachers()->count(),
            'students' => $school->students()->count(),
            'classes' => $school->classes()->count(),
            'subjects' => $school->subjects()->count(),
            'departments' => $school->departments()->count(),
            'academic_years' => $school->academicYears()->count(),
            'terms' => $school->terms()->count(),
        ];

        return response()->json([
            'stats' => $stats
        ]);
    }

    /**
     * Get school dashboard data
     */
    public function dashboard(School $school): JsonResponse
    {
        $currentAcademicYear = $school->getCurrentAcademicYear();
        $currentTerm = $school->getCurrentTerm();

        $dashboard = [
            'school' => $school,
            'current_academic_year' => $currentAcademicYear,
            'current_term' => $currentTerm,
            'stats' => $school->getStats(),
            'recent_activities' => $this->getRecentActivities($school),
            'upcoming_events' => $this->getUpcomingEvents($school),
        ];

        return response()->json([
            'dashboard' => $dashboard
        ]);
    }

    /**
     * Get school organogram
     */
    public function organogram(School $school): JsonResponse
    {
        $organogram = [
            'principal' => $school->principal,
            'vice_principal' => $school->vicePrincipal,
            'departments' => $school->departments()->with('head')->get(),
            'year_tutors' => $this->getYearTutors($school),
            'class_teachers' => $this->getClassTeachers($school),
        ];

        return response()->json([
            'organogram' => $organogram
        ]);
    }

    /**
     * Get year tutors
     */
    protected function getYearTutors(School $school)
    {
        return Teacher::where('school_id', $school->id)
                     ->where('role', 'year_tutor')
                     ->with('user')
                     ->get();
    }

    /**
     * Get class teachers
     */
    protected function getClassTeachers(School $school)
    {
        return ClassModel::where('school_id', $school->id)
                        ->with(['classTeacher.user', 'students'])
                        ->get();
    }

    /**
     * Get recent activities
     */
    protected function getRecentActivities(School $school)
    {
        return [
            [
                'id' => 1,
                'type' => 'student_registration',
                'description' => 'New student registered: John Doe',
                'timestamp' => now()->subHours(2),
                'user' => 'Admin User'
            ],
            [
                'id' => 2,
                'type' => 'exam_created',
                'description' => 'Mathematics exam created for SS1A',
                'timestamp' => now()->subHours(4),
                'user' => 'Teacher Smith'
            ],
            [
                'id' => 3,
                'type' => 'payment_received',
                'description' => 'School fees payment received: $500',
                'timestamp' => now()->subHours(6),
                'user' => 'Finance Office'
            ]
        ];
    }

    /**
     * Get upcoming events
     */
    protected function getUpcomingEvents(School $school)
    {
        return [
            [
                'id' => 1,
                'title' => 'Parent-Teacher Meeting',
                'date' => now()->addDays(3),
                'time' => '10:00 AM',
                'location' => 'School Hall',
                'type' => 'meeting'
            ],
            [
                'id' => 2,
                'title' => 'Mathematics Exam',
                'date' => now()->addDays(5),
                'time' => '9:00 AM',
                'location' => 'Classrooms',
                'type' => 'exam'
            ],
            [
                'id' => 3,
                'title' => 'Sports Day',
                'date' => now()->addDays(7),
                'time' => '8:00 AM',
                'location' => 'Sports Field',
                'type' => 'event'
            ]
        ];
    }
}
