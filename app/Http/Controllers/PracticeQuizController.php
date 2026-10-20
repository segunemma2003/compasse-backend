<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Practice quizzes for the student portal: teachers write quick multiple-choice / true-false
 * questions for a class (or the whole school); students take them as often as they like and
 * see the right answers straight away. Scores are for practice, not results.
 */
class PracticeQuizController extends Controller
{
    private function isStaffEditor(Request $r): bool
    {
        return $this->roleCan($r, 'cbt.manage');
    }

    private function studentOf(Request $r): ?Student
    {
        return Student::where('user_id', $r->user()->id)->first();
    }

    /** GET /practice-quizzes — students: their class's quizzes with their best score; teachers: quizzes they manage. */
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('practice_quizzes')->leftJoin('classes', 'classes.id', '=', 'practice_quizzes.class_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'practice_quizzes.subject_id')
            ->select('practice_quizzes.*', 'classes.name as class_name', 'subjects.name as subject_name')
            ->orderByDesc('practice_quizzes.created_at');

        if ($student = $this->studentOf($request)) {
            $rows = $q->where('practice_quizzes.published', true)
                ->where(fn ($w) => $w->whereNull('practice_quizzes.class_id')->orWhere('practice_quizzes.class_id', $student->class_id))->get();
            $best = DB::table('practice_quiz_attempts')->where('user_id', $request->user()->id)->whereIn('quiz_id', $rows->pluck('id'))
                ->groupBy('quiz_id')->select('quiz_id', DB::raw('MAX(score) best'), DB::raw('COUNT(*) tries'))->get()->keyBy('quiz_id');

            return response()->json(['role' => 'student', 'quizzes' => $rows->map(fn ($r) => $this->summary($r) + [
                'best' => $best[$r->id]->best ?? null, 'tries' => (int) ($best[$r->id]->tries ?? 0),
            ])]);
        }

        if (! $this->isStaffEditor($request)) {
            return response()->json(['role' => 'none', 'quizzes' => []]);
        }
        $classIds = $this->accessibleClassIds($request->user());
        if ($classIds !== null) {
            $q->where(fn ($w) => $w->whereIn('practice_quizzes.class_id', $classIds ?: [0])->orWhere('practice_quizzes.created_by', $request->user()->id));
        }
        $rows = $q->get();
        $stats = DB::table('practice_quiz_attempts')->whereIn('quiz_id', $rows->pluck('id'))->groupBy('quiz_id')
            ->select('quiz_id', DB::raw('COUNT(DISTINCT user_id) students'), DB::raw('AVG(score / NULLIF(total, 0)) * 100 avg_pct'))->get()->keyBy('quiz_id');

        return response()->json(['role' => 'teacher', 'quizzes' => $rows->map(fn ($r) => $this->summary($r) + [
            'published' => (bool) $r->published, 'students' => (int) ($stats[$r->id]->students ?? 0),
            'average' => isset($stats[$r->id]) ? round((float) $stats[$r->id]->avg_pct) : null,
        ])]);
    }

    private function summary(object $r): array
    {
        return ['id' => $r->id, 'title' => $r->title, 'description' => $r->description, 'class_id' => $r->class_id, 'class' => $r->class_name ?? 'Whole school',
            'subject_id' => $r->subject_id, 'subject' => $r->subject_name ?? null, 'questions_count' => count(json_decode($r->questions, true) ?: []), 'created_at' => $r->created_at];
    }

    /** GET /practice-quizzes/{id} — students get the questions without answers; teachers get everything. */
    public function show(Request $request, int $id): JsonResponse
    {
        $quiz = DB::table('practice_quizzes')->find($id);
        if (! $quiz) {
            return response()->json(['message' => 'Quiz not found'], 404);
        }
        $questions = json_decode($quiz->questions, true) ?: [];
        $student = $this->studentOf($request);
        if ($student) {
            if (! $quiz->published || ($quiz->class_id && (int) $quiz->class_id !== (int) $student->class_id)) {
                return response()->json(['message' => 'This quiz is not for your class.'], 403);
            }
            $questions = array_map(fn ($q) => ['text' => $q['text'], 'options' => $q['options']], $questions);
        } elseif (! $this->isStaffEditor($request)) {
            return response()->json(['message' => 'Not available'], 403);
        }

        return response()->json(['quiz' => ['id' => $quiz->id, 'title' => $quiz->title, 'description' => $quiz->description, 'class_id' => $quiz->class_id,
            'subject_id' => $quiz->subject_id, 'published' => (bool) $quiz->published, 'questions' => $questions]]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'class_id' => 'nullable|integer|exists:classes,id',
            'subject_id' => 'nullable|integer|exists:subjects,id',
            'published' => 'nullable|boolean',
            'questions' => 'required|array|min:1|max:100',
            'questions.*.text' => 'required|string|max:2000',
            'questions.*.options' => 'required|array|min:2|max:6',
            'questions.*.options.*' => 'required|string|max:500',
            'questions.*.correct' => 'required|integer|min:0',
            'questions.*.explanation' => 'nullable|string|max:1000',
        ]);
        foreach ($data['questions'] as $i => $q) {
            if ($q['correct'] >= count($q['options'])) {
                abort(response()->json(['message' => 'Question ' . ($i + 1) . ': choose which option is correct.'], 422));
            }
        }
        $classIds = $this->accessibleClassIds($request->user());
        if ($classIds !== null && ! empty($data['class_id']) && ! in_array((int) $data['class_id'], $classIds, true)) {
            abort(response()->json(['message' => 'You can only set quizzes for your own classes.'], 403));
        }

