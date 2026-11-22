<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the school does with fee money, without showing it on the invoice: a fee line such as
 * "School Fees" is split into its parts (e.g. Lesson fees 50,000 + PTA 10,000, the rest Tuition).
 * Parents see and pay one "School Fees" line; the school's report shows every part billed, paid
 * and owing, and downloads as a spreadsheet.
 *
 * Settings key fee_splits = [{"item": "School Fees", "rest": "Tuition", "parts": [{"name": "PTA", "amount": 10000} | {"name": "…", "percent": 5}]}]
 * A payment is shared across a fee's lines, and a line's share across its parts, in proportion to their amounts.
 */
class FeeSplit
{
    /** @return array<int, array{item: string, rest: string, parts: array<int, array{name: string, amount: ?float, percent: ?float}>}> */
    public static function rules(): array
    {
        $raw = json_decode((string) (DB::table('settings')->where('key', 'fee_splits')->value('value') ?? ''), true);

        return self::clean(is_array($raw) ? $raw : []);
    }

    public static function clean(array $rules): array
    {
        return collect($rules)->map(fn ($r) => [
            'item' => trim((string) ($r['item'] ?? '')),
            'rest' => trim((string) ($r['rest'] ?? '')) ?: 'Tuition',
            'parts' => collect((array) ($r['parts'] ?? []))->map(fn ($p) => [
                'name' => trim((string) ($p['name'] ?? '')),
                'amount' => isset($p['amount']) && $p['amount'] !== '' && $p['amount'] !== null ? max(0, (float) $p['amount']) : null,
                'percent' => isset($p['percent']) && $p['percent'] !== '' && $p['percent'] !== null ? min(100, max(0, (float) $p['percent'])) : null,
            ])->filter(fn ($p) => $p['name'] !== '' && ($p['amount'] !== null || $p['percent'] !== null))->values()->all(),
        ])->filter(fn ($r) => $r['item'] !== '' && $r['parts'])->values()->all();
    }

    public static function save(array $rules): array
    {
        $clean = self::clean($rules);
        DB::table('settings')->updateOrInsert(['key' => 'fee_splits'], ['value' => json_encode($clean), 'updated_at' => now(), 'created_at' => now()]);

        return $clean;
    }

    /** The rule for a fee line ("School Fees Amt" matches "School Fees"), if any. */
    private static function ruleFor(string $itemName, array $rules): ?array
    {
        $n = mb_strtolower(trim($itemName));
        foreach ($rules as $r) {
            $k = mb_strtolower($r['item']);
            if ($n === $k || str_starts_with($n, $k)) {
                return $r;
            }
        }

        return null;
    }

    /** A line's amount in parts: [part name => amount]. */
    public static function splitLine(string $itemName, float $amount, array $rules): array
    {
        $rule = self::ruleFor($itemName, $rules);
        if (! $rule || $amount <= 0) {
            return [$itemName => $amount];
        }
        $out = [];
        $left = $amount;
        foreach ($rule['parts'] as $p) {
            $v = $p['amount'] !== null ? (float) $p['amount'] : round($amount * (float) $p['percent'] / 100, 2);
            $v = min($v, $left);
            if ($v > 0) {
                $out[$p['name']] = ($out[$p['name']] ?? 0) + $v;
                $left -= $v;
            }
        }
        if ($left > 0.004) {
            $out[$rule['rest']] = ($out[$rule['rest']] ?? 0) + round($left, 2);
        }

        return $out;
    }

