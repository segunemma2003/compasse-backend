<?php

namespace App\Http\Controllers;

use App\Models\StudentResult;
use App\Models\Subject;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BroadsheetController extends Controller
{
    /**
     * CSV broadsheet: one row per student, columns per subject total_score, plus average and grade.
     */
    public function exportClassCsv(Request $request, int $classId): Response
    {
        $request->validate([
            'term_id' => 'required|exists:terms,id',
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        $rows = StudentResult::query()
            ->where('class_id', $classId)
            ->where('term_id', $request->term_id)
            ->where('academic_year_id', $request->academic_year_id)
            ->with(['student', 'subjectResults.subject'])
            ->orderBy('position')
            ->get();

        $subjectIds = $rows->flatMap(static fn (StudentResult $r) => $r->subjectResults->pluck('subject_id'))
            ->unique()
            ->sort()
            ->values();

        $subjects = Subject::query()
            ->whereIn('id', $subjectIds)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $headers = ['Position', 'Admission No.', 'Student Name'];
        foreach ($subjects as $s) {
            $headers[] = $s->name;
        }
        $headers[] = 'Average';
        $headers[] = 'Grade';

        $lines = [$this->csvLine($headers)];

        foreach ($rows as $r) {
            $bySub = $r->subjectResults->keyBy('subject_id');
            $line = [
                $r->position ?? '',
                $r->student?->admission_number ?? '',
                $r->student
                    ? trim(($r->student->first_name ?? '').' '.($r->student->last_name ?? ''))
                    : '',
            ];
            foreach ($subjects as $sid => $_sub) {
                $sr = $bySub->get($sid);
                $line[] = $sr !== null ? (string) $sr->total_score : '';
            }
            $line[] = $r->average_score !== null ? (string) $r->average_score : '';
            $line[] = (string) ($r->grade ?? '');
            $lines[] = $this->csvLine($line);
        }

        $csv = implode("\n", $lines);
        $filename = 'broadsheet-class-'.$classId.'-term-'.$request->term_id.'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function csvLine(array $cols): string
    {
        return implode(',', array_map(static fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $cols));
    }
}
