<?php

namespace App\Http\Controllers;

use App\Models\ResultConfiguration;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Manage per-school, per-section result configurations.
 *
 * Only school admins (and above) may write; all staff can read.
 * Routes are under the tenant middleware so every query hits the tenant DB.
 */
class ResultConfigurationController extends Controller
{
    private const SECTION_TYPES = [
        'nursery', 'primary', 'junior_secondary', 'senior_secondary', 'tertiary', 'custom',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // READ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List all result configurations for this school.
     */
    public function index(Request $request): JsonResponse
    {
        $school  = $this->resolveSchool($request);
        $configs = ResultConfiguration::where('school_id', $school->id)
            ->with('gradingSystem')
            ->orderBy('section_type')
            ->get();

        return response()->json([
            'configurations' => $configs,
            'section_types'  => array_map(fn ($t) => [
                'value' => $t,
                'label' => ResultConfiguration::sectionLabel($t),
            ], self::SECTION_TYPES),
        ]);
    }

    /**
     * Get the section-level configuration for a specific section type
     * (class_id IS NULL — the fallback default, not any class override).
     */
    public function show(Request $request, string $sectionType): JsonResponse
    {
        $school = $this->resolveSchool($request);
        $config = ResultConfiguration::where('school_id', $school->id)
            ->where('section_type', $sectionType)
            ->whereNull('class_id')
            ->with('gradingSystem')
            ->first();

        if (! $config) {
            return response()->json([
                'configuration' => null,
                'preset'        => ResultConfiguration::defaultFor($sectionType, $school->id),
                'message'       => 'No configuration found. Use the preset to create one.',
            ]);
        }

        return response()->json(['configuration' => $config]);
    }

    /**
     * List the class-specific overrides that exist for a section (i.e. rows
     * with class_id set), for the admin UI's "class overrides" list.
     */
    public function classOverrides(Request $request, string $sectionType): JsonResponse
    {
        $school = $this->resolveSchool($request);
        $overrides = ResultConfiguration::where('school_id', $school->id)
            ->where('section_type', $sectionType)
            ->whereNotNull('class_id')
            ->with(['gradingSystem', 'class:id,name'])
            ->get();

        return response()->json(['overrides' => $overrides]);
    }

    /**
     * Get the result configuration that actually governs a class: a
     * class-specific override if one exists, otherwise the section default.
     */
    public function forClass(Request $request, int $classId): JsonResponse
    {
        $school     = $this->resolveSchool($request);
        $class      = DB::table('classes')->find($classId);

        if (! $class) {
            return response()->json(['error' => 'Class not found'], 404);
        }

        $sectionType = $class->section_type ?? 'primary';
        $config = ResultConfiguration::resolveFor($school->id, $sectionType, $classId)?->load('gradingSystem');

        return response()->json([
            'class'          => $class,
            'section_type'   => $sectionType,
            'configuration'  => $config,
            'is_override'    => $config?->class_id === $classId,
            // Remarks-only set-ups still keep scores for a class whose teachers entered them.
            'comments_only'  => (bool) $config?->isCommentsOnly(),
            'preset'         => $config ? null : ResultConfiguration::defaultFor($sectionType, $school->id),
        ]);
    }

    /**
     * Return all available presets (useful for onboarding wizard).
     */
    public function presets(): JsonResponse
    {
        $presets = [];
        foreach (self::SECTION_TYPES as $type) {
            $presets[$type] = array_merge(
                ResultConfiguration::defaultFor($type, 0),
                ['label' => ResultConfiguration::sectionLabel($type)]
            );
        }
        return response()->json(['presets' => $presets]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WRITE (school_admin / principal / vice_principal)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new result configuration.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_type'           => ['required', 'in:' . implode(',', self::SECTION_TYPES)],
            'class_id'               => ['nullable', 'integer', 'exists:classes,id'],
            'name'                   => ['required', 'string', 'max:120'],
            'ca_weight'              => ['required', 'numeric', 'min:0', 'max:100'],
            'exam_weight'            => ['required', 'numeric', 'min:0', 'max:100'],
            'pass_mark'              => ['required', 'numeric', 'min:0', 'max:100'],
            'assessment_components'  => ['nullable', 'array'],
            'assessment_components.*.name'   => ['required_with:assessment_components', 'string'],
            'assessment_components.*.weight' => ['required_with:assessment_components', 'numeric', 'min:0'],
            'assessment_components.*.max'    => ['required_with:assessment_components', 'numeric', 'min:0'],
            'grade_style'            => ['nullable', 'in:letters,percentage,gpa,remarks_only'],
            'remark_bands'           => ['nullable', 'array'],
            'remark_bands.*.min'     => ['required_with:remark_bands', 'numeric'],
            'remark_bands.*.max'     => ['required_with:remark_bands', 'numeric'],
            'remark_bands.*.remark'  => ['required_with:remark_bands', 'string'],
            'show_position'          => ['boolean'],
            'show_class_average'     => ['boolean'],
            'show_subject_position'  => ['boolean'],
            'show_ca_breakdown'      => ['boolean'],
            'show_psychomotor'       => ['boolean'],
            'show_affective'         => ['boolean'],
            'show_attendance'        => ['boolean'],
            'show_next_term_date'    => ['boolean'],
            'comment_fields'         => ['nullable', 'array'],
            'grading_system_id'      => ['nullable', 'integer', 'exists:grading_systems,id'],
            'report_template'        => ['nullable', 'in:basic,standard,detailed,checkpoint'],
            'custom_settings'        => ['nullable', 'array'],
        ]);

        $school = $this->resolveSchool($request);

        $classId = $validated['class_id'] ?? null;
        $exists = ResultConfiguration::where('school_id', $school->id)
            ->where('section_type', $validated['section_type'])
            ->when($classId, fn ($q) => $q->where('class_id', $classId), fn ($q) => $q->whereNull('class_id'))
            ->exists();
        if ($exists) {
            return response()->json([
                'error'   => 'Validation failed',
                'message' => $classId
                    ? 'A result configuration already exists for this class. Edit it instead of creating a new one.'
                    : 'A section-level result configuration already exists for this section type.',
            ], 422);
        }

        // Weight validation
        $caW   = (float) ($validated['ca_weight']   ?? 0);
        $examW = (float) ($validated['exam_weight']  ?? 0);
        if (abs($caW + $examW - 100.0) > 0.01) {
            return response()->json([
                'error'   => 'Validation failed',
                'message' => 'ca_weight + exam_weight must equal 100.',
            ], 422);
        }

        // Component weight validation
        if (! empty($validated['assessment_components'])) {
            $compSum = array_sum(array_column($validated['assessment_components'], 'weight'));
            if (abs($compSum - $caW) > 0.01) {
                return response()->json([
                    'error'   => 'Validation failed',
                    'message' => "assessment_components weights must sum to ca_weight ({$caW}). Got {$compSum}.",
                ], 422);
            }
        }

        $config = ResultConfiguration::create(array_merge($validated, [
            'school_id' => $school->id,
        ]));

        $this->clearConfigCache($school->id);

        return response()->json(['configuration' => $config->load('gradingSystem')], 201);
    }

