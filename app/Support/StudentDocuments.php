<?php

namespace App\Support;

use App\Models\School;
use App\Models\Student;
use App\Models\StudentResult;
use Illuminate\Support\Facades\DB;

/**
 * Documents the school prints for any student: an academic transcript (every term's end-of-term
 * result) and a letter of recommendation. Printed from the browser (or saved as PDF).
 */
class StudentDocuments
{
    public const DEFAULT_LETTER = "To Whom It May Concern,\n\n"
        . "This is to confirm that {name} (Admission No. {admission_number}) has been a student of {school} since {admitted}{current_class}.\n\n"
        . "During this time {first_name} has been a diligent and well-behaved student, has taken an active part in the life of the school and has related well with teachers and fellow students.\n\n"
        . "We are pleased to recommend {first_name} without reservation, and we believe {first_name} will be an asset to any institution or organisation.\n\n"
        . "Please contact the school if you need any further information.";

    /** Placeholders for the letter text. */
    public static function values(Student $s, ?School $school): array
    {
        $class = $s->class?->name;
        $arm = $s->arm?->name;

        return [
            '{name}' => implode(' ', array_filter(array_map('trim', [(string) $s->first_name, (string) $s->middle_name, (string) $s->last_name]))),
            '{first_name}' => (string) $s->first_name,
            '{last_name}' => (string) $s->last_name,
            '{admission_number}' => (string) ($s->admission_number ?? ''),
            '{school}' => (string) ($school?->name ?? 'the school'),
            '{admitted}' => $s->admission_date ? $s->admission_date->format('F Y') : ($s->created_at?->format('F Y') ?? ''),
            '{class}' => trim(($class ?? '') . ' ' . ($arm ?? '')),
            '{current_class}' => $class ? ', and is currently in ' . trim($class . ' ' . ($arm ?? '')) : '',
            '{date}' => now()->format('j F Y'),
        ];
    }

    private static function signature(?School $school): string
    {
        if ($school) {
            $sigs = \App\Models\SchoolSignature::activeForSchool($school->id);
            $sig = $sigs['principal'] ?? $sigs['head_teacher'] ?? $sigs['director'] ?? $sigs->first();
            if ($sig) {
                $img = $sig->signature_url ? '<img src="' . e($sig->signature_url) . '" style="max-height:60px;max-width:180px"><br>' : '<div style="height:40px"></div>';

                return $img . '<strong>' . e($sig->name) . '</strong><br>' . e(ucwords(str_replace('_', ' ', (string) $sig->role)));
            }
        }

        return '<div style="border-bottom:1px solid #333;width:220px;height:50px"></div>For: ' . e($school?->name ?? '') . ' Management';
    }

    private static function studentPanel(Student $s, array $v): string
    {
        $row = fn ($l, $x) => '<tr><td class="l">' . e($l) . '</td><td>' . e((string) ($x ?: '—')) . '</td></tr>';

        return '<table class="grid">' . $row('Name', $v['{name}']) . $row('Admission No.', $v['{admission_number}'])
            . $row('Date of birth', $s->date_of_birth?->format('j F Y')) . $row('Gender', $s->gender ? ucfirst($s->gender) : null)
            . $row('Admitted', $v['{admitted}']) . $row('Current class', $v['{class}']) . '</table>';
    }

