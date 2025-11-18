<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class GradeController extends Controller
{
    /**
     * List grades
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('grades');

            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }

            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->has('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }

            if ($request->has('term_id')) {
                $query->where('term_id', $request->term_id);
            }

            $grades = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json($grades);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 15,
                'total' => 0
            ]);
        }
    }

    /**
     * Get grade details
     */
    public function show($id): JsonResponse
    {
        $grade = DB::table('grades')->find($id);

        if (!$grade) {
            return response()->json(['error' => 'Grade not found'], 404);
        }

        return response()->json(['grade' => $grade]);
    }

    /**
     * Create/record grade
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'score' => 'required|numeric|min:0',
            'total_marks' => 'required|numeric|min:0',
            'assessment_type' => 'nullable|string',
            'assessment_id' => 'nullable|integer',
            'class_id' => 'nullable|exists:classes,id',
            'term_id' => 'nullable|exists:terms,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $score = $request->score;
        $totalMarks = $request->total_marks;
        $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;

        // Calculate grade letter
        $grade = $this->calculateGrade($percentage);

        $gradeId = DB::table('grades')->insertGetId([
            'school_id' => $request->school_id ?? 1, // Get from context
            'student_id' => $request->student_id,
            'subject_id' => $request->subject_id,
            'class_id' => $request->class_id,
            'term_id' => $request->term_id,
            'academic_year_id' => $request->academic_year_id,
            'assessment_type' => $request->assessment_type,
            'assessment_id' => $request->assessment_id,
            'score' => $score,
            'total_marks' => $totalMarks,
            'grade' => $grade,
            'percentage' => $percentage,
            'remarks' => $request->remarks,
            'graded_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gradeRecord = DB::table('grades')->find($gradeId);

        return response()->json([
            'message' => 'Grade recorded successfully',
            'grade' => $gradeRecord
        ], 201);
    }

    /**
     * Update grade
     */
    public function update(Request $request, $id): JsonResponse
    {
        $grade = DB::table('grades')->find($id);

        if (!$grade) {
            return response()->json(['error' => 'Grade not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'score' => 'sometimes|numeric|min:0',
            'total_marks' => 'sometimes|numeric|min:0',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $score = $request->score ?? $grade->score;
        $totalMarks = $request->total_marks ?? $grade->total_marks;
        $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;
        $gradeLetter = $this->calculateGrade($percentage);

        DB::table('grades')
            ->where('id', $id)
            ->update([
                'score' => $score,
                'total_marks' => $totalMarks,
                'grade' => $gradeLetter,
                'percentage' => $percentage,
                'remarks' => $request->remarks ?? $grade->remarks,
                'updated_at' => now(),
            ]);

        $grade = DB::table('grades')->find($id);

        return response()->json([
            'message' => 'Grade updated successfully',
            'grade' => $grade
        ]);
    }

    /**
     * Delete grade
     */
    public function destroy($id): JsonResponse
    {
        $grade = DB::table('grades')->find($id);

        if (!$grade) {
            return response()->json(['error' => 'Grade not found'], 404);
        }

        DB::table('grades')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Grade deleted successfully'
        ]);
    }

    /**
     * Get student grades
     */
    public function getStudentGrades($studentId): JsonResponse
    {
        $grades = DB::table('grades')
            ->where('student_id', $studentId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'student_id' => $studentId,
            'grades' => $grades
        ]);
    }

    /**
     * Get class grades
     */
    public function getClassGrades($classId): JsonResponse
    {
        $grades = DB::table('grades')
            ->where('class_id', $classId)
            ->orderBy('student_id')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'class_id' => $classId,
            'grades' => $grades
        ]);
    }

    /**
     * Calculate grade letter from percentage
     */
    protected function calculateGrade(float $percentage): string
    {
        if ($percentage >= 90) return 'A+';
        if ($percentage >= 80) return 'A';
        if ($percentage >= 70) return 'B+';
        if ($percentage >= 60) return 'B';
        if ($percentage >= 50) return 'C+';
        if ($percentage >= 40) return 'C';
        if ($percentage >= 30) return 'D';
        return 'F';
    }
}
