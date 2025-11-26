<?php

namespace App\Http\Controllers;

use App\Models\PsychomotorAssessment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PsychomotorAssessmentController extends Controller
{
    /**
     * Get psychomotor assessment for student
     */
    public function show($studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            $assessment = PsychomotorAssessment::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->with(['student.user', 'term', 'academicYear', 'assessedBy'])
                ->first();

            if (!$assessment) {
                return response()->json([
                    'message' => 'No assessment found',
                    'student_id' => $studentId,
                    'term_id' => $termId,
                    'academic_year_id' => $academicYearId
                ], 404);
            }

            return response()->json([
                'assessment' => $assessment,
                'psychomotor_average' => $assessment->getPsychomotorAverage(),
                'affective_average' => $assessment->getAffectiveAverage()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch assessment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update psychomotor assessment
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            // Psychomotor (1-5)
            'handwriting' => 'nullable|integer|min:1|max:5',
            'drawing' => 'nullable|integer|min:1|max:5',
            'sports' => 'nullable|integer|min:1|max:5',
            'musical_skills' => 'nullable|integer|min:1|max:5',
            'handling_tools' => 'nullable|integer|min:1|max:5',
            // Affective (1-5)
            'punctuality' => 'nullable|integer|min:1|max:5',
            'neatness' => 'nullable|integer|min:1|max:5',
            'politeness' => 'nullable|integer|min:1|max:5',
            'honesty' => 'nullable|integer|min:1|max:5',
            'relationship_with_others' => 'nullable|integer|min:1|max:5',
            'self_control' => 'nullable|integer|min:1|max:5',
            'attentiveness' => 'nullable|integer|min:1|max:5',
            'perseverance' => 'nullable|integer|min:1|max:5',
            'emotional_stability' => 'nullable|integer|min:1|max:5',
            'teacher_comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            $teacher = DB::table('teachers')->where('user_id', $user->id)->first();

            $data = $request->only([
                'student_id', 'term_id', 'academic_year_id',
                'handwriting', 'drawing', 'sports', 'musical_skills', 'handling_tools',
                'punctuality', 'neatness', 'politeness', 'honesty', 'relationship_with_others',
                'self_control', 'attentiveness', 'perseverance', 'emotional_stability',
                'teacher_comment'
            ]);
            $data['assessed_by'] = $teacher->id ?? null;

            $assessment = PsychomotorAssessment::updateOrCreate(
                [
                    'student_id' => $request->student_id,
                    'term_id' => $request->term_id,
                    'academic_year_id' => $request->academic_year_id,
                ],
                $data
            );

            $assessment->load(['student.user', 'term', 'academicYear', 'assessedBy']);

            return response()->json([
                'message' => 'Assessment saved successfully',
                'assessment' => $assessment,
                'psychomotor_average' => $assessment->getPsychomotorAverage(),
                'affective_average' => $assessment->getAffectiveAverage()
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to save assessment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assessments for a class
     */
    public function getByClass(Request $request, $classId): JsonResponse
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

            $students = Student::where('class_id', $classId)->pluck('id');

            $assessments = PsychomotorAssessment::whereIn('student_id', $students)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->with(['student.user', 'assessedBy'])
                ->get();

            $statistics = [
                'total_students' => $students->count(),
                'assessed' => $assessments->count(),
                'pending' => $students->count() - $assessments->count(),
            ];

            return response()->json([
                'assessments' => $assessments,
                'statistics' => $statistics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch assessments',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create assessments for class
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'assessments' => 'required|array',
            'assessments.*.student_id' => 'required|exists:students,id',
            'assessments.*.handwriting' => 'nullable|integer|min:1|max:5',
            'assessments.*.drawing' => 'nullable|integer|min:1|max:5',
            'assessments.*.sports' => 'nullable|integer|min:1|max:5',
            'assessments.*.musical_skills' => 'nullable|integer|min:1|max:5',
            'assessments.*.handling_tools' => 'nullable|integer|min:1|max:5',
            'assessments.*.punctuality' => 'nullable|integer|min:1|max:5',
            'assessments.*.neatness' => 'nullable|integer|min:1|max:5',
            'assessments.*.politeness' => 'nullable|integer|min:1|max:5',
            'assessments.*.honesty' => 'nullable|integer|min:1|max:5',
            'assessments.*.relationship_with_others' => 'nullable|integer|min:1|max:5',
            'assessments.*.self_control' => 'nullable|integer|min:1|max:5',
            'assessments.*.attentiveness' => 'nullable|integer|min:1|max:5',
            'assessments.*.perseverance' => 'nullable|integer|min:1|max:5',
            'assessments.*.emotional_stability' => 'nullable|integer|min:1|max:5',
            'assessments.*.teacher_comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            $teacher = DB::table('teachers')->where('user_id', $user->id)->first();

            DB::beginTransaction();

            $created = 0;
            $updated = 0;

            foreach ($request->assessments as $assessmentData) {
                $data = array_merge($assessmentData, [
                    'term_id' => $request->term_id,
                    'academic_year_id' => $request->academic_year_id,
                    'assessed_by' => $teacher->id ?? null,
                ]);

                $existing = PsychomotorAssessment::where('student_id', $assessmentData['student_id'])
                    ->where('term_id', $request->term_id)
                    ->where('academic_year_id', $request->academic_year_id)
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    PsychomotorAssessment::create($data);
                    $created++;
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Assessments saved successfully',
                'summary' => [
                    'total' => count($request->assessments),
                    'created' => $created,
                    'updated' => $updated
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to save assessments',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete assessment
     */
    public function destroy($id): JsonResponse
    {
        try {
            $assessment = PsychomotorAssessment::find($id);

            if (!$assessment) {
                return response()->json(['error' => 'Assessment not found'], 404);
            }

            $assessment->delete();

            return response()->json(['message' => 'Assessment deleted successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete assessment',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

