<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\Student;
use App\Models\StudentResult;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PromotionController extends Controller
{
    /**
     * Get promotion records
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Promotion::with(['student.user', 'fromClass', 'toClass', 'academicYear', 'approvedBy']);

            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }
            if ($request->has('academic_year_id')) {
                $query->where('academic_year_id', $request->academic_year_id);
            }
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $promotions = $query->orderBy('promoted_at', 'desc')->get();

            return response()->json(['promotions' => $promotions]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch promotions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Promote single student
     */
    public function promoteStudent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'to_class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'status' => 'required|in:promoted,repeated,graduated',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $student = Student::find($request->student_id);
            $fromClassId = $student->class_id;

            DB::beginTransaction();

            // Create promotion record
            $promotion = Promotion::create([
                'student_id' => $request->student_id,
                'from_class_id' => $fromClassId,
                'to_class_id' => $request->to_class_id,
                'academic_year_id' => $request->academic_year_id,
                'status' => $request->status,
                'reason' => $request->reason,
                'approved_by' => Auth::id(),
                'promoted_at' => now(),
            ]);

            // Update student's class
            if ($request->status === 'promoted' || $request->status === 'graduated') {
                $student->update(['class_id' => $request->to_class_id]);
            }

            DB::commit();

            $promotion->load(['student.user', 'fromClass', 'toClass']);

            return response()->json([
                'message' => 'Student promoted successfully',
                'promotion' => $promotion
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to promote student',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk promote students.
     *
     * N+1 fix: pre-load StudentResults in one query, batch-insert Promotion rows,
     * and batch-update student class_ids with two whereIn UPDATEs instead of
     * N individual create()/update() calls.
     */
    public function bulkPromote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_class_id'   => 'required|exists:classes,id',
            'to_class_id'     => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'student_ids'     => 'nullable|array',
            'student_ids.*'   => 'exists:students,id',
            'promote_all'     => 'boolean',
            'minimum_average' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $studentsQuery = Student::where('class_id', $request->from_class_id);

            if ($request->filled('student_ids')) {
                $studentsQuery->whereIn('id', $request->student_ids);
            } elseif (!$request->promote_all) {
                return response()->json([
                    'error' => 'Either provide student_ids or set promote_all to true'
                ], 400);
            }

            // One query for all student IDs
            $studentIds = $studentsQuery->pluck('id');

            // Pre-load latest StudentResult per student in one query (keyed by student_id)
            $latestResults = [];
            if ($request->filled('minimum_average')) {
                // Latest term per student — use MAX(term_id) as a proxy for "most recent"
                $latestResults = StudentResult::whereIn('student_id', $studentIds)
                    ->where('academic_year_id', $request->academic_year_id)
                    ->select('student_id', DB::raw('MAX(term_id) as term_id'), 'average_score')
                    ->groupBy('student_id', 'average_score')
                    ->pluck('average_score', 'student_id')
                    ->all();
            }

            $now          = now()->toDateTimeString();
            $approvedBy   = Auth::id();
            $minAverage   = $request->input('minimum_average');

            $promotionRows = [];
            $promotedIds   = [];
            $repeatedIds   = [];

            foreach ($studentIds as $studentId) {
                $avg           = $latestResults[$studentId] ?? null;
                $shouldPromote = $minAverage === null || ($avg !== null && $avg >= $minAverage);
                $status        = $shouldPromote ? 'promoted' : 'repeated';
                $toClassId     = $shouldPromote ? $request->to_class_id : $request->from_class_id;

                $promotionRows[] = [
                    'student_id'       => $studentId,
                    'from_class_id'    => $request->from_class_id,
                    'to_class_id'      => $toClassId,
                    'academic_year_id' => $request->academic_year_id,
                    'status'           => $status,
                    'reason'           => $shouldPromote ? 'Automatic promotion' : 'Below minimum average',
                    'approved_by'      => $approvedBy,
                    'promoted_at'      => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];

                if ($shouldPromote) {
                    $promotedIds[] = $studentId;
                } else {
                    $repeatedIds[] = $studentId;
                }
            }

            // One batch insert for all promotion records
            DB::table('promotions')->insert($promotionRows);

            // Two batch UPDATEs instead of N individual updates
            if (!empty($promotedIds)) {
                Student::whereIn('id', $promotedIds)->update(['class_id' => $request->to_class_id]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Bulk promotion completed',
                'summary' => [
                    'total'    => count($promotionRows),
                    'promoted' => count($promotedIds),
                    'repeated' => count($repeatedIds),
                    'errors'   => 0,
                ],
                'errors' => [],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to bulk promote', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Auto-promote based on performance.
     *
     * N+1 fix: results already fetched in one query; batch-insert Promotion rows
     * and batch-update class_ids with two whereIn UPDATEs.
     */
    public function autoPromote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_class_id'    => 'required|exists:classes,id',
            'to_class_id'      => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'required|exists:terms,id',
            'pass_mark'        => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // One query — no eager-load of student needed; we'll batch-update by ID
            $results = StudentResult::where('class_id', $request->from_class_id)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->get(['id', 'student_id', 'average_score']);

            $now        = now()->toDateTimeString();
            $approvedBy = Auth::id();
            $passMark   = (float) $request->pass_mark;

            $promotionRows = [];
            $promotedIds   = [];
            $repeatedIds   = [];

            foreach ($results as $result) {
                $shouldPromote = $result->average_score >= $passMark;
                $status        = $shouldPromote ? 'promoted' : 'repeated';
                $toClassId     = $shouldPromote ? $request->to_class_id : $request->from_class_id;

                $promotionRows[] = [
                    'student_id'       => $result->student_id,
                    'from_class_id'    => $request->from_class_id,
                    'to_class_id'      => $toClassId,
                    'academic_year_id' => $request->academic_year_id,
                    'status'           => $status,
                    'reason'           => $shouldPromote
                        ? "Average {$result->average_score}% >= Pass mark {$passMark}%"
                        : "Average {$result->average_score}% < Pass mark {$passMark}%",
                    'approved_by'      => $approvedBy,
                    'promoted_at'      => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];

                if ($shouldPromote) {
                    $promotedIds[] = $result->student_id;
                } else {
                    $repeatedIds[] = $result->student_id;
                }
            }

            // One batch insert instead of N Promotion::create() calls
            DB::table('promotions')->insert($promotionRows);

            // One batch UPDATE instead of N $student->update() calls
            if (!empty($promotedIds)) {
                Student::whereIn('id', $promotedIds)->update(['class_id' => $request->to_class_id]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Auto-promotion completed',
                'summary' => [
                    'total'     => count($promotionRows),
                    'promoted'  => count($promotedIds),
                    'repeated'  => count($repeatedIds),
                    'pass_mark' => $passMark,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to auto-promote', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Graduate students.
     *
     * N+1 fix: batch-insert all Promotion rows and batch-update student statuses
     * with one whereIn UPDATE instead of N individual create()/update() calls.
     */
    public function graduateStudents(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id'         => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'student_ids'      => 'nullable|array',
            'student_ids.*'    => 'exists:students,id',
            'graduate_all'     => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $studentsQuery = Student::where('class_id', $request->class_id);

            if ($request->filled('student_ids')) {
                $studentsQuery->whereIn('id', $request->student_ids);
            } elseif (!$request->graduate_all) {
                return response()->json([
                    'error' => 'Either provide student_ids or set graduate_all to true'
                ], 400);
            }

            // One query for IDs only — no need to hydrate full models for batch ops
            $studentIds = $studentsQuery->pluck('id');

            if ($studentIds->isEmpty()) {
                DB::commit();
                return response()->json(['message' => 'No students found', 'summary' => ['total' => 0, 'graduated' => 0]]);
            }

            $now        = now()->toDateTimeString();
            $approvedBy = Auth::id();

            // Build all rows in memory
            $promotionRows = $studentIds->map(fn ($id) => [
                'student_id'       => $id,
                'from_class_id'    => $request->class_id,
                'to_class_id'      => $request->class_id,
                'academic_year_id' => $request->academic_year_id,
                'status'           => 'graduated',
                'reason'           => 'Graduated from school',
                'approved_by'      => $approvedBy,
                'promoted_at'      => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ])->all();

            // One batch insert instead of N Promotion::create() calls
            DB::table('promotions')->insert($promotionRows);

            // One batch UPDATE instead of N $student->update() calls
            Student::whereIn('id', $studentIds)->update(['status' => 'graduated']);

            DB::commit();

            return response()->json([
                'message' => 'Students graduated successfully',
                'summary' => [
                    'total'     => $studentIds->count(),
                    'graduated' => $studentIds->count(),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to graduate students', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get promotion statistics
     */
    public function getStatistics(Request $request): JsonResponse
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
            $stats = [
                'promoted' => Promotion::where('academic_year_id', $request->academic_year_id)
                    ->where('status', 'promoted')->count(),
                'repeated' => Promotion::where('academic_year_id', $request->academic_year_id)
                    ->where('status', 'repeated')->count(),
                'graduated' => Promotion::where('academic_year_id', $request->academic_year_id)
                    ->where('status', 'graduated')->count(),
            ];

            $stats['total'] = $stats['promoted'] + $stats['repeated'] + $stats['graduated'];
            $stats['promotion_rate'] = $stats['total'] > 0 
                ? round(($stats['promoted'] / $stats['total']) * 100, 2)
                : 0;

            return response()->json([
                'statistics' => $stats,
                'academic_year_id' => $request->academic_year_id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete promotion record (undo)
     */
    public function destroy($id): JsonResponse
    {
        try {
            $promotion = Promotion::find($id);

            if (!$promotion) {
                return response()->json(['error' => 'Promotion record not found'], 404);
            }

            DB::beginTransaction();

            // Revert student to original class
            $student = Student::find($promotion->student_id);
            if ($student && $promotion->status === 'promoted') {
                $student->update(['class_id' => $promotion->from_class_id]);
            }

            $promotion->delete();

            DB::commit();

            return response()->json(['message' => 'Promotion record deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to delete promotion record',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

