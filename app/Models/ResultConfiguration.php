<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultConfiguration extends Model
{
    protected $fillable = [
        'school_id',
        'section_type',
        'name',
        'ca_weight',
        'exam_weight',
        'pass_mark',
        'assessment_components',
        'grade_style',
        'remark_bands',
        'show_position',
        'show_class_average',
        'show_subject_position',
        'show_ca_breakdown',
        'show_psychomotor',
        'show_affective',
        'show_attendance',
        'show_next_term_date',
        'comment_fields',
        'grading_system_id',
        'report_template',
        'custom_settings',
        'is_active',
    ];

    protected $casts = [
        'ca_weight'             => 'float',
        'exam_weight'           => 'float',
        'pass_mark'             => 'float',
        'assessment_components' => 'array',
        'remark_bands'          => 'array',
        'show_position'         => 'boolean',
        'show_class_average'    => 'boolean',
        'show_subject_position' => 'boolean',
        'show_ca_breakdown'     => 'boolean',
        'show_psychomotor'      => 'boolean',
        'show_affective'        => 'boolean',
        'show_attendance'       => 'boolean',
        'show_next_term_date'   => 'boolean',
        'comment_fields'        => 'array',
        'custom_settings'       => 'array',
        'is_active'             => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function gradingSystem(): BelongsTo
    {
        return $this->belongsTo(GradingSystem::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ResultDomain::class)->orderBy('display_order');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(ResultCheckpoint::class)->orderBy('display_order');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Validate that ca_weight + exam_weight == 100.
     */
    public function weightsAreValid(): bool
    {
        return abs(($this->ca_weight + $this->exam_weight) - 100.0) < 0.01;
    }

    /**
     * Nursery / comment-only sections: remarks without numeric subject scores.
     */
    public function isCommentsOnly(): bool
    {
        if ($this->grade_style !== 'remarks_only') {
            return false;
        }

        return ! (bool) ($this->custom_settings['require_subject_scores'] ?? false);
    }

    /**
     * Whether psychomotor ratings are optional (comment-only assessments allowed).
     */
    public function psychomotorRatingsRequired(): bool
    {
        return (bool) ($this->custom_settings['psychomotor_ratings_required'] ?? true);
    }

    /**
     * Validate that assessment_components weights sum to ca_weight.
     */
    public function componentsAreValid(): bool
    {
        if (empty($this->assessment_components)) {
            return true; // no breakdown required
        }
        $sum = array_sum(array_column($this->assessment_components, 'weight'));
        return abs($sum - $this->ca_weight) < 0.01;
    }

    /**
     * Return the human-readable label for a section type.
     */
    public static function sectionLabel(string $type): string
    {
        return match ($type) {
            'nursery'           => 'Nursery / Crèche',
            'primary'           => 'Primary School',
            'junior_secondary'  => 'Junior Secondary (JSS)',
            'senior_secondary'  => 'Senior Secondary (SSS)',
            'tertiary'          => 'Tertiary',
            default             => 'Custom',
        };
    }

    /**
     * Default configuration templates per section type.
     * School admins can start from one of these presets.
     */
    public static function defaultFor(string $sectionType, int $schoolId): array
    {
        $base = [
            'school_id'    => $schoolId,
            'section_type' => $sectionType,
            'is_active'    => true,
        ];

        return match ($sectionType) {
            'nursery' => array_merge($base, [
                'name'         => 'Nursery Section',
                'ca_weight'    => 100,
                'exam_weight'  => 0,
                'pass_mark'    => 0,
                'grade_style'  => 'remarks_only',
                'remark_bands' => [
                    ['min' => 80, 'max' => 100, 'remark' => 'Excellent'],
                    ['min' => 60, 'max' => 79,  'remark' => 'Very Good'],
                    ['min' => 40, 'max' => 59,  'remark' => 'Good'],
                    ['min' => 0,  'max' => 39,  'remark' => 'Needs Improvement'],
                ],
                'show_position'         => false,
                'show_subject_position' => false,
                'show_ca_breakdown'     => false,
                'show_psychomotor'      => true,
                'show_affective'        => true,
                'show_attendance'       => false,
                'show_next_term_date'   => true,
                'report_template'       => 'basic',
                'assessment_components' => [
                    ['name' => 'Continuous Assessment', 'weight' => 100, 'max' => 100],
                ],
                'comment_fields' => [
                    ['key' => 'class_teacher_comment', 'label' => "Class Teacher's Remark", 'required' => true],
                    ['key' => 'principal_comment',     'label' => "Head Teacher's Remark",  'required' => false],
                ],
                'custom_settings' => [
                    'require_subject_scores'         => false,
                    'psychomotor_ratings_required'   => false,
                ],
            ]),

            'primary' => array_merge($base, [
                'name'         => 'Primary School Section',
                'ca_weight'    => 40,
                'exam_weight'  => 60,
                'pass_mark'    => 50,
                'grade_style'  => 'letters',
                'show_position'         => true,
                'show_subject_position' => false,
                'show_ca_breakdown'     => true,
                'show_psychomotor'      => true,
                'show_affective'        => true,
                'report_template'       => 'standard',
                'assessment_components' => [
                    ['name' => '1st CA Test',  'weight' => 10, 'max' => 10],
                    ['name' => '2nd CA Test',  'weight' => 10, 'max' => 10],
                    ['name' => 'Assignment',   'weight' => 10, 'max' => 10],
                    ['name' => 'Classwork',    'weight' => 10, 'max' => 10],
                ],
                'comment_fields' => [
                    ['key' => 'class_teacher_comment', 'label' => "Class Teacher's Comment", 'required' => true],
                    ['key' => 'principal_comment',     'label' => "Head Teacher's Comment",  'required' => false],
                ],
            ]),

            'junior_secondary' => array_merge($base, [
                'name'         => 'Junior Secondary Section (JSS)',
                'ca_weight'    => 40,
                'exam_weight'  => 60,
                'pass_mark'    => 50,
                'grade_style'  => 'letters',
                'show_position'         => true,
                'show_subject_position' => true,
                'show_ca_breakdown'     => true,
                'show_psychomotor'      => true,
                'show_affective'        => true,
                'report_template'       => 'standard',
                'assessment_components' => [
                    ['name' => '1st CA Test',  'weight' => 15, 'max' => 15],
                    ['name' => '2nd CA Test',  'weight' => 15, 'max' => 15],
                    ['name' => 'Assignment',   'weight' => 10, 'max' => 10],
                ],
                'comment_fields' => [
                    ['key' => 'class_teacher_comment', 'label' => "Form Teacher's Comment",  'required' => true],
                    ['key' => 'principal_comment',     'label' => "Principal's Comment",     'required' => false],
                ],
            ]),

            'senior_secondary' => array_merge($base, [
                'name'         => 'Senior Secondary Section (SSS)',
                'ca_weight'    => 30,
                'exam_weight'  => 70,
                'pass_mark'    => 50,
                'grade_style'  => 'letters',
                'show_position'         => true,
                'show_subject_position' => true,
                'show_ca_breakdown'     => true,
                'show_psychomotor'      => false,
                'show_affective'        => false,
                'report_template'       => 'detailed',
                'assessment_components' => [
                    ['name' => '1st CA Test',  'weight' => 10, 'max' => 10],
                    ['name' => '2nd CA Test',  'weight' => 10, 'max' => 10],
                    ['name' => 'Practical/Project', 'weight' => 10, 'max' => 10],
                ],
                'comment_fields' => [
                    ['key' => 'class_teacher_comment', 'label' => "Form Teacher's Comment",  'required' => true],
                    ['key' => 'principal_comment',     'label' => "Principal's Comment",     'required' => true],
                ],
            ]),

            default => array_merge($base, [
                'name'         => 'Custom Section',
                'ca_weight'    => 40,
                'exam_weight'  => 60,
                'pass_mark'    => 50,
                'grade_style'  => 'letters',
                'report_template' => 'standard',
            ]),
        };
    }

    /**
     * Whether this configuration uses the checkpoint/competency pattern
     * (early-years style with domains → strands → indicators × checkpoints).
     */
    public function isCheckpointPattern(): bool
    {
        return $this->report_template === 'checkpoint';
    }

    /**
     * Default checkpoint grade scale — used when creating a new checkpoint config.
     * Admin can override via custom_settings.checkpoint_grade_scale.
     */
    public static function defaultCheckpointGradeScale(): array
    {
        return [
            ['code' => 'B', 'label' => 'Beginning',  'description' => 'Child is at the beginning of this skill'],
            ['code' => 'E', 'label' => 'Expected',    'description' => 'Child meets the expected level'],
            ['code' => 'P', 'label' => 'Practicing',  'description' => 'Child is actively practicing this skill'],
            ['code' => 'C', 'label' => 'Confident',   'description' => 'Child demonstrates this skill confidently'],
        ];
    }
}
