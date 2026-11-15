<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
use App\Support\RecycleBin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance → select rows → "Delete selected": removes fee records, invoices or payments
 * entered by mistake, in one go. School administrator (or the Compasse super admin) only,
 * with a reason; every deletion is written to the audit log.
 *
 * Deleting a fee also removes its invoice and any payments against it (they belong to it).
 */
class FinanceCleanupController extends Controller
{
    /** POST /financial/bulk-delete {kind: fees|invoices|payments, ids: [], reason} */
    public function destroy(Request $request): JsonResponse
    {
        $role = $request->user()?->role;
        if (! in_array($role, ['school_admin', 'super_admin'], true)) {
            return response()->json(['error' => 'Only the school administrator can delete finance records.'], 403);
        }
        $v = $request->validate([
            'kind' => 'required|in:fees,invoices,payments',
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'reason' => 'required|string|min:5|max:500',
        ]);
        $ids = array_values(array_unique($v['ids']));
        $reason = trim($v['reason']);

        $deleted = DB::transaction(function () use ($v, $ids, $reason) {
            $count = 0;
            if ($v['kind'] === 'payments') {
                $fees = [];
                foreach (DB::table('payments')->whereIn('id', $ids)->get() as $p) {
                    RecycleBin::captureRow('payments', $p, 'payment');
                    DB::table('payments')->where('id', $p->id)->delete();
                    AuditLog::record('payment', $p->id, 'deleted', "Payment of {$p->amount} deleted (bulk). Reason: {$reason}", (array) $p);
                    if (! empty($p->fee_id)) {
                        $fees[(int) $p->fee_id] = true;
                    }
                    $count++;
                }
                // The fee no longer counts the deleted payment(s): it showed as paid before.
                foreach (array_keys($fees) as $feeId) {
                    self::recomputeFee($feeId);
                }
            } else {
                // An invoice is the bill for a fee: deleting either removes the whole bill
                // (fee, its items, invoice and payments), so nothing is left showing.
                $feeIds = $v['kind'] === 'fees'
                    ? $ids
                    : DB::table('invoices')->whereIn('id', $ids)->whereNotNull('fee_id')->pluck('fee_id')->map(fn ($x) => (int) $x)->all();
                if ($v['kind'] === 'invoices') {
                    foreach (DB::table('invoices')->whereIn('id', $ids)->whereNull('fee_id')->get() as $inv) {
                        RecycleBin::captureRow('invoices', $inv, 'invoice');
                        DB::table('invoices')->where('id', $inv->id)->delete();
                        AuditLog::record('invoice', $inv->id, 'deleted', "Invoice #{$inv->id} deleted (bulk). Reason: {$reason}", (array) $inv);
                        $count++;
                    }
                }
                foreach (DB::table('fees')->whereIn('id', $feeIds)->get() as $fee) {
                    RecycleBin::captureRow('fees', $fee, 'fee');
                    foreach (['payments' => 'payment', 'fee_items' => 'fee item', 'invoices' => 'invoice'] as $table => $type) {
                        if (Schema::hasTable($table) && Schema::hasColumn($table, 'fee_id')) {
                            foreach (DB::table($table)->where('fee_id', $fee->id)->get() as $row) {
                                RecycleBin::captureRow($table, $row, $type);
                            }
                            DB::table($table)->where('fee_id', $fee->id)->delete();
                        }
                    }
                    DB::table('fees')->where('id', $fee->id)->delete();
                    AuditLog::record('fee', $fee->id, 'deleted', "Fee record #{$fee->id} ({$fee->amount}) deleted with its invoice and payments (bulk). Reason: {$reason}", (array) $fee);
                    $count++;
                }
            }

            return $count;
        });
        \App\Http\Controllers\DashboardController::bustCache();

        return response()->json(['message' => "{$deleted} deleted", 'deleted' => $deleted]);
    }

    /** Amount paid, balance and status from the fee's confirmed payments; its invoice follows. */
    public static function recomputeFee(int $feeId): void
    {
        $fee = DB::table('fees')->where('id', $feeId)->first();
        if (! $fee) {
            return;
        }
        $paid = (float) DB::table('payments')->where('fee_id', $feeId)->where('status', 'confirmed')->sum('amount');
        $balance = max(0, round((float) $fee->amount - $paid, 2));
        DB::table('fees')->where('id', $feeId)->update([
            'amount_paid' => $paid, 'balance' => $balance,
            'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'pending'), 'updated_at' => now(),
        ]);
        if (class_exists(\App\Support\FeeInvoices::class) && \App\Support\FeeInvoices::ready()) {
            \App\Support\FeeInvoices::sync($feeId);
        }
    }
}
