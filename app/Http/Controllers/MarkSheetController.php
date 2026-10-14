<?php

namespace App\Http\Controllers;

use App\Models\ContinuousAssessment;
use App\Models\Exam;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Support\ExamScoreSync;
use App\Support\ResultReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mark sheet: one grid per class + subject + term where a teacher types every
 * student's CA and exam scores (or uploads them as CSV). Columns are the
 * subject's CAs for the term and its exam; when none exist yet they are
 * proposed from the class's result settings (e.g. 1st CA /10, 2nd CA /10 …
 * Exam /60) and created on first save. Scores land in the same tables result
 * generation reads (ca_scores, exam_submissions).
 */
class MarkSheetController extends Controller
{
    private function context(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'term_id' => 'required|integer|exists:terms,id',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
        ]);
        $data['academic_year_id'] = $data['academic_year_id']
            ?? DB::table('terms')->where('id', $data['term_id'])->value('academic_year_id')
            ?? DB::table('academic_years')->where('is_current', true)->value('id');
        if (! $data['academic_year_id']) {
            return response()->json(['message' => 'Choose an academic year.'], 422);
        }
        if ($denied = $this->requireCapability($request, 'cbt.manage')) {
            return $denied;
        }
        if ($denied = $this->assertCanManageSubjectResource($request->user(), (int) $data['subject_id'], (int) $data['class_id'], 'mark sheet')) {
            return $denied;
        }

        return $data;
    }

    /** Columns for this sheet: existing CAs + exam, else proposals from the result settings. */
    private function columns(array $ctx): array
    {
        $school = School::first();
        $config = ResultReportBuilder::resolveConfigForClass((int) $ctx['class_id'], (int) ($school?->id ?? 0));
        $caWeight = $config ? (float) $config->ca_weight : 40.0;
        $examWeight = $config ? (float) $config->exam_weight : 60.0;

        $cols = [];
        $cas = ContinuousAssessment::where('class_id', $ctx['class_id'])->where('subject_id', $ctx['subject_id'])
            ->where('term_id', $ctx['term_id'])->orderBy('assessment_date')->orderBy('id')->get();
        foreach ($cas as $ca) {
            $cols[] = ['key' => "ca:{$ca->id}", 'kind' => 'ca', 'label' => $ca->name, 'max' => (float) $ca->total_marks, 'exists' => true];
        }
        if ($cas->isEmpty() && $caWeight > 0) {
            $components = is_array($config?->assessment_components) ? $config->assessment_components : [];
            if (! $components) {
                $components = [['name' => '1st CA', 'max' => $caWeight / 2], ['name' => '2nd CA', 'max' => $caWeight / 2]];
            }
            foreach (array_values($components) as $i => $c) {
                $cols[] = ['key' => "new-ca:{$i}", 'kind' => 'ca', 'label' => (string) ($c['name'] ?? 'CA ' . ($i + 1)),
                    'max' => (float) ($c['max'] ?? $c['weight'] ?? 10), 'exists' => false];
            }
        }

        $exam = Exam::where('class_id', $ctx['class_id'])->where('subject_id', $ctx['subject_id'])
            ->where('term_id', $ctx['term_id'])->where('academic_year_id', $ctx['academic_year_id'])
            ->orderByRaw("type = 'exam' desc")->orderByDesc('id')->first();
        if ($exam) {
            $cols[] = ['key' => "exam:{$exam->id}", 'kind' => 'exam', 'label' => $exam->is_cbt ? "{$exam->name} (CBT)" : $exam->name, 'max' => (float) $exam->total_marks, 'exists' => true];
        } elseif ($examWeight > 0) {
            $cols[] = ['key' => 'new-exam', 'kind' => 'exam', 'label' => 'Exam', 'max' => $examWeight, 'exists' => false];
        }

        return ['columns' => $cols, 'ca_weight' => $caWeight, 'exam_weight' => $examWeight];
    }

    /** GET /assessments/marksheet?class_id&subject_id&term_id[&academic_year_id] */
    public function show(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $layout = $this->columns($ctx);

        $students = Student::where('class_id', $ctx['class_id'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'admission_number']);
        $ids = $students->pluck('id');

        $caIds = collect($layout['columns'])->where('kind', 'ca')->where('exists', true)->map(fn ($c) => (int) substr($c['key'], 3));
        $caScores = DB::table('ca_scores')->whereIn('continuous_assessment_id', $caIds)->whereIn('student_id', $ids)
            ->get(['continuous_assessment_id', 'student_id', 'score'])->groupBy('student_id');
        $examCol = collect($layout['columns'])->first(fn ($c) => $c['kind'] === 'exam' && $c['exists']);
        $examScores = $examCol
            ? DB::table('exam_submissions')->where('exam_id', (int) substr($examCol['key'], 5))->whereIn('student_id', $ids)->pluck('score', 'student_id')
            : collect();

        $rows = $students->map(function ($s) use ($caScores, $examScores, $examCol) {
            $values = [];
            foreach ($caScores[$s->id] ?? [] as $r) {
                $values["ca:{$r->continuous_assessment_id}"] = (float) $r->score;
            }
            if ($examCol && isset($examScores[$s->id]) && $examScores[$s->id] !== null) {
                $values[$examCol['key']] = (float) $examScores[$s->id];
            }

            return ['student_id' => $s->id, 'name' => trim("{$s->first_name} {$s->last_name}"), 'admission_number' => $s->admission_number, 'values' => (object) $values];
        });

        return response()->json($layout + ['academic_year_id' => (int) $ctx['academic_year_id'], 'rows' => $rows]);
    }

    /** POST /assessments/marksheet {class_id, subject_id, term_id, academic_year_id?, columns?, rows: [{student_id, values: {key: score|null}}]} */
    public function save(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $request->validate([
            'rows' => 'required|array',
            'rows.*.student_id' => 'required|integer',
            'rows.*.values' => 'nullable|array',
            'columns' => 'nullable|array',
            'columns.*.key' => 'required|string',
            'columns.*.label' => 'nullable|string|max:100',
            'columns.*.max' => 'nullable|numeric|min:1|max:1000',
        ]);

        $layout = $this->columns($ctx);
        $edits = collect($request->input('columns', []))->keyBy('key');
        $classStudents = Student::where('class_id', $ctx['class_id'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $teacherId = DB::table('teachers')->where('user_id', $request->user()->id)->value('id');
        $schoolId = School::first()?->id;

        // Validate every score against its column's maximum before writing anything.
        $maxByKey = [];
        foreach ($layout['columns'] as $c) {
            $maxByKey[$c['key']] = (float) ($edits[$c['key']]['max'] ?? $c['max']);
        }
        $errors = [];
        foreach ($request->input('rows') as $row) {
            foreach ((array) ($row['values'] ?? []) as $key => $v) {
                if ($v === null || $v === '' || ! isset($maxByKey[$key])) {
                    continue;
                }
                if (! is_numeric($v) || $v < 0 || $v > $maxByKey[$key]) {
                    $errors[] = "Student #{$row['student_id']}: {$v} is not between 0 and {$maxByKey[$key]}.";
                }
            }
        }
        if ($errors) {
            return response()->json(['message' => 'Some scores are out of range.', 'errors' => array_slice($errors, 0, 20)], 422);
        }

        $saved = 0;
        DB::transaction(function () use ($layout, $edits, $request, $ctx, $classStudents, $teacherId, $schoolId, &$saved) {
            // Create proposed columns on first save; real ones keep their id.
            $resolved = [];
            foreach ($layout['columns'] as $c) {
                $label = trim((string) ($edits[$c['key']]['label'] ?? $c['label'])) ?: $c['label'];
                $max = (float) ($edits[$c['key']]['max'] ?? $c['max']);
                if ($c['exists']) {
                    $resolved[$c['key']] = $c;
                    continue;
                }
                if ($c['kind'] === 'ca') {
                    $ca = ContinuousAssessment::create([
                        'school_id' => $schoolId, 'subject_id' => $ctx['subject_id'], 'class_id' => $ctx['class_id'],
                        'term_id' => $ctx['term_id'], 'academic_year_id' => $ctx['academic_year_id'], 'teacher_id' => $teacherId,
                        'name' => $label, 'type' => 'test', 'total_marks' => $max, 'status' => 'published',
                    ]);
                    $resolved[$c['key']] = ['kind' => 'ca', 'id' => $ca->id, 'max' => $max];
                } else {
                    $exam = Exam::create([
                        'school_id' => $schoolId, 'subject_id' => $ctx['subject_id'], 'class_id' => $ctx['class_id'],
                        'term_id' => $ctx['term_id'], 'academic_year_id' => $ctx['academic_year_id'],
                        'name' => $label, 'type' => 'exam', 'duration_minutes' => 60, 'total_marks' => $max,
                        'passing_marks' => round($max / 2, 2), 'start_date' => Carbon::now(), 'end_date' => Carbon::now(),
                        'is_cbt' => false, 'status' => 'completed', 'created_by' => $request->user()->id,
                    ]);
                    $resolved[$c['key']] = ['kind' => 'exam', 'id' => $exam->id, 'max' => $max];
                }
            }

            foreach ($request->input('rows') as $row) {
                $sid = (int) $row['student_id'];
                if (! in_array($sid, $classStudents, true)) {
                    continue;
                }
                foreach ((array) ($row['values'] ?? []) as $key => $v) {
                    $col = $resolved[$key] ?? null;
                    if (! $col) {
                        continue;
                    }
                    $id = $col['id'] ?? (int) substr($key, strpos($key, ':') + 1);
                    if ($col['kind'] === 'ca') {
                        if ($v === null || $v === '') {
                            DB::table('ca_scores')->where('continuous_assessment_id', $id)->where('student_id', $sid)->delete();
                            continue;
                        }
                        DB::table('ca_scores')->updateOrInsert(
                            ['continuous_assessment_id' => $id, 'student_id' => $sid],
                            ['score' => (float) $v, 'recorded_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]
                        );
                    } else {
                        if ($v === null || $v === '') {
                            continue;
                        }
                        ExamScoreSync::write(examId: $id, studentId: $sid, subjectId: (int) $ctx['subject_id'],
                            score: (float) $v, totalMarks: (float) $col['max'], recordedBy: $request->user()->id);
                    }
                    $saved++;
                }
            }
        });

        return response()->json(['message' => "Saved {$saved} score(s)."] + $this->show($request)->getData(true));
    }
}
