<?php

namespace App\Http\Controllers;

use App\Models\ResultConfiguration;
use App\Models\ResultCheckpoint;
use App\Models\ResultDomain;
use App\Models\ResultIndicator;
use App\Models\ResultStrand;
use App\Models\Student;
use App\Models\StudentDomainComment;
use App\Models\StudentIndicatorGrade;
use App\Models\StudentTermVitals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Manages the checkpoint/competency-based report card system.
 *
 * Admin routes:  manage domains, strands, indicators, checkpoints for a config
 * Teacher routes: record/update grades, vitals, domain comments
 * Shared routes:  read the full report card for a student
 */
class CheckpointReportController extends Controller
{
    // ── Admin: Domain CRUD ────────────────────────────────────────────────────

    public function getDomains(int $configId): JsonResponse
    {
        $config = ResultConfiguration::findOrFail($configId);

        $domains = $config->domains()->with([
            'strands.indicators',
        ])->get();

        return response()->json([
            'config'  => [
                'id'              => $config->id,
                'name'            => $config->name,
                'section_type'    => $config->section_type,
                'report_template' => $config->report_template,
                'checkpoint_grade_scale' => $config->custom_settings['checkpoint_grade_scale']
                    ?? ResultConfiguration::defaultCheckpointGradeScale(),
            ],
            'domains'     => $domains,
            'checkpoints' => $config->checkpoints()->orderBy('display_order')->get(),
        ]);
    }

