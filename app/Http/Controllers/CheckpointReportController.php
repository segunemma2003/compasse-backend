<?php

namespace App\Http\Controllers;

use App\Models\ResultConfiguration;
use App\Models\ResultCheckpoint;
use App\Models\ResultDomain;
use App\Models\ResultIndicator;
use App\Models\ResultStrand;
use App\Models\School;
use App\Models\SchoolSignature;
use App\Models\Student;
use App\Models\StudentDomainComment;
use App\Models\StudentIndicatorGrade;
use App\Models\StudentTermVitals;
use App\Models\Term;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'report_photo_url'    => 'nullable|string|max:500',
            'memorable_moment'    => 'nullable|string|max:5000',
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
                    'report_photo_url', 'memorable_moment',
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
        $bundle = $this->loadCheckpointReportData($request, $studentId);
        if ($bundle instanceof JsonResponse) {
            return $bundle;
        }
        ['student' => $student, 'config' => $config, 'domains' => $domains, 'vitals' => $vitals, 'comments' => $comments] = $bundle;

        $yearRow = AcademicYear::find($request->academic_year_id);
        $termRow = $request->filled('term_id') ? Term::find($request->term_id) : null;

        return response()->json([
            'student'     => $student,
            'report_photo_url' => $this->resolveCheckpointReportPhotoUrl($student, $vitals),
            'academic_year' => $yearRow ? ['id' => $yearRow->id, 'name' => $yearRow->name] : null,
            'term'        => $termRow ? ['id' => $termRow->id, 'name' => $termRow->name] : null,
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
     * Shared loader for studentReport() (JSON) and generatePDF() (print-ready HTML).
     *
     * @return array{student: Student, config: ResultConfiguration, domains: \Illuminate\Support\Collection, vitals: ?StudentTermVitals, comments: \Illuminate\Support\Collection}|JsonResponse
     */
    private function loadCheckpointReportData(Request $request, int $studentId): array|JsonResponse
    {
        $user = Auth::user();
        $ownId = $this->ownStudentId($user);
        if ($ownId !== null && (int) $ownId !== $studentId) {
            return $this->forbiddenResponse('You may only view your own checkpoint report.');
        }

        $guardianStudentIds = null;
        if ($ownId === null) {
            $guardianStudentIds = $this->accessibleStudentIdsForGuardian($user);
            if ($guardianStudentIds !== null) {
                if (! in_array($studentId, $guardianStudentIds, true)) {
                    return $this->forbiddenResponse('This student is not one of your children.');
                }
            } else {
                $classIds = $this->accessibleClassIds($user);
                if ($classIds !== null) {
                    $studentClassId = Student::where('id', $studentId)->value('class_id');
                    if (! in_array((int) $studentClassId, $classIds, true)) {
                        return $this->forbiddenResponse('You are not assigned to this student\'s class.');
                    }
                }
            }
        }

        $v = Validator::make($request->all(), [
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
            'config_id'        => 'nullable|exists:result_configurations,id',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $student = Student::with(['user', 'class', 'arm'])->findOrFail($studentId);

        if ($request->filled('config_id')) {
            $config = ResultConfiguration::with(['checkpoints' => fn ($q) => $q->orderBy('display_order')])
                ->findOrFail($request->config_id);
        } else {
            $sectionType = $student->class?->section_type ?? 'primary';
            $config = ResultConfiguration::resolveFor(
                (int) $student->school_id,
                $sectionType,
                $student->class_id ? (int) $student->class_id : null
            );
            if (! $config || $config->report_template !== 'checkpoint') {
                return response()->json([
                    'error'   => 'Configuration not found',
                    'message' => 'No checkpoint result configuration applies to this student.',
                ], 404);
            }
            $config->load(['checkpoints' => fn ($q) => $q->orderBy('display_order')]);
        }

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

        return compact('student', 'config', 'domains', 'vitals', 'comments');
    }

    /**
     * Print-ready HTML "Stage Profile" style report for the checkpoint pattern
     * (Nursery/KG — no numeric scores, developmental domains rated per
     * checkpoint instead). Mirrors ReportCardController::generatePDF()'s
     * browser-print approach for the standard pattern.
     *
     * GET /checkpoint-report/student/{studentId}/pdf
     *     ?academic_year_id=&term_id=&config_id=
     */
    public function generatePDF(Request $request, int $studentId): Response
    {
        $bundle = $this->loadCheckpointReportData($request, $studentId);
        if ($bundle instanceof JsonResponse) {
            return response($bundle->getContent(), $bundle->getStatusCode())->header('Content-Type', 'application/json');
        }
        ['student' => $student, 'config' => $config, 'domains' => $domains, 'vitals' => $vitals, 'comments' => $comments] = $bundle;

        $school       = School::first();
        $signatures   = $school ? SchoolSignature::activeForSchool($school->id) : collect();
        $schoolLogo   = $school?->logo ?? '';
        $schoolName   = e($school?->name ?? 'School');
        $studentName  = e($student->user?->name ?? trim("{$student->first_name} {$student->last_name}"));
        $admissionNo  = e($student->admission_number ?? '');
        $className    = e($student->class?->name ?? 'N/A');
        $dob          = $student->date_of_birth ? e($student->date_of_birth->format('F j, Y')) : '—';

        $reportPhotoUrl = $this->resolveCheckpointReportPhotoUrl($student, $vitals);
        $photoHtml = $reportPhotoUrl
            ? '<img src="' . e($reportPhotoUrl) . '" alt="Student photo" style="width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #ccc;">'
            : '';

        $yearRow = AcademicYear::find($request->academic_year_id);
        $termRow = $request->filled('term_id') ? Term::find($request->term_id) : null;
        $sessionLine = e(trim(($yearRow?->name ?? '') . ($termRow ? ' · ' . $termRow->name : '')) ?: '—');

        $memorableHtml = '';
        if ($vitals && filled($vitals->memorable_moment ?? null)) {
            $memorableHtml = '<div class="comment-box" style="margin-bottom:12px;"><strong>Memorable moment</strong><br>'
                . nl2br(e($vitals->memorable_moment)) . '</div>';
        }

        $gradeScale = $config->custom_settings['checkpoint_grade_scale'] ?? ResultConfiguration::defaultCheckpointGradeScale();
        $checkpointLabels = $config->checkpoints->pluck('label')->all();

        $logoHtml = $schoolLogo
            ? "<img src=\"{$schoolLogo}\" style=\"max-height:80px;max-width:160px;\" alt=\"{$schoolName} logo\">"
            : "<div style=\"font-size:28px;font-weight:bold;\">{$schoolName}</div>";

        $vitalsHtml = '';
        if ($vitals) {
            $vitalsHtml = '<div class="vitals-grid">'
                . '<div>Days School Opened: <span>' . e((string) ($vitals->days_school_opened ?? '—')) . '</span></div>'
                . '<div>Attendance: <span>' . e((string) ($vitals->days_attended ?? '—')) . '</span></div>'
                . '<div>Height (beginning / end of term): <span>' . e((string) ($vitals->height_beginning ?? '—')) . 'cm / ' . e((string) ($vitals->height_end ?? '—')) . 'cm</span></div>'
                . '<div>Weight (beginning / end of term): <span>' . e((string) ($vitals->weight_beginning ?? '—')) . 'kg / ' . e((string) ($vitals->weight_end ?? '—')) . 'kg</span></div>'
                . '<div>Homework: <span>' . e($vitals->homework_rating ?? '—') . '</span></div>'
                . '<div>Punctuality: <span>' . e($vitals->punctuality_rating ?? '—') . '</span></div>'
                . '</div>';
        }

        $legendHtml = '<div class="legend"><strong>KEY:</strong> ' . collect($gradeScale)->map(
            fn ($g) => e($g['code'] ?? $g['label'] ?? '') . ' = ' . e($g['label'] ?? '')
        )->implode(' &nbsp;&nbsp; ') . '</div>';

        $domainsHtml = '';
        $commentBoxesHtml = '';
        foreach ($domains as $domain) {
            $color = e($domain->color ?? '#1a3a6b');
            $name  = e($domain->name);

            if ($domain->strands->isEmpty()) {
                // Comment-only domain (e.g. Music, French, Class Teacher's Comment).
                $comment = $comments->get($domain->id);
                if (! $comment) {
                    continue;
                }
                $teacher = $comment->teacher_name ? ' — <em>' . e($comment->teacher_name) . '</em>' : '';
                $commentBoxesHtml .= '<h3 style="color:' . $color . ';">' . $name . $teacher . '</h3>'
                    . '<div class="comment-box">' . nl2br(e($comment->comment)) . '</div>';
                continue;
            }

            $rows = '';
            foreach ($domain->strands as $strand) {
                $rows .= '<tr class="strand-row"><td colspan="' . (1 + count($checkpointLabels)) . '">' . e($strand->name) . '</td></tr>';
                foreach ($strand->indicators as $indicator) {
                    $cells = '';
                    foreach ($checkpointLabels as $label) {
                        $cells .= '<td class="cp-cell">' . e($indicator->grades_by_checkpoint[$label] ?? '') . '</td>';
                    }
                    $rows .= '<tr><td>' . e($indicator->name) . '</td>' . $cells . '</tr>';
                }
            }

            $headerCells = collect($checkpointLabels)->map(fn ($l) => '<th>' . e($l) . '</th>')->implode('');
            $domainsHtml .= '<table class="domain-table">'
                . '<thead><tr class="domain-banner" style="background:' . $color . ';"><th>' . $name . '</th>' . $headerCells . '</tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>';
        }

        $sigHtml = '';
        foreach ($signatures as $role => $sig) {
            $sigName = e($sig->name);
            $sigRole = e(ucwords(str_replace('_', ' ', (string) $role)));
            $sigUrl  = $sig->signature_url;
            $sigImg  = $sigUrl
                ? "<img src=\"{$sigUrl}\" style=\"max-height:60px;max-width:160px;\" alt=\"{$sigRole} signature\">"
                : '<div style="border-bottom:1px solid #333;width:160px;height:60px;"></div>';
            $sigHtml .= "<div style=\"text-align:center;min-width:180px;\">{$sigImg}<div style=\"font-size:11px;margin-top:4px;\">{$sigName}</div><div style=\"font-size:10px;color:#666;\">{$sigRole}</div></div>";
        }
        if (! $sigHtml) {
            $sigHtml = '<div style="border-bottom:1px solid #333;width:160px;height:60px;margin:auto;"></div><div style="font-size:11px;text-align:center;">Class Teacher</div>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Stage Profile – {$studentName}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; padding: 20px; }
  .header { display: flex; align-items: center; gap: 20px; border-bottom: 3px solid #1a3a6b; padding-bottom: 12px; margin-bottom: 16px; }
  .header-text h1 { font-size: 18px; color: #1a3a6b; }
  .header-text p { font-size: 11px; color: #555; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 12px; }
  .info-grid div { font-size: 12px; }
  .info-grid span { font-weight: bold; }
  .vitals-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 16px; padding: 10px; background: #f0f4ff; border-radius: 6px; }
  .vitals-grid div { font-size: 11px; }
  .vitals-grid span { font-weight: bold; }
  .legend { font-size: 11px; color: #555; margin-bottom: 12px; }
  table.domain-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  .domain-banner th { color: #fff; padding: 6px 8px; text-align: left; font-size: 12px; }
  .strand-row td { background: #eef1f8; font-weight: bold; font-size: 11px; padding: 4px 8px; }
  td { padding: 5px 8px; border-bottom: 1px solid #ddd; font-size: 11px; }
  .cp-cell { text-align: center; font-weight: bold; width: 50px; }
  tr:nth-child(even) td { background: #fafbff; }
  h3 { font-size: 12px; margin: 10px 0 4px; }
  .comment-box { background: #fafafa; border: 1px solid #eee; padding: 8px; border-radius: 4px; font-size: 11px; margin-bottom: 8px; }
  .signatures { display: flex; gap: 40px; flex-wrap: wrap; margin-top: 20px; padding-top: 12px; border-top: 1px solid #ddd; }
  @media print { body { padding: 0; } @page { margin: 1.5cm; } }
</style>
</head>
<body>
<div class="header">
  <div>{$logoHtml}</div>
  <div class="header-text">
    <h1>{$schoolName}</h1>
    <p>{$config->name} — Stage Profile</p>
  </div>
</div>
<div class="info-grid">
  <div>Student Name: <span>{$studentName}</span></div>
  <div>Class: <span>{$className}</span></div>
  <div>Admission No.: <span>{$admissionNo}</span></div>
  <div>Date of Birth: <span>{$dob}</span></div>
  <div>Session: <span>{$sessionLine}</span></div>
</div>
<div style="display:flex;gap:16px;align-items:flex-start;margin-bottom:12px;">{$photoHtml}<div style="flex:1;">{$memorableHtml}</div></div>
{$vitalsHtml}
{$legendHtml}
{$domainsHtml}
<div class="comments">{$commentBoxesHtml}</div>
<div class="signatures">{$sigHtml}</div>
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
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

    private function resolveCheckpointReportPhotoUrl(Student $student, ?StudentTermVitals $vitals): ?string
    {
        $url = trim((string) ($vitals->report_photo_url ?? ''));
        if ($url !== '') {
            return $url;
        }

        $profile = trim((string) ($student->profile_picture ?? ''));
        if ($profile !== '') {
            return $profile;
        }

        $student->loadMissing('user');
        $userPhoto = trim((string) ($student->user?->profile_picture ?? ''));

        return $userPhoto !== '' ? $userPhoto : null;
    }
}
