<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TimetablePeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('timetable_periods')) {
            return response()->json(['periods' => $this->defaultPeriods()]);
        }

        $schoolId = $this->getSchoolIdFromTenant($request);
        $periods  = DB::table('timetable_periods')
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->orderBy('sort_order')
            ->orderBy('period_number')
            ->get();

        if ($periods->isEmpty()) {
            return response()->json(['periods' => $this->defaultPeriods(), 'is_default' => true]);
        }

        return response()->json(['periods' => $periods, 'is_default' => false]);
    }

    /**
     * Replace the school's bell schedule (period slots).
     */
    public function sync(Request $request): JsonResponse
    {
        if (! Schema::hasTable('timetable_periods')) {
            return response()->json(['error' => 'Timetable periods not available — run tenant migrations'], 503);
        }

        $validator = Validator::make($request->all(), [
            'periods' => 'required|array|min:1',
            'periods.*.period_number' => 'required|integer|min:1|max:20',
            'periods.*.start_time'    => 'required|date_format:H:i',
            'periods.*.end_time'      => 'required|date_format:H:i',
            'periods.*.label'         => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $rows = $request->input('periods');
        foreach ($rows as $i => $row) {
            if (strtotime($row['end_time']) <= strtotime($row['start_time'])) {
                return response()->json([
                    'error' => 'Validation failed',
                    'messages' => ['periods' => ["Period {$row['period_number']}: end time must be after start time"]],
                ], 422);
            }
        }

        DB::transaction(function () use ($schoolId, $rows) {
            DB::table('timetable_periods')->where('school_id', $schoolId)->delete();
            foreach ($rows as $i => $row) {
                DB::table('timetable_periods')->insert([
                    'school_id'      => $schoolId,
                    'period_number'  => (int) $row['period_number'],
                    'label'          => $row['label'] ?? ('Period '.(int) $row['period_number']),
                    'start_time'     => $row['start_time'].':00',
                    'end_time'       => $row['end_time'].':00',
                    'sort_order'     => $i,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Period schedule saved', 'periods' => $this->index($request)->getData(true)['periods'] ?? []]);
    }

    /**
     * Timetable reminder settings (minutes before each period).
     */
    public function reminderSettings(Request $request): JsonResponse
    {
        $schoolId = $this->getSchoolIdFromTenant($request) ?? 1;
        $keys     = ['timetable_teacher_reminder_minutes', 'timetable_student_reminder_minutes', 'timetable_reminders_enabled'];
        $settings = DB::table('settings')
            ->where('school_id', $schoolId)
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        return response()->json([
            'teacher_reminder_minutes'  => (int) ($settings['timetable_teacher_reminder_minutes'] ?? 10),
            'student_reminder_minutes'  => (int) ($settings['timetable_student_reminder_minutes'] ?? 5),
            'reminders_enabled'         => filter_var($settings['timetable_reminders_enabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function updateReminderSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'teacher_reminder_minutes' => 'nullable|integer|min:0|max:120',
            'student_reminder_minutes' => 'nullable|integer|min:0|max:120',
            'reminders_enabled'        => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolIdFromTenant($request);
        if (! $schoolId) {
            return response()->json(['error' => 'School not found'], 400);
        }

        $map = [
            'timetable_teacher_reminder_minutes' => $request->input('teacher_reminder_minutes'),
            'timetable_student_reminder_minutes' => $request->input('student_reminder_minutes'),
            'timetable_reminders_enabled'        => $request->has('reminders_enabled')
                ? ($request->boolean('reminders_enabled') ? 'true' : 'false')
                : null,
        ];

        foreach ($map as $key => $value) {
            if ($value === null) {
                continue;
            }
            DB::table('settings')->updateOrInsert(
                ['key' => $key, 'school_id' => $schoolId],
                [
                    'value'      => (string) $value,
                    'type'       => is_bool($value) ? 'boolean' : 'integer',
                    'category'   => 'school',
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Reminder settings saved'] + $this->reminderSettings($request)->getData(true));
    }

    /** @return array<int, array<string, mixed>> */
    private function defaultPeriods(): array
    {
        $out = [];
        for ($n = 1; $n <= 8; $n++) {
            $h = 7 + $n;
            $out[] = [
                'period_number' => $n,
                'label'         => "Period {$n}",
                'start_time'    => sprintf('%02d:00:00', $h),
                'end_time'      => sprintf('%02d:45:00', $h),
                'sort_order'    => $n - 1,
            ];
        }

        return $out;
    }
}