    /**
     * Update an existing result configuration.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $school = $this->resolveSchool($request);
        $config = ResultConfiguration::where('id', $id)
            ->where('school_id', $school->id)
            ->firstOrFail();

        $validated = $request->validate([
            'name'                   => ['sometimes', 'string', 'max:120'],
            'ca_weight'              => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'exam_weight'            => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'pass_mark'              => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'assessment_components'  => ['nullable', 'array'],
            'assessment_components.*.name'   => ['required_with:assessment_components', 'string'],
            'assessment_components.*.weight' => ['required_with:assessment_components', 'numeric', 'min:0'],
            'assessment_components.*.max'    => ['required_with:assessment_components', 'numeric', 'min:0'],
            'grade_style'            => ['nullable', 'in:letters,percentage,gpa,remarks_only'],
            'remark_bands'           => ['nullable', 'array'],
            'remark_bands.*.min'     => ['required_with:remark_bands', 'numeric'],
            'remark_bands.*.max'     => ['required_with:remark_bands', 'numeric'],
            'remark_bands.*.remark'  => ['required_with:remark_bands', 'string'],
            'show_position'          => ['boolean'],
            'show_class_average'     => ['boolean'],
            'show_subject_position'  => ['boolean'],
            'show_ca_breakdown'      => ['boolean'],
            'show_psychomotor'       => ['boolean'],
            'show_affective'         => ['boolean'],
            'show_attendance'        => ['boolean'],
            'show_next_term_date'    => ['boolean'],
            'comment_fields'         => ['nullable', 'array'],
            'grading_system_id'      => ['nullable', 'integer', 'exists:grading_systems,id'],
            'report_template'        => ['nullable', 'in:basic,standard,detailed,checkpoint'],
            'custom_settings'        => ['nullable', 'array'],
            'is_active'              => ['boolean'],
        ]);

        // Re-validate weights only if either was changed
        $caW   = (float) ($validated['ca_weight']  ?? $config->ca_weight);
        $examW = (float) ($validated['exam_weight'] ?? $config->exam_weight);
        if (abs($caW + $examW - 100.0) > 0.01) {
            return response()->json([
                'error'   => 'Validation failed',
                'message' => 'ca_weight + exam_weight must equal 100.',
            ], 422);
        }

        if (! empty($validated['assessment_components'])) {
            $compSum = array_sum(array_column($validated['assessment_components'], 'weight'));
            if (abs($compSum - $caW) > 0.01) {
                return response()->json([
                    'error'   => 'Validation failed',
                    'message' => "assessment_components weights must sum to ca_weight ({$caW}). Got {$compSum}.",
                ], 422);
            }
        }

        $config->update($validated);
        $this->clearConfigCache($school->id);

        return response()->json(['configuration' => $config->fresh()->load('gradingSystem')]);
    }

    /**
     * Apply a preset to create or overwrite a configuration.
     */
    public function applyPreset(Request $request, string $sectionType): JsonResponse
    {
        $school  = $this->resolveSchool($request);
        $classId = $request->integer('class_id') ?: null;
        $preset  = ResultConfiguration::defaultFor($sectionType, $school->id);
        if ($classId) {
            $preset['class_id'] = $classId;
        }

        $config = ResultConfiguration::updateOrCreate(
            ['school_id' => $school->id, 'section_type' => $sectionType, 'class_id' => $classId],
            $preset
        );

        $this->clearConfigCache($school->id);

        return response()->json([
            'configuration' => $config->fresh()->load('gradingSystem'),
            'message'       => 'Preset applied successfully.',
        ]);
    }

