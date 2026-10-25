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
                'comments_enabled' => $request->boolean('comments_enabled', true),
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
            'comments_enabled' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $v->errors()], 422);
        }

        $domain->update($request->only(['name', 'color', 'display_order', 'comments_enabled']));

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
    /** Leadership, or a teacher of these students' classes (class teachers enter the ratings). */
    private function denyUnlessTeaches(array $studentIds): ?JsonResponse
    {
        if (! \App\Support\RoleCapabilityService::userCan(Auth::user(), 'preschool_report.enter', School::first()?->id)) {
            return $this->forbiddenResponse('You do not have permission to enter the Preschool Progress Report.');
        }
        $classIds = $this->accessibleClassIds(Auth::user());
        if ($classIds === null) {
            return null;
        }
        $theirs = Student::whereIn('id', array_unique(array_map('intval', $studentIds)))->pluck('class_id')->map(fn ($c) => (int) $c)->unique();
        foreach ($theirs as $c) {
            if (! in_array($c, array_map('intval', $classIds), true)) {
                return $this->forbiddenResponse('You can only enter the progress report for your own class.');
            }
        }

        return null;
    }

    public function recordGrades(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessTeaches([(int) $request->input('student_id')])) {
            return $denied;
        }
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
        if ($denied = $this->denyUnlessTeaches(array_column((array) $request->input('grades', []), 'student_id'))) {
            return $denied;
        }
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
        if ($denied = $this->denyUnlessTeaches([(int) $request->input('student_id')])) {
            return $denied;
        }
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
        if ($denied = $this->denyUnlessTeaches([(int) $request->input('student_id')])) {
            return $denied;
        }
        if (ResultDomain::where('id', $request->input('domain_id'))->value('comments_enabled') === false) {
            return response()->json(['error' => 'Comments are switched off for this learning area.'], 422);
        }
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
            'extra_comments' => $bundle['extraComments'],
            'portfolio'   => $bundle['portfolio'],
        ]);
    }

    // ── Teacher: configs, extra comments, portfolio ───────────────────────────

    /** GET /checkpoint-report/my-configs — progress-report set-ups for the classes this user teaches (all for leadership). */
    public function myConfigs(Request $request): JsonResponse
    {
        $classIds = $this->accessibleClassIds(Auth::user());
        $classes = DB::table('classes')->when($classIds !== null, fn ($q) => $q->whereIn('id', $classIds ?: [0]))->orderBy('name')->get(['id', 'name', 'section_type']);
        $out = [];
        foreach ($classes as $c) {
            $cfg = ResultConfiguration::progressConfigFor((int) (School::first()?->id ?? 1), $c->section_type ?? 'primary', (int) $c->id);
            if ($cfg) {
                $out[$cfg->id] ??= ['id' => $cfg->id, 'name' => $cfg->name, 'section_type' => $cfg->section_type, 'report_template' => $cfg->report_template, 'class_id' => $cfg->class_id, 'classes' => []];
                $out[$cfg->id]['classes'][] = ['id' => $c->id, 'name' => $c->name];
            }
        }

        return response()->json(['configurations' => array_values($out), 'is_leadership' => $classIds === null]);
    }

    /** POST /checkpoint-report/extra-comments {student_id, academic_year_id, term_id?, subject, comment, id?} */
    public function saveExtraComment(Request $request): JsonResponse
    {
        $v = $request->validate(['id' => 'nullable|integer', 'student_id' => 'required|exists:students,id', 'academic_year_id' => 'required|integer', 'term_id' => 'nullable|integer',
            'subject' => 'required|string|max:120', 'comment' => 'required|string|max:3000', 'teacher_name' => 'nullable|string|max:100']);
        if ($denied = $this->denyUnlessTeaches([(int) $v['student_id']])) {
            return $denied;
        }
        $row = ['student_id' => $v['student_id'], 'academic_year_id' => $v['academic_year_id'], 'term_id' => $v['term_id'] ?? null, 'subject' => $v['subject'],
            'comment' => $v['comment'], 'teacher_name' => $v['teacher_name'] ?? Auth::user()?->name, 'recorded_by' => Auth::id(), 'updated_at' => now()];
        if (! empty($v['id'])) {
            DB::table('student_report_extra_comments')->where('id', $v['id'])->where('student_id', $v['student_id'])->update($row);
            $id = $v['id'];
        } else {
            $id = DB::table('student_report_extra_comments')->insertGetId($row + ['created_at' => now()]);
        }

        return response()->json(['message' => 'Comment saved', 'id' => $id]);
    }

    public function deleteExtraComment(int $id): JsonResponse
    {
        $row = DB::table('student_report_extra_comments')->find($id);
        if (! $row) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if ($denied = $this->denyUnlessTeaches([(int) $row->student_id])) {
            return $denied;
        }
        DB::table('student_report_extra_comments')->where('id', $id)->delete();

        return response()->json(['message' => 'Removed']);
    }

    /** POST /checkpoint-report/portfolio {student_id, academic_year_id, term_id?, image, caption?} — up to 9 pictures a term. */
    public function uploadPortfolio(Request $request): JsonResponse
    {
        $v = $request->validate(['student_id' => 'required|exists:students,id', 'academic_year_id' => 'required|integer', 'term_id' => 'nullable|integer',
            'image' => 'required|image|max:6144', 'caption' => 'nullable|string|max:200']);
        if ($denied = $this->denyUnlessTeaches([(int) $v['student_id']])) {
            return $denied;
        }
        $q = DB::table('student_portfolio_items')->where('student_id', $v['student_id'])->where('academic_year_id', $v['academic_year_id'])->where('term_id', $v['term_id'] ?? null);
        if ($q->count() >= 9) {
            return response()->json(['error' => 'The portfolio already has 9 pictures for this term — remove one first.'], 422);
        }
        $schoolId = School::first()?->id ?? 0;
        try {
            $path = $request->file('image')->store("schools/{$schoolId}/portfolio/{$v['student_id']}", 's3');
            $url = \Illuminate\Support\Facades\Storage::disk('s3')->url($path);
        } catch (\Throwable) {
            $path = $request->file('image')->store("portfolio/{$v['student_id']}", 'public');
            $url = $path;
        }
        $id = DB::table('student_portfolio_items')->insertGetId([
            'student_id' => $v['student_id'], 'academic_year_id' => $v['academic_year_id'], 'term_id' => $v['term_id'] ?? null,
            'image_url' => $url, 'caption' => $v['caption'] ?? null, 'sort_order' => $q->count(), 'uploaded_by' => Auth::id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Picture added', 'item' => DB::table('student_portfolio_items')->find($id)], 201);
    }

    public function updatePortfolio(Request $request, int $id): JsonResponse
    {
        $row = DB::table('student_portfolio_items')->find($id);
        if (! $row) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if ($denied = $this->denyUnlessTeaches([(int) $row->student_id])) {
            return $denied;
        }
        DB::table('student_portfolio_items')->where('id', $id)->update(['caption' => $request->input('caption'), 'updated_at' => now()]);

        return response()->json(['message' => 'Saved']);
    }

    public function deletePortfolio(int $id): JsonResponse
    {
        $row = DB::table('student_portfolio_items')->find($id);
        if (! $row) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if ($denied = $this->denyUnlessTeaches([(int) $row->student_id])) {
            return $denied;
        }
        DB::table('student_portfolio_items')->where('id', $id)->delete();

        return response()->json(['message' => 'Removed']);
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
            $config = ResultConfiguration::progressConfigFor(
                (int) $student->school_id,
                $sectionType,
                $student->class_id ? (int) $student->class_id : null
            );
            if (! $config) {
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

        $extraComments = \Illuminate\Support\Facades\Schema::hasTable('student_report_extra_comments')
            ? DB::table('student_report_extra_comments')->where('student_id', $studentId)->where('academic_year_id', $request->academic_year_id)
                ->when($request->term_id, fn ($q) => $q->where('term_id', $request->term_id))->orderBy('id')->get()
            : collect();
        $portfolio = \Illuminate\Support\Facades\Schema::hasTable('student_portfolio_items')
            ? DB::table('student_portfolio_items')->where('student_id', $studentId)->where('academic_year_id', $request->academic_year_id)
                ->when($request->term_id, fn ($q) => $q->where('term_id', $request->term_id))->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        return compact('student', 'config', 'domains', 'vitals', 'comments', 'extraComments', 'portfolio');
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
        $extraComments = $bundle['extraComments'];
        $portfolio = $bundle['portfolio'];

        $school       = School::first();
        $signatures   = $school
            ? SchoolSignature::resolveForReportCard($school->id, $this->resolveClassTeacherId($student))
            : collect();
        $schoolLogo   = $this->resolveAssetUrl($school?->logo);
        $schoolName   = e($school?->name ?? 'School');
        $addressLine  = trim(implode('  |  ', array_filter([$school?->address, $school?->phone, $school?->email])));
        $schoolAddress = e($addressLine);
        $studentName  = e($student->user?->name ?? trim("{$student->first_name} {$student->last_name}"));
        $admissionNo  = e($student->admission_number ?? '');
        $className    = e($student->class?->name ?? 'N/A');
        $dob          = $student->date_of_birth ? e($student->date_of_birth->format('F j, Y')) : '—';

        $reportPhotoUrl = $this->resolveAssetUrl($this->resolveCheckpointReportPhotoUrl($student, $vitals));
        $photoHtml = $reportPhotoUrl
            ? '<img src="' . e($reportPhotoUrl) . '" alt="' . $studentName . '">'
            : '<div class="photo-fallback"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.5-7 8-7s8 3 8 7"/></svg></div>';

        $yearRow = AcademicYear::find($request->academic_year_id);
        $termRow = $request->filled('term_id') ? Term::find($request->term_id) : null;
        $termName = e($termRow?->name ?? ($yearRow?->name ?? ''));
        $sessionLine = e(trim(($yearRow?->name ?? '') . ($termRow ? ' · ' . $termRow->name : '')) ?: '—');

        $memorableHtml = '';
        if ($vitals && filled($vitals->memorable_moment ?? null)) {
            $memorableHtml = '<div class="comment-block"><div class="comment-label">Memorable Moment</div><div class="comment-box">'
                . nl2br(e($vitals->memorable_moment)) . '</div></div>';
        }

        $gradeScale = $config->custom_settings['checkpoint_grade_scale'] ?? ResultConfiguration::defaultCheckpointGradeScale();
        $checkpointLabels = $config->checkpoints->pluck('label')->all();

        $logoHtml = $schoolLogo
            ? "<img src=\"{$schoolLogo}\" alt=\"{$schoolName} logo\">"
            : '<div class="logo-fallback">' . mb_strtoupper(mb_substr($schoolName, 0, 1)) . '</div>';

        $vitalsHtml = '<div class="panel-head">Vitals &amp; Attendance</div>';
        if ($vitals) {
            $vitalsHtml .= '<table class="kv two-per-row">'
                . '<tr><td>Days School Opened</td><td>' . e((string) ($vitals->days_school_opened ?? '—')) . '</td>'
                . '<td>Days Attended</td><td>' . e((string) ($vitals->days_attended ?? '—')) . '</td></tr>'
                . '<tr><td>Height (start/end)</td><td>' . e((string) ($vitals->height_beginning ?? '—')) . ' / ' . e((string) ($vitals->height_end ?? '—')) . ' cm</td>'
                . '<td>Weight (start/end)</td><td>' . e((string) ($vitals->weight_beginning ?? '—')) . ' / ' . e((string) ($vitals->weight_end ?? '—')) . ' kg</td></tr>'
                . '<tr><td>Homework</td><td>' . e($vitals->homework_rating ?? '—') . '</td>'
                . '<td>Punctuality</td><td>' . e($vitals->punctuality_rating ?? '—') . '</td></tr>'
                . '</table>';
        } else {
            $vitalsHtml .= '<p class="muted">Not recorded for this term.</p>';
        }

        $legendHtml = '<div class="grade-legend"><strong>Key:&nbsp;</strong>' . collect($gradeScale)->map(
            fn ($g) => '<div><strong>' . e($g['code'] ?? $g['label'] ?? '') . '</strong> = ' . e($g['label'] ?? '') . '</div>'
        )->implode('') . '</div>';

        $domainsHtml = '';
        $commentBoxesHtml = '';
        foreach ($domains as $domain) {
            $color = e($domain->color ?? '#1a3a6b');
            $name  = e($domain->name);

            $comment = ($domain->comments_enabled ?? true) ? $comments->get($domain->id) : null;
            if ($domain->strands->isEmpty()) {
                // Comment-only domain (e.g. Music, French, Class Teacher's Comment).
                if (! $comment) {
                    continue;
                }
                $teacher = $comment->teacher_name ? ' <span class="comment-teacher">— ' . e($comment->teacher_name) . '</span>' : '';
                $commentBoxesHtml .= '<div class="comment-block"><div class="comment-label" style="color:' . $color . ';">' . $name . $teacher . '</div>'
                    . '<div class="comment-box">' . nl2br(e($comment->comment)) . '</div></div>';
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
            if ($comment) {
                $domainsHtml .= '<div class="comment-block domain-comment"><div class="comment-label" style="color:' . $color . ';">' . $name . ' — comment'
                    . ($comment->teacher_name ? ' <span class="comment-teacher">— ' . e($comment->teacher_name) . '</span>' : '') . '</div><div class="comment-box">' . nl2br(e($comment->comment)) . '</div></div>';
            }
        }
        foreach ($extraComments as $x) {
            $commentBoxesHtml .= '<div class="comment-block"><div class="comment-label">' . e($x->subject)
                . ($x->teacher_name ? ' <span class="comment-teacher">— ' . e($x->teacher_name) . '</span>' : '') . '</div><div class="comment-box">' . nl2br(e($x->comment)) . '</div></div>';
        }
        $portfolioHtml = '';
        if ($portfolio->isNotEmpty()) {
            $cells = $portfolio->map(fn ($p) => '<td class="pf-cell"><div class="pf-frame"><img src="' . e((string) $this->resolveAssetUrl($p->image_url)) . '" alt=""></div>'
                . ($p->caption ? '<div class="pf-cap">' . e((string) $p->caption) . '</div>' : '') . '</td>')->values()->all();
            $rowsPf = '';
            foreach (array_chunk($cells, 3) as $chunk) {
                $rowsPf .= '<tr>' . implode('', $chunk) . str_repeat('<td class="pf-cell"></td>', 3 - count($chunk)) . '</tr>';
            }
            // Its own page at the end, like the schools' printed term portfolio.
            $portfolioHtml = '<div class="pf-page"><div class="pf-title">PORTFOLIO</div><p class="pf-intro">Here is an overview of our term in pictures.</p><table class="portfolio">' . $rowsPf . '</table></div>';
        }

        // Class teacher and head of school (with the school stamp) — no other signatories.
        $signatures = $signatures->toBase();
        $sigHtml = '';
        foreach (['class_teacher' => ['Class Teacher', ['class_teacher', 'form_teacher']], 'principal' => ['Head of School', ['principal', 'head_teacher', 'headteacher', 'head_of_school']]] as $role => [$label, $keys]) {
            $sig = collect($keys)->map(fn ($k) => $signatures[$k] ?? null)->filter()->first();
            $name = $sig?->name ?: ($role === 'class_teacher'
                ? (($ctid = $this->resolveClassTeacherId($student)) ? \App\Models\Teacher::with('user')->find($ctid)?->user?->name : null)
                : \App\Models\User::where('role', 'principal')->value('name'));
            $img = $sig?->signature_url ? '<img src="' . $sig->signature_url . '" style="max-height:40px;max-width:130px;" alt="">' : '<div class="sig-line"></div>';
            $sigHtml .= '<div class="sig-block">' . $img . '<div class="sig-name">' . e((string) $name) . '</div><div class="sig-role">' . $label . '</div></div>';
        }
        $stamp = $signatures['school_stamp'] ?? null;
        $sigHtml .= $stamp?->signature_url ? '<div class="sig-block"><img src="' . $stamp->signature_url . '" style="max-height:80px;max-width:110px;opacity:.85;transform:rotate(-8deg)" alt=""><div class="sig-role">School Stamp</div></div>' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Preschool Progress Report – {$studentName}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1a1a1a; padding: 14px 18px; line-height: 1.25; }
  .header { display: flex; align-items: center; gap: 12px; border-bottom: 3px solid #1a3a6b; padding-bottom: 8px; margin-bottom: 10px; }
  .header img { max-height: 52px; max-width: 52px; object-fit: contain; }
  .logo-fallback { width: 52px; height: 52px; border-radius: 50%; background: #1a3a6b; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: bold; }
  .header-text { flex: 1; }
  .header-text h1 { font-size: 16px; color: #1a3a6b; letter-spacing: 0.2px; }
  .header-text p.addr { font-size: 9px; color: #555; margin-top: 1px; }
  .header-text p.report-title { font-size: 10.5px; color: #1a3a6b; font-weight: 600; margin-top: 3px; }
  .badge { text-align: center; border: 2px solid #1a3a6b; border-radius: 6px; overflow: hidden; min-width: 80px; }
  .badge div { padding: 3px 8px; font-weight: bold; font-size: 10.5px; }
  .badge .b-class { background: #fff; color: #1a3a6b; }
  .badge .b-term { background: #1a3a6b; color: #fff; font-size: 9px; }

  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
  .panel { border: 1px solid #d5deec; border-radius: 6px; overflow: hidden; }
  .panel-head { background: #eef2fb; color: #1a3a6b; font-weight: 700; font-size: 9.5px; padding: 4px 10px; text-transform: uppercase; letter-spacing: 0.3px; }
  .panel-body { display: flex; align-items: center; gap: 10px; padding: 7px 10px; }
  .kv { width: 100%; }
  .kv tr td:nth-child(odd) { color: #667; font-size: 9px; padding: 2px 4px 2px 0; }
  .kv tr td:nth-child(even) { font-weight: 600; font-size: 10px; padding: 2px 8px 2px 0; }
  .kv.two-per-row { padding: 6px 10px; }
  .photo-wrap img, .photo-fallback { width: 56px; height: 56px; border-radius: 6px; object-fit: cover; border: 1px solid #d5deec; flex-shrink: 0; }
  .photo-fallback { display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #9aabc9; }
  .photo-fallback svg { width: 26px; height: 26px; }
  .muted { padding: 7px 10px; color: #999; font-size: 10px; }

  .section-head { background: #1a3a6b; color: #fff; font-weight: 700; font-size: 10.5px; padding: 4px 10px; margin: 10px 0 0; text-transform: uppercase; letter-spacing: 0.3px; border-radius: 5px 5px 0 0; }

  table.domain-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; border: 1px solid #d5deec; border-radius: 6px; overflow: hidden; }
  .domain-banner th { color: #fff; padding: 4px 8px; text-align: left; font-size: 10px; }
  .domain-banner th:not(:first-child) { text-align: center; width: 42px; }
  .strand-row td { background: #eef1f8; font-weight: 700; font-size: 9.5px; padding: 3px 8px; color: #445; }
  table.domain-table td { padding: 3px 8px; border-bottom: 1px solid #eef2fb; font-size: 10px; }
  .cp-cell { text-align: center; font-weight: 700; color: #1a3a6b; width: 42px; }
  table.domain-table tr:nth-child(even) td { background: #fafcff; }

  .grade-legend { display: flex; flex-wrap: wrap; gap: 6px 12px; background: #f7f9fd; border: 1px solid #d5deec; border-radius: 5px; padding: 5px 10px; font-size: 9px; color: #445; margin-bottom: 10px; align-items: center; }

  .comment-block { margin-bottom: 6px; }
  .comment-label { font-size: 9px; font-weight: 700; color: #1a3a6b; text-transform: uppercase; margin-bottom: 2px; }
  .comment-teacher { text-transform: none; font-weight: 400; color: #778; font-style: italic; }
  .comment-box { background: #fafcff; border: 1px dashed #b9c6de; padding: 5px 8px; border-radius: 4px; font-size: 10px; min-height: 12px; color: #333; }

  .signatures { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 12px; padding-top: 8px; border-top: 1px solid #d5deec; }
  .sig-block { text-align: center; min-width: 130px; }
  .sig-line { border-bottom: 1px solid #333; width: 130px; height: 30px; }
  .sig-name { font-size: 10px; font-weight: 600; margin-top: 2px; }
  .sig-role { font-size: 9px; color: #778; }
  .footer { text-align: center; margin-top: 8px; font-size: 8px; color: #aab; }
  .domain-comment { margin: -4px 0 10px; }
  .pf-page { page-break-before: always; padding-top: 6px; }
  .pf-title { font-family: Georgia, 'Times New Roman', serif; font-weight: 700; font-size: 15px; letter-spacing: 0.5px; margin-bottom: 14px; }
  .pf-intro { font-family: Georgia, 'Times New Roman', serif; font-size: 12.5px; margin: 0 0 22px; }
  table.portfolio { width: 100%; border-collapse: separate; border-spacing: 18px 30px; margin: 0 -18px; }
  td.pf-cell { width: 33%; vertical-align: top; text-align: center; }
  .pf-frame { border: 1.5px solid #111; padding: 6px; background: #fff; height: 178px; text-align: center; }
  .pf-frame img { width: 100%; height: 178px; object-fit: cover; display: block; }
  .pf-cap { font-size: 10.5px; color: #333; margin-top: 5px; }

  @media print { body { padding: 0 8px; } @page { size: A4; margin: 0.9cm; } }
</style>
</head>
<body>
<div class="header">
  {$logoHtml}
  <div class="header-text">
    <h1>{$schoolName}</h1>
    <p class="addr">{$schoolAddress}</p>
    <p class="report-title">Preschool Progress Report &nbsp;&middot;&nbsp; {$sessionLine}</p>
  </div>
  <div class="badge">
    <div class="b-class">{$className}</div>
    <div class="b-term">{$termName}</div>
  </div>
</div>

<div class="two-col">
  <div class="panel">
    <div class="panel-head">Student's Personal Data</div>
    <div class="panel-body">
      <div class="photo-wrap">{$photoHtml}</div>
      <table class="kv">
        <tr><td>Name</td><td>{$studentName}</td></tr>
        <tr><td>Admission No.</td><td>{$admissionNo}</td></tr>
        <tr><td>Date of Birth</td><td>{$dob}</td></tr>
        <tr><td>Class</td><td>{$className}</td></tr>
      </table>
    </div>
  </div>
  <div class="panel">
    {$vitalsHtml}
  </div>
</div>

{$memorableHtml}
<div class="section-head">Developmental Domains</div>
{$legendHtml}
{$domainsHtml}
{$commentBoxesHtml}
<div class="signatures">{$sigHtml}</div>
<div class="footer">Generated by Compasse</div>
{$portfolioHtml}
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
HTML;

        return response(\App\Support\SchoolColor::apply($html), 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Image fields (school logo, student photo) are stored either as a full
     * URL (S3 uploads) or a relative path on the local public disk — mirrors
     * ReportCardController::resolveAssetUrl().
     */
    private function resolveAssetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return \App\Support\TenantUrl::forPublicDiskKey($path);
    }

    /**
     * Lightweight summary of grades for a whole class × checkpoint.
     *
     * GET /checkpoint-report/class/{classId}
     *     ?config_id=&checkpoint_id=&academic_year_id=&arm_id=
     */
    public function classReport(Request $request, int $classId): JsonResponse
    {
        $mine = $this->accessibleClassIds(Auth::user());
        if ($mine !== null && ! in_array($classId, array_map('intval', $mine), true)) {
            return $this->forbiddenResponse('That is not one of your classes.');
        }
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
