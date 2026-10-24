<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Runs a school's recurring billing plans that are due: bill the students, email the invoices, set the next date. */
class RecurringInvoices
{
    public static function nextDate(string $from, string $frequency, ?int $day = null): string
    {
        $d = Carbon::parse($from);
        return match ($frequency) {
            'weekly' => $d->addWeek()->toDateString(),
            'termly' => $d->addMonthsNoOverflow(4)->toDateString(),
            default => $d->addMonthNoOverflow()->day(min($day ?? $d->day, $d->copy()->addMonthNoOverflow()->daysInMonth))->toDateString(),
        };
    }

    /** @return array{plans:int, invoices:int} */
    public static function runDue(?int $onlyId = null, bool $force = false): array
    {
        if (! Schema::hasTable('recurring_invoices') || ! FeeInvoices::ready()) {
            return ['plans' => 0, 'invoices' => 0];
        }
        $plans = DB::table('recurring_invoices')->where('active', true)
            ->when($onlyId, fn ($q) => $q->where('id', $onlyId))
            ->when(! $force, fn ($q) => $q->whereDate('next_run_on', '<=', now()->toDateString()))->get();
        $count = 0;
        foreach ($plans as $p) {
            $students = array_values(array_unique(array_merge(
                json_decode($p->student_ids ?? '[]', true) ?: [],
                ($cls = json_decode($p->class_ids ?? '[]', true)) ? FeeInvoices::studentsIn($cls, json_decode($p->arm_ids ?? '[]', true) ?: []) : [],
            )));
            $lines = array_map(fn ($l) => ['name' => (string) $l['name'], 'amount' => (float) $l['amount']], json_decode($p->lines, true) ?: []);
            $made = $students && $lines
                ? FeeInvoices::bill($students, $lines, $p->name . ' — ' . now()->format('M Y'), now()->addDays((int) $p->due_in_days)->toDateString(), $p->notes)
                : [];
            if ($p->send_email) {
                foreach ($made as $fid) {
                    try {
                        FeeDocuments::emailInvoice(\App\Modules\Financial\Models\Fee::find($fid));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
            $next = $p->next_run_on;
            do {
                $next = self::nextDate($next, $p->frequency, (int) Carbon::parse($p->next_run_on)->day);
            } while (! $force && Carbon::parse($next)->lte(now()->startOfDay()));
            DB::table('recurring_invoices')->where('id', $p->id)->update([
                'next_run_on' => $force && Carbon::parse($p->next_run_on)->isFuture() ? $p->next_run_on : $next,
                'last_run_at' => now(), 'last_run_count' => count($made), 'updated_at' => now(),
            ]);
            $count += count($made);
        }

        return ['plans' => $plans->count(), 'invoices' => $count];
    }
}