    /**
     * Delete a configuration.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $school = $this->resolveSchool($request);
        $config = ResultConfiguration::where('id', $id)
            ->where('school_id', $school->id)
            ->firstOrFail();

        $config->delete();
        $this->clearConfigCache($school->id);

        return response()->json(['message' => 'Result configuration deleted.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveSchool(Request $request): School
    {
        $school = $request->attributes->get('school')
            ?? School::first();

        if (! $school) {
            abort(422, 'School context not found.');
        }

        return $school;
    }

    private function clearConfigCache(int $schoolId): void
    {
        try {
            $db = DB::connection()->getDatabaseName();
            Cache::forget("result_config:school:{$schoolId}:{$db}");
        } catch (\Throwable $ignored) {}
    }

    /**
     * POST /result-configurations/{id}/apply-columns {term_id?}
     * Every subject's mark sheet in the section (or the class, for a class's own set-up) takes this
     * set-up's CA columns and exam marks for the term — one pattern for all, as the school sets it.
     * Scores are not changed; any now above the new maximum are listed for the school to correct.
     */
    public function applyColumns(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        if (! \App\Http\Controllers\MarkSheetController::isLeadership($u)) {
            return response()->json(['message' => 'Only the school administrator can do this.'], 403);
        }
        $cfg = ResultConfiguration::find($id);
        if (! $cfg) {
            return response()->json(['message' => 'Set-up not found'], 404);
        }
        $termId = $request->integer('term_id') ?: DB::table('terms')->where('is_current', true)->value('id');
        $yearId = DB::table('terms')->where('id', $termId)->value('academic_year_id') ?? DB::table('academic_years')->where('is_current', true)->value('id');
        if (! $termId) {
            return response()->json(['message' => 'Choose a term.'], 422);
        }
        $caWeight = (float) $cfg->ca_weight;
        $examWeight = (float) $cfg->exam_weight;
        $components = array_values(array_filter((array) ($cfg->assessment_components ?: []), fn ($c) => ! empty($c['name'] ?? null)));
        if (! $components && $caWeight > 0) {
            $components = [['name' => '1st CA', 'max' => $caWeight / 2], ['name' => '2nd CA', 'max' => $caWeight / 2]];
        }
        $components = array_map(fn ($c) => ['name' => (string) $c['name'], 'max' => (float) ($c['max'] ?? $c['weight'] ?? 0)], $components);

        // Classes this set-up governs: its own class, else the section's classes without a set-up of their own.
        $classIds = $cfg->class_id
            ? [(int) $cfg->class_id]
            : DB::table('classes')->where('section_type', $cfg->section_type)->pluck('id')
                ->reject(fn ($cid) => ResultConfiguration::where('class_id', $cid)->where('is_active', true)->where('section_type', $cfg->section_type)->exists())
                ->map(fn ($x) => (int) $x)->values()->all();

        if (! $classIds) {
            return response()->json(['message' => "No class uses \"{$cfg->name}\" yet, so nothing was changed. Choose its classes on its card first."], 422);
        }

        $hasMid = \Illuminate\Support\Facades\Schema::hasColumn('continuous_assessments', 'counts_for_mid_term');
        $updated = 0;
        $problems = [];
        DB::transaction(function () use ($classIds, $termId, $yearId, $components, $examWeight, $hasMid, &$updated, &$problems) {
            foreach (DB::table('subjects')->whereIn('class_id', $classIds)->get(['id', 'name', 'class_id']) as $sub) {
                $className = DB::table('classes')->where('id', $sub->class_id)->value('name');
                $cas = DB::table('continuous_assessments')->where('class_id', $sub->class_id)->where('subject_id', $sub->id)
                    ->where('term_id', $termId)->orderBy('assessment_date')->orderBy('id')->get();
                if ($cas->isEmpty()) {
                    continue; // a new sheet takes the set-up's columns when first opened
                }
                foreach ($components as $i => $c) {
                    $ca = $cas[$i] ?? null;
                    if (! $ca) {
                        DB::table('continuous_assessments')->insert([
                            'school_id' => $cas[0]->school_id, 'subject_id' => $sub->id, 'class_id' => $sub->class_id, 'term_id' => $termId,
                            'academic_year_id' => $yearId, 'teacher_id' => $cas[0]->teacher_id, 'name' => $c['name'], 'type' => 'test',
                            'total_marks' => $c['max'], 'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
                        ] + ($hasMid ? ['counts_for_mid_term' => $i === 0] : []));
                        continue;
                    }
                    if ((float) $ca->total_marks !== $c['max'] || $ca->name !== $c['name']) {
                        DB::table('continuous_assessments')->where('id', $ca->id)->update(['name' => $c['name'], 'total_marks' => $c['max'], 'updated_at' => now()]);
                        $updated++;
                    }
                    $over = DB::table('ca_scores')->where('continuous_assessment_id', $ca->id)->where('score', '>', $c['max'])->count();
                    if ($over) {
                        $problems[] = "{$sub->name} ({$className}) {$c['name']}: {$over} score(s) above {$c['max']}";
                    }
                }
                // Extra CA columns beyond the set-up: removed when no pupil still in the school has a
                // score in it (a deleted test pupil's score kept a stray "CA1 /10" column, which put the
                // whole class's subject out of step with the set-up), else listed.
                foreach ($cas->slice(count($components)) as $extra) {
                    $liveScores = DB::table('ca_scores')->join('students', 'students.id', '=', 'ca_scores.student_id')
                        ->where('ca_scores.continuous_assessment_id', $extra->id)->whereNull('students.deleted_at')->exists();
                    if ($liveScores) {
                        $problems[] = "{$sub->name} ({$className}): extra column \"{$extra->name}\" has scores — move them, then delete it";
                    } else {
                        DB::table('ca_scores')->where('continuous_assessment_id', $extra->id)->delete();
                        DB::table('continuous_assessments')->where('id', $extra->id)->delete();
                        $updated++;
                    }
                }
                // Theory exam marks: the exam share less any CBT paper.
                $exams = DB::table('exams')->where('class_id', $sub->class_id)->where('subject_id', $sub->id)->where('term_id', $termId)
                    ->whereNotIn('status', ['draft', 'cancelled'])->where(fn ($q) => $q->whereNull('type')->orWhereIn('type', ['exam', 'test']))->get();
                $cbt = (float) $exams->where('is_cbt', true)->sum('total_marks');
                foreach ($exams->where('is_cbt', false)->take(1) as $ex) {
                    $max = $cbt > 0 ? max($examWeight - $cbt, 10) : $examWeight;
                    if ($max > 0 && (float) $ex->total_marks !== $max) {
                        DB::table('exams')->where('id', $ex->id)->update(['total_marks' => $max, 'passing_marks' => round($max / 2, 2), 'updated_at' => now()]);
                        $updated++;
                    }
                    $over = \Illuminate\Support\Facades\Schema::hasTable('exam_submissions')
                        ? DB::table('exam_submissions')->where('exam_id', $ex->id)->where('score', '>', $max)->count() : 0;
                    if ($over) {
                        $problems[] = "{$sub->name} ({$className}) exam: {$over} score(s) above {$max}";
                    }
                }
            }
        });
        \App\Support\AuditLog::record('result_configuration', $cfg->id, 'columns_applied', "Applied the set-up's marks to every subject ({$cfg->name})");

        return response()->json([
            'message' => "Every subject now uses this set-up's columns ({$updated} change(s))." . ($problems ? ' Check the scores listed below.' : ''),
            'changes' => $updated, 'problems' => array_slice($problems, 0, 100),
        ]);
    }

