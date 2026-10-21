<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentResult;
use App\Support\ClassTeachers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark Sheet → Comments & height/weight: for a whole class at once, the class teacher's comment
 * (attitude), the head's comment (academic) and each child's height and weight for the term.
 * Comments can be typed before results are generated; they are kept in result_remarks and also
 * written onto the result when it exists.
 */
class ResultRemarksController extends Controller
{
    private const LEADERSHIP = ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'];

    private function rights(Request $request, int $classId): array
    {
        $u = $request->user() ?: auth()->user();
        $leader = $u && in_array($u->role, self::LEADERSHIP, true);
        $classTeacher = $u?->teacher && in_array((int) $u->teacher->id, array_map('intval', ClassTeachers::teacherIdsFor($classId)), true);
        $allowed = $u ? $this->accessibleClassIds($u) : [];
        $sees = $leader || $allowed === null || in_array($classId, array_map('intval', $allowed ?? []), true);

        return ['see' => $sees, 'class_teacher' => $leader || $classTeacher, 'principal' => $leader, 'vitals' => $leader || $classTeacher || $sees];
    }

    /** GET /assessments/remarks?class_id=&term_id=&academic_year_id= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['class_id' => 'required|integer', 'term_id' => 'required|integer', 'academic_year_id' => 'required|integer']);
        $rights = $this->rights($request, (int) $v['class_id']);
        if (! $rights['see']) {
            return $this->forbiddenResponse('That is not one of your classes.');
        }
        $students = Student::where('class_id', $v['class_id'])->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'admission_number']);
        $ids = $students->pluck('id');
        $results = StudentResult::whereIn('student_id', $ids)->where('term_id', $v['term_id'])->where('academic_year_id', $v['academic_year_id'])
            ->where('result_type', 'end_term')->get(['id', 'student_id', 'class_teacher_comment', 'principal_comment', 'average_score'])->keyBy('student_id');
        $remarks = Schema::hasTable('result_remarks')
            ? DB::table('result_remarks')->whereIn('student_id', $ids)->where('term_id', $v['term_id'])->where('academic_year_id', $v['academic_year_id'])->get()->keyBy('student_id')
            : collect();
        $vitals = Schema::hasTable('student_term_vitals')
            ? DB::table('student_term_vitals')->whereIn('student_id', $ids)->where('term_id', $v['term_id'])->where('academic_year_id', $v['academic_year_id'])->get()->keyBy('student_id')
            : collect();

        return response()->json([
            'rows' => $students->map(fn ($s) => [
                'student_id' => $s->id, 'name' => trim("{$s->first_name} {$s->last_name}"), 'admission_number' => $s->admission_number,
                'class_teacher_comment' => $results[$s->id]?->class_teacher_comment ?: ($remarks[$s->id]->class_teacher_comment ?? null),
                'principal_comment' => $results[$s->id]?->principal_comment ?: ($remarks[$s->id]->principal_comment ?? null),
                'height' => $vitals[$s->id]->height_end ?? $vitals[$s->id]->height_beginning ?? null,
                'weight' => $vitals[$s->id]->weight_end ?? $vitals[$s->id]->weight_beginning ?? null,
                'average' => $results[$s->id]?->average_score !== null ? round((float) $results[$s->id]->average_score, 1) : null,
                'has_result' => isset($results[$s->id]),
            ])->values(),
            'can_edit_class_teacher_comment' => $rights['class_teacher'],
            'can_edit_principal_comment' => $rights['principal'],
            'can_edit_vitals' => $rights['vitals'],
        ]);
    }

    /** PUT /assessments/remarks {class_id, term_id, academic_year_id, rows: [{student_id, class_teacher_comment?, principal_comment?, height?, weight?}]} */
    public function save(Request $request): JsonResponse
    {
        $v = $request->validate([
            'class_id' => 'required|integer', 'term_id' => 'required|integer', 'academic_year_id' => 'required|integer',
            'rows' => 'required|array|max:300', 'rows.*.student_id' => 'required|integer',
            'rows.*.class_teacher_comment' => 'nullable|string|max:1000', 'rows.*.principal_comment' => 'nullable|string|max:1000',
            'rows.*.height' => 'nullable|numeric|min:0|max:300', 'rows.*.weight' => 'nullable|numeric|min:0|max:500',
        ]);
        $rights = $this->rights($request, (int) $v['class_id']);
        if (! $rights['see']) {
            return $this->forbiddenResponse('That is not one of your classes.');
        }
        $inClass = Student::where('class_id', $v['class_id'])->pluck('id')->map(fn ($i) => (int) $i)->all();
        $by = ($request->user() ?: auth()->user())?->id;
        $saved = 0;
        DB::transaction(function () use ($v, $rights, $inClass, $by, &$saved) {
            foreach ($v['rows'] as $row) {
                $sid = (int) $row['student_id'];
                if (! in_array($sid, $inClass, true)) {
                    continue;
                }
                $key = ['student_id' => $sid, 'term_id' => $v['term_id'], 'academic_year_id' => $v['academic_year_id']];
                $comments = [];
                if ($rights['class_teacher'] && array_key_exists('class_teacher_comment', $row)) {
                    $comments['class_teacher_comment'] = trim((string) $row['class_teacher_comment']) ?: null;
                }
                if ($rights['principal'] && array_key_exists('principal_comment', $row)) {
                    $comments['principal_comment'] = trim((string) $row['principal_comment']) ?: null;
                }
                if ($comments && Schema::hasTable('result_remarks')) {
                    DB::table('result_remarks')->updateOrInsert($key, $comments + ['updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
                    StudentResult::where($key)->where('result_type', 'end_term')->update($comments);
                }
                if ($rights['vitals'] && (array_key_exists('height', $row) || array_key_exists('weight', $row)) && Schema::hasTable('student_term_vitals')) {
                    $vals = [];
                    if (array_key_exists('height', $row)) {
                        $vals['height_end'] = $row['height'] === null || $row['height'] === '' ? null : (float) $row['height'];
                    }
                    if (array_key_exists('weight', $row)) {
                        $vals['weight_end'] = $row['weight'] === null || $row['weight'] === '' ? null : (float) $row['weight'];
                    }
                    DB::table('student_term_vitals')->updateOrInsert($key, $vals + ['recorded_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
                }
                $saved++;
            }
        });

        return response()->json(['message' => "Saved for {$saved} student(s)"]);
    }
}
