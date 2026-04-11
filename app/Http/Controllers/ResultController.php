<?php

namespace App\Http\Controllers;

use App\Models\StudentResult;
use App\Models\SubjectResult;
use App\Models\Student;
use App\Models\CAScore;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\GradingSystem;
use App\Models\ResultConfiguration;
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
            // In tenant context, get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 400);
            }

            $classRow = DB::table('classes')->where('id', $request->class_id)->first();
            if (! $classRow) {
                return response()->json(['error' => 'Class not found'], 404);
            }

            $sectionType = $classRow->section_type ?? 'primary';
            $resultConfig = ResultConfiguration::where('school_id', $school->id)
                ->where('section_type', $sectionType)
                ->where('is_active', true)
                ->first();

            $gradingSystem = null;
            if ($resultConfig && $resultConfig->grading_system_id) {
                $gradingSystem = GradingSystem::find($resultConfig->grading_system_id);
            }
            if (! $gradingSystem) {
                $gradingSystem = GradingSystem::where('school_id', $school->id)
                    ->where('is_default', true)
                    ->first();
            }

            $needsDefaultGradingScale = ! $resultConfig
                || ! in_array($resultConfig->grade_style, ['remarks_only', 'percentage'], true);

            if ($needsDefaultGradingScale && ! $gradingSystem) {
                return response()->json([
                    'error' => 'No grading system found. Set a default grading system or link one in result configuration.',
                ], 400);
            }

            // When a section config exists, blend CA and exam using configured weights (both inputs assumed 0–100).
            // Legacy tenants with no config keep additive ca_total + exam_score.
            $useWeightedBlend = $resultConfig !== null;
            $caWeight = $resultConfig ? (float) $resultConfig->ca_weight : 40.0;
            $examWeight = $resultConfig ? (float) $resultConfig->exam_weight : 60.0;

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

            $studentIds  = $students->pluck('id');
            $subjectIds  = $subjects->pluck('id');

            // ── Pre-load ALL CA scores for these students/term in ONE query ──
            // Previously: 1 query per student per subject inside nested loops
            $allCaTotals = DB::table('ca_scores')
                ->join('continuous_assessments', 'ca_scores.continuous_assessment_id', '=', 'continuous_assessments.id')
                ->where('continuous_assessments.term_id', $request->term_id)
                ->whereIn('ca_scores.student_id', $studentIds)
                ->whereIn('continuous_assessments.subject_id', $subjectIds)
                ->select(
                    'ca_scores.student_id',
                    'continuous_assessments.subject_id',
                    DB::raw('SUM(ca_scores.score) as ca_total')
                )
                ->groupBy('ca_scores.student_id', 'continuous_assessments.subject_id')
                ->get()
                ->groupBy('student_id')
                ->map(fn ($rows) => $rows->keyBy('subject_id'));

            // ── Pre-load ALL exam scores for these students in ONE query ─────
            $allExamScores = DB::table('exam_submissions')
                ->join('exams', 'exam_submissions.exam_id', '=', 'exams.id')
                ->where('exams.term_id', $request->term_id)
                ->where('exams.academic_year_id', $request->academic_year_id)
                ->whereIn('exam_submissions.student_id', $studentIds)
                ->whereIn('exams.subject_id', $subjectIds)
                ->select('exam_submissions.student_id', 'exams.subject_id', 'exam_submissions.score')
                ->get()
                ->groupBy('student_id')
                ->map(fn ($rows) => $rows->keyBy('subject_id'));

            // ── Ensure StudentResult rows exist (single upsert) ───────────────
            $now = now()->toDateTimeString();
            $resultRows = $studentIds->map(fn ($sid) => [
                'student_id'       => $sid,
                'class_id'         => $request->class_id,
                'term_id'          => $request->term_id,
                'academic_year_id' => $request->academic_year_id,
                'status'           => 'draft',
                'created_at'       => $now,
                'updated_at'       => $now,
            ])->all();

            DB::table('student_results')->upsert(
                $resultRows,
                ['student_id', 'class_id', 'term_id', 'academic_year_id'],
                ['status', 'updated_at']
            );

            // Fetch all result IDs in one query
            $resultIdMap = StudentResult::where('class_id', $request->class_id)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->whereIn('student_id', $studentIds)
                ->pluck('id', 'student_id');

            // ── Process all student × subject combinations in memory ──────────
            $subjectResultRows  = [];
            $studentSummaryRows = [];
            $generatedResults   = [];

            foreach ($students as $student) {
                $totalScore   = 0;
                $subjectCount = 0;
                $resultId     = $resultIdMap[$student->id] ?? null;

                if (!$resultId) continue;

                foreach ($subjects as $subject) {
                    $caRow     = $allCaTotals[$student->id][$subject->id] ?? null;
                    $examRow   = $allExamScores[$student->id][$subject->id] ?? null;
                    $caTotal   = (float) ($caRow->ca_total ?? 0);
                    $examScore = (float) ($examRow->score ?? 0);
                    $subjectTotal = $useWeightedBlend
                        ? round(($caTotal * $caWeight + $examScore * $examWeight) / 100.0, 2)
                        : round($caTotal + $examScore, 2);

                    $totalScore += $subjectTotal;
                    $subjectCount++;

                    $gradeInfo = $this->gradeSubjectScore($subjectTotal, $resultConfig, $gradingSystem);

                    $subjectResultRows[] = [
                        'student_result_id' => $resultId,
                        'subject_id'        => $subject->id,
                        'ca_total'          => $caTotal,
                        'exam_score'        => $examScore,
                        'total_score'       => $subjectTotal,
                        'grade'             => $gradeInfo['grade'],
                        'teacher_remark'    => $gradeInfo['teacher_remark'],
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ];
                }

                $averageScore = $subjectCount > 0 ? $totalScore / $subjectCount : 0;
                $overallGrade = $this->gradeSubjectScore($averageScore, $resultConfig, $gradingSystem);

                $studentSummaryRows[] = [
                    'id'            => $resultId,
                    'total_score'   => $totalScore,
                    'average_score' => $averageScore,
                    'grade'         => $overallGrade['grade'],
                    'updated_at'    => $now,
                ];

                $generatedResults[] = [
                    'student_id'   => $student->id,
                    'student_name' => $student->name ?? 'N/A',
                    'total_score'  => $totalScore,
                    'average'      => round($averageScore, 2),
                    'grade'        => $overallGrade['grade'],
                ];
            }

            // ── Batch upsert subject results (1 query) ────────────────────────
            if (!empty($subjectResultRows)) {
                DB::table('subject_results')->upsert(
                    $subjectResultRows,
                    ['student_result_id', 'subject_id'],
                    ['ca_total', 'exam_score', 'total_score', 'grade', 'teacher_remark', 'updated_at']
                );
            }

            // ── Subject statistics: single aggregate query, keyed by subject_id
            $statsMap = DB::table('subject_results')
                ->whereIn('student_result_id', $resultIdMap->values())
                ->select(
                    'subject_id',
                    DB::raw('MAX(total_score) as highest'),
                    DB::raw('MIN(total_score) as lowest'),
                    DB::raw('AVG(total_score) as average')
                )
                ->groupBy('subject_id')
                ->get()
                ->keyBy('subject_id');

            // Update subject results with stats (batch UPDATE per subject)
            foreach ($subjects as $subject) {
                $stats = $statsMap[$subject->id] ?? null;
                if (!$stats) continue;
                DB::table('subject_results')
                    ->whereIn('student_result_id', $resultIdMap->values())
                    ->where('subject_id', $subject->id)
                    ->update([
                        'highest_score' => $stats->highest,
                        'lowest_score'  => $stats->lowest,
                        'class_average' => $stats->average,
                        'updated_at'    => $now,
                    ]);
            }

            // ── Batch update student result summaries ─────────────────────────
            foreach ($studentSummaryRows as $row) {
                DB::table('student_results')->where('id', $row['id'])->update([
                    'total_score'   => $row['total_score'],
                    'average_score' => $row['average_score'],
                    'grade'         => $row['grade'],
                    'updated_at'    => $row['updated_at'],
                ]);
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
                    'section_type' => $sectionType,
                    'result_configuration_id' => $resultConfig?->id,
                    'scoring_mode' => $useWeightedBlend ? 'weighted' : 'legacy_additive',
                    'ca_weight' => $caWeight,
                    'exam_weight' => $examWeight,
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
    public function getStudentResult(Request $request, $studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            // ── Row-level scoping ────────────────────────────────────────────
            $user  = Auth::user();
            $ownId = $this->ownStudentId($user);
            if ($ownId !== null && (int) $ownId !== (int) $studentId) {
                return $this->forbiddenResponse('You may only view your own results.');
            }

            if ($ownId === null) {
                $classIds = $this->accessibleClassIds($user);
                if ($classIds !== null) {
                    $studentClassId = Student::where('id', $studentId)->value('class_id');
                    if (!in_array($studentClassId, $classIds, true)) {
                        return $this->forbiddenResponse('This student is not in one of your assigned classes.');
                    }
                }
            }

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
            // ── Row-level scoping ────────────────────────────────────────────
            $user     = Auth::user();
            $classIds = $this->accessibleClassIds($user);
            if ($classIds !== null && !in_array((int) $classId, $classIds, true)) {
                return $this->forbiddenResponse('You are not assigned to this class.');
            }

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
     * Helper: Calculate class positions using a single UPDATE + RANK() window function.
     * Replaces N individual UPDATE queries (one per student) with one statement.
     */
    private function calculatePositions($classId, $termId, $academicYearId): void
    {
        // Aggregate stats in one query — avg() returns null when no rows match
        $stats = DB::table('student_results')
            ->where('class_id', $classId)
            ->where('term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->selectRaw('COUNT(*) as total, COALESCE(AVG(average_score), 0) as avg_score')
            ->first();

        $totalStudents = (int) ($stats->total ?? 0);
        $classAverage  = round((float) ($stats->avg_score ?? 0), 2);

        if ($totalStudents === 0) {
            $this->calculateSubjectPositions($classId, $termId, $academicYearId);
            return;
        }

        // Single statement: RANK() inside a derived table joined back to the live table.
        // RANK() assigns ties the same rank and skips the next (dense behaviour not needed here).
        DB::statement('
            UPDATE student_results sr
            JOIN (
                SELECT id,
                       RANK() OVER (ORDER BY average_score DESC) AS rk
                FROM student_results
                WHERE class_id        = ?
                  AND term_id         = ?
                  AND academic_year_id = ?
            ) ranked ON sr.id = ranked.id
            SET sr.position      = ranked.rk,
                sr.out_of        = ?,
                sr.class_average = ?
        ', [$classId, $termId, $academicYearId, $totalStudents, $classAverage]);

        $this->calculateSubjectPositions($classId, $termId, $academicYearId);
    }

    /**
     * Helper: Calculate per-subject positions using RANK() OVER (PARTITION BY subject_id).
     * Replaces (subjects × students) individual UPDATE queries with one statement.
     */
    private function calculateSubjectPositions($classId, $termId, $academicYearId): void
    {
        // One UPDATE: partition by subject so each subject gets its own 1-based ranking.
        DB::statement('
            UPDATE subject_results sr
            JOIN student_results   stu ON sr.student_result_id = stu.id
            JOIN (
                SELECT sr2.id,
                       RANK() OVER (
                           PARTITION BY sr2.subject_id
                           ORDER BY sr2.total_score DESC
                       ) AS rk
                FROM subject_results sr2
                JOIN student_results stu2 ON sr2.student_result_id = stu2.id
                WHERE stu2.class_id         = ?
                  AND stu2.term_id          = ?
                  AND stu2.academic_year_id = ?
            ) ranked ON sr.id = ranked.id
            SET sr.position = ranked.rk
            WHERE stu.class_id         = ?
              AND stu.term_id          = ?
              AND stu.academic_year_id = ?
        ', [$classId, $termId, $academicYearId, $classId, $termId, $academicYearId]);
    }

    /**
     * Grade a numeric score using the section's ResultConfiguration (if any) and GradingSystem.
     *
     * @return array{grade: string, teacher_remark: string}
     */
    private function gradeSubjectScore(float $score, ?ResultConfiguration $config, ?GradingSystem $gradingSystem): array
    {
        if ($config && $config->grade_style === 'remarks_only' && ! empty($config->remark_bands)) {
            foreach ($config->remark_bands as $band) {
                $min = (float) ($band['min'] ?? 0);
                $max = (float) ($band['max'] ?? 100);
                if ($score >= $min && $score <= $max) {
                    $text = (string) ($band['remark'] ?? '—');

                    return ['grade' => $text, 'teacher_remark' => $text];
                }
            }

            return ['grade' => '—', 'teacher_remark' => ''];
        }

        if ($config && $config->grade_style === 'percentage') {
            $pct = (string) round($score, 2) . '%';

            return ['grade' => $pct, 'teacher_remark' => ''];
        }

        if (! $gradingSystem) {
            return ['grade' => 'N/A', 'teacher_remark' => ''];
        }

        $g = $gradingSystem->getGrade($score);

        return [
            'grade' => $g['grade'],
            'teacher_remark' => $g['remark'] ?? $this->remarkForLetterGrade($g['grade']),
        ];
    }

    /**
     * Fallback remarks when the grading system does not supply a remark string.
     */
    private function remarkForLetterGrade(string $grade): string
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