    /**
     * PUT /result-configurations/{id}/classes {class_ids: []} — the classes that use this set-up:
     * they move into its section (e.g. RECEPTION into the "Reception Class" set-up), so different
     * nursery classes can each have their own result and Preschool Progress Report set-up.
     */
    public function assignClasses(Request $request, int $id): JsonResponse
    {
        if (! \App\Http\Controllers\MarkSheetController::isLeadership($request->user())) {
            return response()->json(['message' => 'Only the school administrator can do this.'], 403);
        }
        $cfg = ResultConfiguration::find($id);
        if (! $cfg || $cfg->class_id) {
            return response()->json(['message' => 'Choose a section set-up.'], 422);
        }
        $ids = array_map('intval', (array) $request->input('class_ids', []));
        DB::table('classes')->whereIn('id', $ids)->update(['section_type' => $cfg->section_type, 'updated_at' => now()]);
        Cache::flush();
        \App\Support\AuditLog::record('result_configuration', $cfg->id, 'classes_assigned', 'Classes using "' . $cfg->name . '": '
            . DB::table('classes')->whereIn('id', $ids)->pluck('name')->implode(', '));

        return response()->json(['message' => 'Saved', 'classes' => DB::table('classes')->where('section_type', $cfg->section_type)->get(['id', 'name'])]);
    }
}
