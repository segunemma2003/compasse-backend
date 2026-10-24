<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Reports: the school's reports in one place (students, attendance, exams, library, fees,
 * payroll, inventory, visitors…). Each report returns columns + rows the portal shows as a table
 * (search, CSV, print), or cards (ID card, admit card, library card) to print and cut out.
 */
class ReportsController extends Controller
{
    /** key => [title, group, filters, description] */
    private const REPORTS = [
        'class' => ['Class Report', 'Students & classes', ['term'], 'Each class: class teacher, students (boys / girls), sections.'],
        'student' => ['Student Report', 'Students & classes', ['class', 'status'], 'Every student with class, gender, date of birth, parent and phone.'],
        'id_card' => ['ID Card Report', 'Students & classes', ['class'], 'Printable student ID cards.'],
        'admit_card' => ['Admit Card Report', 'Students & classes', ['class', 'term'], 'Printable exam admit cards.'],
        'sponsorship' => ['Sponsorship / Scholarship Report', 'Students & classes', ['class', 'term'], 'Students with a discount, scholarship or sponsorship on their fees.'],
        'online_admission' => ['Online Admission Report', 'Students & classes', ['from', 'to', 'status'], 'Applications received online and their status.'],
        'routine' => ['Routine (Timetable) Report', 'Timetable & exams', ['class'], 'Each class\'s timetable: day, time, subject and teacher.'],
        'exam_schedule' => ['Exam Schedule Report', 'Timetable & exams', ['class', 'term'], 'Exams with subject, class, date and duration.'],
        'merit' => ['Merit Stage Report', 'Timetable & exams', ['class', 'term'], 'Students ranked by average for the term.'],
        'mark_sheet' => ['Mark Sheet Report', 'Timetable & exams', ['class', 'term'], 'Every student\'s total in every subject for the term.'],
        'online_exam' => ['Online Exam Report', 'Timetable & exams', ['class', 'term'], 'CBT exams: how many sat, average, highest and lowest.'],
        'online_exam_question' => ['Online Exam Question Report', 'Timetable & exams', ['exam'], 'Each question of a CBT exam and how many got it right.'],
        'online_exam_answer' => ['Online Exam Question Answer Report', 'Timetable & exams', ['exam'], 'Every student\'s answer to every question of a CBT exam.'],
        'attendance' => ['Attendance Report', 'Attendance', ['class', 'from', 'to'], 'Each student\'s days present, absent and late in a period.'],
        'attendance_overview' => ['Attendance Overview Report', 'Attendance', ['from', 'to'], 'Each class by day: present, absent, % present.'],
        'overtime' => ['Overtime Report', 'Attendance', ['from', 'to'], 'Staff overtime hours from clock-in / clock-out.'],
        'library_books' => ['Library Books Report', 'Library', [], 'Books with copies in stock and on loan.'],
        'library_issue' => ['Library Book Issue Report', 'Library', ['from', 'to', 'status'], 'Books borrowed, due and returned.'],
        'library_card' => ['Library Card Report', 'Library', ['class'], 'Printable library cards.'],
        'student_fine' => ['Student Fine Report', 'Library', ['from', 'to'], 'Library fines.'],
        'fees' => ['Fees Report', 'Fees & finance', ['class', 'term'], 'Fees assigned: amount, paid and balance.'],
        'due_fees' => ['Due Fees Report', 'Fees & finance', ['class', 'term'], 'Fees past their due date that are not fully paid.'],
        'balance_fees' => ['Balance Fees Report', 'Fees & finance', ['class', 'term'], 'Each student\'s total outstanding balance.'],
        'payment_search' => ['Search Payment Fees Report', 'Fees & finance', ['class', 'from', 'to', 'method'], 'Payments received, with receipt number and method.'],
        'transaction' => ['Transaction Report', 'Fees & finance', ['from', 'to'], 'Money in (payments) and out (expenses), day by day.'],
        'ledger' => ['Account Ledger Report', 'Fees & finance', ['from', 'to'], 'Income and expenses with the running balance.'],
        'product_purchase' => ['Product Purchase Report', 'Fees & finance', ['from', 'to'], 'Stock added to inventory.'],
        'product_sale' => ['Product Sale / Issue Report', 'Fees & finance', ['from', 'to'], 'Stock issued, sold or checked out from inventory.'],
        'salary' => ['Salary Report', 'Staff', ['month', 'year'], 'Payroll for a month: basic, earnings, deductions and net pay.'],
        'visitor' => ['Visitor Information Report', 'Staff', ['from', 'to'], 'Visitors signed in at the gate.'],
    ];

    private const NOT_YET = [
        'Leave Application Report' => 'Leave applications are not recorded in Compasse yet.',
        'Certificate Report' => 'Certificates are not issued from Compasse yet.',
    ];

