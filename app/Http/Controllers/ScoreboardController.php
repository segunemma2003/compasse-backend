<?php

namespace App\Http\Controllers;

use App\Models\Scoreboard;
use App\Models\StudentResult;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ScoreboardController extends Controller
{
    /**
     * Get scoreboard for class
     */
    public function getScoreboard(Request $request, $classId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Check if scoreboard exists and is recent
            $scoreboard = Scoreboard::where('class_id', $classId)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->first();

            // Refresh if older than 1 hour or doesn't exist
            if (!$scoreboard || $scoreboard->last_updated < now()->subHour()) {
                $scoreboard = $this->refreshScoreboard($classId, $request->term_id, $request->academic_year_id);
            }

            $limit = $request->limit ?? 10;
            $rankings = collect($scoreboard->rankings)->take($limit);

            return response()->json([
                'scoreboard' => [
                    'class_id' => $scoreboard->class_id,
                    'term_id' => $scoreboard->term_id,
                    'academic_year_id' => $scoreboard->academic_year_id,
                    'rankings' => $rankings,
                    'class_average' => $scoreboard->class_average,
                    'total_students' => $scoreboard->total_students,
                    'pass_rate' => $scoreboard->pass_rate,
                    'last_updated' => $scoreboard->last_updated,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch scoreboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get top performers across all classes
     */
    public function getTopPerformers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'limit' => 'nullable|integer|min:1|max:100',
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

            $limit = $request->limit ?? 20;

            $topPerformers = StudentResult::where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->with(['student.user', 'class'])
                ->orderBy('average_score', 'desc')
                ->limit($limit)
                ->get()
                ->map(function($result, $index) {
                    return [
                        'position' => $index + 1,
                        'student_id' => $result->student_id,
                        'student_name' => $result->student->user->name ?? 'N/A',
                        'admission_number' => $result->student->admission_number,
                        'class' => $result->class->name ?? 'N/A',
                        'average_score' => round($result->average_score, 2),
                        'grade' => $result->grade,
                    ];
                });

            return response()->json([
                'top_performers' => $topPerformers,
                'term_id' => $request->term_id,
                'academic_year_id' => $request->academic_year_id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch top performers',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get subject toppers
     */
    public function getSubjectToppers(Request $request, $subjectId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'class_id' => 'nullable|exists:classes,id',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $query = DB::table('subject_results')
                ->join('student_results', 'subject_results.student_result_id', '=', 'student_results.id')
                ->join('students', 'student_results.student_id', '=', 'students.id')
                ->join('users', 'students.user_id', '=', 'users.id')
                ->join('subjects', 'subject_results.subject_id', '=', 'subjects.id')
                ->where('subject_results.subject_id', $subjectId)
                ->where('student_results.term_id', $request->term_id)
                ->where('student_results.academic_year_id', $request->academic_year_id);

            if ($request->has('class_id')) {
                $query->where('student_results.class_id', $request->class_id);
            }

            $limit = $request->limit ?? 10;

            $toppers = $query->select([
                    'students.id as student_id',
                    'users.name as student_name',
                    'students.admission_number',
                    'subjects.name as subject_name',
                    'subject_results.total_score',
                    'subject_results.grade',
                    'subject_results.position',
                ])
                ->orderBy('subject_results.total_score', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'subject_toppers' => $toppers,
                'subject_id' => $subjectId,
                'term_id' => $request->term_id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch subject toppers',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh scoreboard
     */
    public function refreshScoreboard($classId, $termId, $academicYearId): Scoreboard
    {
        $results = StudentResult::where('class_id', $classId)
            ->where('term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->with(['student.user'])
            ->orderBy('average_score', 'desc')
            ->get();

        $totalStudents = $results->count();
        $classAverage = $results->avg('average_score') ?? 0;
        $passedStudents = $results->where('average_score', '>=', 50)->count();
        $passRate = $totalStudents > 0 ? round(($passedStudents / $totalStudents) * 100) : 0;

        $rankings = $results->map(function($result, $index) {
            return [
                'position' => $index + 1,
                'student_id' => $result->student_id,
                'student_name' => $result->student->user->name ?? 'N/A',
                'admission_number' => $result->student->admission_number,
                'average_score' => round($result->average_score, 2),
                'total_score' => round($result->total_score, 2),
                'grade' => $result->grade,
            ];
        })->toArray();

        return Scoreboard::updateOrCreate(
            [
                'class_id' => $classId,
                'term_id' => $termId,
                'academic_year_id' => $academicYearId,
            ],
            [
                'rankings' => $rankings,
                'class_average' => round($classAverage, 2),
                'total_students' => $totalStudents,
                'pass_rate' => $passRate,
                'last_updated' => now(),
            ]
        );
    }

    /**
     * Manual refresh endpoint
     */
    public function manualRefresh(Request $request): JsonResponse
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
            $scoreboard = $this->refreshScoreboard(
                $request->class_id,
                $request->term_id,
                $request->academic_year_id
            );

            return response()->json([
                'message' => 'Scoreboard refreshed successfully',
                'scoreboard' => $scoreboard
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to refresh scoreboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get class comparison
     */
    public function getClassComparison(Request $request): JsonResponse
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
            $subdomain = $request->header('X-Subdomain');
            $school = School::where('subdomain', $subdomain)->first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 400);
            }

            $scoreboards = Scoreboard::where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->with('class')
                ->get()
                ->map(function($scoreboard) {
                    return [
                        'class_id' => $scoreboard->class_id,
                        'class_name' => $scoreboard->class->name ?? 'N/A',
                        'class_average' => $scoreboard->class_average,
                        'total_students' => $scoreboard->total_students,
                        'pass_rate' => $scoreboard->pass_rate,
                    ];
                })
                ->sortByDesc('class_average')
                ->values();

            return response()->json([
                'class_comparison' => $scoreboards,
                'term_id' => $request->term_id,
                'academic_year_id' => $request->academic_year_id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch class comparison',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

