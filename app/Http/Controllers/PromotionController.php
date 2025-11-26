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
     * Bulk promote students
     */
    public function bulkPromote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_class_id' => 'required|exists:classes,id',
            'to_class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
            'promote_all' => 'boolean', // Promote all students in class
            'minimum_average' => 'nullable|numeric|min:0|max:100', // Only promote if average >= this
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get students to promote
            $studentsQuery = Student::where('class_id', $request->from_class_id);
            
            if ($request->has('student_ids') && !empty($request->student_ids)) {
                $studentsQuery->whereIn('id', $request->student_ids);
            } elseif (!$request->promote_all) {
                return response()->json([
                    'error' => 'Either provide student_ids or set promote_all to true'
                ], 400);
            }

            $students = $studentsQuery->get();

            $promoted = 0;
            $repeated = 0;
            $errors = [];

            foreach ($students as $student) {
                try {
                    // Check if minimum average required
                    $shouldPromote = true;
                    if ($request->has('minimum_average')) {
                        $result = StudentResult::where('student_id', $student->id)
                            ->where('academic_year_id', $request->academic_year_id)
                            ->orderBy('term_id', 'desc')
                            ->first();

                        if (!$result || $result->average_score < $request->minimum_average) {
                            $shouldPromote = false;
                        }
                    }

                    $status = $shouldPromote ? 'promoted' : 'repeated';
                    $toClassId = $shouldPromote ? $request->to_class_id : $request->from_class_id;

                    // Create promotion record
                    Promotion::create([
                        'student_id' => $student->id,
                        'from_class_id' => $request->from_class_id,
                        'to_class_id' => $toClassId,
                        'academic_year_id' => $request->academic_year_id,
                        'status' => $status,
                        'reason' => $shouldPromote ? 'Automatic promotion' : 'Below minimum average',
                        'approved_by' => Auth::id(),
                        'promoted_at' => now(),
                    ]);

                    // Update student class if promoted
                    if ($shouldPromote) {
                        $student->update(['class_id' => $request->to_class_id]);
                        $promoted++;
                    } else {
                        $repeated++;
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'student_id' => $student->id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Bulk promotion completed',
                'summary' => [
                    'total' => $students->count(),
                    'promoted' => $promoted,
                    'repeated' => $repeated,
                    'errors' => count($errors)
                ],
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to bulk promote',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Auto-promote based on performance
     */
    public function autoPromote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_class_id' => 'required|exists:classes,id',
            'to_class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id' => 'required|exists:terms,id',
            'pass_mark' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get all student results for the term
            $results = StudentResult::where('class_id', $request->from_class_id)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->with('student')
                ->get();

            $promoted = 0;
            $repeated = 0;

            foreach ($results as $result) {
                $shouldPromote = $result->average_score >= $request->pass_mark;
                $status = $shouldPromote ? 'promoted' : 'repeated';
                $toClassId = $shouldPromote ? $request->to_class_id : $request->from_class_id;

                Promotion::create([
                    'student_id' => $result->student_id,
                    'from_class_id' => $request->from_class_id,
                    'to_class_id' => $toClassId,
                    'academic_year_id' => $request->academic_year_id,
                    'status' => $status,
                    'reason' => $shouldPromote 
                        ? "Average {$result->average_score}% >= Pass mark {$request->pass_mark}%"
                        : "Average {$result->average_score}% < Pass mark {$request->pass_mark}%",
                    'approved_by' => Auth::id(),
                    'promoted_at' => now(),
                ]);

                // Update student class if promoted
                if ($shouldPromote) {
                    $result->student->update(['class_id' => $request->to_class_id]);
                    $promoted++;
                } else {
                    $repeated++;
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Auto-promotion completed',
                'summary' => [
                    'total' => $results->count(),
                    'promoted' => $promoted,
                    'repeated' => $repeated,
                    'pass_mark' => $request->pass_mark
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to auto-promote',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Graduate students
     */
    public function graduateStudents(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
            'graduate_all' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $studentsQuery = Student::where('class_id', $request->class_id);
            
            if ($request->has('student_ids') && !empty($request->student_ids)) {
                $studentsQuery->whereIn('id', $request->student_ids);
            } elseif (!$request->graduate_all) {
                return response()->json([
                    'error' => 'Either provide student_ids or set graduate_all to true'
                ], 400);
            }

            $students = $studentsQuery->get();
            $graduated = 0;

            foreach ($students as $student) {
                Promotion::create([
                    'student_id' => $student->id,
                    'from_class_id' => $request->class_id,
                    'to_class_id' => $request->class_id, // Same class for graduation
                    'academic_year_id' => $request->academic_year_id,
                    'status' => 'graduated',
                    'reason' => 'Graduated from school',
                    'approved_by' => Auth::id(),
                    'promoted_at' => now(),
                ]);

                // Update student status
                $student->update(['status' => 'graduated']);
                $graduated++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Students graduated successfully',
                'summary' => [
                    'total' => $students->count(),
                    'graduated' => $graduated
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to graduate students',
                'message' => $e->getMessage()
            ], 500);
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

