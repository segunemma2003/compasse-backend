<?php

namespace App\Http\Controllers;

use App\Models\StudentResult;
use App\Models\SubjectResult;
use App\Models\Student;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Get overall school performance analytics
     */
    public function getSchoolAnalytics(Request $request): JsonResponse
    {
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

        try {
            // In tenant context, get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 400);
            }

            $results = StudentResult::where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->get();

            $totalStudents = $results->count();
            $passedStudents = $results->where('average_score', '>=', 50)->count();

            $analytics = [
                'summary' => [
                    'total_students' => $totalStudents,
                    'total_results' => $results->count(),
                    'passed' => $passedStudents,
                    'failed' => $totalStudents - $passedStudents,
                    'pass_rate' => $totalStudents > 0 ? round(($passedStudents / $totalStudents) * 100, 2) : 0,
                ],
                'performance' => [
                    'school_average' => round($results->avg('average_score') ?? 0, 2),
                    'highest_average' => round($results->max('average_score') ?? 0, 2),
                    'lowest_average' => round($results->min('average_score') ?? 0, 2),
                ],
                'grade_distribution' => [
                    'A' => $results->where('grade', 'A')->count(),
                    'B' => $results->where('grade', 'B')->count(),
                    'C' => $results->where('grade', 'C')->count(),
                    'D' => $results->where('grade', 'D')->count(),
                    'E' => $results->where('grade', 'E')->count(),
                    'F' => $results->where('grade', 'F')->count(),
                ],
            ];

            return response()->json([
                'analytics' => $analytics,
                'term_id' => $request->term_id,
                'academic_year_id' => $request->academic_year_id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch school analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get class performance analytics
     */
    public function getClassAnalytics(Request $request, $classId): JsonResponse
    {
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

        try {
            $results = StudentResult::where('class_id', $classId)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->with('student.user')
                ->get();

            $totalStudents = $results->count();
            $passedStudents = $results->where('average_score', '>=', 50)->count();

            $analytics = [
                'class_id' => $classId,
                'summary' => [
                    'total_students' => $totalStudents,
                    'passed' => $passedStudents,
                    'failed' => $totalStudents - $passedStudents,
                    'pass_rate' => $totalStudents > 0 ? round(($passedStudents / $totalStudents) * 100, 2) : 0,
                ],
                'performance' => [
                    'class_average' => round($results->avg('average_score') ?? 0, 2),
                    'highest_average' => round($results->max('average_score') ?? 0, 2),
                    'lowest_average' => round($results->min('average_score') ?? 0, 2),
                    'median' => $this->calculateMedian($results->pluck('average_score')->toArray()),
                ],
                'grade_distribution' => [
                    'A' => $results->where('grade', 'A')->count(),
                    'B' => $results->where('grade', 'B')->count(),
                    'C' => $results->where('grade', 'C')->count(),
                    'D' => $results->where('grade', 'D')->count(),
                    'E' => $results->where('grade', 'E')->count(),
                    'F' => $results->where('grade', 'F')->count(),
                ],
                'top_performers' => $results->sortByDesc('average_score')->take(5)->map(function($result) {
                    return [
                        'student_id' => $result->student_id,
                        'name' => $result->student->user->name ?? 'N/A',
                        'average_score' => round($result->average_score, 2),
                        'position' => $result->position,
                    ];
                })->values(),
            ];

            return response()->json(['analytics' => $analytics]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch class analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get subject performance analytics
     */
    public function getSubjectAnalytics(Request $request, $subjectId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'class_id' => 'nullable|exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $query = SubjectResult::where('subject_id', $subjectId)
                ->whereHas('studentResult', function($q) use ($request) {
                    $q->where('term_id', $request->term_id)
                      ->where('academic_year_id', $request->academic_year_id);
                    if ($request->has('class_id')) {
                        $q->where('class_id', $request->class_id);
                    }
                });

            $subjectResults = $query->get();
            $totalStudents = $subjectResults->count();
            $passedStudents = $subjectResults->where('total_score', '>=', 50)->count();

            $analytics = [
                'subject_id' => $subjectId,
                'summary' => [
                    'total_students' => $totalStudents,
                    'passed' => $passedStudents,
                    'failed' => $totalStudents - $passedStudents,
                    'pass_rate' => $totalStudents > 0 ? round(($passedStudents / $totalStudents) * 100, 2) : 0,
                ],
                'performance' => [
                    'average_score' => round($subjectResults->avg('total_score') ?? 0, 2),
                    'highest_score' => round($subjectResults->max('total_score') ?? 0, 2),
                    'lowest_score' => round($subjectResults->min('total_score') ?? 0, 2),
                    'average_ca' => round($subjectResults->avg('ca_total') ?? 0, 2),
                    'average_exam' => round($subjectResults->avg('exam_score') ?? 0, 2),
                ],
                'grade_distribution' => [
                    'A' => $subjectResults->where('grade', 'A')->count(),
                    'B' => $subjectResults->where('grade', 'B')->count(),
                    'C' => $subjectResults->where('grade', 'C')->count(),
                    'D' => $subjectResults->where('grade', 'D')->count(),
                    'E' => $subjectResults->where('grade', 'E')->count(),
                    'F' => $subjectResults->where('grade', 'F')->count(),
                ],
            ];

            return response()->json(['analytics' => $analytics]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch subject analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get student performance trend
     */
    public function getStudentTrend(Request $request, $studentId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $results = StudentResult::where('student_id', $studentId)
                ->where('academic_year_id', $request->academic_year_id)
                ->with('term')
                ->orderBy('term_id')
                ->get();

            $trend = $results->map(function($result) {
                return [
                    'term' => $result->term->name ?? 'N/A',
                    'term_id' => $result->term_id,
                    'average_score' => round($result->average_score, 2),
                    'total_score' => round($result->total_score, 2),
                    'grade' => $result->grade,
                    'position' => $result->position,
                    'out_of' => $result->out_of,
                ];
            });

            $analytics = [
                'student_id' => $studentId,
                'academic_year_id' => $request->academic_year_id,
                'trend' => $trend,
                'summary' => [
                    'best_term' => $results->sortByDesc('average_score')->first()->term->name ?? 'N/A',
                    'best_average' => round($results->max('average_score') ?? 0, 2),
                    'current_average' => round($results->last()->average_score ?? 0, 2),
                    'improvement' => $this->calculateImprovement($results),
                ],
            ];

            return response()->json(['analytics' => $analytics]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch student trend',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get comparative analytics (class vs class, term vs term)
     */
    public function getComparativeAnalytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:class,term,subject',
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
            if ($request->type === 'class') {
                $comparison = $this->compareClasses($request->term_id, $request->academic_year_id);
            } elseif ($request->type === 'term') {
                $comparison = $this->compareTerms($request->academic_year_id);
            } else {
                $comparison = $this->compareSubjects($request->term_id, $request->academic_year_id);
            }

            return response()->json([
                'comparison' => $comparison,
                'type' => $request->type,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch comparative analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance prediction
     */
    public function getPrediction(Request $request, $studentId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $results = StudentResult::where('student_id', $studentId)
                ->where('academic_year_id', $request->academic_year_id)
                ->orderBy('term_id')
                ->get();

            if ($results->count() < 2) {
                return response()->json([
                    'message' => 'Not enough data for prediction',
                    'note' => 'At least 2 terms of data required'
                ]);
            }

            $scores = $results->pluck('average_score')->toArray();
            $trend = $this->calculateTrend($scores);
            $predictedScore = end($scores) + $trend;

            $prediction = [
                'student_id' => $studentId,
                'current_average' => round(end($scores), 2),
                'trend' => $trend > 0 ? 'improving' : ($trend < 0 ? 'declining' : 'stable'),
                'predicted_next_average' => round($predictedScore, 2),
                'confidence' => 'medium', // Could be improved with more sophisticated algorithm
            ];

            return response()->json(['prediction' => $prediction]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate prediction',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper: Calculate median
     */
    private function calculateMedian(array $numbers): float
    {
        if (empty($numbers)) return 0;
        sort($numbers);
        $count = count($numbers);
        $middle = floor($count / 2);
        
        if ($count % 2 == 0) {
            return ($numbers[$middle - 1] + $numbers[$middle]) / 2;
        }
        return $numbers[$middle];
    }

    /**
     * Helper: Calculate improvement
     */
    private function calculateImprovement($results): string
    {
        if ($results->count() < 2) return 'N/A';
        
        $first = $results->first()->average_score;
        $last = $results->last()->average_score;
        $change = $last - $first;
        
        if ($change > 5) return 'Significant improvement';
        if ($change > 0) return 'Slight improvement';
        if ($change < -5) return 'Significant decline';
        if ($change < 0) return 'Slight decline';
        return 'Stable';
    }

    /**
     * Helper: Calculate trend
     */
    private function calculateTrend(array $scores): float
    {
        if (count($scores) < 2) return 0;
        
        $changes = [];
        for ($i = 1; $i < count($scores); $i++) {
            $changes[] = $scores[$i] - $scores[$i - 1];
        }
        
        return array_sum($changes) / count($changes);
    }

    /**
     * Helper: Compare classes
     */
    private function compareClasses($termId, $academicYearId): array
    {
        $classes = DB::table('student_results')
            ->join('classes', 'student_results.class_id', '=', 'classes.id')
            ->where('student_results.term_id', $termId)
            ->where('student_results.academic_year_id', $academicYearId)
            ->select('classes.id', 'classes.name')
            ->groupBy('classes.id', 'classes.name')
            ->selectRaw('AVG(student_results.average_score) as avg_score')
            ->selectRaw('COUNT(student_results.id) as total_students')
            ->orderBy('avg_score', 'desc')
            ->get();

        return $classes->toArray();
    }

    /**
     * Helper: Compare terms
     */
    private function compareTerms($academicYearId): array
    {
        $terms = DB::table('student_results')
            ->join('terms', 'student_results.term_id', '=', 'terms.id')
            ->where('student_results.academic_year_id', $academicYearId)
            ->select('terms.id', 'terms.name')
            ->groupBy('terms.id', 'terms.name')
            ->selectRaw('AVG(student_results.average_score) as avg_score')
            ->selectRaw('COUNT(student_results.id) as total_students')
            ->orderBy('terms.id')
            ->get();

        return $terms->toArray();
    }

    /**
     * Helper: Compare subjects
     */
    private function compareSubjects($termId, $academicYearId): array
    {
        $subjects = DB::table('subject_results')
            ->join('student_results', 'subject_results.student_result_id', '=', 'student_results.id')
            ->join('subjects', 'subject_results.subject_id', '=', 'subjects.id')
            ->where('student_results.term_id', $termId)
            ->where('student_results.academic_year_id', $academicYearId)
            ->select('subjects.id', 'subjects.name')
            ->groupBy('subjects.id', 'subjects.name')
            ->selectRaw('AVG(subject_results.total_score) as avg_score')
            ->selectRaw('COUNT(subject_results.id) as total_students')
            ->orderBy('avg_score', 'desc')
            ->get();

        return $subjects->toArray();
    }
}