        return $data;
    }

    /** POST /practice-quizzes */
    public function store(Request $request): JsonResponse
    {
        if (! $this->isStaffEditor($request)) {
            return response()->json(['message' => 'Only teachers can create quizzes.'], 403);
        }
        $d = $this->validated($request);
        $id = DB::table('practice_quizzes')->insertGetId([
            'title' => $d['title'], 'description' => $d['description'] ?? null, 'class_id' => $d['class_id'] ?? null, 'subject_id' => $d['subject_id'] ?? null,
            'questions' => json_encode(array_values($d['questions'])), 'published' => (bool) ($d['published'] ?? true),
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Quiz created', 'id' => $id], 201);
    }

    /** PUT /practice-quizzes/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->isStaffEditor($request) || ! DB::table('practice_quizzes')->where('id', $id)->exists()) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $d = $this->validated($request);
        DB::table('practice_quizzes')->where('id', $id)->update([
            'title' => $d['title'], 'description' => $d['description'] ?? null, 'class_id' => $d['class_id'] ?? null, 'subject_id' => $d['subject_id'] ?? null,
            'questions' => json_encode(array_values($d['questions'])), 'published' => (bool) ($d['published'] ?? true), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Quiz updated']);
    }

    /** DELETE /practice-quizzes/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->isStaffEditor($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        DB::table('practice_quiz_attempts')->where('quiz_id', $id)->delete();
        DB::table('practice_quizzes')->where('id', $id)->delete();

        return response()->json(['message' => 'Quiz deleted']);
    }

    /** POST /practice-quizzes/{id}/submit {answers: [optionIndex|null, …]} — score + the right answers. */
    public function submit(Request $request, int $id): JsonResponse
    {
        $quiz = DB::table('practice_quizzes')->find($id);
        $student = $this->studentOf($request);
        if (! $quiz || ! $student || ! $quiz->published || ($quiz->class_id && (int) $quiz->class_id !== (int) $student->class_id)) {
            return response()->json(['message' => 'This quiz is not available.'], 403);
        }
        $answers = (array) $request->input('answers', []);
        $questions = json_decode($quiz->questions, true) ?: [];
        $score = 0;
        $review = [];
        foreach ($questions as $i => $q) {
            $given = isset($answers[$i]) && $answers[$i] !== null && $answers[$i] !== '' ? (int) $answers[$i] : null;
            $right = $given === (int) $q['correct'];
            $score += $right ? 1 : 0;
            $review[] = ['given' => $given, 'correct' => (int) $q['correct'], 'right' => $right, 'explanation' => $q['explanation'] ?? null];
        }
        DB::table('practice_quiz_attempts')->insert([
            'quiz_id' => $id, 'student_id' => $student->id, 'user_id' => $request->user()->id, 'score' => $score, 'total' => count($questions),
            'answers' => json_encode($answers), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $best = (int) DB::table('practice_quiz_attempts')->where('quiz_id', $id)->where('user_id', $request->user()->id)->max('score');

        return response()->json(['score' => $score, 'total' => count($questions), 'best' => $best, 'review' => $review]);
    }

    /** GET /practice-quizzes/{id}/results — each student's best score and tries (teachers). */
    public function results(Request $request, int $id): JsonResponse
    {
        if (! $this->isStaffEditor($request)) {
            return response()->json(['message' => 'Not allowed'], 403);
        }
        $quiz = DB::table('practice_quizzes')->find($id);
        $rows = DB::table('practice_quiz_attempts')->where('quiz_id', $id)
            ->groupBy('student_id', 'user_id')
            ->select('student_id', 'user_id', DB::raw('MAX(score) best'), DB::raw('MAX(total) total'), DB::raw('COUNT(*) tries'), DB::raw('MAX(created_at) last_at'))
            ->get();
        $names = DB::table('students')->whereIn('id', $rows->pluck('student_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');

        // Students in the class who haven't tried it yet.
        $notYet = $quiz?->class_id ? DB::table('students')->where('class_id', $quiz->class_id)->whereNull('deleted_at')
            ->whereNotIn('id', $rows->pluck('student_id')->filter())->orderBy('first_name')->get(['first_name', 'last_name'])
            ->map(fn ($s) => trim("{$s->first_name} {$s->last_name}")) : collect();

        return response()->json([
            'results' => $rows->map(fn ($r) => ['name' => isset($names[$r->student_id]) ? trim("{$names[$r->student_id]->first_name} {$names[$r->student_id]->last_name}") : 'Student',
                'best' => (int) $r->best, 'total' => (int) $r->total, 'tries' => (int) $r->tries, 'last_at' => $r->last_at])->sortByDesc('best')->values(),
            'not_attempted' => $notYet->values(),
        ]);
    }
}
