<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SendTimetablePeriodReminders extends Command
{
    protected $signature = 'timetable:send-period-reminders';

    protected $description = 'Notify teachers and students before each timetable period starts';

    public function handle(): int
    {
        $total = 0;

        foreach (Tenant::all() as $tenant) {
            try {
                tenancy()->initialize($tenant);
                $total += $this->processTenant();
                tenancy()->end();
            } catch (\Throwable $e) {
                try {
                    tenancy()->end();
                } catch (\Throwable) {
                }
                Log::warning('timetable:send-period-reminders tenant failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($total > 0) {
            $this->info("Sent {$total} timetable reminder(s).");
        }

        return self::SUCCESS;
    }

    private function processTenant(): int
    {
        if (! Schema::hasTable('timetables') || ! Schema::hasTable('notifications')) {
            return 0;
        }

        $schoolId = DB::table('schools')->value('id') ?? 1;
        $enabled  = DB::table('settings')
            ->where('school_id', $schoolId)
            ->where('key', 'timetable_reminders_enabled')
            ->value('value');
        if ($enabled === 'false' || $enabled === '0') {
            return 0;
        }

        $teacherMinutes = (int) (DB::table('settings')
            ->where('school_id', $schoolId)
            ->where('key', 'timetable_teacher_reminder_minutes')
            ->value('value') ?? 10);
        $studentMinutes = (int) (DB::table('settings')
            ->where('school_id', $schoolId)
            ->where('key', 'timetable_student_reminder_minutes')
            ->value('value') ?? 5);

        if ($teacherMinutes <= 0 && $studentMinutes <= 0) {
            return 0;
        }

        $dayName  = now()->format('l');
        $slotDate = now()->toDateString();
        $sent     = 0;

        $slots = DB::table('timetables')
            ->join('subjects', 'subjects.id', '=', 'timetables.subject_id')
            ->leftJoin('classes', 'classes.id', '=', 'timetables.class_id')
            ->where('timetables.day_of_week', $dayName)
            ->select(
                'timetables.id',
                'timetables.teacher_id',
                'timetables.class_id',
                'timetables.arm_id',
                'timetables.start_time',
                'subjects.name as subject_name',
                'classes.name as class_name'
            )
            ->get();

        foreach ($slots as $slot) {
            $startAt = \Carbon\Carbon::parse("{$slotDate} {$slot->start_time}");

            if ($teacherMinutes > 0 && $slot->teacher_id) {
                $sent += $this->maybeNotify(
                    (int) DB::table('teachers')->where('id', $slot->teacher_id)->value('user_id'),
                    (int) $slot->id,
                    $slotDate,
                    $teacherMinutes,
                    $startAt,
                    'Class starting soon',
                    sprintf(
                        '%s — %s starts in %d min (%s)',
                        $slot->class_name ?? 'Class',
                        $slot->subject_name,
                        $teacherMinutes,
                        $startAt->format('H:i')
                    )
                );
            }

            if ($studentMinutes > 0 && $slot->class_id) {
                $students = DB::table('students')
                    ->where('class_id', $slot->class_id)
                    ->where('status', 'active')
                    ->when($slot->arm_id, fn ($q) => $q->where('arm_id', $slot->arm_id))
                    ->pluck('user_id');

                foreach ($students as $userId) {
                    if (! $userId) {
                        continue;
                    }
                    $sent += $this->maybeNotify(
                        (int) $userId,
                        (int) $slot->id,
                        $slotDate,
                        $studentMinutes,
                        $startAt,
                        'Next period',
                        sprintf(
                            '%s starts in %d min (%s)',
                            $slot->subject_name,
                            $studentMinutes,
                            $startAt->format('H:i')
                        )
                    );
                }
            }
        }

        return $sent;
    }

    private function maybeNotify(
        int $userId,
        int $timetableId,
        string $slotDate,
        int $minutesBefore,
        \Carbon\Carbon $startAt,
        string $title,
        string $message
    ): int {
        if ($userId <= 0) {
            return 0;
        }

        $remindAt = $startAt->copy()->subMinutes($minutesBefore);
        $now      = now();
        if ($now->lt($remindAt) || $now->gte($remindAt->copy()->addMinute())) {
            return 0;
        }

        if (Schema::hasTable('timetable_reminder_logs')) {
            $exists = DB::table('timetable_reminder_logs')
                ->where('user_id', $userId)
                ->where('timetable_id', $timetableId)
                ->where('slot_date', $slotDate)
                ->where('minutes_before', $minutesBefore)
                ->exists();
            if ($exists) {
                return 0;
            }
        }

        DB::table('notifications')->insert([
            'user_id'    => $userId,
            'title'      => $title,
            'message'    => $message,
            'type'       => 'info',
            'is_read'    => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('timetable_reminder_logs')) {
            DB::table('timetable_reminder_logs')->insert([
                'user_id'        => $userId,
                'timetable_id'   => $timetableId,
                'slot_date'      => $slotDate,
                'minutes_before' => $minutesBefore,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        return 1;
    }
}
