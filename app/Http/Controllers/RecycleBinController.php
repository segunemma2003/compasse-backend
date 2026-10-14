<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recycle bin for school leadership: everything deleted, newest first, with Restore.
 * - Payments, fees, parents, classes, subjects, events, exams, assignments, invoices: copies in recycle_bin.
 * - Students, teachers, staff: soft-deleted rows (and their login accounts).
 */
class RecycleBinController extends Controller
{
    private const SOFT = ['students' => 'student', 'teachers' => 'teacher', 'staff' => 'staff'];

    private function leadership(Request $request): ?JsonResponse
    {
        $u = $request->user();
        $ok = in_array($u->role, ['super_admin', 'school_admin', 'principal', 'vice_principal', 'admin'], true)
            || array_intersect(\App\Support\UserEffectiveRoles::forUser($u), ['school_admin', 'principal', 'vice_principal', 'admin']);

        return $ok ? null : response()->json(['message' => 'Only school leadership can use the recycle bin.'], 403);
    }

    /** GET /recycle-bin?type= */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->leadership($request)) {
            return $denied;
        }
        $type = $request->input('type');
        $items = collect();
        $names = DB::table('users')->pluck('name', 'id');

        if (Schema::hasTable('recycle_bin')) {
            $items = DB::table('recycle_bin')->whereNull('restored_at')
                ->when($type && ! in_array($type, self::SOFT, true), fn ($q) => $q->where('type', $type))
                ->when($type && in_array($type, self::SOFT, true), fn ($q) => $q->whereRaw('1 = 0'))
                ->orderByDesc('deleted_at')->limit(300)->get()
                ->map(fn ($r) => ['key' => "bin:{$r->id}", 'type' => $r->type, 'label' => $r->label, 'deleted_at' => $r->deleted_at, 'deleted_by' => $names[$r->deleted_by] ?? null]);
        }

        foreach (self::SOFT as $table => $t) {
            if (($type && $type !== $t) || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            $cols = array_values(array_intersect(['id', 'first_name', 'last_name', 'admission_number', 'employee_id', 'deleted_at'], Schema::getColumnListing($table)));
            $rows = DB::table($table)->whereNotNull('deleted_at')->orderByDesc('deleted_at')->limit(200)->get($cols);
            foreach ($rows as $r) {
                $items->push(['key' => "{$t}:{$r->id}", 'type' => $t,
                    'label' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) . (($r->admission_number ?? $r->employee_id ?? null) ? ' · ' . ($r->admission_number ?? $r->employee_id) : ''),
                    'deleted_at' => $r->deleted_at, 'deleted_by' => null]);
            }
        }

        return response()->json(['items' => $items->sortByDesc('deleted_at')->values()]);
    }

    /** POST /recycle-bin/restore {key} */
    public function restore(Request $request): JsonResponse
    {
        if ($denied = $this->leadership($request)) {
            return $denied;
        }
        $key = (string) $request->input('key');
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, null);
        $id = (int) $id;

        if ($kind === 'bin') {
            $entry = DB::table('recycle_bin')->where('id', $id)->whereNull('restored_at')->first();
            if (! $entry) {
                return response()->json(['message' => 'Already restored or not found.'], 404);
            }
            $data = json_decode($entry->data, true);
            if (DB::table($entry->table_name)->where('id', $entry->record_id)->exists()) {
                return response()->json(['message' => 'A record with the same id already exists.'], 422);
            }
            DB::transaction(function () use ($entry, $data) {
                $cols = Schema::getColumnListing($entry->table_name);
                DB::table($entry->table_name)->insert(array_intersect_key($data, array_flip($cols)));
                // A restored payment counts towards its fee again.
                if ($entry->table_name === 'payments' && ! empty($data['fee_id']) && ($data['status'] ?? '') === 'confirmed') {
                    $this->applyToFee((int) $data['fee_id'], (float) $data['amount']);
                }
                DB::table('recycle_bin')->where('id', $entry->id)->update(['restored_at' => now()]);
            });

            return response()->json(['message' => ucfirst($entry->type) . ' restored.']);
        }

        $table = array_search($kind, self::SOFT, true);
        if (! $table) {
            return response()->json(['message' => 'Unknown item.'], 422);
        }
        $row = DB::table($table)->where('id', $id)->first(['id', 'user_id']);
        if (! $row) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        DB::table($table)->where('id', $id)->update(['deleted_at' => null]);
        if ($row->user_id) {
            User::withTrashed()->whereKey($row->user_id)->restore();
        }

        return response()->json(['message' => ucfirst($kind) . ' restored.']);
    }

    /** DELETE /recycle-bin {key} — remove from the bin for good (copies only). */
    public function purge(Request $request): JsonResponse
    {
        if ($denied = $this->leadership($request)) {
            return $denied;
        }
        [$kind, $id] = array_pad(explode(':', (string) $request->input('key'), 2), 2, null);
        if ($kind !== 'bin') {
            return response()->json(['message' => 'Students, teachers and staff are removed for good from their own pages.'], 422);
        }
        DB::table('recycle_bin')->where('id', (int) $id)->delete();

        return response()->json(['message' => 'Deleted for good.']);
    }

    private function applyToFee(int $feeId, float $amount): void
    {
        $fee = DB::table('fees')->where('id', $feeId)->first();
        if (! $fee) {
            return;
        }
        $paid = max(0, round((float) $fee->amount_paid + $amount, 2));
        $balance = max(0, round((float) $fee->amount - $paid, 2));
        DB::table('fees')->where('id', $feeId)->update([
            'amount_paid' => $paid, 'balance' => $balance,
            'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'pending'), 'updated_at' => now(),
        ]);
    }
}