    private function guard(Request $request): ?JsonResponse
    {
        $u = $request->user() ?: auth()->user();
        if (! $u || (! in_array($u->role, ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin', 'accountant'], true) && ! $this->roleCan($request, 'reports.view'))) {
            return $this->forbiddenResponse('Reports are for the school\'s leadership and finance.');
        }

        return null;
    }

    /** GET /reports/catalog */
    public function catalog(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        return response()->json([
            'reports' => collect(self::REPORTS)->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0], 'group' => $r[1], 'filters' => $r[2], 'description' => $r[3]])->values(),
            'not_available' => self::NOT_YET,
        ]);
    }

    /** GET /reports/{key}?class_id=&term_id=&from=&to=&status=&method=&exam_id=&month=&year= */
    public function show(Request $request, string $key): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if (! isset(self::REPORTS[$key])) {
            return response()->json(['error' => 'Unknown report'], 404);
        }
        $method = 'r' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        try {
            [$columns, $rows, $extra] = array_pad($this->{$method}($request), 3, []);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'This report could not be produced: ' . $e->getMessage()], 500);
        }

        return response()->json(['title' => self::REPORTS[$key][0], 'columns' => $columns, 'rows' => array_values($rows)] + $extra);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function has(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function name(?object $r, string $first = 'first_name', string $last = 'last_name'): string
    {
        return trim(($r->{$first} ?? '') . ' ' . ($r->{$last} ?? ''));
    }

    private function between($q, Request $request, string $col)
    {
        if ($request->filled('from')) {
            $q->whereDate($col, '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate($col, '<=', $request->to);
        }

        return $q;
    }

    private function students(Request $request)
    {
        return DB::table('students')->leftJoin('classes', 'classes.id', '=', 'students.class_id')->whereNull('students.deleted_at')
            ->when($request->filled('class_id'), fn ($q) => $q->where('students.class_id', $request->class_id))
            ->orderBy('classes.name')->orderBy('students.first_name');
    }

    private function cols(array $spec): array
    {
        return array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2] ?? 'text'], $spec);
    }

    private function schoolCard(): array
    {
        $s = \App\Models\School::first();

        return ['school' => ['name' => $s?->name, 'logo' => \App\Support\FinanceDocument::logoSrc($s), 'address' => $s?->address, 'phone' => $s?->phone, 'color' => \App\Support\SchoolColor::primary()]];
    }

    // ── students & classes ───────────────────────────────────────────────

    private function rClass(Request $request): array
    {
        $teachers = DB::table('teachers')->get(['id', 'first_name', 'last_name', 'title'])->keyBy('id');
        $rows = DB::table('classes')->orderBy('name')->get()->map(function ($c) use ($teachers) {
            $st = DB::table('students')->where('class_id', $c->id)->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));
            $t = $teachers[$c->class_teacher_id] ?? null;
            $arms = Schema::hasTable('class_arm') ? DB::table('class_arm')->join('arms', 'arms.id', '=', 'class_arm.arm_id')->where('class_arm.class_id', $c->id)->pluck('arms.name')->implode(', ') : '';

            return ['class' => $c->name, 'teacher' => $t ? trim(($t->title ? $t->title . ' ' : '') . "{$t->first_name} {$t->last_name}") : '—', 'sections' => $arms ?: '—',
                'boys' => (clone $st)->where('gender', 'male')->count(), 'girls' => (clone $st)->where('gender', 'female')->count(), 'total' => (clone $st)->count(), 'capacity' => $c->capacity];
        });

        return [$this->cols([['class', 'Class'], ['teacher', 'Class teacher'], ['sections', 'Sections'], ['boys', 'Boys', 'number'], ['girls', 'Girls', 'number'], ['total', 'Total', 'number'], ['capacity', 'Capacity', 'number']]), $rows->all()];
    }

    private function rStudent(Request $request): array
    {
        $q = $this->students($request)->when($request->filled('status'), fn ($q) => $q->where('students.status', $request->status));
        $rows = $q->get(['students.*', 'classes.name as class_name'])->map(fn ($s) => [
            'admission_number' => $s->admission_number, 'name' => $this->name($s), 'class' => $s->class_name, 'gender' => ucfirst((string) $s->gender),
            'date_of_birth' => $s->date_of_birth, 'parent' => $s->parent_name ?: DB::table('guardians')->join('guardian_students', 'guardian_students.guardian_id', '=', 'guardians.id')->where('guardian_students.student_id', $s->id)->selectRaw("CONCAT(guardians.first_name, ' ', guardians.last_name) as n")->value('n'),
            'phone' => $s->parent_phone ?: $s->phone, 'status' => ucfirst((string) ($s->status ?: 'active')), 'admitted' => $s->admission_date,
        ]);

        return [$this->cols([['admission_number', 'Admission No.'], ['name', 'Name'], ['class', 'Class'], ['gender', 'Gender'], ['date_of_birth', 'Date of birth', 'date'], ['parent', 'Parent'], ['phone', 'Phone'], ['admitted', 'Admitted', 'date'], ['status', 'Status']]), $rows->all()];
    }

    private function cardStudents(Request $request)
    {
        return $this->students($request)->where(fn ($q) => $q->whereNull('students.status')->orWhere('students.status', 'active'))
            ->get(['students.id', 'students.first_name', 'students.last_name', 'students.admission_number', 'students.gender', 'students.date_of_birth', 'students.profile_picture', 'students.blood_group', 'students.parent_phone', 'classes.name as class_name']);
    }

    private function photo(?string $p): ?string
    {
        return $p ? (str_starts_with($p, 'http') ? $p : \App\Support\TenantUrl::forPublicDiskKey($p)) : null;
    }

    private function rIdCard(Request $request): array
    {
        $year = DB::table('academic_years')->where('is_current', true)->value('name');
        $cards = $this->cardStudents($request)->map(fn ($s) => ['title' => 'STUDENT ID CARD', 'name' => $this->name($s), 'photo' => $this->photo($s->profile_picture),
            'lines' => array_filter(['Admission No.' => $s->admission_number, 'Class' => $s->class_name, 'Session' => $year, 'Blood group' => $s->blood_group, 'Parent phone' => $s->parent_phone])]);

        return [[], [], ['cards' => $cards->all()] + $this->schoolCard()];
    }

    private function rAdmitCard(Request $request): array
    {
        $term = $request->filled('term_id') ? DB::table('terms')->where('id', $request->term_id)->value('name') : DB::table('terms')->where('is_current', true)->value('name');
        $cards = $this->cardStudents($request)->values()->map(fn ($s, $i) => ['title' => 'EXAMINATION ADMIT CARD', 'name' => $this->name($s), 'photo' => $this->photo($s->profile_picture),
            'lines' => array_filter(['Admission No.' => $s->admission_number, 'Class' => $s->class_name, 'Examination' => trim(($term ?: '') . ' Examination'), 'Seat No.' => str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)]),
            'footer' => 'Bring this card to every paper. Signature of Principal: ____________']);

        return [[], [], ['cards' => $cards->all()] + $this->schoolCard()];
    }

    private function rLibraryCard(Request $request): array
    {
        $cards = $this->cardStudents($request)->map(fn ($s) => ['title' => 'LIBRARY CARD', 'name' => $this->name($s), 'photo' => $this->photo($s->profile_picture),
            'lines' => ['Member No.' => 'LIB-' . ($s->admission_number ?: $s->id), 'Class' => $s->class_name], 'footer' => 'Return books on time. Lost cards: see the librarian.']);

        return [[], [], ['cards' => $cards->all()] + $this->schoolCard()];
    }

    private function rSponsorship(Request $request): array
    {
        if (! $this->has('fee_items')) {
            return [[], []];
        }
        $rows = DB::table('fee_items')->join('fees', 'fees.id', '=', 'fee_items.fee_id')->join('students', 'students.id', '=', 'fees.student_id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')->where('fee_items.amount', '<', 0)
            ->when($request->filled('class_id'), fn ($q) => $q->where('students.class_id', $request->class_id))
            ->when($request->filled('term_id'), fn ($q) => $q->where('fees.term_id', $request->term_id))
            ->orderBy('classes.name')->orderBy('students.first_name')
            ->get(['students.first_name', 'students.last_name', 'students.admission_number', 'classes.name as class_name', 'fee_items.name as item', 'fee_items.amount', 'fees.fee_type', 'fees.amount as fee_total'])
            ->map(fn ($r) => ['name' => $this->name($r), 'admission_number' => $r->admission_number, 'class' => $r->class_name, 'support' => $r->item, 'fee' => $r->fee_type, 'amount' => abs((float) $r->amount), 'fee_total' => (float) $r->fee_total]);

        return [$this->cols([['name', 'Student'], ['admission_number', 'Admission No.'], ['class', 'Class'], ['support', 'Discount / scholarship'], ['fee', 'Fee'], ['amount', 'Amount taken off', 'money'], ['fee_total', 'Fee after it', 'money']]), $rows->all()];
    }

    private function rOnlineAdmission(Request $request): array
    {
        $q = DB::table('applicants')->leftJoin('admission_cycles', 'admission_cycles.id', '=', 'applicants.admission_cycle_id')->leftJoin('classes', 'classes.id', '=', 'applicants.class_id')
            ->when($request->filled('status'), fn ($q) => $q->where('applicants.status', $request->status))->orderByDesc('applicants.created_at');
        $rows = $this->between($q, $request, 'applicants.created_at')->get(['applicants.*', 'admission_cycles.name as cycle', 'classes.name as class_name'])->map(fn ($a) => [
            'number' => 'APP-' . str_pad((string) $a->id, 5, '0', STR_PAD_LEFT), 'name' => $this->name($a), 'class' => $a->class_name, 'cycle' => $a->cycle,
            'parent' => $a->parent_name, 'phone' => $a->parent_phone ?: $a->phone, 'email' => $a->parent_email ?: $a->email, 'score' => $a->exam_score,
            'status' => ['approved' => 'Offered', 'enrolled' => 'Enrolled'][$a->status] ?? ucfirst(str_replace('_', ' ', (string) $a->status)), 'applied' => substr((string) $a->created_at, 0, 10),
        ]);

        return [$this->cols([['number', 'No.'], ['name', 'Applicant'], ['class', 'Class'], ['cycle', 'Cycle'], ['parent', 'Parent'], ['phone', 'Phone'], ['email', 'Email'], ['score', 'Exam score', 'number'], ['applied', 'Applied', 'date'], ['status', 'Status']]), $rows->all()];
    }

    // ── timetable & exams ────────────────────────────────────────────────

    private function rRoutine(Request $request): array
    {
        $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        // Days are stored as names ("Monday") or numbers; order Monday → Sunday either way.
        $dayIndex = fn ($d) => is_numeric($d) ? (int) $d : ((int) array_search(ucfirst(strtolower((string) $d)), $days, true) ?: 9);
        $rows = DB::table('timetables')->leftJoin('classes', 'classes.id', '=', 'timetables.class_id')->leftJoin('subjects', 'subjects.id', '=', 'timetables.subject_id')
            ->leftJoin('teachers', 'teachers.id', '=', 'timetables.teacher_id')
            ->when($request->filled('class_id'), fn ($q) => $q->where('timetables.class_id', $request->class_id))
            ->get(['classes.name as class_name', 'timetables.day_of_week', 'timetables.start_time', 'timetables.end_time', 'subjects.name as subject', 'teachers.first_name', 'teachers.last_name', 'timetables.room'])
            ->sortBy(fn ($r) => sprintf('%s|%d|%s', $r->class_name, $dayIndex($r->day_of_week), $r->start_time))->values()
            ->map(fn ($r) => ['class' => $r->class_name, 'day' => $days[(int) $r->day_of_week] ?? ucfirst((string) $r->day_of_week), 'time' => substr((string) $r->start_time, 0, 5) . '–' . substr((string) $r->end_time, 0, 5),
                'subject' => $r->subject, 'teacher' => $this->name($r) ?: '—', 'room' => $r->room]);

        return [$this->cols([['class', 'Class'], ['day', 'Day'], ['time', 'Time'], ['subject', 'Subject'], ['teacher', 'Teacher'], ['room', 'Room']]), $rows->all()];
    }

    private function rExamSchedule(Request $request): array
    {
        $rows = DB::table('exams')->leftJoin('classes', 'classes.id', '=', 'exams.class_id')->leftJoin('subjects', 'subjects.id', '=', 'exams.subject_id')
            ->when($request->filled('class_id'), fn ($q) => $q->where('exams.class_id', $request->class_id))
            ->when($request->filled('term_id'), fn ($q) => $q->where('exams.term_id', $request->term_id))
            ->orderBy('exams.start_date')->get(['exams.*', 'classes.name as class_name', 'subjects.name as subject'])
            ->map(fn ($e) => ['exam' => $e->name, 'class' => $e->class_name, 'subject' => $e->subject, 'kind' => $e->is_cbt ? 'CBT' : ucfirst((string) ($e->type ?: 'Written')),
                'starts' => $e->start_date, 'ends' => $e->end_date, 'duration' => $e->duration_minutes, 'marks' => $e->total_marks, 'status' => ucfirst((string) $e->status)]);

        return [$this->cols([['exam', 'Exam'], ['class', 'Class'], ['subject', 'Subject'], ['kind', 'Type'], ['starts', 'Starts', 'datetime'], ['ends', 'Ends', 'datetime'], ['duration', 'Minutes', 'number'], ['marks', 'Marks', 'number'], ['status', 'Status']]), $rows->all()];
    }

    private function termResults(Request $request)
    {
        $term = $request->input('term_id') ?: DB::table('terms')->where('is_current', true)->value('id');

        return DB::table('student_results')->join('students', 'students.id', '=', 'student_results.student_id')->leftJoin('classes', 'classes.id', '=', 'student_results.class_id')
            ->where('student_results.result_type', 'end_term')->when($term, fn ($q) => $q->where('student_results.term_id', $term))
            ->when($request->filled('class_id'), fn ($q) => $q->where('student_results.class_id', $request->class_id));
    }

    private function rMerit(Request $request): array
    {
        $rows = $this->termResults($request)->orderBy('classes.name')->orderByDesc('student_results.average_score')
            ->get(['students.first_name', 'students.last_name', 'students.admission_number', 'classes.name as class_name', 'student_results.average_score', 'student_results.total_score', 'student_results.position', 'student_results.out_of', 'student_results.grade'])
            ->map(fn ($r) => ['position' => $r->position ? $r->position . ($r->out_of ? ' / ' . $r->out_of : '') : '', 'name' => $this->name($r), 'admission_number' => $r->admission_number, 'class' => $r->class_name,
                'total' => (float) $r->total_score, 'average' => round((float) $r->average_score, 1), 'grade' => $r->grade]);

        return [$this->cols([['position', 'Position'], ['name', 'Student'], ['admission_number', 'Admission No.'], ['class', 'Class'], ['total', 'Total', 'number'], ['average', 'Average %', 'number'], ['grade', 'Grade']]), $rows->all()];
    }

    private function rMarkSheet(Request $request): array
    {
        $results = $this->termResults($request)->orderBy('classes.name')->orderBy('students.first_name')
            ->get(['student_results.id', 'students.first_name', 'students.last_name', 'students.admission_number', 'classes.name as class_name', 'student_results.average_score']);
        $scores = DB::table('subject_results')->join('subjects', 'subjects.id', '=', 'subject_results.subject_id')->whereIn('student_result_id', $results->pluck('id'))
            ->get(['subject_results.student_result_id', 'subjects.name', 'subject_results.total_score']);
        $subjects = $scores->pluck('name')->unique()->sort()->values();
        $by = $scores->groupBy('student_result_id');
        $cols = [['name', 'Student'], ['admission_number', 'Admission No.'], ['class', 'Class']];
        foreach ($subjects as $i => $s) {
            $cols[] = ['s' . $i, $s, 'number'];
        }
        $cols[] = ['average', 'Average %', 'number'];
        $rows = $results->map(function ($r) use ($subjects, $by) {
            $row = ['name' => $this->name($r), 'admission_number' => $r->admission_number, 'class' => $r->class_name, 'average' => round((float) $r->average_score, 1)];
            $mine = ($by[$r->id] ?? collect())->keyBy('name');
            foreach ($subjects as $i => $s) {
                $row['s' . $i] = isset($mine[$s]) ? (float) $mine[$s]->total_score : null;
            }

            return $row;
        });

        return [$this->cols($cols), $rows->all()];
    }

    private function rOnlineExam(Request $request): array
    {
        $rows = DB::table('exams')->leftJoin('classes', 'classes.id', '=', 'exams.class_id')->leftJoin('subjects', 'subjects.id', '=', 'exams.subject_id')->where('exams.is_cbt', true)
            ->when($request->filled('class_id'), fn ($q) => $q->where('exams.class_id', $request->class_id))
            ->when($request->filled('term_id'), fn ($q) => $q->where('exams.term_id', $request->term_id))
            ->orderByDesc('exams.start_date')->get(['exams.id', 'exams.name', 'exams.total_marks', 'classes.name as class_name', 'subjects.name as subject'])
            ->map(function ($e) {
                $scores = DB::table('exam_attempts')->where('exam_id', $e->id)->whereNotNull('total_score')->pluck('total_score')->map(fn ($v) => (float) $v);

                return ['exam' => $e->name, 'class' => $e->class_name, 'subject' => $e->subject, 'marks' => (float) $e->total_marks, 'sat' => $scores->count(),
                    'average' => $scores->count() ? round($scores->avg(), 1) : null, 'highest' => $scores->max(), 'lowest' => $scores->min()];
            });

        return [$this->cols([['exam', 'Exam'], ['class', 'Class'], ['subject', 'Subject'], ['marks', 'Out of', 'number'], ['sat', 'Sat', 'number'], ['average', 'Average', 'number'], ['highest', 'Highest', 'number'], ['lowest', 'Lowest', 'number']]), $rows->all()];
    }

    private function examOptions(): array
    {
        return ['exams' => DB::table('exams')->where('is_cbt', true)->orderByDesc('id')->limit(200)->get(['id', 'name'])->all()];
    }

    private function rOnlineExamQuestion(Request $request): array
    {
        if (! $request->filled('exam_id')) {
            return [[], [], $this->examOptions() + ['message' => 'Choose an exam.']];
        }
        $exam = \App\Models\Exam::findOrFail((int) $request->exam_id);
        $report = app(CbtReportController::class)->show($request, $exam);
        if ($report->getStatusCode() !== 200) {
            return [[], [], $this->examOptions()];
        }
        $d = $report->getData(true);
        $rows = collect($d['questions'])->map(fn ($q) => ['number' => $q['number'], 'question' => $q['question'], 'answer' => $q['correct_answer'], 'marks' => $q['marks'],
            'right' => $q['correct'], 'wrong' => $q['wrong'], 'unanswered' => $q['unanswered'], 'percent' => $q['percent_correct']]);

        return [$this->cols([['number', 'Q', 'number'], ['question', 'Question'], ['answer', 'Correct answer'], ['marks', 'Marks', 'number'], ['right', 'Right', 'number'], ['wrong', 'Wrong', 'number'], ['unanswered', 'Not answered', 'number'], ['percent', '% right', 'number']]), $rows->all(), $this->examOptions()];
    }

    private function rOnlineExamAnswer(Request $request): array
    {
        if (! $request->filled('exam_id')) {
            return [[], [], $this->examOptions() + ['message' => 'Choose an exam.']];
        }
        $exam = \App\Models\Exam::findOrFail((int) $request->exam_id);
        $attempts = DB::table('exam_attempts')->join('students', 'students.id', '=', 'exam_attempts.student_id')->where('exam_attempts.exam_id', $exam->id)
            ->orderBy('students.first_name')->get(['exam_attempts.student_id', 'students.first_name', 'students.last_name'])->unique('student_id');
        $rows = [];
        foreach ($attempts as $a) {
            $sheet = app(CbtReportController::class)->student($request, $exam, (int) $a->student_id);
            if ($sheet->getStatusCode() !== 200) {
                continue;
            }
            foreach ($sheet->getData(true)['answers'] as $ans) {
                $rows[] = ['student' => $this->name($a), 'number' => $ans['number'], 'question' => $ans['question'], 'their_answer' => $ans['their_answer'] ?? '—',
                    'correct_answer' => $ans['correct_answer'], 'result' => ucfirst(str_replace('_', ' ', $ans['result'])), 'marks' => $ans['marks_obtained'] ?? 0];
            }
        }

        return [$this->cols([['student', 'Student'], ['number', 'Q', 'number'], ['question', 'Question'], ['their_answer', 'Their answer'], ['correct_answer', 'Correct answer'], ['result', 'Result'], ['marks', 'Marks', 'number']]), $rows, $this->examOptions()];
    }

    // ── attendance ───────────────────────────────────────────────────────

    private function studentAttendance(Request $request)
    {
        $q = DB::table('attendances')->whereIn('attendanceable_type', ['App\\Models\\Student', 'student']);

        return $this->between($q, $request, 'date');
    }

    private function rAttendance(Request $request): array
    {
        $students = $this->students($request)->get(['students.id', 'students.first_name', 'students.last_name', 'students.admission_number', 'classes.name as class_name']);
        $att = $this->studentAttendance($request)->whereIn('attendanceable_id', $students->pluck('id'))->get(['attendanceable_id', 'status'])->groupBy('attendanceable_id');
        $rows = $students->map(function ($s) use ($att) {
            $mine = $att[$s->id] ?? collect();
            $present = $mine->whereIn('status', ['present', 'late'])->count();
            $marked = $mine->count();

            return ['name' => $this->name($s), 'admission_number' => $s->admission_number, 'class' => $s->class_name, 'present' => $present,
                'absent' => $mine->whereIn('status', ['absent'])->count(), 'late' => $mine->where('status', 'late')->count(), 'excused' => $mine->where('status', 'excused')->count(),
                'percent' => $marked ? round($present / $marked * 100) : null];
        });

        return [$this->cols([['name', 'Student'], ['admission_number', 'Admission No.'], ['class', 'Class'], ['present', 'Present', 'number'], ['absent', 'Absent', 'number'], ['late', 'Late', 'number'], ['excused', 'Excused', 'number'], ['percent', '% present', 'number']]), $rows->all()];
    }

    private function rAttendanceOverview(Request $request): array
    {
        $classOf = DB::table('students')->pluck('class_id', 'id');
        $classes = DB::table('classes')->pluck('name', 'id');
        $rows = $this->studentAttendance($request)->orderByDesc('date')->get(['date', 'attendanceable_id', 'status'])
            ->groupBy(fn ($r) => substr((string) $r->date, 0, 10) . '|' . ($classOf[$r->attendanceable_id] ?? 0))
            ->map(function ($g, $k) use ($classes) {
                [$date, $cid] = explode('|', $k);
                $present = $g->whereIn('status', ['present', 'late'])->count();

                return ['date' => $date, 'class' => $classes[$cid] ?? '—', 'present' => $present, 'absent' => $g->where('status', 'absent')->count(), 'marked' => $g->count(), 'percent' => $g->count() ? round($present / $g->count() * 100) : null];
            });

        return [$this->cols([['date', 'Date', 'date'], ['class', 'Class'], ['present', 'Present', 'number'], ['absent', 'Absent', 'number'], ['marked', 'Marked', 'number'], ['percent', '% present', 'number']]), $rows->values()->all()];
    }

    private function rOvertime(Request $request): array
    {
        $q = DB::table('attendances')->whereNotIn('attendanceable_type', ['App\\Models\\Student', 'student']);
        $rows = $this->between($q, $request, 'date')->get(['attendanceable_type', 'attendanceable_id', 'date', 'check_in_time', 'check_out_time', 'total_hours', 'overtime_hours'])
            ->map(function ($r) {
                $name = str_contains((string) $r->attendanceable_type, 'Teacher')
                    ? DB::table('teachers')->where('id', $r->attendanceable_id)->selectRaw("CONCAT(first_name, ' ', last_name) as n")->value('n')
                    : DB::table('users')->where('id', $r->attendanceable_id)->value('name');

                return ['date' => substr((string) $r->date, 0, 10), 'name' => $name, 'in' => substr((string) $r->check_in_time, 0, 5), 'out' => substr((string) $r->check_out_time, 0, 5),
                    'hours' => $r->total_hours !== null ? round((float) $r->total_hours, 2) : null, 'overtime' => $r->overtime_hours !== null ? round((float) $r->overtime_hours, 2) : 0];
            })->sortByDesc('date');

        return [$this->cols([['date', 'Date', 'date'], ['name', 'Staff'], ['in', 'Clock in'], ['out', 'Clock out'], ['hours', 'Hours', 'number'], ['overtime', 'Overtime hours', 'number']]), $rows->values()->all()];
    }

    // ── library ──────────────────────────────────────────────────────────

    private function rLibraryBooks(Request $request): array
    {
        $rows = DB::table('library_books')->orderBy('title')->get()->map(fn ($b) => ['title' => $b->title, 'author' => $b->author, 'isbn' => $b->isbn, 'category' => $b->category,
            'shelf' => trim(($b->location ?? '') . ' ' . ($b->shelf_number ?? '')), 'total' => (int) $b->total_copies, 'available' => (int) $b->available_copies, 'on_loan' => max(0, (int) $b->total_copies - (int) $b->available_copies)]);

        return [$this->cols([['title', 'Title'], ['author', 'Author'], ['isbn', 'ISBN'], ['category', 'Category'], ['shelf', 'Shelf'], ['total', 'Copies', 'number'], ['available', 'Available', 'number'], ['on_loan', 'On loan', 'number']]), $rows->all()];
    }

    private function borrowerName($r): string
    {
        return (string) (str_contains((string) $r->borrower_type, 'Student') || $r->borrower_type === 'student'
            ? DB::table('students')->where('id', $r->borrower_id)->selectRaw("CONCAT(first_name, ' ', last_name) as n")->value('n')
            : DB::table('users')->where('id', $r->borrower_id)->value('name')) ?: ('#' . $r->borrower_id);
    }

    private function rLibraryIssue(Request $request): array
    {
        $q = DB::table('library_borrows')->leftJoin('library_books', 'library_books.id', '=', 'library_borrows.book_id')
            ->when($request->filled('status'), fn ($q) => $q->where('library_borrows.status', $request->status))->orderByDesc('library_borrows.borrowed_at');
        $rows = $this->between($q, $request, 'library_borrows.borrowed_at')->get(['library_borrows.*', 'library_books.title'])->map(fn ($r) => [
            'book' => $r->title, 'borrower' => $this->borrowerName($r), 'borrowed' => substr((string) $r->borrowed_at, 0, 10), 'due' => substr((string) $r->due_date, 0, 10),
            'returned' => $r->returned_at ? substr((string) $r->returned_at, 0, 10) : '—', 'status' => ucfirst((string) $r->status),
            'overdue' => ! $r->returned_at && $r->due_date && $r->due_date < now()->toDateString() ? 'Yes' : '']);

        return [$this->cols([['book', 'Book'], ['borrower', 'Borrower'], ['borrowed', 'Borrowed', 'date'], ['due', 'Due', 'date'], ['returned', 'Returned'], ['overdue', 'Overdue'], ['status', 'Status']]), $rows->all()];
    }

    private function rStudentFine(Request $request): array
    {
        $q = DB::table('library_borrows')->leftJoin('library_books', 'library_books.id', '=', 'library_borrows.book_id')
            ->where(fn ($w) => $w->where('library_borrows.fine_amount', '>', 0)->orWhere('library_borrows.fine', '>', 0));
        $rows = $this->between($q, $request, 'library_borrows.borrowed_at')->get(['library_borrows.*', 'library_books.title'])->map(fn ($r) => [
            'borrower' => $this->borrowerName($r), 'book' => $r->title, 'due' => substr((string) $r->due_date, 0, 10), 'returned' => $r->returned_at ? substr((string) $r->returned_at, 0, 10) : '—',
            'fine' => (float) ($r->fine_amount ?: $r->fine), 'note' => $r->remarks]);

        return [$this->cols([['borrower', 'Borrower'], ['book', 'Book'], ['due', 'Due', 'date'], ['returned', 'Returned'], ['fine', 'Fine', 'money'], ['note', 'Note']]), $rows->all()];
    }

    // ── fees & finance ───────────────────────────────────────────────────

    private function fees(Request $request)
    {
        return DB::table('fees')->join('students', 'students.id', '=', 'fees.student_id')->leftJoin('classes', 'classes.id', '=', 'students.class_id')->leftJoin('terms', 'terms.id', '=', 'fees.term_id')
            ->where('fees.status', '!=', 'cancelled')
            ->when($request->filled('class_id'), fn ($q) => $q->where('students.class_id', $request->class_id))
            ->when($request->filled('term_id'), fn ($q) => $q->where('fees.term_id', $request->term_id));
    }

    private function feeRow($f): array
    {
        return ['name' => $this->name($f), 'admission_number' => $f->admission_number, 'class' => $f->class_name, 'term' => $f->term_name, 'fee' => $f->fee_type,
            'amount' => (float) $f->amount, 'paid' => (float) $f->amount_paid, 'balance' => max(0, (float) $f->amount - (float) $f->amount_paid), 'due' => substr((string) $f->due_date, 0, 10), 'status' => ucfirst((string) $f->status)];
    }

    private function feeCols(): array
    {
        return $this->cols([['name', 'Student'], ['admission_number', 'Admission No.'], ['class', 'Class'], ['term', 'Term'], ['fee', 'Fee'], ['amount', 'Amount', 'money'], ['paid', 'Paid', 'money'], ['balance', 'Balance', 'money'], ['due', 'Due', 'date'], ['status', 'Status']]);
    }

    private const FEE_SELECT = ['fees.*', 'students.first_name', 'students.last_name', 'students.admission_number', 'classes.name as class_name', 'terms.name as term_name'];

    private function rFees(Request $request): array
    {
        return [$this->feeCols(), $this->fees($request)->orderBy('classes.name')->orderBy('students.first_name')->get(self::FEE_SELECT)->map(fn ($f) => $this->feeRow($f))->all()];
    }

    private function rDueFees(Request $request): array
    {
        $rows = $this->fees($request)->whereRaw('fees.amount - fees.amount_paid > 0')->whereDate('fees.due_date', '<', now()->toDateString())
            ->orderBy('fees.due_date')->get(self::FEE_SELECT)->map(fn ($f) => $this->feeRow($f) + ['days_overdue' => now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($f->due_date))]);
        $cols = $this->feeCols();
        $cols[] = ['key' => 'days_overdue', 'label' => 'Days overdue', 'type' => 'number'];

        return [$cols, $rows->all()];
    }

    private function rBalanceFees(Request $request): array
    {
        $rows = $this->fees($request)->get(self::FEE_SELECT)->groupBy('student_id')->map(function ($g) {
            $f = $g->first();
            $amount = $g->sum(fn ($x) => (float) $x->amount);
            $paid = $g->sum(fn ($x) => (float) $x->amount_paid);

            return ['name' => $this->name($f), 'admission_number' => $f->admission_number, 'class' => $f->class_name, 'fees' => $g->count(), 'amount' => $amount, 'paid' => $paid, 'balance' => max(0, $amount - $paid)];
        })->sortByDesc('balance');

        return [$this->cols([['name', 'Student'], ['admission_number', 'Admission No.'], ['class', 'Class'], ['fees', 'Fees', 'number'], ['amount', 'Billed', 'money'], ['paid', 'Paid', 'money'], ['balance', 'Balance', 'money']]), $rows->values()->all()];
    }

    private function rPaymentSearch(Request $request): array
    {
        $q = DB::table('payments')->join('students', 'students.id', '=', 'payments.student_id')->leftJoin('classes', 'classes.id', '=', 'students.class_id')->leftJoin('fees', 'fees.id', '=', 'payments.fee_id')
            ->when($request->filled('class_id'), fn ($q) => $q->where('students.class_id', $request->class_id))
            ->when($request->filled('method'), fn ($q) => $q->where('payments.payment_method', $request->method))->orderByDesc('payments.payment_date');
        $rows = $this->between($q, $request, 'payments.payment_date')->get(['payments.*', 'students.first_name', 'students.last_name', 'students.admission_number', 'classes.name as class_name', 'fees.fee_type'])
            ->map(fn ($p) => ['receipt' => str_pad((string) $p->id, 7, '0', STR_PAD_LEFT), 'date' => substr((string) $p->payment_date, 0, 10), 'name' => $this->name($p), 'class' => $p->class_name,
                'fee' => $p->fee_type, 'amount' => (float) $p->amount, 'method' => ucwords(str_replace('_', ' ', (string) $p->payment_method)), 'reference' => $p->payment_reference, 'paid_into' => $p->paid_into, 'status' => ucfirst((string) $p->status)]);

        return [$this->cols([['receipt', 'Receipt No.'], ['date', 'Date', 'date'], ['name', 'Student'], ['class', 'Class'], ['fee', 'Fee'], ['amount', 'Amount', 'money'], ['method', 'Method'], ['reference', 'Reference'], ['paid_into', 'Paid into'], ['status', 'Status']]), $rows->all()];
    }

    /** Payments in and expenses out, oldest first. */
    private function moneyLines(Request $request)
    {
        $in = $this->between(DB::table('payments')->leftJoin('students', 'students.id', '=', 'payments.student_id')->where('payments.status', 'confirmed'), $request, 'payments.payment_date')
            ->get(['payments.id', 'payments.payment_date as d', 'payments.amount', 'payments.payment_method', 'students.first_name', 'students.last_name'])
            ->map(fn ($p) => ['date' => substr((string) $p->d, 0, 10), 'kind' => 'Fee payment', 'details' => 'Receipt ' . str_pad((string) $p->id, 7, '0', STR_PAD_LEFT) . ' — ' . $this->name($p), 'method' => ucwords(str_replace('_', ' ', (string) $p->payment_method)), 'in' => (float) $p->amount, 'out' => 0.0]);
        $out = $this->has('expenses') ? $this->between(DB::table('expenses'), $request, 'date')->get()
            ->map(fn ($e) => ['date' => substr((string) $e->date, 0, 10), 'kind' => 'Expense · ' . $e->category, 'details' => $e->description, 'method' => ucwords(str_replace('_', ' ', (string) $e->payment_method)), 'in' => 0.0, 'out' => (float) $e->amount]) : collect();

        return $in->concat($out)->sortBy('date')->values();
    }

    private function rTransaction(Request $request): array
    {
        return [$this->cols([['date', 'Date', 'date'], ['kind', 'Type'], ['details', 'Details'], ['method', 'Method'], ['in', 'Money in', 'money'], ['out', 'Money out', 'money']]), $this->moneyLines($request)->all()];
    }

    private function rLedger(Request $request): array
    {
        $balance = 0.0;
        $rows = $this->moneyLines($request)->map(function ($r) use (&$balance) {
            $balance += $r['in'] - $r['out'];

            return $r + ['balance' => round($balance, 2)];
        });

        return [$this->cols([['date', 'Date', 'date'], ['kind', 'Type'], ['details', 'Details'], ['in', 'Debit (in)', 'money'], ['out', 'Credit (out)', 'money'], ['balance', 'Balance', 'money']]), $rows->all()];
    }

    private function inventory(Request $request, array $types)
    {
        $q = DB::table('inventory_transactions')->leftJoin('inventory_items', 'inventory_items.id', '=', 'inventory_transactions.item_id')->whereIn('inventory_transactions.type', $types)->orderByDesc('inventory_transactions.created_at');

        return $this->between($q, $request, 'inventory_transactions.created_at')->get(['inventory_transactions.*', 'inventory_items.name as item', 'inventory_items.unit', 'inventory_items.unit_price']);
    }

    private function rProductPurchase(Request $request): array
    {
        $rows = $this->inventory($request, ['purchase', 'adjustment'])->map(fn ($t) => ['date' => substr((string) $t->created_at, 0, 10), 'item' => $t->item, 'quantity' => (float) $t->quantity,
            'unit' => $t->unit, 'unit_price' => (float) $t->unit_price, 'value' => (float) $t->quantity * (float) $t->unit_price, 'note' => $t->notes ?: $t->purpose]);

        return [$this->cols([['date', 'Date', 'date'], ['item', 'Item'], ['quantity', 'Quantity', 'number'], ['unit', 'Unit'], ['unit_price', 'Unit price', 'money'], ['value', 'Value', 'money'], ['note', 'Note']]), $rows->all()];
    }

    private function rProductSale(Request $request): array
    {
        $rows = $this->inventory($request, ['checkout', 'disposal', 'sale', 'issue'])->map(fn ($t) => ['date' => substr((string) $t->created_at, 0, 10), 'item' => $t->item, 'quantity' => (float) $t->quantity,
            'to' => $t->borrower_name ?: '—', 'value' => (float) $t->quantity * (float) $t->unit_price, 'returned' => $t->returned_at ? substr((string) $t->returned_at, 0, 10) : '—', 'purpose' => $t->purpose ?: $t->notes, 'type' => ucfirst((string) $t->type)]);

        return [$this->cols([['date', 'Date', 'date'], ['type', 'Type'], ['item', 'Item'], ['quantity', 'Quantity', 'number'], ['to', 'Issued to'], ['value', 'Value', 'money'], ['returned', 'Returned'], ['purpose', 'Purpose']]), $rows->all()];
    }

    // ── staff ────────────────────────────────────────────────────────────

    private function rSalary(Request $request): array
    {
        $month = (int) ($request->input('month') ?: now()->month);
        $year = (int) ($request->input('year') ?: now()->year);
        $rows = DB::table('payrolls')->leftJoin('users', 'users.id', '=', 'payrolls.staff_id')->where('month', $month)->where('year', $year)->orderBy('users.name')
            ->get(['payrolls.*', 'users.name', 'users.role'])->map(fn ($p) => ['name' => $p->name, 'role' => ucwords(str_replace('_', ' ', (string) $p->role)), 'basic' => (float) $p->basic_salary,
                'earnings' => (float) $p->allowances, 'deductions' => (float) $p->deductions, 'net' => (float) $p->net_salary, 'status' => ucfirst((string) $p->status), 'paid_on' => $p->payment_date ? substr((string) $p->payment_date, 0, 10) : '—']);

        return [$this->cols([['name', 'Staff'], ['role', 'Role'], ['basic', 'Basic', 'money'], ['earnings', 'Earnings', 'money'], ['deductions', 'Deductions', 'money'], ['net', 'Net pay', 'money'], ['status', 'Status'], ['paid_on', 'Paid on']]), $rows->all()];
    }

    private function rVisitor(Request $request): array
    {
        if (! $this->has('visitors')) {
            return [[], []];
        }
        $rows = $this->between(DB::table('visitors')->orderByDesc('entry_time'), $request, 'entry_time')->get()->map(fn ($v) => [
            'date' => substr((string) $v->entry_time, 0, 10), 'name' => $v->name, 'phone' => $v->phone, 'purpose' => $v->purpose, 'host' => $v->host_name,
            'in' => substr((string) $v->entry_time, 11, 5), 'out' => $v->exit_time ? substr((string) $v->exit_time, 11, 5) : '—', 'badge' => $v->badge_number, 'id' => trim(($v->id_type ?? '') . ' ' . ($v->id_number ?? ''))]);

        return [$this->cols([['date', 'Date', 'date'], ['name', 'Visitor'], ['phone', 'Phone'], ['purpose', 'Purpose'], ['host', 'To see'], ['in', 'In'], ['out', 'Out'], ['badge', 'Badge'], ['id', 'ID']]), $rows->all()];
    }
}
