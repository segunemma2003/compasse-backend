<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
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
                foreach (DB::table('payments')->whereIn('id', $ids)->get() as $p) {
                    DB::table('payments')->where('id', $p->id)->delete();
                    AuditLog::record('payment', $p->id, 'deleted', "Payment of {$p->amount} deleted (bulk). Reason: {$reason}", (array) $p);
                    $count++;
                }
            } elseif ($v['kind'] === 'invoices') {
                foreach (DB::table('invoices')->whereIn('id', $ids)->get() as $inv) {
                    DB::table('invoices')->where('id', $inv->id)->delete();
                    AuditLog::record('invoice', $inv->id, 'deleted', "Invoice #{$inv->id} deleted (bulk). Reason: {$reason}", (array) $inv);
                    $count++;
                }
            } else {
                foreach (DB::table('fees')->whereIn('id', $ids)->get() as $fee) {
                    if (Schema::hasTable('payments')) {
                        DB::table('payments')->where('fee_id', $fee->id)->delete();
                    }
                    if (Schema::hasTable('fee_items')) {
                        DB::table('fee_items')->where('fee_id', $fee->id)->delete();
                    }
                    if (Schema::hasColumn('invoices', 'fee_id')) {
                        DB::table('invoices')->where('fee_id', $fee->id)->delete();
                    }
                    DB::table('fees')->where('id', $fee->id)->delete();
                    AuditLog::record('fee', $fee->id, 'deleted', "Fee record #{$fee->id} ({$fee->amount}) deleted with its invoice and payments (bulk). Reason: {$reason}", (array) $fee);
                    $count++;
                }
            }

            return $count;
        });

        return response()->json(['message' => "{$deleted} deleted", 'deleted' => $deleted]);
    }
}
