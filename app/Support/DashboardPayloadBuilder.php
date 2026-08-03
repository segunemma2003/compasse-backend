<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Shapes tenant data for role dashboards (student, parent, teacher).
 */
class DashboardPayloadBuilder
{
    /**
     * @return list<array{subject: string, score: float|string, grade: string}>
     */
    public static function studentRecentResults(int $studentId, int $limit = 6): array
    {
        $result = DB::table('student_results')
            ->where('student_id', $studentId)
            ->where('status', 'published')
            ->orderByDesc('academic_year_id')
            ->orderByDesc('term_id')
            ->first();

        if (! $result) {
            return [];
        }

        return DB::table('subject_results')
            ->join('subjects', 'subject_results.subject_id', '=', 'subjects.id')
            ->where('subject_results.student_result_id', $result->id)
            ->orderByDesc('subject_results.total_score')
            ->limit($limit)
            ->get(['subjects.name as subject', 'subject_results.total_score as score', 'subject_results.grade'])
            ->map(fn ($row) => [
                'subject' => $row->subject,
                'score'   => round((float) $row->score, 1),
                'grade'   => (string) ($row->grade ?? ''),
            ])
            ->values()
            ->all();
    }

    public static function studentClassPosition(int $studentId): ?int
    {
        $result = DB::table('student_results')
            ->where('student_id', $studentId)
            ->where('status', 'published')
            ->orderByDesc('academic_year_id')
            ->orderByDesc('term_id')
            ->first(['position']);

        return $result?->position !== null ? (int) $result->position : null;
    }

    /**
     * @return list<array{title: string, subject: string, due_date: string}>
     */
    public static function studentPendingAssignments(int $studentId, int $classId, int $limit = 10): array
    {
        $submitted = DB::table('assignment_submissions')
            ->where('student_id', $studentId)
            ->pluck('assignment_id');

        $rows = DB::table('assignments')
            ->leftJoin('subjects', 'assignments.subject_id', '=', 'subjects.id')
            ->where('assignments.class_id', $classId)
            ->whereIn('assignments.status', ['active', 'published', 'open', 'pending'])
            ->when($submitted->isNotEmpty(), fn ($q) => $q->whereNotIn('assignments.id', $submitted))
            ->orderBy('assignments.due_date')
            ->limit($limit)
            ->get([
                'assignments.title',
                'assignments.due_date',
                'subjects.name as subject_name',
            ]);

        return $rows->map(fn ($a) => [
            'title'    => $a->title,
            'subject'  => $a->subject_name ?? 'General',
            'due_date' => $a->due_date ? (string) $a->due_date : '—',
        ])->values()->all();
    }

    /**
     * @return list<array{subject: string, teacher: string, time: string}>
     */
    public static function studentTodaysClasses(int $classId, int $schoolId): array
    {
        $day = now()->format('l');

        $rows = DB::table('timetables')
            ->join('subjects', 'timetables.subject_id', '=', 'subjects.id')
            ->leftJoin('teachers', 'timetables.teacher_id', '=', 'teachers.id')
            ->where('timetables.class_id', $classId)
            ->where('timetables.school_id', $schoolId)
            ->where('timetables.day_of_week', $day)
            ->orderBy('timetables.start_time')
            ->get([
                'subjects.name as subject',
                'teachers.first_name',
                'teachers.last_name',
                'timetables.start_time',
                'timetables.end_time',
            ]);

        if ($rows->isEmpty()) {
            return DB::table('subjects')
                ->where('class_id', $classId)
                ->orderBy('name')
                ->limit(5)
                ->get(['name'])
                ->map(fn ($s, $i) => [
                    'subject' => $s->name,
                    'teacher' => '—',
                    'time'    => sprintf('%02d:00', 8 + $i),
                ])
                ->values()
                ->all();
        }

        return $rows->map(function ($row) {
            $teacher = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

            return [
                'subject' => $row->subject,
                'teacher' => $teacher !== '' ? $teacher : '—',
                'time'    => substr((string) $row->start_time, 0, 5)
                    .(isset($row->end_time) ? '–'.substr((string) $row->end_time, 0, 5) : ''),
            ];
        })->values()->all();
    }

    public static function studentAttendanceRate(int $studentId): float
    {
        $total = DB::table('attendances')
            ->where('attendanceable_id', $studentId)
            ->where('attendanceable_type', 'App\\Models\\Student')
            ->count();

        if ($total === 0) {
            return 0;
        }

        $present = DB::table('attendances')
            ->where('attendanceable_id', $studentId)
            ->where('attendanceable_type', 'App\\Models\\Student')
            ->where('status', 'present')
            ->count();

        return round(($present / $total) * 100, 2);
    }

