<?php

namespace App\Http\Controllers;

use App\Models\StudentResult;
use App\Models\SubjectResult;
use App\Models\Student;
use App\Models\CAScore;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\GradingSystem;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ResultController extends Controller
{
    /**
     * Generate results for a class/student
     */
    public function generateResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'student_ids' => 'nullable|array', // If empty, generate for all students
            'student_ids.*' => 'exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $subdomain = $request->header('X-Subdomain');
            $school = School::where('subdomain', $subdomain)->first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 400);
            }

            // Get grading system
            $gradingSystem = GradingSystem::where('school_id', $school->id)
                ->where('is_default', true)
                ->first();

            if (!$gradingSystem) {
                return response()->json([
                    'error' => 'No default grading system found. Please set one first.'
                ], 400);
            }

            DB::beginTransaction();

            // Get students
            $studentsQuery = Student::where('class_id', $request->class_id);
            if ($request->has('student_ids') && !empty($request->student_ids)) {
                $studentsQuery->whereIn('id', $request->student_ids);
            }
            $students = $studentsQuery->get();

            if ($students->isEmpty()) {
                return response()->json(['error' => 'No students found'], 404);
            }

            // Get subjects for the class
            $subjects = DB::table('subjects')
                ->where('class_id', $request->class_id)
                ->get();

            $generatedResults = [];

            foreach ($students as $student) {
                // Create or update main result
                $result = StudentResult::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'class_id' => $request->class_id,
                        'term_id' => $request->term_id,
                        'academic_year_id' => $request->academic_year_id,
                    ],
                    [
                        'status' => 'draft',
                    ]
                );

                $totalScore = 0;
                $subjectCount = 0;

                // Process each subject
                foreach ($subjects as $subject) {
                    // Get CA total
                    $caTotal = $this->getStudentCATotal($student->id, $subject->id, $request->term_id);

                    // Get exam score
                    $examScore = $this->getStudentExamScore($student->id, $subject->id, $request->term_id, $request->academic_year_id);

                    // Calculate total
                    $subjectTotal = $caTotal + $examScore;
                    $totalScore += $subjectTotal;
                    $subjectCount++;

                    // Get grade
                    $gradeInfo = $gradingSystem->getGrade($subjectTotal);

                    // Get subject statistics
                    $subjectStats = $this->getSubjectStatistics($subject->id, $request->class_id, $request->term_id, $request->academic_year_id);

                    // Create/update subject result
                    SubjectResult::updateOrCreate(
                        [
                            'student_result_id' => $result->id,
                            'subject_id' => $subject->id,
                        ],
                        [
                            'ca_total' => $caTotal,
                            'exam_score' => $examScore,
                            'total_score' => $subjectTotal,
                            'grade' => $gradeInfo['grade'],
                            'teacher_remark' => $this->getRemarkForGrade($gradeInfo['grade']),
                            'highest_score' => $subjectStats['highest'],
                            'lowest_score' => $subjectStats['lowest'],
                            'class_average' => $subjectStats['average'],
                        ]
                    );
                }

                // Update main result
                $averageScore = $subjectCount > 0 ? $totalScore / $subjectCount : 0;
                $overallGrade = $gradingSystem->getGrade($averageScore);

                $result->update([
                    'total_score' => $totalScore,
                    'average_score' => $averageScore,
                    'grade' => $overallGrade['grade'],
                ]);

                $generatedResults[] = [
                    'student_id' => $student->id,
                    'student_name' => $student->user->name ?? 'N/A',
                    'total_score' => $totalScore,
                    'average' => round($averageScore, 2),
                    'grade' => $overallGrade['grade'],
                ];
            }

            // Calculate positions
            $this->calculatePositions($request->class_id, $request->term_id, $request->academic_year_id);

            DB::commit();

            return response()->json([
                'message' => 'Results generated successfully',
                'summary' => [
                    'total_students' => count($generatedResults),
                    'class_id' => $request->class_id,
                    'term_id' => $request->term_id,
                ],
                'results' => $generatedResults
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to generate results',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get student result
     */
    public function getStudentResult($studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            $result = StudentResult::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->with([
                    'student.user',
                    'class',
                    'term',
                    'academicYear',
                    'subjectResults.subject',
                ])
                ->first();

            if (!$result) {
                return response()->json([
                    'error' => 'Result not found',
                    'message' => 'No result generated for this student and term'
                ], 404);
            }

            // Get psychomotor assessment
            $psychomotor = $result->psychomotorAssessment();

            return response()->json([
                'result' => $result,
                'psychomotor_assessment' => $psychomotor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch result',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get class results
     */
    public function getClassResults(Request $request, $classId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'term_id' => 'required|exists:terms,id',
                'academic_year_id' => 'required|exists:academic_years,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'messages' => $validator->errors()
                ], 422);
            }

            $results = StudentResult::where('class_id', $classId)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->with(['student.user', 'subjectResults'])
                ->orderBy('position')
                ->get();

            $statistics = [
                'total_students' => $results->count(),
                'class_average' => $results->avg('average_score') ?? 0,
                'highest_average' => $results->max('average_score') ?? 0,
                'lowest_average' => $results->min('average_score') ?? 0,
            ];

            return response()->json([
                'results' => $results,
                'statistics' => $statistics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch class results',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add comments to result
     */
    public function addComments(Request $request, $resultId): JsonResponse
    {
        $result = StudentResult::find($resultId);

        if (!$result) {
            return response()->json(['error' => 'Result not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'class_teacher_comment' => 'nullable|string|max:500',
            'principal_comment' => 'nullable|string|max:500',
            'next_term_begins' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $result->update($request->only([
                'class_teacher_comment',
                'principal_comment',
                'next_term_begins'
            ]));

            return response()->json([
                'message' => 'Comments added successfully',
                'result' => $result->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to add comments',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve result
     */
    public function approveResult(Request $request, $resultId): JsonResponse
    {
        $result = StudentResult::find($resultId);

        if (!$result) {
            return response()->json(['error' => 'Result not found'], 404);
        }

        try {
            $user = Auth::user();

            $result->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);

            return response()->json([
                'message' => 'Result approved successfully',
                'result' => $result->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to approve result',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish results (make available to students/parents)
     */
    public function publishResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $updated = StudentResult::where('class_id', $request->class_id)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->where('status', 'approved')
                ->update([
                    'status' => 'published',
                    'approved_at' => now(),
                    'approved_by' => Auth::id(),
                ]);

            DB::commit();

            return response()->json([
                'message' => 'Results published successfully',
                'published_count' => $updated
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to publish results',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper: Get student CA total for a subject
     */
    private function getStudentCATotal($studentId, $subjectId, $termId): float
    {
        return CAScore::whereHas('continuousAssessment', function($query) use ($subjectId, $termId) {
            $query->where('subject_id', $subjectId)
                  ->where('term_id', $termId);
        })->where('student_id', $studentId)->sum('score') ?? 0;
    }

    /**
     * Helper: Get student exam score
     */
    private function getStudentExamScore($studentId, $subjectId, $termId, $academicYearId): float
    {
        $exam = Exam::where('subject_id', $subjectId)
            ->where('term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if (!$exam) {
            return 0;
        }

        $submission = ExamSubmission::where('exam_id', $exam->id)
            ->where('student_id', $studentId)
            ->first();

        return $submission->score ?? 0;
    }

    /**
     * Helper: Get subject statistics
     */
    private function getSubjectStatistics($subjectId, $classId, $termId, $academicYearId): array
    {
        $results = SubjectResult::whereHas('studentResult', function($query) use ($classId, $termId, $academicYearId) {
            $query->where('class_id', $classId)
                  ->where('term_id', $termId)
                  ->where('academic_year_id', $academicYearId);
        })->where('subject_id', $subjectId)->get();

        return [
            'highest' => $results->max('total_score') ?? 0,
            'lowest' => $results->min('total_score') ?? 0,
            'average' => $results->avg('total_score') ?? 0,
        ];
    }

    /**
     * Helper: Calculate positions
     */
    private function calculatePositions($classId, $termId, $academicYearId): void
    {
        $results = StudentResult::where('class_id', $classId)
            ->where('term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->orderBy('average_score', 'desc')
            ->get();

        $position = 1;
        $totalStudents = $results->count();

        foreach ($results as $result) {
            $result->update([
                'position' => $position,
                'out_of' => $totalStudents,
                'class_average' => $results->avg('average_score'),
            ]);
            $position++;
        }

        // Also update subject positions
        $this->calculateSubjectPositions($classId, $termId, $academicYearId);
    }

    /**
     * Helper: Calculate subject positions
     */
    private function calculateSubjectPositions($classId, $termId, $academicYearId): void
    {
        $subjects = DB::table('subjects')->where('class_id', $classId)->get();

        foreach ($subjects as $subject) {
            $subjectResults = SubjectResult::whereHas('studentResult', function($query) use ($classId, $termId, $academicYearId) {
                $query->where('class_id', $classId)
                      ->where('term_id', $termId)
                      ->where('academic_year_id', $academicYearId);
            })
            ->where('subject_id', $subject->id)
            ->orderBy('total_score', 'desc')
            ->get();

            $position = 1;
            foreach ($subjectResults as $result) {
                $result->update(['position' => $position]);
                $position++;
            }
        }
    }

    /**
     * Helper: Get remark for grade
     */
    private function getRemarkForGrade($grade): string
    {
        $remarks = [
            'A' => 'Excellent performance',
            'B' => 'Very good performance',
            'C' => 'Good performance',
            'D' => 'Fair performance',
            'E' => 'Pass',
            'F' => 'Needs improvement',
        ];

        return $remarks[$grade] ?? 'N/A';
    }
}
