<?php

namespace App\Support;

use App\Models\PsychomotorAssessment;
use App\Models\ResultConfiguration;
use App\Models\StudentResult;
use App\Models\Term;
use Illuminate\Support\Facades\DB;

class ResultReportBuilder
{
    public static function resolveConfigForClass(int $classId, int $schoolId): ?ResultConfiguration
    {
        $sectionType = DB::table('classes')->where('id', $classId)->value('section_type') ?? 'primary';

        return ResultConfiguration::resolveFor($schoolId, $sectionType, $classId);
    }

    /**
     * Resolve the next term start date for report cards when not manually set.
     */
    public static function resolveNextTermBegins(StudentResult $result): ?string
    {
        if ($result->next_term_begins) {
            return $result->next_term_begins->format('Y-m-d');
        }

        $currentTerm = Term::find($result->term_id);
        if (! $currentTerm) {
            return null;
        }

        $nextInYear = Term::where('academic_year_id', $result->academic_year_id)
            ->where('start_date', '>', $currentTerm->start_date)
            ->orderBy('start_date')
            ->first();

        if ($nextInYear?->start_date) {
            return $nextInYear->start_date->format('Y-m-d');
        }

        return null;
    }

    /**
     * Build a frontend-friendly result payload respecting section configuration.
     */
    public static function buildStudentPayload(
        StudentResult $result,
        ?PsychomotorAssessment $psychomotor = null,
        ?ResultConfiguration $config = null
    ): array {
        $config ??= self::resolveConfigForClass(
            (int) $result->class_id,
            (int) ($result->student?->school_id ?? 0)
        );

        $commentsOnly = $config?->isCommentsOnly() ?? false;
        $showScores   = ! $commentsOnly;
        $psychReport  = PsychomotorConfig::formatForReport($psychomotor, $config);

        $subjects = [];
        if ($showScores) {
            $subjects = $result->subjectResults->map(function ($sr) use ($config) {
                $row = [
                    'subject'     => $sr->subject,
                    'ca_score'    => round((float) $sr->ca_total, 2),
                    'exam_score'  => round((float) $sr->exam_score, 2),
                    'total_score' => round((float) $sr->total_score, 2),
                    'grade'       => $sr->grade,
                    'remark'      => $sr->teacher_remark,
                ];

                if ($config && ! $config->show_ca_breakdown) {
                    unset($row['ca_score'], $row['exam_score']);
                }

                if ($config && $config->show_subject_position) {
                    $row['position'] = $sr->position;
                }

                return $row;
            })->values()->all();
        }

        $commentFields = $config?->comment_fields ?? [
            ['key' => 'class_teacher_comment', 'label' => "Class Teacher's Comment"],
            ['key' => 'principal_comment', 'label' => "Principal's Comment"],
        ];

        $comments = [];
        foreach ($commentFields as $field) {
            $key = $field['key'] ?? null;
            if (! $key) {
                continue;
            }
            $comments[$key] = $result->{$key} ?? null;
        }

        $summary = [
            'total_subjects' => count($subjects),
            'average'        => $showScores ? round((float) $result->average_score, 2) : null,
            'class_position' => ($config && ! $config->show_position) ? null : $result->position,
            'class_size'     => $result->out_of,
            'class_average'  => ($config && ! $config->show_class_average) ? null : round((float) ($result->class_average ?? 0), 2),
            'grade'          => $result->grade,
        ];

        if ($config && $config->show_next_term_date) {
            $summary['next_term_begins'] = self::resolveNextTermBegins($result);
        }

        $psychomotorList = [];
        if ($psychReport) {
            foreach ($psychReport['skills'] as $skill) {
                $psychomotorList[] = [
                    'skill'  => $skill['label'],
                    'rating' => $skill['rating'],
                    'remark' => '',
                ];
            }
        }

        return [
            'id'          => $result->id,
            'result_type' => $result->result_type ?? 'end_term',
            'student'     => [
                'id'                => $result->student?->id,
                'name'              => $result->student?->user?->name ?? trim(($result->student?->first_name ?? '') . ' ' . ($result->student?->last_name ?? '')),
                'admission_number'  => $result->student?->admission_number,
                'class'             => $result->class?->name,
            ],
            'term'          => $result->term,
            'academic_year' => $result->academicYear,
            'subjects'      => $subjects,
            'summary'       => $summary,
            'psychomotor'   => $psychomotorList,
            'psychomotor_assessment' => $psychReport,
            'class_teacher_comment'  => $result->class_teacher_comment,
            'principal_comment'        => $result->principal_comment,
            'teacher_comment'          => $result->class_teacher_comment,
            'comments'                   => $comments,
            'comments_only'              => $commentsOnly,
            'configuration'              => $config ? [
                'grade_style'       => $config->grade_style,
                'show_psychomotor'  => $config->show_psychomotor,
                'show_affective'    => $config->show_affective,
                'show_next_term_date' => $config->show_next_term_date,
            ] : null,
            'status'        => $result->status,
        ];
    }
}
