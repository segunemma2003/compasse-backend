<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draft results follow the scores and Result Settings as they are now. Results are worked out when
 * the school presses Generate, so a class generated before a score was entered, a column was fixed or
 * a setting changed kept printing the old figures ("result settings not working"). When draft results
 * are opened and anything they are built from changed since, the class's results are generated again.
 *
 * Approved or published results are never touched: generating sends results back to draft.
 */
class ResultFreshness
{
    /** @var array<string,true> classes already checked in this request */
    private static array $checked = [];

    private static function key(int $classId, int $termId, int $yearId, string $type): string
    {
        return "results_generated:{$classId}:{$termId}:{$yearId}:{$type}";
    }

    public static function markGenerated(int $classId, int $termId, int $yearId, string $type): void
    {
        try {
            DB::table('settings')->updateOrInsert(
                ['key' => self::key($classId, $termId, $yearId, $type)],
                ['value' => now()->toDateTimeString(), 'updated_at' => now(), 'created_at' => now()]
            );
        } catch (\Throwable) {
            // only an optimisation for the next check
        }
    }

    /** The latest change to anything this class's results are built from. */
    public static function lastChange(int $classId, int $termId, int $yearId): ?string
    {
        $times = [];
        $times[] = DB::table('ca_scores')->join('continuous_assessments as ca', 'ca.id', '=', 'ca_scores.continuous_assessment_id')
            ->where('ca.class_id', $classId)->where('ca.term_id', $termId)->max('ca_scores.updated_at');
        $times[] = DB::table('continuous_assessments')->where('class_id', $classId)->where('term_id', $termId)->max('updated_at');
        $times[] = DB::table('exams')->where('class_id', $classId)->where('term_id', $termId)->max('updated_at');
        if (Schema::hasTable('exam_submissions')) {
            $times[] = DB::table('exam_submissions')->join('exams', 'exams.id', '=', 'exam_submissions.exam_id')
                ->where('exams.class_id', $classId)->where('exams.term_id', $termId)->max('exam_submissions.updated_at');
        }
        if (Schema::hasTable('subject_grades')) {
            $times[] = DB::table('subject_grades')->join('subjects', 'subjects.id', '=', 'subject_grades.subject_id')
                ->where('subjects.class_id', $classId)->where('subject_grades.term_id', $termId)->max('subject_grades.updated_at');
        }
        $times[] = DB::table('subjects')->where('class_id', $classId)->max('updated_at');
        $times[] = DB::table('classes')->where('id', $classId)->value('updated_at');
        $times[] = DB::table('result_configurations')->max('updated_at');
        if (Schema::hasTable('grading_systems')) {
            $times[] = DB::table('grading_systems')->max('updated_at');
        }
        if (Schema::hasColumn('students', 'deleted_at')) {
            $times[] = DB::table('students')->where('class_id', $classId)->max('deleted_at');
        }
        $times = array_filter($times);

        return $times ? max(array_map('strval', $times)) : null;
    }

    /** Generate the class's results again when they are all drafts and something changed since. */
    public static function ensure(int $classId, int $termId, int $yearId, string $type = 'end_term'): bool
    {
        $type = $type === 'mid_term' ? 'mid_term' : 'end_term';
        $k = self::key($classId, $termId, $yearId, $type);
        if (isset(self::$checked[$k]) || ! $classId || ! $termId || ! $yearId) {
            return false;
        }
        self::$checked[$k] = true;
        // Only when the school's staff open them (parents and students see published results only).
        if (in_array((string) (auth()->user()?->role ?? ''), ['student', 'guardian', 'parent'], true)) {
            return false;
        }

        try {
            $rows = DB::table('student_results')->where('class_id', $classId)->where('term_id', $termId)
                ->where('academic_year_id', $yearId)->where('result_type', $type);
            if (! (clone $rows)->exists() || (clone $rows)->where('status', '!=', 'draft')->exists()) {
                return false; // nothing generated yet, or approved / published: leave as they are
            }
            $generated = DB::table('settings')->where('key', $k)->value('value') ?: (clone $rows)->min('updated_at');
            $changed = self::lastChange($classId, $termId, $yearId);
            if (! $changed || (string) $changed <= (string) $generated) {
                return false;
            }

            $req = Request::create('/', 'POST', ['class_id' => $classId, 'term_id' => $termId, 'academic_year_id' => $yearId, 'result_type' => $type]);
            $res = app(\App\Http\Controllers\ResultController::class)->runGeneration($req);

            return $res->getStatusCode() === 200;
        } catch (\Throwable $e) {
            report($e); // showing the result as it was is better than not showing it

            return false;
        }
    }
}
