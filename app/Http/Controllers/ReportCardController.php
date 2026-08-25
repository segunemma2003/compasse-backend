<?php

namespace App\Http\Controllers;

use App\Models\SchoolSignature;
use App\Models\StudentResult;
use App\Models\PsychomotorAssessment;
use App\Models\School;
use App\Models\Student;
use App\Support\PsychomotorConfig;
use App\Support\ResultReportBuilder;
use App\Services\HtmlToPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReportCardController extends Controller
{
    /**
     * Get report card data (JSON format)
     */
    public function getReportCard(Request $request, $studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            $bundle = $this->loadResultBundle($request, (int) $studentId, (int) $termId, (int) $academicYearId);
            if ($bundle instanceof JsonResponse) {
                return $bundle;
            }

            ['result' => $result, 'psychomotor' => $psychomotor, 'config' => $config, 'payload' => $payload] = $bundle;
            $psychReport = PsychomotorConfig::formatForReport($psychomotor, $config);
            $commentsOnly = $config?->isCommentsOnly() ?? false;

            $subjects = collect($payload['subjects'] ?? [])->map(function ($row) use ($config) {
                $mapped = [
                    'subject' => $row['subject']['name'] ?? $row['subject'] ?? 'N/A',
                    'total_score' => $row['total_score'] ?? null,
                    'grade' => $row['grade'] ?? null,
                    'remark' => $row['remark'] ?? null,
                ];

                if ($config?->show_ca_breakdown ?? true) {
                    $mapped['ca_total'] = $row['ca_score'] ?? null;
                    $mapped['exam_score'] = $row['exam_score'] ?? null;
                }

                if ($config?->show_subject_position ?? false) {
                    $mapped['position'] = $row['position'] ?? null;
                }

                return $mapped;
            });

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
                $comments[$key] = [
                    'label' => $field['label'] ?? $key,
                    'text'  => $result->{$key},
                ];
            }

            $reportCard = [
                'student' => $payload['student'],
                'academic' => [
                    'class' => $result->class->name ?? 'N/A',
                    'term' => $result->term->name ?? 'N/A',
                    'academic_year' => $result->academicYear->year ?? 'N/A',
                    'result_type' => $result->result_type ?? 'end_term',
                ],
                'summary' => [
                    'total_score' => $commentsOnly ? null : round((float) $result->total_score, 2),
                    'average_score' => $commentsOnly ? null : round((float) $result->average_score, 2),
                    'grade' => $result->grade,
                    'position' => ($config && ! $config->show_position) ? null : $result->position,
                    'out_of' => $result->out_of,
                    'class_average' => ($config && ! $config->show_class_average) ? null : round((float) ($result->class_average ?? 0), 2),
                    'comments_only' => $commentsOnly,
                ],
                'subjects' => $commentsOnly ? [] : $subjects,
                'psychomotor' => $psychReport,
                'comments' => $comments,
                'next_term_begins' => ($config && $config->show_next_term_date)
                    ? ResultReportBuilder::resolveNextTermBegins($result)
                    : null,
                'status' => $result->status,
                'configuration' => $config ? [
                    'show_psychomotor' => $config->show_psychomotor,
                    'show_affective' => $config->show_affective,
                    'show_next_term_date' => $config->show_next_term_date,
                    'grade_style' => $config->grade_style,
                ] : null,
            ];

            $school = $result->student?->class?->school ?? School::first();
            $reportCard['school'] = [
                'name'    => $school?->name,
                'logo'    => $school?->logo,
                'address' => $school?->address,
                'phone'   => $school?->phone,
                'email'   => $school?->email,
            ];
            $reportCard['signatures'] = $school
                ? SchoolSignature::resolveForReportCard($school->id, $this->resolveClassTeacherId($result->student))
                    ->map(function ($s) {
                        $arr = $s->toArray();
                        $arr['signature_url'] = $s->signature_url;
                        return $arr;
                    })
                : collect();

            return response()->json(['report_card' => $reportCard, 'data' => $payload]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch report card',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return a print-ready HTML page for the report card.
     * Opens in the browser — user triggers Ctrl+P / Print to PDF.
     */
    public function generatePDF(Request $request, $studentId, $termId, $academicYearId): Response
    {
        $bundle = $this->loadResultBundle($request, (int) $studentId, (int) $termId, (int) $academicYearId);
        if ($bundle instanceof JsonResponse) {
            return response('<h2>Result not found</h2>', 404)->header('Content-Type', 'text/html');
        }

        ['result' => $result, 'psychomotor' => $psychomotor, 'config' => $config, 'attendance' => $attendance, 'classSize' => $classSize] = $bundle;

        if ($result->status !== 'published') {
            $user = Auth::user();
            $ownId = $this->ownStudentId($user);
            if ($ownId !== null) {
                return response('<h2>Result not yet published</h2><p>Your school will publish results when ready.</p>', 403)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }
            $guardianStudentIds = $this->accessibleStudentIdsForGuardian($user);
            if ($guardianStudentIds !== null && in_array((int) $studentId, $guardianStudentIds, true)) {
                return response('<h2>Result not yet published</h2>', 403)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }
        }

        $psychReport   = PsychomotorConfig::formatForReport($psychomotor, $config);
        $commentsOnly  = $config?->isCommentsOnly() ?? false;
        $school        = $result->student?->class?->school ?? School::first();
        $signatures    = $school
            ? SchoolSignature::resolveForReportCard($school->id, $this->resolveClassTeacherId($result->student))
            : collect();
        $schoolLogo    = $this->resolveAssetUrl($school?->logo);
        $schoolName    = e($school?->name ?? 'School');
        $addressLine = trim(implode('  |  ', array_filter([$school?->address, $school?->phone, $school?->email])));
        $schoolAddress = e($addressLine);
        $studentName   = e($result->student?->user?->name ?? 'N/A');
        $admissionNumber = e($result->student?->admission_number ?? '');
        $gender        = e(ucfirst($result->student?->gender ?? ''));
        $photoUrl      = $this->resolveAssetUrl($result->student?->profile_picture ?: $result->student?->user?->profile_picture);
        $className     = e($result->class?->name ?? 'N/A');
        $termName      = e($result->term?->name ?? 'N/A');
        $academicYear  = e($result->academicYear?->year ?? '');
        $passMark      = (float) ($config?->gradingSystem?->pass_mark ?? 50);

        $subjectRows = '';
        if (! $commentsOnly) {
            foreach ($result->subjectResults as $sr) {
                $isFail  = (float) $sr->total_score < $passMark;
                $failCls = $isFail ? ' class="fail-cell"' : '';
                $subj    = e($sr->subject?->name ?? 'N/A');
                $caCol   = ($config?->show_ca_breakdown ?? true)
                    ? "<td{$failCls}>" . number_format((float) $sr->ca_total, 1) . "</td><td{$failCls}>" . number_format((float) $sr->exam_score, 1) . '</td>'
                    : '';
                $total = number_format((float) $sr->total_score, 1);
                $grade = e($sr->grade ?? '');
                $pos   = ($config?->show_subject_position ?? false) ? '<td>' . e((string) ($sr->position ?? '')) . '</td>' : '';
                $classStatsCol = ($sr->class_average !== null)
                    ? '<td>' . number_format((float) $sr->class_average, 1) . '</td><td>' . e((string) ($sr->lowest_score ?? '')) . '</td><td>' . e((string) ($sr->highest_score ?? '')) . '</td>'
                    : '';
                $rmk = e($sr->teacher_remark ?? '');
                $subjectRows .= "<tr><td>{$subj}</td>{$caCol}<td{$failCls}><strong>{$total}</strong></td><td{$failCls}>{$grade}</td>{$pos}{$classStatsCol}<td>{$rmk}</td></tr>";
            }
        }
        $hasClassStats = ! $commentsOnly && $result->subjectResults->contains(fn ($sr) => $sr->class_average !== null);

        $psychHtml = '';
        if ($psychReport && (! empty($psychReport['skills']) || ! empty($psychReport['affective']))) {
            $psychHtml = '<div class="skills-grid">';
            foreach (($psychReport['skills'] ?? []) as $skill) {
                $psychHtml .= '<div class="skill-row"><span>' . e($skill['label']) . '</span><strong>' . e((string) $skill['rating']) . '</strong></div>';
            }
            foreach (($psychReport['affective'] ?? []) as $trait) {
                $psychHtml .= '<div class="skill-row"><span>' . e($trait['label']) . '</span><strong>' . e((string) $trait['rating']) . '</strong></div>';
            }
            $psychHtml .= '</div>';
            if (! empty($psychReport['teacher_comment'])) {
                $psychHtml .= '<div class="comment-box"><strong>Assessment Comment:</strong> ' . e($psychReport['teacher_comment']) . '</div>';
            }
        }

        $sigHtml = '';
        foreach ($signatures as $role => $sig) {
            $sigName = e($sig->name);
            $sigRole = e(ucwords(str_replace('_', ' ', (string) $role)));
            $sigUrl  = $sig->signature_url;
            $sigImg  = $sigUrl
                ? "<img src=\"{$sigUrl}\" style=\"max-height:50px;max-width:150px;\" alt=\"{$sigRole} signature\">"
                : '<div class="sig-line"></div>';
            $sigHtml .= "<div class=\"sig-block\">{$sigImg}<div class=\"sig-name\">{$sigName}</div><div class=\"sig-role\">{$sigRole}</div></div>";
        }
        if (! $sigHtml) {
            $sigHtml = '<div class="sig-block"><div class="sig-line"></div><div class="sig-role">Principal</div></div>';
        }

        $overallFail = $commentsOnly ? false : (float) $result->total_score < $passMark && $result->grade;
        $summaryHtml = '';
        if (! $commentsOnly) {
            $summaryHtml = '<div class="overall-summary">'
                . "<div><span class=\"lbl\">No. in Class</span><span class=\"val\">{$classSize}</span></div>"
                . '<div><span class="lbl">Total Score</span><span class="val">' . number_format((float) $result->total_score, 1) . '</span></div>'
                . '<div><span class="lbl">Average</span><span class="val">' . number_format((float) $result->average_score, 1) . '%</span></div>'
                . '<div><span class="lbl">Grade</span><span class="val' . ($overallFail ? ' fail-text' : '') . '">' . e($result->grade ?? '') . '</span></div>';
            if ($config?->show_position ?? true) {
                $outOf = $result->out_of ? " / {$result->out_of}" : '';
                $summaryHtml .= '<div><span class="lbl">Position</span><span class="val">' . e((string) ($result->position ?? '')) . $outOf . '</span></div>';
            }
            if ($config?->show_class_average ?? true) {
                $summaryHtml .= '<div><span class="lbl">Class Avg</span><span class="val">' . number_format((float) ($result->class_average ?? 0), 1) . '%</span></div>';
            }
            $summaryHtml .= '</div>';
        }

        $tableHeader = '';
        if (! $commentsOnly) {
            $classStatsHead = $hasClassStats ? '<th>Class Avg</th><th>Class Low</th><th>Class High</th>' : '';
            $tableHeader = '<table class="perf"><thead><tr><th>Subject</th>'
                . (($config?->show_ca_breakdown ?? true) ? '<th>CA</th><th>Exam</th>' : '')
                . '<th>Total</th><th>Grade</th>'
                . (($config?->show_subject_position ?? false) ? '<th>Position</th>' : '')
                . $classStatsHead
                . '<th>Remark</th></tr></thead><tbody>' . $subjectRows . '</tbody></table>';
        }

        $gradeLegendHtml = '';
        $boundaries = $config?->gradingSystem?->grade_boundaries ?? [];
        if (! empty($boundaries)) {
            $gradeLegendHtml = '<div class="grade-legend">';
            foreach ($boundaries as $b) {
                $gradeLegendHtml .= '<div><strong>' . e((string) ($b['grade'] ?? '')) . '</strong> ' . (int) ($b['min'] ?? 0) . '&ndash;' . (int) ($b['max'] ?? 0) . ' (' . e($b['remark'] ?? '') . ')</div>';
            }
            $gradeLegendHtml .= '</div>';
        }

        $commentHtml = '';
        foreach (($config?->comment_fields ?? [
            ['key' => 'class_teacher_comment', 'label' => "Class Teacher's Comment"],
            ['key' => 'principal_comment', 'label' => "Principal's Comment"],
        ]) as $field) {
            $key = $field['key'] ?? null;
            if (! $key) {
                continue;
            }
            $label = e($field['label'] ?? $key);
            $text  = e($result->{$key} ?? '');
            $commentHtml .= "<div class=\"comment-block\"><div class=\"comment-label\">{$label}</div><div class=\"comment-box\">{$text}</div></div>";
        }

        $nextTermHtml = '';
        if ($config?->show_next_term_date ?? true) {
            $nextTerm = e(ResultReportBuilder::resolveNextTermBegins($result) ?? '');
            if ($nextTerm !== '') {
                $nextTermHtml = '<div class="next-term">Next Term Begins: <strong>' . $nextTerm . '</strong></div>';
            }
        }

        $logoHtml = $schoolLogo
            ? "<img src=\"{$schoolLogo}\" alt=\"{$schoolName} logo\">"
            : '<div class="logo-fallback">' . mb_strtoupper(mb_substr($schoolName, 0, 1)) . '</div>';

        $photoHtml = $photoUrl
            ? "<img src=\"{$photoUrl}\" alt=\"{$studentName}\">"
            : '<div class="photo-fallback"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.5-7 8-7s8 3 8 7"/></svg></div>';

        $attendanceHtml = ($attendance['opened'] ?? 0) > 0
            ? '<div class="panel-head">Attendance</div><table class="attendance-table"><thead><tr><th>School Days</th><th>Present</th><th>Absent</th></tr></thead>'
              . '<tbody><tr><td>' . (int) $attendance['opened'] . '</td><td class="present">' . (int) $attendance['present'] . '</td><td class="absent">' . (int) $attendance['absent'] . '</td></tr></tbody></table>'
            : '<div class="panel-head">Attendance</div><p class="muted">Not recorded for this term.</p>';

        $tableHeaderSectionHead = $commentsOnly ? '' : '<div class="section-head">Academic Performance</div>';
        $psychSectionHtml = $psychHtml !== ''
            ? '<div class="section-head">Skills Development &amp; Behavioural Attributes</div>' . $psychHtml
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Report Card – {$studentName}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; }
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
  .kv tr td:first-child { color: #667; font-size: 10px; padding: 1px 0; width: 42%; }
  .kv tr td:last-child { font-weight: 600; font-size: 11px; padding: 1px 0; }
  .photo-wrap img, .photo-fallback { width: 48px; height: 48px; border-radius: 6px; object-fit: cover; border: 1px solid #d5deec; flex-shrink: 0; }
  .photo-fallback { display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #9aabc9; }
  .photo-fallback svg { width: 24px; height: 24px; }
  .attendance-table { width: 100%; }
  .attendance-table th { background: #f7f9fd; color: #667; font-size: 9px; padding: 4px; text-transform: uppercase; }
  .attendance-table td { text-align: center; font-size: 15px; font-weight: 700; padding: 5px 8px; color: #1a3a6b; }
  .attendance-table td.absent { color: #c0392b; }
  .muted { padding: 7px 10px; color: #999; font-size: 10px; }

  .section-head { background: #1a3a6b; color: #fff; font-weight: 700; font-size: 10.5px; padding: 4px 10px; margin: 10px 0 0; text-transform: uppercase; letter-spacing: 0.3px; border-radius: 5px 5px 0 0; }
  table.perf { width: 100%; border-collapse: collapse; border: 1px solid #d5deec; border-top: none; margin-bottom: 2px; }
  table.perf th { background: #f7f9fd; color: #1a3a6b; padding: 4px 6px; text-align: left; font-size: 9px; text-transform: uppercase; border-bottom: 2px solid #d5deec; }
  table.perf td { padding: 3px 6px; border-bottom: 1px solid #eef2fb; font-size: 10px; }
  table.perf tr:nth-child(even) td { background: #fafcff; }
  table.perf td.fail-cell { color: #c0392b; font-weight: 700; }

  .overall-summary { display: flex; flex-wrap: wrap; gap: 0; border: 1px solid #d5deec; border-top: none; border-radius: 0 0 6px 6px; margin-bottom: 8px; }
  .overall-summary > div { flex: 1; text-align: center; padding: 5px 4px; border-right: 1px solid #eef2fb; }
  .overall-summary > div:last-child { border-right: none; }
  .overall-summary .lbl { display: block; font-size: 8px; color: #778; text-transform: uppercase; margin-bottom: 2px; }
  .overall-summary .val { display: block; font-size: 13px; font-weight: 700; color: #1a3a6b; }
  .overall-summary .val.fail-text { color: #c0392b; }

  .grade-legend { display: flex; flex-wrap: wrap; gap: 8px 12px; background: #f7f9fd; border: 1px solid #d5deec; border-radius: 5px; padding: 5px 10px; font-size: 9px; color: #445; margin-bottom: 10px; }

  .skills-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; border: 1px solid #d5deec; border-top: none; padding: 5px 10px; border-radius: 0 0 6px 6px; }
  .skill-row { display: flex; justify-content: space-between; padding: 2px 0; border-bottom: 1px dotted #e3e8f2; font-size: 10px; }
  .skill-row span { color: #445; }
  .skill-row strong { color: #1a3a6b; }

  .comments-wrap { border: 1px solid #d5deec; border-top: none; padding: 8px 10px; border-radius: 0 0 6px 6px; }
  .comment-block { margin-bottom: 6px; }
  .comment-block:last-child { margin-bottom: 0; }
  .comment-label { font-size: 9px; font-weight: 700; color: #1a3a6b; text-transform: uppercase; margin-bottom: 2px; }
  .comment-box { background: #fafcff; border: 1px dashed #b9c6de; padding: 4px 8px; border-radius: 4px; font-size: 10px; min-height: 12px; font-style: italic; color: #333; }

  .next-term { font-size: 10px; color: #555; margin: 8px 0; text-align: right; }
  .signatures { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 12px; padding-top: 8px; border-top: 1px solid #d5deec; }
  .sig-block { text-align: center; min-width: 130px; }
  .sig-line { border-bottom: 1px solid #333; width: 130px; height: 30px; }
  .sig-name { font-size: 10px; font-weight: 600; margin-top: 2px; }
  .sig-role { font-size: 9px; color: #778; }
  .footer { text-align: center; margin-top: 8px; font-size: 8px; color: #aab; }

  @media print { body { padding: 0 8px; } @page { size: A4; margin: 0.9cm; } }
</style>
</head>
<body>
<div class="header">
  {$logoHtml}
  <div class="header-text">
    <h1>{$schoolName}</h1>
    <p class="addr">{$schoolAddress}</p>
    <p class="report-title">Student Report Card &nbsp;&middot;&nbsp; {$academicYear}</p>
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
        <tr><td>Admission No.</td><td>{$admissionNumber}</td></tr>
        <tr><td>Gender</td><td>{$gender}</td></tr>
        <tr><td>Class</td><td>{$className}</td></tr>
      </table>
    </div>
  </div>
  <div class="panel">
    {$attendanceHtml}
  </div>
</div>

{$tableHeaderSectionHead}
{$tableHeader}
{$summaryHtml}
{$gradeLegendHtml}
{$psychSectionHtml}
<div class="section-head" style="margin-top:18px;">Remarks &amp; Conclusion</div>
<div class="comments-wrap">{$commentHtml}</div>
{$nextTermHtml}
<div class="signatures">{$sigHtml}</div>
<div class="footer">Generated by Compasse</div>
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
HTML;

        return $this->reportCardPdfResponse($request, $html, (int) $studentId);
    }

    private function reportCardPdfResponse(Request $request, string $html, int $studentId): Response
    {
        if ($request->query('format') === 'html') {
            return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $html = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        try {
            $pdf = app(HtmlToPdfService::class)->fromHtml($html);
        } catch (\Throwable $e) {
            return response(
                '<h2>PDF generation failed</h2><p>'.e($e->getMessage()).'</p><p>Open the Print view instead.</p>',
                500
            )->header('Content-Type', 'text/html; charset=utf-8');
        }

        $filename = sprintf('report-card-student-%d.pdf', $studentId);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array{result: StudentResult, psychomotor: ?PsychomotorAssessment, config: ?\App\Models\ResultConfiguration, payload: array}|JsonResponse
     */
    private function loadResultBundle(Request $request, int $studentId, int $termId, int $academicYearId): array|JsonResponse
    {
        $resultType = $request->query('result_type', 'end_term');

        $result = StudentResult::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->where('result_type', $resultType)
            ->with([
                'student.user',
                'student.class',
                'class',
                'term',
                'academicYear',
                'subjectResults.subject',
            ])
            ->first();

        if (! $result) {
            return response()->json([
                'error' => 'Result not found',
                'message' => 'No result available for this student and term',
            ], 404);
        }

        $denied = $this->authorizeStudentResultAccess((int) $studentId, $result);
        if ($denied) {
            return $denied;
        }

        $psychomotor = PsychomotorAssessment::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->with('assessedBy')
            ->first();

        $school = School::first();
        $config = ResultReportBuilder::resolveConfigForClass(
            (int) $result->class_id,
            (int) ($school?->id ?? 0)
        );
        $config?->loadMissing('gradingSystem');

        $payload = ResultReportBuilder::buildStudentPayload($result, $psychomotor, $config);
        $attendance = $this->termAttendanceSummary($studentId, $result->term);
        $classSize = \App\Models\Student::where('class_id', $result->class_id)->count();

        return compact('result', 'psychomotor', 'config', 'payload', 'attendance', 'classSize');
    }

    /**
     * Image fields (school logo, student photo) are stored either as a full
     * URL (S3 uploads) or a relative path on the local public disk, same
     * ambiguity SchoolSignature::signature_url already resolves — using the
     * raw column as an <img src> breaks for local-disk uploads.
     */
    private function resolveAssetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }

    /**
     * @return array{opened: int, present: int, absent: int}
     */
    private function termAttendanceSummary(int $studentId, ?\App\Models\Term $term): array
    {
        if (! $term?->start_date || ! $term?->end_date) {
            return ['opened' => 0, 'present' => 0, 'absent' => 0];
        }

        $rows = \Illuminate\Support\Facades\DB::table('attendances')
            ->where('attendanceable_type', Student::class)
            ->where('attendanceable_id', $studentId)
            ->whereBetween('date', [$term->start_date->toDateString(), $term->end_date->toDateString()])
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $present = (int) ($rows['present'] ?? 0) + (int) ($rows['late'] ?? 0);
        $absent  = (int) ($rows['absent'] ?? 0);
        $excused = (int) ($rows['excused'] ?? 0);

        return [
            'opened'  => $present + $absent + $excused,
            'present' => $present,
            'absent'  => $absent,
        ];
    }

    /**
     * Students/guardians may only view published results; staff scoped by class when applicable.
     */
    private function authorizeStudentResultAccess(int $studentId, StudentResult $result): ?JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $ownId = $this->ownStudentId($user);
        if ($ownId !== null && (int) $ownId !== $studentId) {
            return $this->forbiddenResponse('You may only view your own results.');
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

        $isStudentOrGuardian = $ownId !== null || $guardianStudentIds !== null;
        if ($isStudentOrGuardian && $result->status !== 'published') {
            return response()->json([
                'error' => 'Result not found',
                'message' => 'Results for this term have not been published yet.',
            ], 404);
        }

        return null;
    }

    /**
     * Bulk download report cards for class
     */
    public function bulkDownload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $results = StudentResult::where('class_id', $request->class_id)
                ->where('term_id', $request->term_id)
                ->where('academic_year_id', $request->academic_year_id)
                ->where('status', 'published')
                ->with('student.user')
                ->get();

            if ($results->isEmpty()) {
                return response()->json([
                    'error' => 'No published results found for this class and term'
                ], 404);
            }

            $reportCards = $results->map(function($result) use ($request) {
                return [
                    'student_id' => $result->student_id,
                    'student_name' => $result->student->user->name ?? 'N/A',
                    'admission_number' => $result->student->admission_number,
                    'download_url' => route('report-cards.pdf', [
                        'studentId' => $result->student_id,
                        'termId' => $request->term_id,
                        'academicYearId' => $request->academic_year_id,
                    ]),
                ];
            });

            return response()->json([
                'message' => 'Report cards ready for download',
                'total' => $results->count(),
                'report_cards' => $reportCards
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to prepare bulk download',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Email report card to parent/guardian
     */
    public function emailReportCard(Request $request, $studentId, $termId, $academicYearId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $result = StudentResult::where('student_id', $studentId)
                ->where('term_id', $termId)
                ->where('academic_year_id', $academicYearId)
                ->where('status', 'published')
                ->first();

            if (!$result) {
                return response()->json([
                    'error' => 'No published result found'
                ], 404);
            }

            // TODO: Implement email sending with PDF attachment
            // Mail::to($request->email)->send(new ReportCardMail($result));

            return response()->json([
                'message' => 'Email sending coming soon',
                'note' => 'Implement email service for report card delivery',
                'email' => $request->email
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to email report card',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get printable report card (HTML format)
     */
    public function getPrintableReportCard(Request $request, $studentId, $termId, $academicYearId): Response
    {
        $request->merge(['format' => 'html']);

        return $this->generatePDF($request, $studentId, $termId, $academicYearId);
    }
}

