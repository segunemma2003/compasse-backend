<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\StudentResult;
use App\Models\Subject;
use App\Support\SpreadsheetXlsxBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BroadsheetController extends Controller
{
    /**
     * Excel (.xlsx) broadsheet with grid styling, metadata rows, and frozen header row.
     */
    public function exportClassExcel(Request $request, int $classId): Response|JsonResponse
    {
        $payload = $this->resolveBroadsheet($request, $classId);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $builder = SpreadsheetXlsxBuilder::fromRows(
            $this->buildSpreadsheetRows($payload),
            $this->spreadsheetSheetName($payload)
        )->freezeBelowRow(6);

        $content = $builder->build();
        $filename = sprintf(
            'broadsheet-class-%d-term-%s-%s.xlsx',
            $classId,
            $payload['meta']['term_id'],
            $payload['meta']['result_type']
        );

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * CSV broadsheet: one row per student, columns per subject total_score, plus average and grade.
     */
    public function exportClassCsv(Request $request, int $classId): Response|JsonResponse
    {
        $payload = $this->resolveBroadsheet($request, $classId);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $headers = ['Position', 'Admission No.', 'Student Name'];
        foreach ($payload['subjects'] as $subject) {
            $headers[] = $subject->name;
        }
        $headers[] = 'Average';
        $headers[] = 'Grade';

        $lines = [$this->csvLine($headers)];

        foreach ($payload['rows'] as $result) {
            $bySubject = $result->subjectResults->keyBy('subject_id');
            $line = [
                $result->position ?? '',
                $result->student?->admission_number ?? '',
                $this->studentName($result),
            ];

            foreach ($payload['subjects'] as $subject) {
                $subjectResult = $bySubject->get($subject->id);
                $line[] = $subjectResult !== null ? (string) $subjectResult->total_score : '';
            }

            $line[] = $result->average_score !== null ? (string) $result->average_score : '';
            $line[] = (string) ($result->grade ?? '');
            $lines[] = $this->csvLine($line);
        }

        $csv = implode("\n", $lines);
        $filename = sprintf(
            'broadsheet-class-%d-term-%s-%s.csv',
            $classId,
            $payload['meta']['term_id'],
            $payload['meta']['result_type']
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Print-ready HTML broadsheet (use browser Print → Save as PDF).
     */
    public function exportClassPrint(Request $request, int $classId): Response|JsonResponse
    {
        $payload = $this->resolveBroadsheet($request, $classId);
        if ($payload instanceof JsonResponse) {
            if ($payload->getStatusCode() === 404) {
                return response('<h2>No results found for this broadsheet.</h2>', 404)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }

            return $payload;
        }

        return response($this->renderPrintHtml($payload), 200)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Alias for the printable HTML view (same output as /print).
     */
    public function exportClassPdf(Request $request, int $classId): Response|JsonResponse
    {
        return $this->exportClassPrint($request, $classId);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveBroadsheet(Request $request, int $classId): array|JsonResponse
    {
        $request->validate([
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'result_type' => 'nullable|in:mid_term,end_term',
            'published_only' => 'nullable|boolean',
        ]);

        $user = Auth::user();
        $classIds = $this->accessibleClassIds($user);
        if ($classIds !== null && ! in_array($classId, $classIds, true)) {
            return $this->forbiddenResponse('You are not assigned to this class.');
        }

        $resultType = $request->input('result_type', 'end_term');
        $publishedOnly = $request->boolean('published_only');

        $query = StudentResult::query()
            ->where('class_id', $classId)
            ->where('term_id', $request->term_id)
            ->where('academic_year_id', $request->academic_year_id)
            ->where('result_type', $resultType)
            ->with(['student.user', 'class', 'term', 'academicYear', 'subjectResults.subject']);

        if ($publishedOnly) {
            $query->where('status', 'published');
        }

        $rows = $query->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('student_id')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'error' => 'No results found',
                'message' => $publishedOnly
                    ? 'No published results match this class, term, and result type.'
                    : 'Generate results for this class before exporting a broadsheet.',
            ], 404);
        }

        $subjectIds = $rows->flatMap(static fn (StudentResult $result) => $result->subjectResults->pluck('subject_id'))
            ->unique()
            ->sort()
            ->values();

        $subjects = Subject::query()
            ->whereIn('id', $subjectIds)
            ->orderBy('name')
            ->get();

        $class = $rows->first()->class ?? DB::table('classes')->where('id', $classId)->first();
        $term = $rows->first()->term;
        $year = $rows->first()->academicYear;
        $school = School::first();

        $averages = $rows->pluck('average_score')->filter(static fn ($value) => $value !== null);
        $stats = [
            'students' => $rows->count(),
            'class_average' => round((float) $averages->avg(), 2),
            'highest_average' => round((float) $averages->max(), 2),
            'lowest_average' => round((float) $averages->min(), 2),
        ];

        return [
            'rows' => $rows,
            'subjects' => $subjects,
            'meta' => [
                'class_id' => $classId,
                'class_name' => $class->name ?? 'Class',
                'term_id' => (int) $request->term_id,
                'term_name' => $term->name ?? 'Term',
                'academic_year' => $year->year ?? $year->name ?? '',
                'result_type' => $resultType,
                'published_only' => $publishedOnly,
            ],
            'stats' => $stats,
            'school' => $school,
        ];
    }

    private function renderPrintHtml(array $payload): string
    {
        $schoolName = e($payload['school']?->name ?? 'School');
        $className = e($payload['meta']['class_name']);
        $termName = e($payload['meta']['term_name']);
        $yearName = e($payload['meta']['academic_year']);
        $resultLabel = $payload['meta']['result_type'] === 'mid_term' ? 'Mid-term Broadsheet' : 'End-of-term Broadsheet';
        $generatedAt = e(now()->format('d M Y, H:i'));
        $stats = $payload['stats'];

        $subjectHeaders = '';
        foreach ($payload['subjects'] as $subject) {
            $subjectHeaders .= '<th>'.e($subject->name).'</th>';
        }

        $bodyRows = '';
        $rowIndex = 0;
        foreach ($payload['rows'] as $result) {
            $bySubject = $result->subjectResults->keyBy('subject_id');
            $rowClass = $rowIndex % 2 === 1 ? ' class="alt"' : '';
            $cells = '<td>'.e((string) ($result->position ?? '')).'</td>'
                .'<td>'.e($result->student?->admission_number ?? '').'</td>'
                .'<td>'.e($this->studentName($result)).'</td>';

            foreach ($payload['subjects'] as $subject) {
                $subjectResult = $bySubject->get($subject->id);
                $score = $subjectResult !== null ? number_format((float) $subjectResult->total_score, 1) : '';
                $cells .= '<td class="num">'.e($score).'</td>';
            }

            $cells .= '<td class="num bold">'
                .e($result->average_score !== null ? number_format((float) $result->average_score, 1) : '')
                .'</td>';
            $cells .= '<td class="num">'.e((string) ($result->grade ?? '')).'</td>';
            $bodyRows .= '<tr'.$rowClass.'>'.$cells.'</tr>';
            $rowIndex++;
        }

        $emptySubjectsNote = $payload['subjects']->isEmpty()
            ? '<tr><td colspan="6" class="note">This class uses comment-only results — no subject score columns are available.</td></tr>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Broadsheet – {$className}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Calibri, "Segoe UI", Arial, sans-serif; font-size: 11px; color: #000; padding: 12px; background: #fff; }
  .sheet { border: 1px solid #000; }
  .meta-table, .grid-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .meta-table td { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; }
  .meta-table .title { font-size: 16px; font-weight: bold; }
  .meta-table .subtitle { font-size: 12px; color: #333; }
  .meta-table .stats { font-size: 11px; background: #f2f2f2; }
  .grid-table th, .grid-table td { border: 1px solid #000; padding: 4px 6px; font-size: 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .grid-table th { background: #217346; color: #fff; font-weight: bold; text-align: center; }
  .grid-table td.num { text-align: center; font-variant-numeric: tabular-nums; }
  .grid-table td.bold { font-weight: bold; }
  .grid-table tr.alt td { background: #f2f2f2; }
  .grid-table td.note { text-align: center; color: #666; white-space: normal; }
  .footer { margin-top: 8px; font-size: 10px; color: #666; }
  @media print {
    body { padding: 0; }
    @page { size: landscape; margin: 0.8cm; }
  }
</style>
</head>
<body>
<div class="sheet">
  <table class="meta-table">
    <tr><td class="title" colspan="99">{$schoolName}</td></tr>
    <tr><td class="subtitle" colspan="99">{$resultLabel}</td></tr>
    <tr><td colspan="99">Class: {$className} &nbsp;|&nbsp; Term: {$termName} &nbsp;|&nbsp; Session: {$yearName}</td></tr>
    <tr><td class="stats" colspan="99">Students: {$stats['students']} &nbsp;|&nbsp; Class Average: {$stats['class_average']} &nbsp;|&nbsp; Highest Avg: {$stats['highest_average']} &nbsp;|&nbsp; Lowest Avg: {$stats['lowest_average']}</td></tr>
  </table>
  <table class="grid-table">
    <thead>
      <tr>
        <th style="width:36px;">Pos</th>
        <th style="width:72px;">Adm. No.</th>
        <th style="width:140px;">Student</th>
        {$subjectHeaders}
        <th style="width:56px;">Average</th>
        <th style="width:48px;">Grade</th>
      </tr>
    </thead>
    <tbody>{$emptySubjectsNote}{$bodyRows}</tbody>
  </table>
</div>
<div class="footer">Generated {$generatedAt} — use Print → Save as PDF for a spreadsheet-style PDF.</div>
<script>window.onload = function() { window.print(); };</script>
</body>
</html>
HTML;
    }

    /**
     * @return list<list<array{value: mixed, style?: string, type?: string}>>
     */
    private function buildSpreadsheetRows(array $payload): array
    {
        $schoolName = $payload['school']?->name ?? 'School';
        $resultLabel = $payload['meta']['result_type'] === 'mid_term' ? 'Mid-term Broadsheet' : 'End-of-term Broadsheet';
        $metaLine = sprintf(
            'Class: %s | Term: %s | Session: %s',
            $payload['meta']['class_name'],
            $payload['meta']['term_name'],
            $payload['meta']['academic_year']
        );
        $stats = $payload['stats'];
        $statsLine = sprintf(
            'Students: %d | Class Average: %s | Highest Avg: %s | Lowest Avg: %s',
            $stats['students'],
            $stats['class_average'],
            $stats['highest_average'],
            $stats['lowest_average']
        );

        $headers = ['Pos', 'Admission No.', 'Student Name'];
        foreach ($payload['subjects'] as $subject) {
            $headers[] = $subject->name;
        }
        $headers[] = 'Average';
        $headers[] = 'Grade';

        $rows = [
            [['value' => $schoolName, 'style' => 'title']],
            [['value' => $resultLabel, 'style' => 'meta']],
            [['value' => $metaLine, 'style' => 'meta']],
            [['value' => $statsLine, 'style' => 'meta']],
            [['value' => '']],
        ];

        $headerRow = [];
        foreach ($headers as $header) {
            $headerRow[] = ['value' => $header, 'style' => 'header'];
        }
        $rows[] = $headerRow;

        $alternate = false;
        foreach ($payload['rows'] as $result) {
            $bySubject = $result->subjectResults->keyBy('subject_id');
            $rowStyle = $alternate ? 'dataAlt' : 'data';
            $alternate = ! $alternate;

            $line = [
                $this->spreadsheetCell($result->position, 'number'),
                ['value' => $result->student?->admission_number ?? '', 'style' => $rowStyle],
                ['value' => $this->studentName($result), 'style' => $rowStyle],
            ];

            foreach ($payload['subjects'] as $subject) {
                $subjectResult = $bySubject->get($subject->id);
                $line[] = $subjectResult !== null
                    ? $this->spreadsheetCell($subjectResult->total_score, 'number')
                    : ['value' => '', 'style' => 'number'];
            }

            $line[] = $result->average_score !== null
                ? $this->spreadsheetCell($result->average_score, 'number')
                : ['value' => '', 'style' => 'number'];
            $line[] = ['value' => (string) ($result->grade ?? ''), 'style' => $rowStyle];
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * @return array{value: mixed, style: string, type?: string}
     */
    private function spreadsheetCell(mixed $value, string $style): array
    {
        if ($value === null || $value === '') {
            return ['value' => '', 'style' => $style];
        }

        if (is_numeric($value)) {
            return ['value' => (float) $value, 'style' => $style, 'type' => 'number'];
        }

        return ['value' => (string) $value, 'style' => $style];
    }

    private function spreadsheetSheetName(array $payload): string
    {
        $name = sprintf('%s %s', $payload['meta']['class_name'], $payload['meta']['term_name']);

        return preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name) ?? 'Broadsheet';
    }

    private function studentName(StudentResult $result): string
    {
        $fromUser = trim((string) ($result->student?->user?->name ?? ''));
        if ($fromUser !== '') {
            return $fromUser;
        }

        return trim(($result->student?->first_name ?? '').' '.($result->student?->last_name ?? ''));
    }

    private function csvLine(array $cols): string
    {
        return implode(',', array_map(static fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $cols));
    }
}