    /**
     * @param  int[]  $studentIds
     * @return list<array<string, mixed>>
     */
    public static function parentChildrenCards(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return DB::table('students')
            ->leftJoin('classes', 'students.class_id', '=', 'classes.id')
            ->whereIn('students.id', $studentIds)
            ->get([
                'students.id',
                'students.first_name',
                'students.last_name',
                'students.class_id',
                'classes.name as class_name',
            ])
            ->map(function ($child) {
                $sid = (int) $child->id;
                $unpaid = DB::table('fees')
                    ->where('student_id', $sid)
                    ->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->exists();

                $result = DB::table('student_results')
                    ->where('student_id', $sid)
                    ->where('status', 'published')
                    ->orderByDesc('academic_year_id')
                    ->orderByDesc('term_id')
                    ->first(['position', 'average_score']);

                return [
                    'id'          => $sid,
                    'name'        => trim("{$child->first_name} {$child->last_name}"),
                    'class_name'  => $child->class_name ?? '—',
                    'attendance'  => self::studentAttendanceRate($sid),
                    'position'    => $result?->position,
                    'fees_paid'   => ! $unpaid,
                    'average'     => $result?->average_score !== null ? round((float) $result->average_score, 1) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  int[]  $studentIds
     * @return list<array{child_name: string, subject: string, term: string, score: float}>
     */
    public static function parentRecentPerformance(array $studentIds, int $limit = 8): array
    {
        if ($studentIds === []) {
            return [];
        }

        $items = collect();

        foreach ($studentIds as $studentId) {
            $student = DB::table('students')
                ->where('id', $studentId)
                ->first(['first_name', 'last_name']);
            $childName = trim(($student->first_name ?? '').' '.($student->last_name ?? ''));

            $result = DB::table('student_results')
                ->join('terms', 'student_results.term_id', '=', 'terms.id')
                ->where('student_results.student_id', $studentId)
                ->where('student_results.status', 'published')
                ->orderByDesc('student_results.academic_year_id')
                ->orderByDesc('student_results.term_id')
                ->first(['student_results.id', 'terms.name as term_name']);

            if (! $result) {
                continue;
            }

            $subjects = DB::table('subject_results')
                ->join('subjects', 'subject_results.subject_id', '=', 'subjects.id')
                ->where('subject_results.student_result_id', $result->id)
                ->orderByDesc('subject_results.total_score')
                ->limit(3)
                ->get(['subjects.name as subject', 'subject_results.total_score as score']);

            foreach ($subjects as $subject) {
                $items->push([
                    'child_name' => $childName,
                    'subject'    => $subject->subject,
                    'term'       => $result->term_name ?? 'Current term',
                    'score'      => round((float) $subject->score, 1),
                ]);
            }
        }

        return $items->take($limit)->values()->all();
    }

    /**
     * @return list<array{subject: string, class_name: string, time: string}>
     */
    public static function teacherTodaysSchedule(int $teacherId): array
    {
        $day = now()->format('l');

        $rows = DB::table('timetables')
            ->join('subjects', 'timetables.subject_id', '=', 'subjects.id')
            ->leftJoin('classes', 'timetables.class_id', '=', 'classes.id')
            ->where('timetables.teacher_id', $teacherId)
            ->where('timetables.day_of_week', $day)
            ->orderBy('timetables.start_time')
            ->get([
                'subjects.name as subject',
                'classes.name as class_name',
                'timetables.start_time',
                'timetables.end_time',
            ]);

        return $rows->map(fn ($row) => [
            'subject'    => $row->subject,
            'class_name' => $row->class_name ?? '—',
            'time'       => substr((string) $row->start_time, 0, 5)
                .(isset($row->end_time) ? '–'.substr((string) $row->end_time, 0, 5) : ''),
        ])->values()->all();
    }

    /**
     * @return list<array{student_name: string, assignment_title: string, submitted_at: string}>
     */
    public static function teacherRecentSubmissions(int $teacherId, int $limit = 8): array
    {
        return DB::table('assignment_submissions')
            ->join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
            ->join('students', 'assignment_submissions.student_id', '=', 'students.id')
            ->where('assignments.teacher_id', $teacherId)
            ->orderByDesc('assignment_submissions.submitted_at')
            ->limit($limit)
            ->get([
                'students.first_name',
                'students.last_name',
                'assignments.title as assignment_title',
                'assignment_submissions.submitted_at',
            ])
            ->map(fn ($row) => [
                'student_name'     => trim("{$row->first_name} {$row->last_name}"),
                'assignment_title' => $row->assignment_title,
                'submitted_at'     => $row->submitted_at ? substr((string) $row->submitted_at, 0, 10) : '—',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  int[]  $studentIds
     */
    public static function parentAverageAttendance(array $studentIds): ?float
    {
        if ($studentIds === []) {
            return null;
        }

        $rates = array_map(fn (int $id) => self::studentAttendanceRate($id), $studentIds);

        return round(array_sum($rates) / count($rates), 1);
    }

    public static function unreadMessageCount(int $userId): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('messages')) {
            return 0;
        }

        return (int) DB::table('messages')
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->count();
    }
}
