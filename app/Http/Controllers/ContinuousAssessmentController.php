<?php

namespace App\Http\Controllers;

use App\Models\CAQuestionAnswer;
use App\Models\ContinuousAssessment;
use App\Models\CAScore;
use App\Models\Question;
use App\Models\ResultConfiguration;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ContinuousAssessmentController extends Controller
{
    /**
     * List continuous assessments
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // In tenant context, get the first (and only) school
            $school = School::first();

            if (!$school) {
                return response()->json(['error' => 'School not found'], 404);
            }

            $query = ContinuousAssessment::where('school_id', $school->id)
                ->with(['subject', 'class', 'term', 'academicYear', 'teacher']);

            if ($request->has('subject_id')) {
                $query->where('subject_id', $request->subject_id);
            }
            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }
            if ($request->has('term_id')) {
                $query->where('term_id', $request->term_id);
            }
            if ($request->has('teacher_id')) {
                $query->where('teacher_id', $request->teacher_id);
            }
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            $assessments = $query->withCount('scores')->get();

            return response()->json(['assessments' => $assessments]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch assessments',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create continuous assessment
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string|max:255',
            'type' => 'required|in:test,classwork,homework,project,quiz',
            'total_marks' => 'required|numeric|min:1',
            'assessment_date' => 'nullable|date',
            'description' => 'nullable|string',
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

            // ── ResultConfiguration bounds check ─────────────────────────────
            // If the class has a section_type and an active config exists, ensure
            // total_marks does not exceed the component max for this CA type.
            $configWarning = null;
            $classRow = DB::table('classes')->where('id', $request->class_id)->first();
            if ($classRow && ! empty($classRow->section_type)) {
                $config = ResultConfiguration::resolveFor($school->id, $classRow->section_type, (int) $classRow->id);

                if ($config && ! empty($config->assessment_components)) {
                    $component = $this->matchComponent($config->assessment_components, $request->type);
                    if ($component !== null && isset($component['max'])) {
                        $componentMax = (float) $component['max'];
                        if ((float) $request->total_marks > $componentMax) {
                            return response()->json([
                                'error'          => 'Validation failed',
                                'messages'       => [
                                    'total_marks' => [
                                        "The total marks ({$request->total_marks}) exceeds the configured component maximum ({$componentMax}) for the '{$component['name']}' component in this section's result configuration.",
                                    ],
                                ],
                                'component'      => $component,
                                'section_type'   => $classRow->section_type,
                            ], 422);
                        }
                        $configWarning = null; // within bounds — no warning
                    }
                }
            }
            // ─────────────────────────────────────────────────────────────────

            $user = Auth::user();
            $teacher = DB::table('teachers')->where('user_id', $user->id)->first();

            $assessment = ContinuousAssessment::create([
                'school_id' => $school->id,
                'subject_id' => $request->subject_id,
                'class_id' => $request->class_id,
                'term_id' => $request->term_id,
                'academic_year_id' => $request->academic_year_id,
                'teacher_id' => $teacher->id ?? null,
                'name' => $request->name,
                'type' => $request->type,
                'total_marks' => $request->total_marks,
                'assessment_date' => $request->assessment_date,
                'description' => $request->description,
                'status' => 'draft',
            ]);

            $assessment->load(['subject', 'class', 'term', 'teacher']);

            $response = [
                'message'    => 'CA created successfully',
                'assessment' => $assessment,
            ];

            if ($configWarning) {
                $response['warning'] = $configWarning;
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create CA',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record CA scores
     */
    public function recordScores(Request $request, $id): JsonResponse
    {
        $assessment = ContinuousAssessment::find($id);

        if (!$assessment) {
            return response()->json(['error' => 'Assessment not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'scores' => 'required|array',
            'scores.*.student_id' => 'required|exists:students,id',
            'scores.*.score' => 'required|numeric|min:0|max:' . $assessment->total_marks,
            'scores.*.remarks' => 'nullable|string',
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

            foreach ($request->scores as $scoreData) {
                $existing = CAScore::where('continuous_assessment_id', $id)
                    ->where('student_id', $scoreData['student_id'])
                    ->first();

                if ($existing) {
                    $existing->update([
                        'score' => $scoreData['score'],
                        'remarks' => $scoreData['remarks'] ?? null,
                        'recorded_by' => $teacher->id ?? null,
                    ]);
                    $updated++;
                } else {
                    CAScore::create([
                        'continuous_assessment_id' => $id,
                        'student_id' => $scoreData['student_id'],
                        'score' => $scoreData['score'],
                        'remarks' => $scoreData['remarks'] ?? null,
                        'recorded_by' => $teacher->id ?? null,
                    ]);
                    $created++;
                }
            }

            DB::commit();

            // ── Running CA total per student ─────────────────────────────────
            // After saving, compute each student's cumulative CA for this
            // class+subject+term and compare against the configured ca_weight
            // so the teacher knows if they are about to exceed the limit.
            $studentWarnings = [];

            $school = School::first();
            $classRow = DB::table('classes')->where('id', $assessment->class_id)->first();
            if ($school && $classRow && ! empty($classRow->section_type)) {
                $config = ResultConfiguration::resolveFor($school->id, $classRow->section_type, (int) $classRow->id);

                if ($config) {
                    $caWeight = $config->ca_weight;

                    // Batch-sum all CA scores for all students in one query
                    $studentIds = collect($request->scores)->pluck('student_id')->unique()->values();
                    $caIds = DB::table('continuous_assessments')
                        ->where('class_id',         $assessment->class_id)
                        ->where('subject_id',        $assessment->subject_id)
                        ->where('term_id',           $assessment->term_id)
                        ->where('academic_year_id',  $assessment->academic_year_id)
                        ->pluck('id');

                    $totalsByStudent = CAScore::whereIn('continuous_assessment_id', $caIds)
                        ->whereIn('student_id', $studentIds)
                        ->groupBy('student_id')
                        ->selectRaw('student_id, SUM(score) as total')
                        ->pluck('total', 'student_id');

                    foreach ($request->scores as $item) {
                        $totalCA = (float) ($totalsByStudent[$item['student_id']] ?? 0);
                        if ($totalCA > $caWeight) {
                            $studentWarnings[] = [
                                'student_id' => $item['student_id'],
                                'ca_total'   => $totalCA,
                                'ca_weight'  => $caWeight,
                                'message'    => "Student #{$item['student_id']}: CA total {$totalCA} exceeds configured CA weight {$caWeight}.",
                            ];
                        }
                    }
                }
            }
            // ─────────────────────────────────────────────────────────────────

            $response = [
                'message' => count($request->scores) . ' score(s) recorded.',
                'summary' => [
                    'total'   => count($request->scores),
                    'created' => $created,
                    'updated' => $updated,
                ],
            ];

            if (! empty($studentWarnings)) {
                $response['warnings'] = $studentWarnings;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to record scores',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get CA scores for assessment
     */
    public function getScores($id): JsonResponse
    {
        try {
            $assessment = ContinuousAssessment::with(['subject', 'class', 'term'])
                ->find($id);

            if (!$assessment) {
                return response()->json(['error' => 'Assessment not found'], 404);
            }

            $scores = $assessment->scores()
                ->with(['student.user', 'recordedBy'])
                ->get();

            $statistics = [
                'total_students' => $scores->count(),
                'highest_score' => $scores->max('score') ?? 0,
                'lowest_score' => $scores->min('score') ?? 0,
                'average_score' => $scores->avg('score') ?? 0,
            ];

            return response()->json([
                'assessment' => $assessment,
                'scores' => $scores,
                'statistics' => $statistics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch scores',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get student's CA scores
     */
    public function getStudentScores(Request $request, $studentId): JsonResponse
    {
        try {
            $query = CAScore::where('student_id', $studentId)
                ->with(['continuousAssessment.subject', 'continuousAssessment.term']);

            if ($request->has('subject_id')) {
                $query->whereHas('continuousAssessment', function($q) use ($request) {
                    $q->where('subject_id', $request->subject_id);
                });
            }
            if ($request->has('term_id')) {
                $query->whereHas('continuousAssessment', function($q) use ($request) {
                    $q->where('term_id', $request->term_id);
                });
            }

            $scores = $query->get();

            return response()->json([
                'student_id' => $studentId,
                'ca_scores' => $scores,
                'total_scores' => $scores->sum('score'),
                'average' => $scores->avg('score') ?? 0
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch student CA scores',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update assessment
     */
    public function update(Request $request, $id): JsonResponse
    {
        $assessment = ContinuousAssessment::find($id);

        if (!$assessment) {
            return response()->json(['error' => 'Assessment not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'total_marks' => 'sometimes|numeric|min:1',
            'assessment_date' => 'nullable|date',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:draft,published,completed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $assessment->update($request->only([
                'name', 'total_marks', 'assessment_date', 'description', 'status'
            ]));

            return response()->json([
                'message' => 'Assessment updated successfully',
                'assessment' => $assessment->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update assessment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete assessment
     */
    public function destroy($id): JsonResponse
    {
        $assessment = ContinuousAssessment::find($id);

        if (!$assessment) {
            return response()->json(['error' => 'Assessment not found'], 404);
        }

        try {
            $assessment->delete();
            return response()->json(['message' => 'Assessment deleted successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete assessment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Find the best-matching assessment component for the given CA type.
     *
     * Matching is done by lowercasing both the component name and the type keyword.
     * Returns null when no component matches (i.e. no bounds check is applied).
     */
    private function matchComponent(array $components, string $type): ?array
    {
        $typeKeywords = match (strtolower($type)) {
            'test'      => ['test', 'ca', 'assessment'],
            'classwork' => ['classwork', 'class work'],
            'homework'  => ['homework', 'home work', 'assignment'],
            'project'   => ['project', 'practical'],
            'quiz'      => ['quiz'],
            default     => [strtolower($type)],
        };

        foreach ($components as $component) {
            $nameLower = strtolower($component['name'] ?? '');
            foreach ($typeKeywords as $kw) {
                if (str_contains($nameLower, $kw)) {
                    return $component;
                }
            }
        }

        return null;
    }

    // ── Question-based CA methods ──────────────────────────────────────────────

    public function listQuestions(int $id): JsonResponse
    {
        $ca = ContinuousAssessment::findOrFail($id);
        return response()->json([
            'ca'        => $ca->only(['id', 'name', 'total_marks', 'status']),
            'questions' => $ca->questions()->get(),
        ]);
    }

    public function addQuestion(Request $request, int $id): JsonResponse
    {
        $ca = ContinuousAssessment::findOrFail($id);

        $v = Validator::make($request->all(), [
            'question_text'   => 'required|string',
            'question_type'   => 'required|in:multiple_choice,true_false,essay,fill_blank',
            'marks'           => 'required|numeric|min:0',
            'options'         => 'nullable|array',
            'options.*.text'  => 'required_with:options|string',
            'correct_answer'  => 'nullable|array',
            'explanation'     => 'nullable|string',
            'difficulty_level'=> 'nullable|in:easy,medium,hard',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $options = $request->options;
        if ($request->question_type === 'true_false' && empty($options)) {
            $options = [['text' => 'True'], ['text' => 'False']];
        }

        $question = Question::create([
            'ca_id'           => $ca->id,
            'exam_id'         => null,
            'subject_id'      => $ca->subject_id,
            'question_text'   => $request->question_text,
            'question_type'   => $request->question_type,
            'difficulty_level'=> $request->input('difficulty_level', 'medium'),
            'marks'           => $request->marks,
            'options'         => $options,
            'correct_answer'  => $request->input('correct_answer', []),
            'explanation'     => $request->explanation,
            'status'          => 'active',
        ]);

        return response()->json(['message' => 'Question added', 'question' => $question], 201);
    }

    public function updateQuestion(Request $request, int $id, int $questionId): JsonResponse
    {
        $question = Question::where('ca_id', $id)->findOrFail($questionId);

        $v = Validator::make($request->all(), [
            'question_text'   => 'sometimes|string',
            'question_type'   => 'sometimes|in:multiple_choice,true_false,essay,fill_blank',
            'marks'           => 'sometimes|numeric|min:0',
            'options'         => 'nullable|array',
            'correct_answer'  => 'nullable|array',
            'explanation'     => 'nullable|string',
            'difficulty_level'=> 'nullable|in:easy,medium,hard',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $question->update($request->only([
            'question_text', 'question_type', 'marks', 'options', 'correct_answer', 'explanation', 'difficulty_level',
        ]));

        return response()->json(['message' => 'Question updated', 'question' => $question->fresh()]);
    }

    public function removeQuestion(int $id, int $questionId): JsonResponse
    {
        Question::where('ca_id', $id)->findOrFail($questionId)->delete();
        return response()->json(['message' => 'Question removed']);
    }

    /**
     * Student submits answers to CA questions.
     */
    public function submitAnswers(Request $request, int $id): JsonResponse
    {
        $ca = ContinuousAssessment::findOrFail($id);

        $v = Validator::make($request->all(), [
            'student_id'              => 'required|exists:students,id',
            'answers'                 => 'required|array|min:1',
            'answers.*.question_id'   => 'required|exists:questions,id',
            'answers.*.answer_data'   => 'required|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $now   = now();
        $saved = 0;

        foreach ($request->answers as $ans) {
            $question = Question::where('ca_id', $ca->id)->where('id', $ans['question_id'])->first();
            if (!$question) continue;

            $isCorrect = null;
            $marks     = null;
            if ($question->question_type !== 'essay') {
                $isCorrect = $question->isCorrectAnswer($ans['answer_data']);
                $marks     = $isCorrect ? $question->marks : 0;
            }

            CAQuestionAnswer::updateOrCreate(
                ['ca_id' => $ca->id, 'question_id' => $question->id, 'student_id' => $request->student_id],
                ['answer_data' => $ans['answer_data'], 'is_correct' => $isCorrect, 'marks_obtained' => $marks, 'updated_at' => $now]
            );
            $saved++;
        }

        return response()->json(['message' => "{$saved} answer(s) submitted"]);
    }

    /**
     * Teacher views all student answers per CA question.
     */
    public function questionResponses(int $id): JsonResponse
    {
        $ca        = ContinuousAssessment::findOrFail($id);
        $questions = $ca->questions()->get();

        $answers = CAQuestionAnswer::where('ca_id', $ca->id)
            ->with(['student.user:id,first_name,last_name'])
            ->get()
            ->groupBy('question_id');

        $result = $questions->map(fn($q) => [
            'question'  => $q,
            'responses' => $answers->get($q->id, collect())->map(fn($a) => [
                'student_id'     => $a->student_id,
                'student_name'   => $a->student?->user?->first_name . ' ' . $a->student?->user?->last_name,
                'answer_data'    => $a->answer_data,
                'is_correct'     => $a->is_correct,
                'marks_obtained' => $a->marks_obtained,
                'feedback'       => $a->feedback,
            ]),
        ]);

        return response()->json(['ca' => $ca->only(['id', 'name', 'total_marks']), 'questions' => $result]);
    }

    /**
     * Teacher grades open-ended CA answers or overrides auto-grade.
     */
    public function gradeQuestions(Request $request, int $id): JsonResponse
    {
        $ca = ContinuousAssessment::findOrFail($id);

        $v = Validator::make($request->all(), [
            'grades'                  => 'required|array|min:1',
            'grades.*.student_id'     => 'required|exists:students,id',
            'grades.*.question_id'    => 'required|exists:questions,id',
            'grades.*.marks_obtained' => 'required|numeric|min:0',
            'grades.*.feedback'       => 'nullable|string',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $now    = now();
        $userId = Auth::id();

        foreach ($request->grades as $g) {
            CAQuestionAnswer::updateOrCreate(
                ['ca_id' => $ca->id, 'question_id' => $g['question_id'], 'student_id' => $g['student_id']],
                ['marks_obtained' => $g['marks_obtained'], 'feedback' => $g['feedback'] ?? null, 'graded_by' => $userId, 'graded_at' => $now]
            );
        }

        return response()->json(['message' => count($request->grades) . ' grade(s) saved']);
    }
}

