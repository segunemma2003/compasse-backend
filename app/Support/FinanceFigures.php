<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One set of money figures for the Finance page and every dashboard, so they always agree:
 *  - expected / outstanding: fees of students still in the school, not cancelled;
 *  - collected: confirmed payments, by the date they were paid;
 *  - this term / this month: by payment date within the current term / calendar month.
 */
class FinanceFigures
{
    /** Fees of students still in the school (fees of deleted students showed with no name). */
    public static function fees()
    {
        $q = DB::table('fees')->where('fees.status', '!=', 'cancelled');
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'deleted_at')) {
            $q->join('students', 'students.id', '=', 'fees.student_id')->whereNull('students.deleted_at');
        }

        return $q;
    }

    public static function payments()
    {
        return DB::table('payments')->where('payments.status', 'confirmed');
    }

    /** [start, end] of the current term, else of the current calendar year. */
    public static function termRange(): array
    {
        $term = Schema::hasTable('terms') ? DB::table('terms')->where('is_current', true)->first(['start_date', 'end_date']) : null;
        $start = $term?->start_date ? Carbon::parse($term->start_date)->startOfDay() : now()->startOfYear();
        $end = $term?->end_date ? Carbon::parse($term->end_date)->endOfDay() : now()->endOfDay();

        return [$start, $end];
    }

    public static function totals(): array
    {
        [$ts, $te] = self::termRange();
        $expected = (float) self::fees()->sum('fees.amount');
        $collected = (float) self::payments()->sum('payments.amount');

        return [
            'expected'             => $expected,
            'collected'            => $collected,
            'collected_this_term'  => (float) self::payments()->whereBetween('payments.payment_date', [$ts, $te])->sum('payments.amount'),
            'collected_this_month' => (float) self::payments()->whereBetween('payments.payment_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('payments.amount'),
            'outstanding'          => (float) self::fees()->whereIn('fees.status', ['pending', 'partial', 'overdue'])->sum('fees.balance'),
            'collection_rate'      => $expected > 0 ? round($collected / $expected * 100, 1) : 0,
        ];
    }

    /** Confirmed payments per month, oldest first: [{month: 'Nov 2026', key: '2026-11', amount}] */
    public static function monthly(int $months = 12): array
    {
        $rows = self::payments()->where('payments.payment_date', '>=', now()->subMonths($months - 1)->startOfMonth())
            ->get(['payments.payment_date', 'payments.amount'])
            ->groupBy(fn ($p) => Carbon::parse($p->payment_date)->format('Y-m'))
            ->map(fn ($g) => ['amount' => (float) $g->sum('amount'), 'count' => $g->count()]);
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $out[] = ['key' => $m->format('Y-m'), 'month' => $m->format('M Y'), 'amount' => $rows[$m->format('Y-m')]['amount'] ?? 0.0, 'count' => $rows[$m->format('Y-m')]['count'] ?? 0];
        }

        return $out;
    }
}
