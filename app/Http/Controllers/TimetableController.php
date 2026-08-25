<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TimetableController extends Controller
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('timetables')) {
            return response()->json(['timetables' => []]);
        }

        try {
            $user  = $request->user();
            $query = $this->enrichedQuery();

            $this->applyListFilters($request, $query, $user);

            $query->orderBy('timetables.day_of_week');
            if ($this->timetablesHasColumn('period_number')) {
                $query->orderBy('timetables.period_number');
            }
            $timetables = $query
                ->orderBy('timetables.start_time')
                ->get()
                ->map(fn ($row) => $this->formatRow($row));

            return response()->json(['timetables' => $timetables]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('TimetableController::index failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['timetables' => []]);
        }
    }

    public function show(Request $request, $id): JsonResponse
    {
        if (! Schema::hasTable('timetables')) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $row = $this->enrichedQuery()->where('timetables.id', $id)->first();
        if (! $row) {
            return response()->json(['error' => 'Timetable entry not found'], 404);
        }

        return response()->json(['timetable' => $this->formatRow($row)]);
    }

    public function studentMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $studentId = (int) ($request->query('student_id') ?: 0);

        if ($studentId > 0) {
            $guardianIds = $this->accessibleStudentIdsForGuardian($user);
            if ($guardianIds !== null && ! in_array($studentId, $guardianIds, true)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        } else {
            $studentId = (int) ($this->ownStudentId($user) ?? 0);
        }

        if ($studentId <= 0) {
            return response()->json(['error' => 'Student context required'], 422);
        }

        $student = DB::table('students')->where('id', $studentId)->first();
        if (! $student) {
            return response()->json(['error' => 'Student not found'], 404);
        }

        $request->merge([
            'class_id' => $student->class_id,
            'arm_id'   => $student->arm_id,
        ]);

        return $this->index($request);
    }

    public function teacherMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $teacherId = DB::table('teachers')->where('user_id', $user->id)->value('id');
        if (! $teacherId) {
            return response()->json(['timetables' => []]);
        }

        $request->merge(['teacher_id' => $teacherId]);

        return $this->index($request);
    }

    public function getClassTimetable(Request $request, $classId): JsonResponse
    {
        $request->merge(['class_id' => $classId]);
        $response = $this->index($request);
        $payload  = $response->getData(true);

        return response()->json([
            'class_id'   => (int) $classId,
            'timetables' => $payload['timetables'] ?? [],
        ]);
    }

    public function getTeacherTimetable(Request $request, $teacherId): JsonResponse
    {
        $request->merge(['teacher_id' => $teacherId]);
        $response = $this->index($request);
        $payload  = $response->getData(true);

        return response()->json([
            'teacher_id' => (int) $teacherId,
            'timetables' => $payload['timetables'] ?? [],
        ]);
    }

    /**
     * Replace all slots for a class (optional arm) on given days.
     */
    public function syncGrid(Request $request): JsonResponse
    {
        if (! Schema::hasTable('timetables')) {
            return response()->json(['error' => 'Timetables not available'], 503);
        }

        $validator = Validator::make($request->all(), [
            'class_id'         => 'required|exists:classes,id',
            'arm_id'           => 'nullable|exists:arms,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'entries'          => 'required|array',
            'entries.*.day_of_week'    => 'required|in:'.implode(',', self::DAYS),
            'entries.*.period_number'  => 'nullable|integer|min:1|max:20',
            'entries.*.subject_id'     => 'required|exists:subjects,id',
            'entries.*.teacher_id'     => 'nullable|exists:teachers,id',
            'entries.*.start_time'     => 'nullable|date_format:H:i',
            'entries.*.end_time'       => 'nullable|date_format:H:i',
            'entries.*.room'           => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $classId = (int) $request->class_id;
        $armId   = $request->filled('arm_id') ? (int) $request->arm_id : null;
        $yearId  = $request->input('academic_year_id');

        $periodMap = $this->periodTimeMap($schoolId);

        try {
            DB::transaction(function () use ($request, $schoolId, $classId, $armId, $yearId, $periodMap) {
                $delete = DB::table('timetables')->where('class_id', $classId);
                if ($this->timetablesHasColumn('arm_id')) {
                    if ($armId) {
                        $delete->where('arm_id', $armId);
                    } else {
                        $delete->whereNull('arm_id');
                    }
                }
                $delete->delete();

                foreach ($request->entries as $entry) {
                    $period = isset($entry['period_number']) ? (int) $entry['period_number'] : null;
                    [$start, $end] = $this->resolveTimes($entry, $period, $periodMap);

                    $teacherId = $entry['teacher_id'] ?? null;
                    if (! $teacherId && ! empty($entry['subject_id'])) {
                        $teacherId = DB::table('subjects')->where('id', $entry['subject_id'])->value('teacher_id');
                    }

                    $row = [
                        'school_id'        => $schoolId,
                        'class_id'         => $classId,
                        'subject_id'       => $entry['subject_id'],
                        'teacher_id'       => $teacherId,
                        'day_of_week'      => $entry['day_of_week'],
                        'start_time'       => $start,
                        'end_time'         => $end,
                        'room'             => $entry['room'] ?? null,
                        'academic_year_id' => $yearId,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];

                    if ($this->timetablesHasColumn('arm_id')) {
                        $row['arm_id'] = $armId;
                    }
                    if ($this->timetablesHasColumn('period_number')) {
                        $row['period_number'] = $period;
                    }

                    DB::table('timetables')->insert($row);
                }
            });
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => 'Failed to save timetable',
                'message' => str_contains($e->getMessage(), 'Unknown column')
                    ? 'Timetable schema is outdated — run tenant migrations (php artisan tenants:migrate).'
                    : $e->getMessage(),
            ], 503);
        }

        DashboardController::bustCache();

        $request->merge(['class_id' => $classId, 'arm_id' => $armId]);

        return response()->json([
            'message'    => 'Timetable saved',
            'timetables' => $this->index($request)->getData(true)['timetables'] ?? [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'nullable|exists:classes,id',
            'arm_id'   => 'nullable|exists:arms,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'day_of_week' => 'required|in:'.implode(',', self::DAYS),
            'period_number' => 'nullable|integer|min:1|max:20',
            'start_time' => 'required_without:period_number|date_format:H:i',
            'end_time' => 'required_without:period_number|date_format:H:i|after:start_time',
            'room' => 'nullable|string|max:50',
            'term' => 'nullable|in:first,second,third',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request) ?? 1;
        $periodMap = $this->periodTimeMap($schoolId);
        $period = $request->input('period_number') ? (int) $request->period_number : null;
        [$start, $end] = $this->resolveTimes($request->all(), $period, $periodMap);

        $insert = [
            'school_id' => $schoolId,
            'class_id' => $request->class_id,
            'subject_id' => $request->subject_id,
            'teacher_id' => $request->teacher_id,
            'day_of_week' => $request->day_of_week,
            'start_time' => $start,
            'end_time' => $end,
            'room' => $request->room,
            'term' => $request->term,
            'academic_year_id' => $request->academic_year_id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($this->timetablesHasColumn('arm_id')) {
            $insert['arm_id'] = $request->arm_id;
        }
        if ($this->timetablesHasColumn('period_number')) {
            $insert['period_number'] = $period;
        }

        $timetableId = DB::table('timetables')->insertGetId($insert);

        DashboardController::bustCache();

        $row = $this->enrichedQuery()->where('timetables.id', $timetableId)->first();

        return response()->json([
            'message' => 'Timetable entry created successfully',
            'timetable' => $this->formatRow($row),
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $timetable = DB::table('timetables')->find($id);

        if (! $timetable) {
            return response()->json(['error' => 'Timetable entry not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'day_of_week' => 'sometimes|in:'.implode(',', self::DAYS),
            'period_number' => 'nullable|integer|min:1|max:20',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'room' => 'nullable|string|max:50',
            'teacher_id' => 'nullable|exists:teachers,id',
            'subject_id' => 'sometimes|exists:subjects,id',
            'arm_id' => 'nullable|exists:arms,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $allowed = ['day_of_week', 'room', 'teacher_id', 'subject_id'];
        if ($this->timetablesHasColumn('arm_id')) {
            $allowed[] = 'arm_id';
        }
        if ($this->timetablesHasColumn('period_number')) {
            $allowed[] = 'period_number';
        }
        $updates = $request->only($allowed);
        if ($request->has('start_time')) {
            $updates['start_time'] = $request->start_time.':00';
        }
        if ($request->has('end_time')) {
            $updates['end_time'] = $request->end_time.':00';
        }
        $updates['updated_at'] = now();

        DB::table('timetables')->where('id', $id)->update($updates);

        DashboardController::bustCache();

        $row = $this->enrichedQuery()->where('timetables.id', $id)->first();

        return response()->json([
            'message' => 'Timetable entry updated successfully',
            'timetable' => $this->formatRow($row),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $timetable = DB::table('timetables')->find($id);

        if (! $timetable) {
            return response()->json(['error' => 'Timetable entry not found'], 404);
        }

        DB::table('timetables')->where('id', $id)->delete();
        DashboardController::bustCache();

        return response()->json(['message' => 'Timetable entry deleted successfully']);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function enrichedQuery()
    {
        $q = DB::table('timetables')
            ->join('subjects', 'timetables.subject_id', '=', 'subjects.id')
            ->leftJoin('classes', 'timetables.class_id', '=', 'classes.id')
            ->leftJoin('teachers', 'timetables.teacher_id', '=', 'teachers.id');

        $select = [
            'timetables.*',
            'subjects.name as subject_name',
            'subjects.code as subject_code',
            'classes.name as class_name',
            DB::raw("TRIM(CONCAT(COALESCE(teachers.first_name,''), ' ', COALESCE(teachers.last_name,''))) as teacher_name"),
        ];

        if ($this->timetablesHasColumn('arm_id')) {
            $q->leftJoin('arms', 'timetables.arm_id', '=', 'arms.id');
            $select[] = 'arms.name as arm_name';
        } else {
            $select[] = DB::raw('NULL as arm_name');
        }

        return $q->select($select);
    }

    private function timetablesHasColumn(string $column): bool
    {
        return Schema::hasTable('timetables') && Schema::hasColumn('timetables', $column);
    }

    private function applyListFilters(Request $request, $query, User $user): void
    {
        if ($request->filled('class_id')) {
            $query->where('timetables.class_id', $request->class_id);
        }

        if ($request->filled('arm_id') && $this->timetablesHasColumn('arm_id')) {
            $armId = $request->arm_id;
            $query->where(function ($q) use ($armId) {
                $q->whereNull('timetables.arm_id')->orWhere('timetables.arm_id', $armId);
            });
        }

        if ($request->filled('teacher_id')) {
            $query->where('timetables.teacher_id', $request->teacher_id);
        }

        if ($request->filled('day_of_week')) {
            $query->where('timetables.day_of_week', $request->day_of_week);
        }

        // Auto-scope unfiltered list for students/teachers calling GET /timetable
        if (! $request->filled('class_id') && ! $request->filled('teacher_id')) {
            $ownStudent = $this->ownStudentId($user);
            if ($ownStudent) {
                $student = DB::table('students')->where('id', $ownStudent)->first();
                if ($student) {
                    $query->where('timetables.class_id', $student->class_id);
                    if ($this->timetablesHasColumn('arm_id')) {
                        $query->where(function ($q) use ($student) {
                            $q->whereNull('timetables.arm_id');
                            if ($student->arm_id) {
                                $q->orWhere('timetables.arm_id', $student->arm_id);
                            }
                        });
                    }
                }
            } elseif (in_array($user->role, ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'], true)) {
                $teacherId = DB::table('teachers')->where('user_id', $user->id)->value('id');
                if ($teacherId) {
                    $query->where('timetables.teacher_id', $teacherId);
                }
            }
        }
    }

    private function formatRow(object $row): array
    {
        $start = substr((string) $row->start_time, 0, 5);
        $end   = substr((string) $row->end_time, 0, 5);

        return [
            'id'             => $row->id,
            'class_id'       => $row->class_id,
            'arm_id'         => $row->arm_id ?? null,
            'subject_id'     => $row->subject_id,
            'teacher_id'     => $row->teacher_id,
            'day_of_week'    => $row->day_of_week,
            'day'            => $row->day_of_week,
            'period_number'  => $row->period_number ?? null,
            'start_time'     => $start,
            'end_time'       => $end,
            'time'           => "{$start} – {$end}",
            'room'           => $row->room,
            'subject_name'   => trim($row->subject_name ?? ''),
            'subject_code'   => $row->subject_code ?? null,
            'class_name'     => $row->class_name ?? null,
            'arm_name'       => $row->arm_name ?? null,
            'teacher_name'   => trim($row->teacher_name ?? '') ?: null,
        ];
    }

    /** @return array<int, array{start: string, end: string}> */
    private function periodTimeMap(int $schoolId): array
    {
        if (! Schema::hasTable('timetable_periods')) {
            return [];
        }

        $rows = DB::table('timetable_periods')->where('school_id', $schoolId)->get();
        $map  = [];
        foreach ($rows as $row) {
            $map[(int) $row->period_number] = [
                'start' => substr((string) $row->start_time, 0, 8),
                'end'   => substr((string) $row->end_time, 0, 8),
            ];
        }

        return $map;
    }

    /** @param array<string, mixed> $entry */
    private function resolveTimes(array $entry, ?int $period, array $periodMap): array
    {
        if ($period && isset($periodMap[$period])) {
            return [$periodMap[$period]['start'], $periodMap[$period]['end']];
        }

        $start = isset($entry['start_time']) ? $entry['start_time'].':00' : '08:00:00';
        $end   = isset($entry['end_time']) ? $entry['end_time'].':00' : '08:45:00';

        return [$start, $end];
    }
}
