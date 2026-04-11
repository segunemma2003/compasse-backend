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
     * Get the configuration for a specific section type.
     */
    public function show(Request $request, string $sectionType): JsonResponse
    {
        $school = $this->resolveSchool($request);
        $config = ResultConfiguration::where('school_id', $school->id)
            ->where('section_type', $sectionType)
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
     * Get the result configuration for a specific class (resolved via section_type).
     */
    public function forClass(Request $request, int $classId): JsonResponse
    {
        $school     = $this->resolveSchool($request);
        $class      = DB::table('classes')->find($classId);

        if (! $class) {
            return response()->json(['error' => 'Class not found'], 404);
        }

        $sectionType = $class->section_type ?? 'primary';
        $config = ResultConfiguration::where('school_id', $school->id)
            ->where('section_type', $sectionType)
            ->where('is_active', true)
            ->with('gradingSystem')
            ->first();

        return response()->json([
            'class'          => $class,
            'section_type'   => $sectionType,
            'configuration'  => $config,
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
            'report_template'        => ['nullable', 'in:basic,standard,detailed'],
            'custom_settings'        => ['nullable', 'array'],
        ]);

        $school = $this->resolveSchool($request);

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
            'report_template'        => ['nullable', 'in:basic,standard,detailed'],
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
        $preset  = ResultConfiguration::defaultFor($sectionType, $school->id);

        $config = ResultConfiguration::updateOrCreate(
            ['school_id' => $school->id, 'section_type' => $sectionType],
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
}
