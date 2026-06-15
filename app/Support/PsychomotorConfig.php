<?php

namespace App\Support;

use App\Models\PsychomotorAssessment;
use App\Models\ResultConfiguration;

class PsychomotorConfig
{
    /** @var array<string, string> */
    public const BUILTIN_PSYCHOMOTOR = [
        'handwriting'    => 'Handwriting',
        'drawing'        => 'Drawing',
        'sports'         => 'Sports',
        'musical_skills' => 'Musical Skills',
        'handling_tools' => 'Handling Tools',
    ];

    /** @var array<string, string> */
    public const BUILTIN_AFFECTIVE = [
        'punctuality'               => 'Punctuality',
        'neatness'                  => 'Neatness',
        'politeness'                => 'Politeness',
        'honesty'                   => 'Honesty',
        'relationship_with_others'  => 'Relationship with Others',
        'self_control'              => 'Self Control',
        'attentiveness'             => 'Attentiveness',
        'perseverance'              => 'Perseverance',
        'emotional_stability'       => 'Emotional Stability',
    ];

    /**
     * @return list<array{key: string, label: string, builtin: bool, enabled: bool}>
     */
    public static function psychomotorSkills(?ResultConfiguration $config): array
    {
        return self::resolveSkillList(
            $config,
            'psychomotor_skills',
            self::BUILTIN_PSYCHOMOTOR
        );
    }

    /**
     * @return list<array{key: string, label: string, builtin: bool, enabled: bool}>
     */
    public static function affectiveTraits(?ResultConfiguration $config): array
    {
        return self::resolveSkillList(
            $config,
            'affective_traits',
            self::BUILTIN_AFFECTIVE
        );
    }

    /**
     * @return list<array{key: string, label: string, builtin: bool, enabled: bool}>
     */
    private static function resolveSkillList(
        ?ResultConfiguration $config,
        string $settingsKey,
        array $builtins
    ): array {
        $configured = $config?->custom_settings[$settingsKey] ?? null;

        if (is_array($configured) && ! empty($configured)) {
            return array_values(array_map(function ($item) use ($builtins) {
                $key = (string) ($item['key'] ?? '');

                return [
                    'key'     => $key,
                    'label'   => (string) ($item['label'] ?? $builtins[$key] ?? ucwords(str_replace('_', ' ', $key))),
                    'builtin' => array_key_exists($key, $builtins),
                    'enabled' => (bool) ($item['enabled'] ?? true),
                ];
            }, $configured));
        }

        return array_map(
            fn (string $label, string $key) => [
                'key'     => $key,
                'label'   => $label,
                'builtin' => true,
                'enabled' => true,
            ],
            $builtins,
            array_keys($builtins)
        );
    }

    /**
     * Build validated assessment payload from request input.
     *
     * @return array<string, mixed>
     */
    public static function parseInput(array $input, ?ResultConfiguration $config): array
    {
        $data = [
            'teacher_comment' => $input['teacher_comment'] ?? null,
            'custom_psychomotor' => [],
            'custom_affective' => [],
        ];

        foreach (self::psychomotorSkills($config) as $skill) {
            if (! ($skill['enabled'] ?? true)) {
                continue;
            }

            $key = $skill['key'];
            if (! array_key_exists($key, $input) && ! array_key_exists($key, $input['custom_psychomotor'] ?? [])) {
                continue;
            }

            $value = $input[$key] ?? ($input['custom_psychomotor'][$key] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            $rating = (int) $value;
            if ($rating < 1 || $rating > 5) {
                continue;
            }

            if ($skill['builtin']) {
                $data[$key] = $rating;
            } else {
                $data['custom_psychomotor'][$key] = $rating;
            }
        }

        foreach (self::affectiveTraits($config) as $trait) {
            if (! ($trait['enabled'] ?? true)) {
                continue;
            }

            $key = $trait['key'];
            if (! array_key_exists($key, $input) && ! array_key_exists($key, $input['custom_affective'] ?? [])) {
                continue;
            }

            $value = $input[$key] ?? ($input['custom_affective'][$key] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            $rating = (int) $value;
            if ($rating < 1 || $rating > 5) {
                continue;
            }

            if ($trait['builtin']) {
                $data[$key] = $rating;
            } else {
                $data['custom_affective'][$key] = $rating;
            }
        }

        if (empty($data['custom_psychomotor'])) {
            $data['custom_psychomotor'] = null;
        }

        if (empty($data['custom_affective'])) {
            $data['custom_affective'] = null;
        }

        return $data;
    }

    /**
     * @return array{skills: list<array{key: string, label: string, rating: int|null}>, affective: list<array{key: string, label: string, rating: int|null}>, teacher_comment: string|null}|null
     */
    public static function formatForReport(
        ?PsychomotorAssessment $assessment,
        ?ResultConfiguration $config
    ): ?array {
        if (! $assessment) {
            return null;
        }

        $showPsychomotor = $config?->show_psychomotor ?? true;
        $showAffective   = $config?->show_affective ?? true;

        if (! $showPsychomotor && ! $showAffective) {
            return null;
        }

        $payload = [
            'skills'           => [],
            'affective'        => [],
            'teacher_comment'  => $assessment->teacher_comment,
        ];

        if ($showPsychomotor) {
            foreach (self::psychomotorSkills($config) as $skill) {
                if (! ($skill['enabled'] ?? true)) {
                    continue;
                }

                $rating = self::readRating($assessment, $skill['key'], 'psychomotor');
                if ($rating !== null) {
                    $payload['skills'][] = [
                        'key'    => $skill['key'],
                        'label'  => $skill['label'],
                        'rating' => $rating,
                    ];
                }
            }
        }

        if ($showAffective) {
            foreach (self::affectiveTraits($config) as $trait) {
                if (! ($trait['enabled'] ?? true)) {
                    continue;
                }

                $rating = self::readRating($assessment, $trait['key'], 'affective');
                if ($rating !== null) {
                    $payload['affective'][] = [
                        'key'    => $trait['key'],
                        'label'  => $trait['label'],
                        'rating' => $rating,
                    ];
                }
            }
        }

        if (
            empty($payload['skills'])
            && empty($payload['affective'])
            && empty($payload['teacher_comment'])
        ) {
            return null;
        }

        return $payload;
    }

    private static function readRating(
        PsychomotorAssessment $assessment,
        string $key,
        string $domain
    ): ?int {
        if (array_key_exists($key, PsychomotorConfig::BUILTIN_PSYCHOMOTOR) && $domain === 'psychomotor') {
            $value = $assessment->{$key};

            return $value !== null ? (int) $value : null;
        }

        if (array_key_exists($key, PsychomotorConfig::BUILTIN_AFFECTIVE) && $domain === 'affective') {
            $value = $assessment->{$key};

            return $value !== null ? (int) $value : null;
        }

        $custom = $domain === 'psychomotor'
            ? ($assessment->custom_psychomotor ?? [])
            : ($assessment->custom_affective ?? []);

        if (! is_array($custom) || ! array_key_exists($key, $custom)) {
            return null;
        }

        return (int) $custom[$key];
    }
}