    /**
     * Billed / paid / owing per part, for every fee in the filter, by student.
     *
     * @return array{parts: string[], rows: array, totals: array}
     */
    public static function report(?int $termId = null, ?int $yearId = null, ?int $classId = null): array
    {
        $rules = self::rules();
        $fees = DB::table('fees')
            ->join('students', 'students.id', '=', 'fees.student_id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->whereNull('students.deleted_at')
            ->where('fees.status', '!=', 'cancelled')
            ->when($termId, fn ($q) => $q->where('fees.term_id', $termId))
            ->when($yearId, fn ($q) => $q->where('fees.academic_year_id', $yearId))
            ->when($classId, fn ($q) => $q->where('students.class_id', $classId))
            ->orderBy('classes.name')->orderBy('students.last_name')->orderBy('students.first_name')
            ->get(['fees.id', 'fees.amount', 'fees.amount_paid', 'fees.fee_type', 'fees.description', 'fees.student_id',
                'students.first_name', 'students.last_name', 'students.admission_number', 'classes.name as class_name']);

        $items = Schema::hasTable('fee_items') && $fees->isNotEmpty()
            ? DB::table('fee_items')->whereIn('fee_id', $fees->pluck('id'))->get(['fee_id', 'name', 'amount'])->groupBy('fee_id')
            : collect();

        $parts = [];
        $rows = [];
        foreach ($fees as $f) {
            $amount = (float) $f->amount;
            $paid = min((float) $f->amount_paid, $amount > 0 ? $amount : (float) $f->amount_paid);
            $lines = ($items[$f->id] ?? collect())->filter(fn ($i) => (float) $i->amount > 0);
            $lineSum = (float) $lines->sum('amount');
            // No lines (or lines that do not add up to the fee): the fee is one line.
            if ($lines->isEmpty() || abs($lineSum - $amount) > 0.01) {
                $lines = collect([(object) ['name' => trim((string) ($f->description ?: $f->fee_type ?: 'Fees')), 'amount' => $amount]]);
                $lineSum = $amount;
            }
            $key = $f->student_id;
            $rows[$key] ??= ['student' => trim("{$f->first_name} {$f->last_name}"), 'admission_number' => $f->admission_number, 'class' => $f->class_name, 'billed' => [], 'paid' => []];
            foreach ($lines as $line) {
                foreach (self::splitLine((string) $line->name, (float) $line->amount, $rules) as $part => $billed) {
                    $parts[$part] = true;
                    $share = $lineSum > 0 ? $billed / $lineSum : 0;
                    $rows[$key]['billed'][$part] = round(($rows[$key]['billed'][$part] ?? 0) + $billed, 2);
                    $rows[$key]['paid'][$part] = round(($rows[$key]['paid'][$part] ?? 0) + $paid * $share, 2);
                }
            }
        }

        $parts = array_keys($parts);
        $totals = ['billed' => [], 'paid' => [], 'owing' => []];
        foreach ($rows as &$r) {
            $r['owing'] = [];
            foreach ($parts as $p) {
                $b = $r['billed'][$p] ?? 0;
                $pd = $r['paid'][$p] ?? 0;
                $r['owing'][$p] = round(max(0, $b - $pd), 2);
                $totals['billed'][$p] = round(($totals['billed'][$p] ?? 0) + $b, 2);
                $totals['paid'][$p] = round(($totals['paid'][$p] ?? 0) + $pd, 2);
                $totals['owing'][$p] = round(($totals['owing'][$p] ?? 0) + $r['owing'][$p], 2);
            }
        }
        unset($r);

        return ['parts' => $parts, 'rows' => array_values($rows), 'totals' => $totals];
    }

    /** The report as a spreadsheet (CSV, opens in Excel): Billed, Paid and Owing columns for each part. */
    public static function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₦ and accents correctly
        $head = ['Student', 'Admission No.', 'Class'];
        foreach ($report['parts'] as $p) {
            array_push($head, "{$p} billed", "{$p} paid", "{$p} owing");
        }
        array_push($head, 'Total billed', 'Total paid', 'Total owing');
        fputcsv($out, $head);
        foreach ($report['rows'] as $r) {
            $line = [$r['student'], $r['admission_number'], $r['class']];
            foreach ($report['parts'] as $p) {
                array_push($line, $r['billed'][$p] ?? 0, $r['paid'][$p] ?? 0, $r['owing'][$p] ?? 0);
            }
            array_push($line, array_sum($r['billed']), round(array_sum($r['paid']), 2), round(array_sum($r['owing']), 2));
            fputcsv($out, $line);
        }
        $t = ['TOTAL', '', ''];
        foreach ($report['parts'] as $p) {
            array_push($t, $report['totals']['billed'][$p] ?? 0, $report['totals']['paid'][$p] ?? 0, $report['totals']['owing'][$p] ?? 0);
        }
        array_push($t, array_sum($report['totals']['billed']), round(array_sum($report['totals']['paid']), 2), round(array_sum($report['totals']['owing']), 2));
        fputcsv($out, $t);
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
