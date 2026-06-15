<?php

namespace App\Http\Controllers;

use App\Models\PsychomotorAssessment;
use App\Models\ResultConfiguration;
use App\Models\School;
use App\Models\Student;
use App\Support\PsychomotorConfig;
use App\Support\ResultReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PsychomotorAssessmentController extends Controller
{
    /**
     * Skill/trait definitions for a class (respects section result configuration).
     */
    public function getConfigForClass(Request $request, int $classId): JsonResponse
    {
        try {
            if ($deny = $this->denyIfClassInaccessible($classId)) {
                return $deny;
            }

            $school = School::first();
            $config = ResultReportBuilder::resolveConfigForClass($classId, (int) ($school?->id ?? 0));

            return response()->json([
                'class_id' => $classId,
                'configuration' => $config ? [
                    'show_psychomotor' => $config->show_psychomotor,
                    'show_affective' => $config->show_affective,
                    'psychomotor_ratings_required' => $config->psychomotorRatingsRequired(),
                ] : [
                    'show_psychomotor' => true,
                    'show_affective' => true,
                    'psychomotor_ratings_required' => true,
                ],
                'psychomotor_skills' => PsychomotorConfig::psychomotorSkills($config),
                'affective_traits' => PsychomotorConfig::affectiveTraits($config),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch psychomotor configuration',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get psychomotor assessment for student
     */
    public function show($studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            if ($deny = $this->denyIfStudentInaccessible((int) $studentId)) {
                return $deny;
            }

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
                    'academic_year_id' => $academicYearId,
                ], 404);
            }

            $student = $assessment->student;
            $config  = ResultReportBuilder::resolveConfigForClass(
                (int) ($student?->class_id ?? 0),
                (int) ($student?->school_id ?? 0)
            );

            return response()->json([
                'assessment' => $assessment,
                'formatted' => PsychomotorConfig::formatForReport($assessment, $config),
                'psychomotor_average' => $assessment->getPsychomotorAverage(),
                'affective_average' => $assessment->getAffectiveAverage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch assessment',
                'message' => $e->getMessage(),
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
            'teacher_comment' => 'nullable|string',
            'custom_psychomotor' => 'nullable|array',
            'custom_affective' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            if ($deny = $this->denyIfStudentInaccessible((int) $request->student_id)) {
                return $deny;
            }

            $student = Student::findOrFail($request->student_id);
            $config  = ResultReportBuilder::resolveConfigForClass(
                (int) $student->class_id,
                (int) $student->school_id
            );

            if ($config && ! $config->show_psychomotor && ! $config->show_affective) {
                return response()->json([
                    'error' => 'Psychomotor assessments are disabled for this section.',
                ], 422);
            }

            $parsed = PsychomotorConfig::parseInput($request->all(), $config);

            if ($config?->psychomotorRatingsRequired()) {
                $hasRating = false;
                foreach (array_merge(
                    PsychomotorConfig::psychomotorSkills($config),
                    PsychomotorConfig::affectiveTraits($config)
                ) as $field) {
                    $key = $field['key'];
                    if (isset($parsed[$key]) || isset(($parsed['custom_psychomotor'] ?? [])[$key]) || isset(($parsed['custom_affective'] ?? [])[$key])) {
                        $hasRating = true;
                        break;
                    }
                }
                if (! $hasRating && empty($parsed['teacher_comment'])) {
                    return response()->json([
                        'error' => 'Provide at least one rating or a teacher comment.',
                    ], 422);
                }
            }

            $user    = Auth::user();
            $teacher = DB::table('teachers')->where('user_id', $user->id)->first();

            $data = array_merge($parsed, [
                'student_id' => $request->student_id,
                'term_id' => $request->term_id,
                'academic_year_id' => $request->academic_year_id,
                'assessed_by' => $teacher->id ?? null,
            ]);

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
                'formatted' => PsychomotorConfig::formatForReport($assessment, $config),
                'psychomotor_average' => $assessment->getPsychomotorAverage(),
                'affective_average' => $assessment->getAffectiveAverage(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to save assessment',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get assessments for a class
     */
    public function getByClass(Request $request, $classId): JsonResponse
    {
        try {
            if ($deny = $this->denyIfClassInaccessible((int) $classId)) {
                return $deny;
            }

            $validator = Validator::make($request->all(), [
                'term_id' => 'required|exists:terms,id',
                'academic_year_id' => 'required|exists:academic_years,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'messages' => $validator->errors(),
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
                'statistics' => $statistics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch assessments',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk create assessments for class
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'nullable|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'assessments' => 'required|array',
            'assessments.*.student_id' => 'required|exists:students,id',
            'assessments.*.teacher_comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            if ($request->filled('class_id') && ($deny = $this->denyIfClassInaccessible((int) $request->class_id))) {
                return $deny;
            }

            $user    = Auth::user();
            $teacher = DB::table('teachers')->where('user_id', $user->id)->first();

            DB::beginTransaction();

            $created = 0;
            $updated = 0;

            foreach ($request->assessments as $assessmentData) {
                if ($deny = $this->denyIfStudentInaccessible((int) $assessmentData['student_id'])) {
                    DB::rollBack();

                    return $deny;
                }

                $student = Student::find($assessmentData['student_id']);
                $config  = ResultReportBuilder::resolveConfigForClass(
                    (int) ($student?->class_id ?? 0),
                    (int) ($student?->school_id ?? 0)
                );

                $parsed = PsychomotorConfig::parseInput($assessmentData, $config);
                $data   = array_merge($parsed, [
                    'student_id' => $assessmentData['student_id'],
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
                    'updated' => $updated,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to save assessments',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete assessment
     */
    public function destroy($id): JsonResponse
    {
        try {
            $assessment = PsychomotorAssessment::with('student')->find($id);

            if (!$assessment) {
                return response()->json(['error' => 'Assessment not found'], 404);
            }

            if ($deny = $this->denyIfStudentInaccessible((int) $assessment->student_id)) {
                return $deny;
            }

            $assessment->delete();

            return response()->json(['message' => 'Assessment deleted successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete assessment',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function denyIfClassInaccessible(int $classId): ?JsonResponse
    {
        $user     = Auth::user();
        $classIds = $this->accessibleClassIds($user);

        if ($classIds !== null && ! in_array($classId, $classIds, true)) {
            return $this->forbiddenResponse('You are not assigned to this class.');
        }

        return null;
    }

    private function denyIfStudentInaccessible(int $studentId): ?JsonResponse
    {
        $user  = Auth::user();
        $ownId = $this->ownStudentId($user);

        if ($ownId !== null && (int) $ownId !== $studentId) {
            return $this->forbiddenResponse('You may only access your own assessment.');
        }

        if ($ownId === null) {
            $classIds = $this->accessibleClassIds($user);
            if ($classIds !== null) {
                $studentClassId = Student::where('id', $studentId)->value('class_id');
                if (! in_array((int) $studentClassId, $classIds, true)) {
                    return $this->forbiddenResponse('This student is not in one of your assigned classes.');
                }
            }
        }

        return null;
    }
}