    /** Every term's end-of-term result, by session. $publishedOnly: leave out results not yet published. */
    public static function transcriptHtml(Student $s, bool $publishedOnly = false): string
    {
        $school = School::first();
        $s->loadMissing(['class', 'arm']);
        $v = self::values($s, $school);

        $results = StudentResult::where('student_id', $s->id)->where('result_type', 'end_term')
            ->when($publishedOnly, fn ($q) => $q->where('status', 'published'))
            ->with(['subjectResults.subject', 'term', 'academicYear', 'class'])
            ->get()
            ->sortBy(fn ($r) => sprintf('%s|%s|%010d', $r->academicYear?->start_date ?? $r->academicYear?->name ?? '', $r->term?->start_date ?? '', $r->term_id))
            ->groupBy('academic_year_id');

        $body = '';
        foreach ($results as $yearResults) {
            $year = $yearResults->first()->academicYear?->name ?? 'Session';
            $body .= '<h3>' . e($year) . '</h3>';
            $averages = [];
            foreach ($yearResults as $r) {
                $rows = $r->subjectResults->sortBy(fn ($sr) => $sr->subject?->name)->map(function ($sr) {
                    $gradeOnly = (bool) ($sr->subject?->grade_only ?? false);

                    return '<tr><td>' . e($sr->subject?->name ?? 'Subject') . '</td>'
                        . '<td class="n">' . ($gradeOnly ? '—' : e(self::num($sr->ca_total))) . '</td>'
                        . '<td class="n">' . ($gradeOnly ? '—' : e(self::num($sr->exam_score))) . '</td>'
                        . '<td class="n"><strong>' . ($gradeOnly ? '—' : e(self::num($sr->total_score))) . '</strong></td>'
                        . '<td class="n">' . e((string) ($sr->grade ?? '')) . '</td></tr>';
                })->implode('');
                if ($rows === '') {
                    continue;
                }
                $averages[] = (float) $r->average_score;
                $pos = $r->position ? ' · Position ' . $r->position . ($r->out_of ? ' of ' . $r->out_of : '') : '';
                $body .= '<div class="term"><div class="th">' . e($r->term?->name ?? 'Term') . ' — ' . e(trim(($r->class?->name ?? ''))) . '</div>'
                    . '<table class="marks"><thead><tr><th>Subject</th><th>CA</th><th>Exam</th><th>Total</th><th>Grade</th></tr></thead><tbody>' . $rows . '</tbody></table>'
                    . '<div class="sum">Average ' . e(self::num($r->average_score)) . '%' . ($r->grade ? ' · Grade ' . e((string) $r->grade) : '') . e($pos) . '</div></div>';
            }
            if (count($averages) > 1) {
                $body .= '<div class="sum year">Session average: ' . e(self::num(array_sum($averages) / count($averages))) . '%</div>';
            }
        }
        if ($body === '') {
            $body = '<p><em>No end-of-term results on record' . ($publishedOnly ? ' (published)' : '') . '.</em></p>';
        }

        $style = '<style>table.marks{width:100%;border-collapse:collapse;margin:4px 0}table.marks th,table.marks td{border:1px solid #bbb;padding:3px 6px;font-size:11.5px}'
            . 'table.marks th{background:#f0f2f6;text-align:left}td.n,table.marks th:not(:first-child){text-align:center;width:13%}.term{margin:0 0 12px;page-break-inside:avoid}'
            . '.th{font-weight:bold;font-size:12px;margin:6px 0 2px}.sum{font-size:11.5px;color:#333}.sum.year{font-weight:bold;margin:-4px 0 14px}</style>';
        $inner = $style . Onboarding::letterhead($school, 'ACADEMIC TRANSCRIPT') . self::studentPanel($s, $v) . $body
            . '<p style="font-size:11px;color:#555;margin-top:16px">Issued ' . e($v['{date}']) . '. This transcript shows end-of-term results recorded on Compasse.</p>'
            . '<div style="margin-top:18px">' . self::signature($school) . '</div>'
            . '<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),300))</script>';

        return SchoolColor::apply(Onboarding::page('Transcript — ' . $v['{name}'], $inner));
    }

    public static function recommendationHtml(Student $s, ?string $text = null, ?string $addressee = null): string
    {
        $school = School::first();
        $s->loadMissing(['class', 'arm']);
        $v = self::values($s, $school);
        $text = trim((string) $text) !== '' ? (string) $text : self::DEFAULT_LETTER;
        $inner = Onboarding::letterhead($school, 'LETTER OF RECOMMENDATION')
            . '<p style="text-align:right">' . e($v['{date}']) . '</p>'
            . ($addressee && trim($addressee) !== '' ? '<p>' . nl2br(e(trim($addressee))) . '</p>' : '')
            . '<p><strong>RE: ' . e(mb_strtoupper($v['{name}'])) . '</strong></p>'
            . '<p>' . nl2br(e(strtr($text, $v))) . '</p>'
            . '<p>Yours faithfully,</p><div style="margin-top:10px">' . self::signature($school) . '</div>'
            . '<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),300))</script>';

        return SchoolColor::apply(Onboarding::page('Recommendation — ' . $v['{name}'], $inner));
    }

    private static function num($v): string
    {
        $f = round((float) $v, 1);

        return fmod($f, 1.0) === 0.0 ? (string) (int) $f : (string) $f;
    }
}
