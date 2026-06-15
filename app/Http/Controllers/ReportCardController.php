<?php

namespace App\Http\Controllers;

use App\Models\SchoolSignature;
use App\Models\StudentResult;
use App\Models\PsychomotorAssessment;
use App\Models\School;
use App\Support\PsychomotorConfig;
use App\Support\ResultReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
                ? SchoolSignature::activeForSchool($school->id)->map(function ($s) {
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

        ['result' => $result, 'psychomotor' => $psychomotor, 'config' => $config] = $bundle;

        if ($result->status !== 'published') {
            return response('<h2>Result not yet published</h2>', 400)->header('Content-Type', 'text/html');
        }

        $psychReport   = PsychomotorConfig::formatForReport($psychomotor, $config);
        $commentsOnly  = $config?->isCommentsOnly() ?? false;
        $school        = $result->student?->class?->school ?? School::first();
        $signatures    = $school ? SchoolSignature::activeForSchool($school->id) : collect();
        $schoolLogo    = $school?->logo ?? '';
        $schoolName    = e($school?->name ?? 'School');
        $studentName   = e($result->student?->user?->name ?? 'N/A');
        $admissionNumber = e($result->student?->admission_number ?? '');
        $className     = e($result->class?->name ?? 'N/A');
        $termName      = e($result->term?->name ?? 'N/A');
        $academicYear  = e($result->academicYear?->year ?? '');

        $subjectRows = '';
        if (! $commentsOnly) {
            foreach ($result->subjectResults as $sr) {
                $subj  = e($sr->subject?->name ?? 'N/A');
                $caCol = ($config?->show_ca_breakdown ?? true)
                    ? '<td>' . number_format($sr->ca_total, 1) . '</td><td>' . number_format($sr->exam_score, 1) . '</td>'
                    : '';
                $total = number_format($sr->total_score, 1);
                $grade = e($sr->grade ?? '');
                $pos   = ($config?->show_subject_position ?? false) ? '<td>' . e($sr->position ?? '') . '</td>' : '';
                $rmk   = e($sr->teacher_remark ?? '');
                $subjectRows .= "<tr><td>{$subj}</td>{$caCol}<td><strong>{$total}</strong></td><td>{$grade}</td>{$pos}<td>{$rmk}</td></tr>";
            }
        }

        $psychHtml = '';
        if ($psychReport) {
            if (! empty($psychReport['skills'])) {
                $psychHtml .= '<h3>Psychomotor Skills</h3><table><thead><tr><th>Skill</th><th>Rating (1-5)</th></tr></thead><tbody>';
                foreach ($psychReport['skills'] as $skill) {
                    $psychHtml .= '<tr><td>' . e($skill['label']) . '</td><td>' . e((string) $skill['rating']) . '</td></tr>';
                }
                $psychHtml .= '</tbody></table>';
            }
            if (! empty($psychReport['affective'])) {
                $psychHtml .= '<h3>Affective Traits</h3><table><thead><tr><th>Trait</th><th>Rating (1-5)</th></tr></thead><tbody>';
                foreach ($psychReport['affective'] as $trait) {
                    $psychHtml .= '<tr><td>' . e($trait['label']) . '</td><td>' . e((string) $trait['rating']) . '</td></tr>';
                }
                $psychHtml .= '</tbody></table>';
            }
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
                ? "<img src=\"{$sigUrl}\" style=\"max-height:60px;max-width:160px;\" alt=\"{$sigRole} signature\">"
                : '<div style="border-bottom:1px solid #333;width:160px;height:60px;"></div>';
            $sigHtml .= "<div style=\"text-align:center;min-width:180px;\">{$sigImg}<div style=\"font-size:11px;margin-top:4px;\">{$sigName}</div><div style=\"font-size:10px;color:#666;\">{$sigRole}</div></div>";
        }
        if (! $sigHtml) {
            $sigHtml = '<div style="border-bottom:1px solid #333;width:160px;height:60px;margin:auto;"></div><div style="font-size:11px;text-align:center;">Principal</div>';
        }

        $summaryHtml = '';
        if (! $commentsOnly) {
            $summaryHtml = '<div class="summary">'
                . '<div><div class="val">' . number_format($result->total_score, 1) . '</div><div class="lbl">Total Score</div></div>'
                . '<div><div class="val">' . number_format($result->average_score, 1) . '%</div><div class="lbl">Average</div></div>'
                . '<div><div class="val">' . e($result->grade ?? '') . '</div><div class="lbl">Grade</div></div>';
            if ($config?->show_position ?? true) {
                $summaryHtml .= '<div><div class="val">' . e((string) ($result->position ?? '')) . '</div><div class="lbl">Position</div></div>';
            }
            if ($config?->show_class_average ?? true) {
                $summaryHtml .= '<div><div class="val">' . number_format($result->class_average ?? 0, 1) . '%</div><div class="lbl">Class Avg</div></div>';
            }
            $summaryHtml .= '</div>';
        }

        $tableHeader = $commentsOnly ? '' : (
            ($config?->show_ca_breakdown ?? true)
                ? '<table><thead><tr><th>Subject</th><th>CA</th><th>Exam</th><th>Total</th><th>Grade</th>'
                  . (($config?->show_subject_position ?? false) ? '<th>Position</th>' : '')
                  . '<th>Remark</th></tr></thead><tbody>' . $subjectRows . '</tbody></table>'
                : '<table><thead><tr><th>Subject</th><th>Total</th><th>Grade</th><th>Remark</th></tr></thead><tbody>' . $subjectRows . '</tbody></table>'
        );

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
            $commentHtml .= "<h3>{$label}</h3><div class=\"comment-box\">{$text}</div>";
        }

        $nextTermHtml = '';
        if ($config?->show_next_term_date ?? true) {
            $nextTerm = e(ResultReportBuilder::resolveNextTermBegins($result) ?? '');
            if ($nextTerm !== '') {
                $nextTermHtml = '<div class="next-term">Next Term Begins: <strong>' . $nextTerm . '</strong></div>';
            }
        }

        $logoHtml = $schoolLogo
            ? "<img src=\"{$schoolLogo}\" style=\"max-height:80px;max-width:160px;\" alt=\"{$schoolName} logo\">"
            : "<div style=\"font-size:28px;font-weight:bold;\">{$schoolName}</div>";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Report Card – {$studentName}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; padding: 20px; }
  .header { display: flex; align-items: center; gap: 20px; border-bottom: 3px solid #1a3a6b; padding-bottom: 12px; margin-bottom: 16px; }
  .header-text h1 { font-size: 18px; color: #1a3a6b; }
  .header-text p { font-size: 11px; color: #555; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 16px; }
  .info-grid div { font-size: 12px; }
  .info-grid span { font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th { background: #1a3a6b; color: #fff; padding: 6px 8px; text-align: left; font-size: 11px; }
  td { padding: 5px 8px; border-bottom: 1px solid #ddd; font-size: 11px; }
  tr:nth-child(even) td { background: #f5f8ff; }
  .summary { display: flex; gap: 20px; margin-bottom: 16px; padding: 10px; background: #f0f4ff; border-radius: 6px; }
  .summary div { text-align: center; }
  .summary .val { font-size: 18px; font-weight: bold; color: #1a3a6b; }
  .summary .lbl { font-size: 10px; color: #555; }
  .comments { margin-bottom: 16px; }
  .comments h3, h3 { font-size: 12px; color: #1a3a6b; margin-bottom: 4px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
  .comment-box { background: #fafafa; border: 1px solid #eee; padding: 8px; border-radius: 4px; font-size: 11px; margin-bottom: 8px; }
  .signatures { display: flex; gap: 40px; flex-wrap: wrap; margin-top: 20px; padding-top: 12px; border-top: 1px solid #ddd; }
  .next-term { font-size: 11px; color: #555; margin-bottom: 12px; }
  @media print { body { padding: 0; } @page { margin: 1.5cm; } }
</style>
</head>
<body>
<div class="header">
  <div>{$logoHtml}</div>
  <div class="header-text">
    <h1>{$schoolName}</h1>
    <p>Student Report Card</p>
    <p>{$termName} &nbsp;|&nbsp; {$academicYear}</p>
  </div>
</div>
<div class="info-grid">
  <div>Student Name: <span>{$studentName}</span></div>
  <div>Class: <span>{$className}</span></div>
  <div>Admission No.: <span>{$admissionNumber}</span></div>
  <div>Term: <span>{$termName}</span></div>
</div>
{$summaryHtml}
{$tableHeader}
<div class="comments">{$psychHtml}{$commentHtml}</div>
{$nextTermHtml}
<div class="signatures">{$sigHtml}</div>
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
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

        $payload = ResultReportBuilder::buildStudentPayload($result, $psychomotor, $config);

        return compact('result', 'psychomotor', 'config', 'payload');
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
    public function getPrintableReportCard(Request $request, $studentId, $termId, $academicYearId): JsonResponse
    {
        try {
            $response = $this->getReportCard($request, $studentId, $termId, $academicYearId);
            $data = json_decode($response->getContent(), true);

            if (isset($data['error'])) {
                return $response;
            }

            // Return HTML-ready data
            return response()->json([
                'message' => 'Report card data ready for printing',
                'data' => $data['report_card'],
                'note' => 'Frontend should render this as a printable HTML template'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to prepare printable report card',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

