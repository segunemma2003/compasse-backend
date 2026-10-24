<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every fee a student is billed (assigned one by one, by class, imported, or recurring) has a
 * matching invoice, kept in step with the fee's lines, amount paid and status — so assigned fees
 * always show on the Invoices tab. Works at the database level so both Fee models are covered.
 */
class FeeInvoices
{
    /** @var array<int,true> fee ids changed during this request */
    private static array $pending = [];
    private static bool $hooked = false;

    public static function ready(): bool
    {
        return Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'fee_id');
    }

    /** Sync once, after the request (fee lines are often written just after the fee itself). */
    public static function defer(int $feeId): void
    {
        self::$pending[$feeId] = true;
        if (! self::$hooked) {
            self::$hooked = true;
            app()->terminating(fn () => self::flush());
        }
    }

    public static function flush(): void
    {
        $ids = array_keys(self::$pending);
        self::$pending = [];
        foreach ($ids as $id) {
            try {
                self::sync($id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Fee invoice sync failed for fee {$id}: " . $e->getMessage());
            }
        }
    }

    public static function sync(int $feeId): ?int
    {
        if (! self::ready()) {
            return null;
        }
        $fee = DB::table('fees')->where('id', $feeId)->first();
        $invoice = DB::table('invoices')->where('fee_id', $feeId)->first();
        if (! $fee || (Schema::hasColumn('fees', 'deleted_at') && $fee->deleted_at)) {
            if ($invoice) {
                DB::table('invoice_items')->where('invoice_id', $invoice->id)->delete();
                DB::table('invoices')->where('id', $invoice->id)->delete();
            }

            return null;
        }
        $amount = (float) $fee->amount;
        $paid = (float) ($fee->amount_paid ?? 0);
        $balance = max(0, round($amount - $paid, 2));
        $due = $fee->due_date ?: now()->addDays(30)->toDateString();
        $status = match (true) {
            ($fee->status ?? '') === 'cancelled' || ($invoice->status ?? '') === 'cancelled' => 'cancelled',
            $amount > 0 && $balance <= 0 => 'paid',
            $paid > 0 => 'partial',
            \Carbon\Carbon::parse($due)->endOfDay()->isPast() => 'overdue',
            default => 'sent',
        };
        $lines = Schema::hasTable('fee_items') ? DB::table('fee_items')->where('fee_id', $feeId)->get(['name', 'amount']) : collect();
        if ($lines->isEmpty()) {
            $lines = collect([(object) ['name' => $fee->fee_type ?: 'School fees', 'amount' => $amount]]);
        }
        $charges = $lines->filter(fn ($l) => (float) $l->amount >= 0)->sum(fn ($l) => (float) $l->amount);
        $discount = abs($lines->filter(fn ($l) => (float) $l->amount < 0)->sum(fn ($l) => (float) $l->amount));
        $data = [
            'school_id' => $fee->school_id, 'student_id' => $fee->student_id, 'due_date' => $due,
            'subtotal' => $charges, 'discount_amount' => $discount, 'tax_amount' => 0, 'total_amount' => $amount,
            'amount_paid' => $paid, 'balance' => $balance, 'status' => $status,
            'notes' => $fee->description, 'paid_at' => $status === 'paid' ? ($invoice->paid_at ?? now()) : null, 'updated_at' => now(),
        ];
        if ($invoice) {
            DB::table('invoices')->where('id', $invoice->id)->update($data);
            $id = $invoice->id;
            DB::table('invoice_items')->where('invoice_id', $id)->delete();
        } else {
            $id = DB::table('invoices')->insertGetId($data + [
                'fee_id' => $feeId, 'invoice_number' => \App\Models\Invoice::generateInvoiceNumber((int) $fee->school_id),
                'invoice_date' => substr((string) ($fee->created_at ?? now()), 0, 10), 'created_at' => now(),
            ]);
        }
        foreach ($lines as $l) {
            DB::table('invoice_items')->insert(['invoice_id' => $id, 'description' => $l->name, 'quantity' => 1, 'unit_price' => (float) $l->amount,
                'total_price' => (float) $l->amount, 'created_at' => now(), 'updated_at' => now()]);
        }

        return (int) $id;
    }

    /**
     * Bill each student a fee with these lines (name, amount — negative for a discount); each gets
     * its invoice. Returns the new fee ids.
     *
     * @param  list<int>  $studentIds
     * @param  list<array{name:string,amount:float}>  $lines
     */
    public static function bill(array $studentIds, array $lines, string $title, string $dueDate, ?string $notes = null, ?int $termId = null, ?int $yearId = null): array
    {
        $yearId ??= DB::table('academic_years')->where('is_current', true)->value('id');
        $termId ??= DB::table('terms')->where('is_current', true)->value('id');
        $total = round(array_sum(array_column($lines, 'amount')), 2);
        $schoolId = \App\Models\School::first()?->id ?? 1;
        $made = [];
        DB::transaction(function () use ($studentIds, $lines, $title, $dueDate, $notes, $termId, $yearId, $total, $schoolId, &$made) {
            foreach ($studentIds as $sid) {
                $fee = \App\Modules\Financial\Models\Fee::create([
                    'school_id' => $schoolId, 'student_id' => $sid, 'class_id' => \App\Models\Student::where('id', $sid)->value('class_id'),
                    'fee_type' => $title, 'amount' => $total, 'amount_paid' => 0, 'balance' => $total, 'due_date' => $dueDate,
                    'description' => $notes, 'academic_year_id' => $yearId, 'term_id' => $termId, 'status' => 'pending', 'is_customized' => count($lines) > 1,
                ]);
                foreach ($lines as $l) {
                    $fee->items()->create(['name' => $l['name'], 'amount' => $l['amount']]);
                }
                $made[] = (int) $fee->id;
            }
        });
        foreach ($made as $fid) {
            self::sync($fid);
        }

        return $made;
    }

    /** Active students in these classes (and arms, if given). */
    public static function studentsIn(array $classIds, array $armIds = []): array
    {
        return \App\Models\Student::whereIn('class_id', $classIds)
            ->when($armIds && Schema::hasColumn('students', 'arm_id'), fn ($q) => $q->whereIn('arm_id', $armIds))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Invoices for fees that don't have one yet (after this feature arrives). */
    public static function backfill(): int
    {
        if (! self::ready() || ! Schema::hasTable('fees')) {
            return 0;
        }
        $n = 0;
        DB::table('fees')->whereNotIn('id', DB::table('invoices')->whereNotNull('fee_id')->select('fee_id'))
            ->when(Schema::hasColumn('fees', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('id')->chunkById(200, function ($fees) use (&$n) {
                foreach ($fees as $f) {
                    self::sync((int) $f->id) && $n++;
                }
            });

        return $n;
    }
}
