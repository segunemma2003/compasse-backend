<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\GradingSystem;
use App\Models\Question;
use App\Models\School;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * CBT report for teachers and admins: every student in the class with their score, grade and
 * position, how each question went (who got it right), and each student's answer sheet.
 */
class CbtReportController extends Controller
{
    private function answerText(Question $q, $answerData, ?string $answerText): string
    {
        $vals = is_array($answerData) ? $answerData : ($answerData ? [$answerData] : []);
        if (! $vals && $answerText) {
            $vals = [$answerText];
        }

        return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $vals));
    }

    private function correctText(Question $q): string
    {
        if ($q->question_type === 'essay') {
            return '(marked by teacher)';
        }
        $values = $q->optionValues();
        $correct = $q->correctAnswers();
        // Show the option as written (not lower-cased).
        $shown = [];
        foreach ($correct as $c) {
            $match = collect($values)->first(fn ($v) => Question::normaliseAnswer($v) === $c);
            $shown[] = $match ?? $c;
        }

        return implode(', ', $shown);
    }

    /** GET /assessments/exams/{exam}/cbt-report */
    /**
     * GET /assessments/cbt-reports?class_id=&term_id= — every CBT this user can see, with how many sat
     * it, the average and how many passed (open one for names, scores and every question).
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $exams = Exam::with(['subject:id,name', 'class:id,name'])
            ->where(fn ($q) => $q->where('is_cbt', true)->orWhereIn('id', Question::select('exam_id')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->term_id))
            ->orderByDesc('id')->limit(300)->get()
            ->filter(fn ($e) => $this->assertCanManageExam($user, $e) === null);
        $stats = DB::table('exam_attempts')->whereIn('exam_id', $exams->pluck('id'))->whereIn('status', ['completed', 'submitted', 'graded'])
            ->groupBy('exam_id')->select('exam_id', DB::raw('count(distinct student_id) as sat'), DB::raw('avg(percentage) as avg_pct'), DB::raw('max(percentage) as max_pct'))
            ->get()->keyBy('exam_id');
        $rows = $exams->map(function ($e) use ($stats) {
            $st = $stats[$e->id] ?? null;
            $passPct = $e->total_marks > 0 && $e->passing_marks ? ($e->passing_marks / $e->total_marks) * 100 : null;
            $passed = $st && $passPct !== null ? DB::table('exam_attempts')->where('exam_id', $e->id)->whereIn('status', ['completed', 'submitted', 'graded'])->where('percentage', '>=', $passPct)->distinct('student_id')->count('student_id') : null;

            return [
                'id' => $e->id, 'name' => $e->name, 'subject' => $e->subject?->name, 'class' => $e->class?->name, 'status' => $e->status,
                'date' => $e->start_date, 'sat' => (int) ($st->sat ?? 0), 'average' => $st ? round((float) $st->avg_pct, 1) : null,
                'highest' => $st ? round((float) $st->max_pct, 1) : null, 'passed' => $passed,
            ];
        })->values();

        return response()->json(['exams' => $rows]);
    }

    /** GET /assessments/cbt-reports/student/{studentId} — one student's CBT history: every exam, score and %. */
    public function studentHistory(Request $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);
        $classIds = $this->accessibleClassIds(Auth::user());
        if ($classIds !== null && ! in_array((int) $student->class_id, array_map('intval', $classIds), true)) {
            return $this->forbiddenResponse('That student is not in one of your classes.');
        }
        $rows = DB::table('exam_attempts')->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'exams.subject_id')
            ->where('exam_attempts.student_id', $studentId)
            ->orderByDesc('exam_attempts.completed_at')
            ->get(['exams.id as exam_id', 'exams.name', 'subjects.name as subject', 'exams.total_marks', 'exam_attempts.total_score', 'exam_attempts.percentage', 'exam_attempts.status', 'exam_attempts.completed_at']);

        return response()->json(['student' => ['id' => $student->id, 'name' => trim("{$student->first_name} {$student->last_name}")], 'attempts' => $rows]);
    }

    public function show(Request $request, Exam $exam): JsonResponse
    {
        if ($denied = $this->assertCanManageExam(Auth::user(), $exam)) {
            return $denied;
        }
        $exam->loadMissing(['subject:id,name', 'class:id,name']);
        $questions = Question::where('exam_id', $exam->id)->orderBy('id')->get();

        // Latest attempt per student.
        $attempts = DB::table('exam_attempts')->where('exam_id', $exam->id)->orderByDesc('id')->get()->unique('student_id')->keyBy('student_id');
        $answers = DB::table('answers')->whereIn('exam_attempt_id', $attempts->pluck('id'))->get(['exam_attempt_id', 'question_id', 'is_correct', 'marks_obtained'])
            ->groupBy('exam_attempt_id');

        $students = Student::where('class_id', $exam->class_id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'admission_number']);
        // Students who sat it but have since left the class still appear.
        $extra = $attempts->keys()->diff($students->pluck('id'));
        if ($extra->isNotEmpty()) {
            $students = $students->concat(Student::whereIn('id', $extra)->get(['id', 'first_name', 'last_name', 'admission_number']));
        }

        $school = School::first();
        $grading = GradingSystem::where('school_id', $school?->id)->where('is_default', true)->first();
        $total = (float) $exam->total_marks ?: (float) $questions->sum('marks');
        $qCount = $questions->count();

        $rows = $students->map(function ($s) use ($attempts, $answers, $qCount, $total, $grading) {
            $a = $attempts[$s->id] ?? null;
            $ans = $a ? ($answers[$a->id] ?? collect()) : collect();
            $correct = $ans->filter(fn ($x) => (bool) $x->is_correct)->count();
            $wrong = $ans->filter(fn ($x) => $x->is_correct !== null && ! $x->is_correct)->count();
            $pending = $ans->filter(fn ($x) => $x->is_correct === null)->count();
            $score = $a ? (float) ($a->total_score ?? $ans->sum('marks_obtained')) : null;
            $pct = $a && $total > 0 ? round($score / $total * 100, 1) : null;

            return [
                'student_id' => $s->id,
                'name' => trim("{$s->first_name} {$s->last_name}"),
                'admission_number' => $s->admission_number,
                'status' => $a ? $a->status : 'not_taken',
                'score' => $score,
                'percentage' => $pct,
                'grade' => $pct !== null && $grading ? $grading->getGrade($pct)['grade'] : null,
                'correct' => $a ? $correct : null,
                'wrong' => $a ? $wrong : null,
                'awaiting_marking' => $a ? $pending : null,
                'unanswered' => $a ? max(0, $qCount - $ans->count()) : null,
                'time_taken_minutes' => $a?->time_taken_minutes,
                'completed_at' => $a?->completed_at,
            ];
        })->values();

        // Positions among those who sat it (ties share a position).
        $ranked = $rows->filter(fn ($r) => $r['score'] !== null)->sortByDesc('score')->values();
        $pos = [];
        foreach ($ranked as $i => $r) {
            $pos[$r['student_id']] = $i > 0 && $r['score'] === $ranked[$i - 1]['score'] ? $pos[$ranked[$i - 1]['student_id']] : $i + 1;
        }
        $rows = $rows->map(fn ($r) => $r + ['position' => $pos[$r['student_id']] ?? null]);

        // How each question went.
        $allAnswers = $answers->flatten(1);
        $sat = $attempts->count();
        $qRows = $questions->values()->map(function ($q, $i) use ($allAnswers, $sat) {
            $forQ = $allAnswers->where('question_id', $q->id);
            $right = $forQ->filter(fn ($x) => (bool) $x->is_correct)->count();
            $wrong = $forQ->filter(fn ($x) => $x->is_correct !== null && ! $x->is_correct)->count();

            return [
                'number' => $i + 1, 'question_id' => $q->id, 'question' => trim(strip_tags((string) $q->question_text)), 'type' => $q->question_type,
                'marks' => (float) $q->marks, 'correct_answer' => $this->correctText($q),
                'correct' => $right, 'wrong' => $wrong, 'unanswered' => max(0, $sat - $forQ->count()),
                'percent_correct' => $sat ? round($right / $sat * 100) : null,
            ];
        });

        $scores = $ranked->pluck('score');
        $pass = (float) ($exam->passing_marks ?? 0);

        return response()->json([
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'subject' => $exam->subject?->name, 'class' => $exam->class?->name,
                'total_marks' => $total, 'pass_mark' => $pass, 'questions' => $qCount],
            'summary' => [
                'students' => $rows->count(), 'sat' => $sat, 'not_taken' => $rows->where('status', 'not_taken')->count(),
                'average' => $scores->count() ? round($scores->avg(), 1) : null, 'highest' => $scores->max(), 'lowest' => $scores->min(),
                'passed' => $pass > 0 ? $scores->filter(fn ($sc) => $sc >= $pass)->count() : null,
            ],
            'students' => $rows->sortBy(fn ($r) => [$r['position'] ?? PHP_INT_MAX, $r['name']])->values(),
            'questions' => $qRows,
        ]);
    }

    /** GET /assessments/exams/{exam}/cbt-report/students/{studentId} — one student's answer sheet. */
    public function student(Request $request, Exam $exam, int $studentId): JsonResponse
    {
        if ($denied = $this->assertCanManageExam(Auth::user(), $exam)) {
            return $denied;
        }
        $attempt = DB::table('exam_attempts')->where('exam_id', $exam->id)->where('student_id', $studentId)->orderByDesc('id')->first();
        $s = Student::find($studentId, ['id', 'first_name', 'last_name', 'admission_number']);
        $answers = $attempt ? DB::table('answers')->where('exam_attempt_id', $attempt->id)->get()->keyBy('question_id') : collect();

        $rows = Question::where('exam_id', $exam->id)->orderBy('id')->get()->values()->map(function ($q, $i) use ($answers) {
            $a = $answers[$q->id] ?? null;
            $data = $a ? json_decode((string) $a->answer_data, true) : null;

            return [
                'number' => $i + 1, 'question' => trim(strip_tags((string) $q->question_text)), 'type' => $q->question_type,
                'options' => $q->question_type === 'essay' ? [] : $q->optionValues(),
                'their_answer' => $a ? $this->answerText($q, $data, $a->answer_text) : null,
                'correct_answer' => $this->correctText($q),
                'result' => ! $a ? 'unanswered' : ($a->is_correct === null ? 'awaiting_marking' : ((bool) $a->is_correct ? 'correct' : 'wrong')),
                'marks' => (float) $q->marks, 'marks_obtained' => $a?->marks_obtained !== null ? (float) $a->marks_obtained : null,
            ];
        });

        return response()->json([
            'student' => $s ? ['id' => $s->id, 'name' => trim("{$s->first_name} {$s->last_name}"), 'admission_number' => $s->admission_number] : null,
            'attempt' => $attempt ? ['status' => $attempt->status, 'score' => $attempt->total_score, 'percentage' => $attempt->percentage,
                'started_at' => $attempt->started_at, 'completed_at' => $attempt->completed_at, 'time_taken_minutes' => $attempt->time_taken_minutes] : null,
            'answers' => $rows,
        ]);
    }
}
