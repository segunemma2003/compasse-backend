<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermAttendanceSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Term attendance totals for a class (days opened / present / absent per
 * student), filled in by the class teacher and printed on report cards.
 * Students with nothing entered show what the daily register counted.
 */
class TermAttendanceSummaryController extends Controller
{
    /** GET /attendance/term-summary?class_id=&term_id= */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => 'required|integer',
            'term_id'  => 'required|integer',
            'arm_id'   => 'nullable|integer',
        ]);

        if ($denied = $this->assertCanManageClass($request, (int) $validated['class_id'])) {
            return $denied;
        }

        $term = Term::find($validated['term_id']);
        $students = Student::where('class_id', $validated['class_id'])
            ->when($validated['arm_id'] ?? null, fn ($q, $arm) => $q->where('arm_id', $arm))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'admission_number']);

        $saved = TermAttendanceSummary::where('term_id', $validated['term_id'])
            ->whereIn('student_id', $students->pluck('id'))
            ->get()->keyBy('student_id');

        $rows = $students->map(function (Student $s) use ($saved, $term) {
            $entry = $saved->get($s->id);
            $register = self::registerCounts($s->id, $term);

            return [
                'student_id'       => $s->id,
                'name'             => trim("{$s->first_name} {$s->last_name}"),
                'admission_number' => $s->admission_number,
                // Until the class teacher enters it, days opened comes from the term (set by the admin).
                'days_opened'      => $entry?->days_opened ?? ($term?->days_opened ?: null),
                'days_present'     => $entry?->days_present,
                'days_absent'      => $entry?->days_absent,
                'entered'          => (bool) $entry,
                'register'         => $register,
            ];
        });

        return response()->json(['term' => $term?->only(['id', 'name', 'days_opened']), 'students' => $rows]);
    }

    /**
     * PUT /attendance/term-summary
     * { class_id, term_id, rows: [{ student_id, days_opened, days_present, days_absent }] }
     * days_absent defaults to opened − present when left out.
     */
    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id'              => 'required|integer',
            'term_id'               => 'required|integer|exists:terms,id',
            'rows'                  => 'required|array|min:1|max:500',
            'rows.*.student_id'     => 'required|integer',
            'rows.*.days_opened'    => 'required|integer|min:0|max:366',
            'rows.*.days_present'   => 'required|integer|min:0|max:366',
            'rows.*.days_absent'    => 'nullable|integer|min:0|max:366',
        ]);

        if ($denied = $this->assertCanManageClass($request, (int) $validated['class_id'])) {
            return $denied;
        }

        $inClass = Student::where('class_id', $validated['class_id'])->pluck('id')->flip();
        $term = Term::find($validated['term_id']);
        $schoolId = School::value('id');
        $saved = 0;
        $errors = [];

        DB::transaction(function () use ($validated, $inClass, $term, $schoolId, $request, &$saved, &$errors) {
            foreach ($validated['rows'] as $row) {
                if (! $inClass->has($row['student_id'])) {
                    $errors[] = "Student #{$row['student_id']} is not in this class.";
                    continue;
                }
                $absent = $row['days_absent'] ?? max(0, $row['days_opened'] - $row['days_present']);
                if ($row['days_present'] + $absent > $row['days_opened']) {
                    $errors[] = "Student #{$row['student_id']}: present + absent is more than the days the school opened.";
                    continue;
                }
                TermAttendanceSummary::updateOrCreate(
                    ['student_id' => $row['student_id'], 'term_id' => $term->id],
                    [
                        'school_id'        => $schoolId,
                        'academic_year_id' => $term->academic_year_id,
                        'days_opened'      => $row['days_opened'],
                        'days_present'     => $row['days_present'],
                        'days_absent'      => $absent,
                        'recorded_by'      => $request->user()?->id,
                    ]
                );
                $saved++;
            }
        });

        return response()->json([
            'message' => "Saved attendance for {$saved} student(s)" . ($errors ? ', ' . count($errors) . ' skipped' : ''),
            'saved'   => $saved,
            'errors'  => $errors,
        ], $saved || ! $errors ? 200 : 422);
    }

    /**
     * Counts from the daily register for a term — the fallback the report
     * card uses when no totals were entered.
     *
     * @return array{opened:int, present:int, absent:int}
     */
    public static function registerCounts(int $studentId, ?Term $term): array
    {
        if (! $term?->start_date || ! $term?->end_date) {
            return ['opened' => 0, 'present' => 0, 'absent' => 0];
        }

        $rows = DB::table('attendances')
            ->where('attendanceable_type', Student::class)
            ->where('attendanceable_id', $studentId)
            ->whereBetween('date', [$term->start_date->toDateString(), $term->end_date->toDateString()])
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $present = (int) ($rows['present'] ?? 0) + (int) ($rows['late'] ?? 0);
        $absent  = (int) ($rows['absent'] ?? 0);

        return ['opened' => $present + $absent + (int) ($rows['excused'] ?? 0), 'present' => $present, 'absent' => $absent];
    }

    /** What the report card shows: entered totals if any, else the register. */
    public static function forReportCard(int $studentId, ?Term $term): array
    {
        $entry = $term ? TermAttendanceSummary::where('student_id', $studentId)->where('term_id', $term->id)->first() : null;
        if ($entry) {
            return ['opened' => $entry->days_opened, 'present' => $entry->days_present, 'absent' => $entry->days_absent];
        }

        $counts = self::registerCounts($studentId, $term);
        // The term's "days school opened" beats counting register days.
        if ($term?->days_opened) {
            $counts['opened'] = (int) $term->days_opened;
        }

        return $counts;
    }

    /** Admins and leadership; otherwise the class's (or one of its arms') class teacher / year tutor. */
    private function assertCanManageClass(Request $request, int $classId): ?JsonResponse
    {
        $user = $request->user();
        if ($this->accessibleClassIds($user) === null) {
            return null;
        }
        if (in_array($classId, $this->classTeacherOnlyClassIds($user), true)) {
            return null;
        }

        return $this->forbiddenResponse('Only the class teacher (or an administrator) can enter term attendance for this class.');
    }
}
