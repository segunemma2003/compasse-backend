<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\GradingService;
use App\Models\Result;
use App\Models\Student;
use App\Models\ClassModel;
use App\Models\Term;
use App\Models\AcademicYear;

class ResultController extends Controller
{
    protected GradingService $gradingService;

    public function __construct(GradingService $gradingService)
    {
        $this->gradingService = $gradingService;
    }

    /**
     * Generate mid-term results
     */
    public function generateMidTermResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $results = $this->gradingService->generateMidTermResults(
                $request->class_id,
                $request->term_id,
                $request->academic_year_id
            );

            $statistics = $this->gradingService->calculateClassStatistics($results['results']);

            return response()->json([
                'success' => true,
                'message' => 'Mid-term results generated successfully',
                'data' => [
                    'results' => $results,
                    'statistics' => $statistics,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Mid-term results generation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate mid-term results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate end-of-term results
     */
    public function generateEndOfTermResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $results = $this->gradingService->generateEndOfTermResults(
                $request->class_id,
                $request->term_id,
                $request->academic_year_id
            );

            $statistics = $this->gradingService->calculateClassStatistics($results['results']);

            return response()->json([
                'success' => true,
                'message' => 'End-of-term results generated successfully',
                'data' => [
                    'results' => $results,
                    'statistics' => $statistics,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('End-of-term results generation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate end-of-term results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate annual results
     */
    public function generateAnnualResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $results = $this->gradingService->generateAnnualResults(
                $request->class_id,
                $request->academic_year_id
            );

            $statistics = $this->gradingService->calculateClassStatistics($results['results']);

            return response()->json([
                'success' => true,
                'message' => 'Annual results generated successfully',
                'data' => [
                    'results' => $results,
                    'statistics' => $statistics,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Annual results generation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate annual results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get student results
     */
    public function getStudentResults(Request $request, int $studentId): JsonResponse
    {
        try {
            $student = Student::findOrFail($studentId);

            $results = Result::where('student_id', $studentId)
                ->with(['exam', 'subject', 'class', 'term', 'academicYear'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('term_id');

            $formattedResults = [];
            foreach ($results as $termId => $termResults) {
                $term = $termResults->first()->term;
                $academicYear = $termResults->first()->academicYear;

                $subjectResults = $termResults->groupBy('subject_id')->map(function ($subjectResults) {
                    $subject = $subjectResults->first()->subject;
                    $totalMarks = $subjectResults->sum('total_marks');
                    $obtainedMarks = $subjectResults->sum('marks_obtained');
                    $percentage = $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0;
                    $grade = $subjectResults->first()->grade;

                    return [
                        'subject_id' => $subject->id,
                        'subject_name' => $subject->name,
                        'subject_code' => $subject->code,
                        'total_marks' => $totalMarks,
                        'obtained_marks' => $obtainedMarks,
                        'percentage' => $percentage,
                        'grade' => $grade,
                        'position' => $subjectResults->first()->position,
                        'assessments' => $subjectResults->map(function ($result) {
                            return [
                                'exam_name' => $result->exam->name,
                                'marks_obtained' => $result->marks_obtained,
                                'total_marks' => $result->total_marks,
                                'percentage' => $result->percentage,
                                'grade' => $result->grade,
                                'date' => $result->created_at->format('Y-m-d'),
                            ];
                        })->toArray(),
                    ];
                })->values()->toArray();

                $overallMarks = collect($subjectResults)->sum('obtained_marks');
                $overallTotal = collect($subjectResults)->sum('total_marks');
                $overallPercentage = $overallTotal > 0 ? round(($overallMarks / $overallTotal) * 100, 2) : 0;
                $overallGrade = $this->gradingService->calculateGrade($overallPercentage);

                $formattedResults[] = [
                    'term_id' => $termId,
                    'term_name' => $term->name,
                    'academic_year' => $academicYear->name,
                    'subject_results' => $subjectResults,
                    'overall_percentage' => $overallPercentage,
                    'overall_grade' => $overallGrade,
                    'total_marks' => $overallTotal,
                    'obtained_marks' => $overallMarks,
                    'subject_count' => count($subjectResults),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'student' => [
                        'id' => $student->id,
                        'name' => $student->first_name . ' ' . $student->last_name,
                        'admission_number' => $student->admission_number,
                        'class_name' => $student->class->name,
                        'arm_name' => $student->arm->name ?? null,
                    ],
                    'results' => $formattedResults,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Student results retrieval failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve student results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get class results
     */
    public function getClassResults(Request $request, int $classId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'nullable|exists:terms,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $class = ClassModel::findOrFail($classId);

            $query = Result::whereHas('student', function ($query) use ($classId) {
                $query->where('class_id', $classId);
            });

            if ($request->term_id) {
                $query->where('term_id', $request->term_id);
            }

            if ($request->academic_year_id) {
                $query->where('academic_year_id', $request->academic_year_id);
            }

            $results = $query->with(['student', 'subject', 'exam', 'term', 'academicYear'])
                ->get()
                ->groupBy('student_id');

            $formattedResults = [];
            foreach ($results as $studentId => $studentResults) {
                $student = $studentResults->first()->student;
                $subjectResults = $studentResults->groupBy('subject_id')->map(function ($subjectResults) {
                    $subject = $subjectResults->first()->subject;
                    $totalMarks = $subjectResults->sum('total_marks');
                    $obtainedMarks = $subjectResults->sum('marks_obtained');
                    $percentage = $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0;
                    $grade = $subjectResults->first()->grade;

                    return [
                        'subject_id' => $subject->id,
                        'subject_name' => $subject->name,
                        'subject_code' => $subject->code,
                        'total_marks' => $totalMarks,
                        'obtained_marks' => $obtainedMarks,
                        'percentage' => $percentage,
                        'grade' => $grade,
                        'position' => $subjectResults->first()->position,
                    ];
                })->values()->toArray();

                $overallMarks = collect($subjectResults)->sum('obtained_marks');
                $overallTotal = collect($subjectResults)->sum('total_marks');
                $overallPercentage = $overallTotal > 0 ? round(($overallMarks / $overallTotal) * 100, 2) : 0;
                $overallGrade = $this->gradingService->calculateGrade($overallPercentage);

                $formattedResults[] = [
                    'student_id' => $student->id,
                    'student_name' => $student->first_name . ' ' . $student->last_name,
                    'admission_number' => $student->admission_number,
                    'class_name' => $student->class->name,
                    'arm_name' => $student->arm->name ?? null,
                    'subject_results' => $subjectResults,
                    'overall_percentage' => $overallPercentage,
                    'overall_grade' => $overallGrade,
                    'total_marks' => $overallTotal,
                    'obtained_marks' => $overallMarks,
                    'subject_count' => count($subjectResults),
                ];
            }

            // Sort by overall percentage
            usort($formattedResults, function ($a, $b) {
                return $b['overall_percentage'] <=> $a['overall_percentage'];
            });

            // Assign positions
            foreach ($formattedResults as $index => &$result) {
                $result['position'] = $index + 1;
            }

            $statistics = $this->gradingService->calculateClassStatistics($formattedResults);

            return response()->json([
                'success' => true,
                'data' => [
                    'class' => [
                        'id' => $class->id,
                        'name' => $class->name,
                        'description' => $class->description,
                    ],
                    'results' => $formattedResults,
                    'statistics' => $statistics,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Class results retrieval failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve class results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish results
     */
    public function publishResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'result_ids' => 'required|array',
            'result_ids.*' => 'exists:results,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updated = Result::whereIn('id', $request->result_ids)
                ->update(['is_published' => true]);

            return response()->json([
                'success' => true,
                'message' => "Successfully published {$updated} results",
                'data' => [
                    'published_count' => $updated,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Results publishing failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to publish results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unpublish results
     */
    public function unpublishResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'result_ids' => 'required|array',
            'result_ids.*' => 'exists:results,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updated = Result::whereIn('id', $request->result_ids)
                ->update(['is_published' => false]);

            return response()->json([
                'success' => true,
                'message' => "Successfully unpublished {$updated} results",
                'data' => [
                    'unpublished_count' => $updated,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Results unpublishing failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to unpublish results',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