    public function storeDomain(Request $request, int $configId): JsonResponse
    {
        $config = ResultConfiguration::findOrFail($configId);

        $v = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'color'         => 'nullable|string|max:20',
            'display_order' => 'nullable|integer|min:0',
            'strands'       => 'nullable|array',
            'strands.*.name'          => 'required_with:strands|string|max:255',
            'strands.*.display_order' => 'nullable|integer|min:0',
            'strands.*.indicators'    => 'nullable|array',
            'strands.*.indicators.*.name'          => 'required_with:strands.*.indicators|string',
            'strands.*.indicators.*.display_order' => 'nullable|integer|min:0',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $maxOrder = $config->domains()->max('display_order') ?? -1;

            $domain = $config->domains()->create([
                'name'          => $request->name,
                'color'         => $request->input('color', '#6b21a8'),
                'display_order' => $request->input('display_order', $maxOrder + 1),
            ]);

            foreach ($request->input('strands', []) as $si => $strandData) {
                $strand = $domain->strands()->create([
                    'name'          => $strandData['name'],
                    'display_order' => $strandData['display_order'] ?? $si,
                ]);

                foreach ($strandData['indicators'] ?? [] as $ii => $indicatorData) {
                    $strand->indicators()->create([
                        'name'          => $indicatorData['name'],
                        'display_order' => $indicatorData['display_order'] ?? $ii,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Domain created',
                'domain'  => $domain->load('strands.indicators'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateDomain(Request $request, int $configId, int $domainId): JsonResponse
    {
        $domain = ResultDomain::where('result_configuration_id', $configId)->findOrFail($domainId);

        $v = Validator::make($request->all(), [
            'name'          => 'sometimes|string|max:255',
            'color'         => 'nullable|string|max:20',
            'display_order' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $domain->update($request->only(['name', 'color', 'display_order']));

        return response()->json(['message' => 'Domain updated', 'domain' => $domain]);
    }

    public function destroyDomain(int $configId, int $domainId): JsonResponse
    {
        $domain = ResultDomain::where('result_configuration_id', $configId)->findOrFail($domainId);
        $domain->delete();
        return response()->json(['message' => 'Domain deleted']);
    }

    // ── Admin: Strand CRUD ────────────────────────────────────────────────────

    public function storeStrand(Request $request, int $domainId): JsonResponse
    {
        $domain = ResultDomain::findOrFail($domainId);

        $v = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'display_order' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $maxOrder = $domain->strands()->max('display_order') ?? -1;
        $strand = $domain->strands()->create([
            'name'          => $request->name,
            'display_order' => $request->input('display_order', $maxOrder + 1),
        ]);

        return response()->json(['message' => 'Strand created', 'strand' => $strand], 201);
    }

    public function updateStrand(Request $request, int $domainId, int $strandId): JsonResponse
    {
        $strand = ResultStrand::where('result_domain_id', $domainId)->findOrFail($strandId);

        $v = Validator::make($request->all(), [
            'name'          => 'sometimes|string|max:255',
            'display_order' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $strand->update($request->only(['name', 'display_order']));
        return response()->json(['message' => 'Strand updated', 'strand' => $strand]);
    }

    public function destroyStrand(int $domainId, int $strandId): JsonResponse
    {
        $strand = ResultStrand::where('result_domain_id', $domainId)->findOrFail($strandId);
        $strand->delete();
        return response()->json(['message' => 'Strand deleted']);
    }

    // ── Admin: Indicator CRUD ─────────────────────────────────────────────────

    public function storeIndicator(Request $request, int $strandId): JsonResponse
    {
        $strand = ResultStrand::findOrFail($strandId);

        $v = Validator::make($request->all(), [
            'name'          => 'required|string',
            'display_order' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $maxOrder = $strand->indicators()->max('display_order') ?? -1;
        $indicator = $strand->indicators()->create([
            'name'          => $request->name,
            'display_order' => $request->input('display_order', $maxOrder + 1),
        ]);

        return response()->json(['message' => 'Indicator created', 'indicator' => $indicator], 201);
    }

    public function updateIndicator(Request $request, int $strandId, int $indicatorId): JsonResponse
    {
        $indicator = ResultIndicator::where('result_strand_id', $strandId)->findOrFail($indicatorId);

        $v = Validator::make($request->all(), [
            'name'          => 'sometimes|string',
            'display_order' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $indicator->update($request->only(['name', 'display_order']));
        return response()->json(['message' => 'Indicator updated', 'indicator' => $indicator]);
    }

    public function destroyIndicator(int $strandId, int $indicatorId): JsonResponse
    {
        $indicator = ResultIndicator::where('result_strand_id', $strandId)->findOrFail($indicatorId);
        $indicator->delete();
        return response()->json(['message' => 'Indicator deleted']);
    }

    // ── Admin: Checkpoint CRUD ────────────────────────────────────────────────

    public function getCheckpoints(int $configId): JsonResponse
    {
        $config = ResultConfiguration::findOrFail($configId);
        return response()->json(['checkpoints' => $config->checkpoints()->orderBy('display_order')->get()]);
    }

    public function storeCheckpoint(Request $request, int $configId): JsonResponse
    {
        $config = ResultConfiguration::findOrFail($configId);

        $v = Validator::make($request->all(), [
            'label'         => 'required|string|max:10',
            'name'          => 'required|string|max:255',
            'display_order' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        if ($config->checkpoints()->where('label', $request->label)->exists()) {
            return response()->json(['error' => "Checkpoint '{$request->label}' already exists for this configuration"], 422);
        }

        $maxOrder = $config->checkpoints()->max('display_order') ?? -1;
        $checkpoint = $config->checkpoints()->create([
            'label'         => strtoupper($request->label),
            'name'          => $request->name,
            'display_order' => $request->input('display_order', $maxOrder + 1),
        ]);

        return response()->json(['message' => 'Checkpoint created', 'checkpoint' => $checkpoint], 201);
    }

    public function updateCheckpoint(Request $request, int $configId, int $checkpointId): JsonResponse
    {
        $checkpoint = ResultCheckpoint::where('result_configuration_id', $configId)->findOrFail($checkpointId);

        $v = Validator::make($request->all(), [
            'label'         => 'sometimes|string|max:10',
            'name'          => 'sometimes|string|max:255',
            'display_order' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $data = $request->only(['name', 'display_order']);
        if ($request->has('label')) {
            $data['label'] = strtoupper($request->label);
        }
        $checkpoint->update($data);
        return response()->json(['message' => 'Checkpoint updated', 'checkpoint' => $checkpoint]);
    }

    public function destroyCheckpoint(int $configId, int $checkpointId): JsonResponse
    {
        $checkpoint = ResultCheckpoint::where('result_configuration_id', $configId)->findOrFail($checkpointId);
        $checkpoint->delete();
        return response()->json(['message' => 'Checkpoint deleted']);
    }

    // ── Grade Scale on Config ─────────────────────────────────────────────────

    public function updateGradeScale(Request $request, int $configId): JsonResponse
    {
        $config = ResultConfiguration::findOrFail($configId);

        $v = Validator::make($request->all(), [
            'grade_scale'                  => 'required|array|min:1',
            'grade_scale.*.code'           => 'required|string|max:5',
            'grade_scale.*.label'          => 'required|string|max:50',
            'grade_scale.*.description'    => 'nullable|string',
            'homework_options'             => 'nullable|array',
            'punctuality_options'          => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $settings = $config->custom_settings ?? [];
        $settings['checkpoint_grade_scale'] = $request->grade_scale;

        if ($request->has('homework_options')) {
            $settings['homework_options'] = $request->homework_options;
        }
        if ($request->has('punctuality_options')) {
            $settings['punctuality_options'] = $request->punctuality_options;
        }

        $config->update(['custom_settings' => $settings]);

        return response()->json(['message' => 'Grade scale updated', 'custom_settings' => $config->custom_settings]);
    }

    // ── Teacher: Record Grades ─────────────────────────────────────────────────

    /**
     * Upsert grades for one student × checkpoint (batch).
     *
     * Body: {
     *   student_id, checkpoint_id, academic_year_id, term_id (optional),
     *   grades: [ { indicator_id, grade }, … ]
     * }
     */
    public function recordGrades(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'student_id'       => 'required|exists:students,id',
            'checkpoint_id'    => 'required|exists:result_checkpoints,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'grades'           => 'required|array|min:1',
            'grades.*.indicator_id' => 'required|exists:result_indicators,id',
            'grades.*.grade'        => 'nullable|string|max:10',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $userId = Auth::id();
        $now    = now();
        $saved  = 0;

        foreach ($request->grades as $entry) {
            StudentIndicatorGrade::updateOrCreate(
                [
                    'student_id'           => $request->student_id,
                    'result_indicator_id'  => $entry['indicator_id'],
                    'result_checkpoint_id' => $request->checkpoint_id,
                    'academic_year_id'     => $request->academic_year_id,
                ],
                [
                    'term_id'     => $request->term_id,
                    'grade'       => $entry['grade'] ?? null,
                    'recorded_by' => $userId,
                    'updated_at'  => $now,
                ]
            );
            $saved++;
        }

        return response()->json(['message' => "{$saved} grade(s) saved"]);
    }

    /**
     * Upsert grades for an entire class × checkpoint (batch by class).
     *
     * Body: {
     *   class_id, arm_id (optional), checkpoint_id, academic_year_id, term_id (optional),
     *   grades: [ { student_id, indicator_id, grade }, … ]
     * }
     */
    public function recordClassGrades(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'checkpoint_id'    => 'required|exists:result_checkpoints,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'grades'           => 'required|array|min:1',
            'grades.*.student_id'   => 'required|exists:students,id',
            'grades.*.indicator_id' => 'required|exists:result_indicators,id',
            'grades.*.grade'        => 'nullable|string|max:10',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $userId = Auth::id();
        $now    = now();

        $rows = array_map(fn($entry) => [
            'student_id'           => $entry['student_id'],
            'result_indicator_id'  => $entry['indicator_id'],
            'result_checkpoint_id' => $request->checkpoint_id,
            'academic_year_id'     => $request->academic_year_id,
            'term_id'              => $request->term_id,
            'grade'                => $entry['grade'] ?? null,
            'recorded_by'          => $userId,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], $request->grades);

        // upsert on unique key: student × indicator × checkpoint × year
        StudentIndicatorGrade::upsert(
            $rows,
            ['student_id', 'result_indicator_id', 'result_checkpoint_id', 'academic_year_id'],
            ['term_id', 'grade', 'recorded_by', 'updated_at']
        );

        return response()->json(['message' => count($rows) . ' grade(s) saved']);
    }

    // ── Teacher: Vitals ───────────────────────────────────────────────────────

    public function recordVitals(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'student_id'          => 'required|exists:students,id',
            'academic_year_id'    => 'required|exists:academic_years,id',
            'term_id'             => 'nullable|exists:terms,id',
            'days_school_opened'  => 'nullable|integer|min:0',
            'days_attended'       => 'nullable|integer|min:0',
            'height_beginning'    => 'nullable|numeric|min:0',
            'height_end'          => 'nullable|numeric|min:0',
            'weight_beginning'    => 'nullable|numeric|min:0',
            'weight_end'          => 'nullable|numeric|min:0',
            'homework_rating'     => 'nullable|string|max:30',
            'punctuality_rating'  => 'nullable|string|max:30',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $vitals = StudentTermVitals::updateOrCreate(
            [
                'student_id'       => $request->student_id,
                'academic_year_id' => $request->academic_year_id,
                'term_id'          => $request->term_id,
            ],
            array_merge(
                $request->only([
                    'days_school_opened', 'days_attended',
                    'height_beginning', 'height_end',
                    'weight_beginning', 'weight_end',
                    'homework_rating', 'punctuality_rating',
                ]),
                ['recorded_by' => Auth::id()]
            )
        );

        return response()->json(['message' => 'Vitals saved', 'vitals' => $vitals]);
    }

    // ── Teacher: Domain Comments ──────────────────────────────────────────────

    public function recordDomainComment(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'student_id'       => 'required|exists:students,id',
            'domain_id'        => 'required|exists:result_domains,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'comment'          => 'required|string',
            'teacher_name'     => 'nullable|string|max:100',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $comment = StudentDomainComment::updateOrCreate(
            [
                'student_id'       => $request->student_id,
                'result_domain_id' => $request->domain_id,
                'academic_year_id' => $request->academic_year_id,
                'term_id'          => $request->term_id,
            ],
            [
                'comment'      => $request->comment,
                'teacher_name' => $request->teacher_name,
                'recorded_by'  => Auth::id(),
            ]
        );

        return response()->json(['message' => 'Comment saved', 'comment' => $comment]);
    }

    // ── Report Card ───────────────────────────────────────────────────────────

    /**
     * Full checkpoint report card for a single student.
     *
     * GET /checkpoint-report/student/{studentId}
     *     ?academic_year_id=&term_id=&config_id=
     */
    public function studentReport(Request $request, int $studentId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'config_id'        => 'required|exists:result_configurations,id',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $student = Student::with(['user', 'class', 'arm'])->findOrFail($studentId);
        $config  = ResultConfiguration::with(['checkpoints' => fn($q) => $q->orderBy('display_order')])->findOrFail($request->config_id);

        // Build indicator grade map: [indicator_id][checkpoint_id] = grade
        $gradeRows = StudentIndicatorGrade::where('student_id', $studentId)
            ->where('academic_year_id', $request->academic_year_id)
            ->when($request->term_id, fn($q) => $q->where('term_id', $request->term_id))
            ->get();

        $gradeMap = [];
        foreach ($gradeRows as $row) {
            $gradeMap[$row->result_indicator_id][$row->result_checkpoint_id] = $row->grade;
        }

        // Load domains → strands → indicators, annotate with grades
        $domains = $config->domains()->with('strands.indicators')->get()->map(function ($domain) use ($gradeMap, $config) {
            $domain->strands->each(function ($strand) use ($gradeMap, $config) {
                $strand->indicators->each(function ($indicator) use ($gradeMap, $config) {
                    $indicator->grades_by_checkpoint = $config->checkpoints->mapWithKeys(fn($cp) => [
                        $cp->label => $gradeMap[$indicator->id][$cp->id] ?? null,
                    ]);
                });
            });
            return $domain;
        });

        // Vitals
        $vitals = StudentTermVitals::where('student_id', $studentId)
            ->where('academic_year_id', $request->academic_year_id)
            ->when($request->term_id, fn($q) => $q->where('term_id', $request->term_id))
            ->first();

        // Domain comments
        $comments = StudentDomainComment::where('student_id', $studentId)
            ->where('academic_year_id', $request->academic_year_id)
            ->when($request->term_id, fn($q) => $q->where('term_id', $request->term_id))
            ->get()
            ->keyBy('result_domain_id');

        return response()->json([
            'student'     => $student,
            'config'      => [
                'id'           => $config->id,
                'name'         => $config->name,
                'checkpoints'  => $config->checkpoints,
                'grade_scale'  => $config->custom_settings['checkpoint_grade_scale']
                    ?? ResultConfiguration::defaultCheckpointGradeScale(),
                'homework_options'    => $config->custom_settings['homework_options']    ?? ['Good', 'Satisfactory', 'Weak'],
                'punctuality_options' => $config->custom_settings['punctuality_options'] ?? ['Always', 'Sometimes', 'Hardly'],
            ],
            'domains'     => $domains,
            'vitals'      => $vitals,
            'comments'    => $comments->values(),
        ]);
    }

    /**
     * Lightweight summary of grades for a whole class × checkpoint.
     *
     * GET /checkpoint-report/class/{classId}
     *     ?config_id=&checkpoint_id=&academic_year_id=&arm_id=
     */
    public function classReport(Request $request, int $classId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'config_id'        => 'required|exists:result_configurations,id',
            'checkpoint_id'    => 'required|exists:result_checkpoints,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'arm_id'           => 'nullable|exists:arms,id',
            'term_id'          => 'nullable|exists:terms,id',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $students = Student::where('class_id', $classId)
            ->where('status', 'active')
            ->when($request->arm_id, fn($q) => $q->where('arm_id', $request->arm_id))
            ->with('user:id,first_name,last_name')
            ->get();

        $studentIds = $students->pluck('id');

        $grades = StudentIndicatorGrade::whereIn('student_id', $studentIds)
            ->where('result_checkpoint_id', $request->checkpoint_id)
            ->where('academic_year_id', $request->academic_year_id)
            ->when($request->term_id, fn($q) => $q->where('term_id', $request->term_id))
            ->get()
            ->groupBy('student_id');

        $config = ResultConfiguration::with([
            'domains.strands.indicators',
            'checkpoints' => fn($q) => $q->orderBy('display_order'),
        ])->findOrFail($request->config_id);

        return response()->json([
            'config'    => $config,
            'students'  => $students->map(fn($s) => [
                'id'         => $s->id,
                'name'       => $s->user?->first_name . ' ' . $s->user?->last_name,
                'student_code' => $s->student_code,
                'grades'     => $grades->get($s->id, collect())->mapWithKeys(fn($g) => [
                    $g->result_indicator_id => $g->grade,
                ]),
            ]),
        ]);
    }
}
