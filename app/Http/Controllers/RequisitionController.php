<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Notification;
use App\Models\Requisition;
use App\Models\School;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Imprest (a cash advance the staff member later retires with what they spent) and requisitions
 * (a request for items or a purchase). Any staff member can request; the admin / finance approve,
 * pay out (optionally recording it as an expense) and close. Every edit and delete is logged.
 */
class RequisitionController extends Controller
{
    private const LEADERSHIP = ['school_admin', 'principal', 'vice_principal', 'admin', 'super_admin'];
    private const NOT_STAFF = ['student', 'guardian', 'parent'];

    private function manages(Request $request): bool
    {
        $u = $request->user();

        return in_array($u->role, self::LEADERSHIP, true) || $this->roleCan($request, 'finance.manage');
    }

    /** GET /requisitions?status=&type=&mine= */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        if (in_array($u->role, self::NOT_STAFF, true)) {
            return $this->forbiddenResponse();
        }
        $manages = $this->manages($request);
        $q = Requisition::with(['requester:id,name,role', 'approver:id,name', 'disburser:id,name']);
        if (! $manages || $request->boolean('mine')) {
            $q->where('requested_by', $u->id);
        }
        foreach (['status', 'type'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }
        $rows = $q->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'disbursed' THEN 2 ELSE 3 END")->orderByDesc('id')
            ->paginate(min((int) $request->get('per_page', 50), 100));

        $all = $manages ? Requisition::query() : Requisition::where('requested_by', $u->id);
        $summary = [
            'pending' => (clone $all)->where('status', 'pending')->count(),
            'awaiting_payment' => (clone $all)->where('status', 'approved')->count(),
            'outstanding_imprest' => (float) (clone $all)->where('type', 'imprest')->where('status', 'disbursed')->sum('amount_approved'),
            'paid_this_month' => (float) (clone $all)->whereNotNull('disbursed_at')->where('disbursed_at', '>=', now()->startOfMonth())->sum('amount_approved'),
        ];

        return response()->json($rows->toArray() + ['summary' => $summary, 'can_manage' => $manages]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $r = Requisition::with(['requester:id,name,role', 'approver:id,name', 'disburser:id,name'])->findOrFail($id);
        if (! $this->manages($request) && (int) $r->requested_by !== (int) $request->user()->id) {
            return $this->forbiddenResponse();
        }

        return response()->json(['requisition' => $r, 'history' => AuditLog::for('requisition', $r->id), 'can_manage' => $this->manages($request)]);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'type' => "{$req}|in:requisition,imprest",
            'title' => "{$req}|string|max:200",
            'purpose' => 'nullable|string|max:5000',
            'department' => 'nullable|string|max:120',
            'items' => 'nullable|array|max:50',
            'items.*.description' => 'required|string|max:300',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'needed_by' => 'nullable|date',
        ];
    }

    private function withAmount(array $v): array
    {
        if (! empty($v['items'])) {
            $v['items'] = array_values(array_map(fn ($i) => [
                'description' => trim($i['description']), 'quantity' => (float) ($i['quantity'] ?? 1), 'unit_cost' => (float) ($i['unit_cost'] ?? 0),
            ], $v['items']));
            $sum = array_sum(array_map(fn ($i) => $i['quantity'] * $i['unit_cost'], $v['items']));
            if ($sum > 0) {
                $v['amount'] = round($sum, 2);
            }
        }

        return $v;
    }

    /** POST /requisitions */
    public function store(Request $request): JsonResponse
    {
        $u = $request->user();
        if (in_array($u->role, self::NOT_STAFF, true)) {
            return $this->forbiddenResponse();
        }
        $v = $this->withAmount($request->validate($this->rules(true)));
        if ((float) ($v['amount'] ?? 0) <= 0) {
            return response()->json(['message' => 'Enter the amount, or the items with their cost.'], 422);
        }
        $r = Requisition::create($v + ['status' => 'pending', 'requested_by' => $u->id]);
        $r->update(['number' => ($r->type === 'imprest' ? 'IMP-' : 'REQ-') . str_pad((string) $r->id, 5, '0', STR_PAD_LEFT)]);
        AuditLog::record('requisition', $r->id, 'created', "{$u->name} requested {$this->describe($r)}");

        // Tell whoever approves.
        foreach (User::whereIn('role', ['school_admin', 'principal', 'admin', 'accountant'])->where('id', '!=', $u->id)->pluck('id') as $id) {
            Notification::create(['user_id' => $id, 'type' => 'info', 'title' => ucfirst($r->type) . ' request from ' . $u->name,
                'message' => mb_substr($r->title . ' — ₦' . number_format((float) $r->amount, 2), 0, 250)]);
        }

        return response()->json(['message' => 'Request sent for approval', 'requisition' => $r], 201);
    }

    /** PUT /requisitions/{id} — the requester while it is pending; the admin / finance at any time (logged). */
    public function update(Request $request, int $id): JsonResponse
    {
        $r = Requisition::findOrFail($id);
        $own = (int) $r->requested_by === (int) $request->user()->id;
        if (! $this->manages($request) && ! ($own && $r->status === 'pending')) {
            return $this->forbiddenResponse('Only the admin or finance can change a request once it has been decided.');
        }
        $v = $this->withAmount($request->validate($this->rules(false) + [
            'amount_approved' => 'nullable|numeric|min:0', 'amount_spent' => 'nullable|numeric|min:0', 'amount_returned' => 'nullable|numeric|min:0',
            'decision_note' => 'nullable|string|max:2000', 'retirement_note' => 'nullable|string|max:5000',
        ]));
        if (! $this->manages($request)) {
            unset($v['amount_approved'], $v['amount_spent'], $v['amount_returned'], $v['decision_note']);
        }
        $before = $r->only(array_keys($v));
        $r->update($v);
        if ($changes = AuditLog::diff($before, $v)) {
            AuditLog::record('requisition', $r->id, 'updated', "Edited {$this->describe($r)}", $changes);
        }

        return response()->json(['message' => 'Saved', 'requisition' => $r]);
    }

    /** POST /requisitions/{id}/decide {decision: approve|reject, amount_approved?, note?} */
    public function decide(Request $request, int $id): JsonResponse
    {
        if (! $this->manages($request)) {
            return $this->forbiddenResponse('Only the admin or finance can approve requests.');
        }
        $v = $request->validate(['decision' => 'required|in:approve,reject', 'amount_approved' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:2000']);
        $r = Requisition::findOrFail($id);
        if (! in_array($r->status, ['pending', 'approved', 'rejected'], true)) {
            return response()->json(['message' => 'This request has already been paid out.'], 422);
        }
        $approve = $v['decision'] === 'approve';
        $r->update([
            'status' => $approve ? 'approved' : 'rejected',
            'amount_approved' => $approve ? ($v['amount_approved'] ?? $r->amount) : null,
            'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_note' => $v['note'] ?? null,
        ]);
        AuditLog::record('requisition', $r->id, $approve ? 'approved' : 'rejected',
            ($approve ? 'Approved ' : 'Rejected ') . $this->describe($r) . ($approve && (float) $r->amount_approved != (float) $r->amount ? ' for ₦' . number_format((float) $r->amount_approved, 2) : '') . (! empty($v['note']) ? " — {$v['note']}" : ''));
        Notification::create(['user_id' => $r->requested_by, 'type' => $approve ? 'success' : 'warning',
            'title' => ucfirst($r->type) . ($approve ? ' approved' : ' not approved'), 'message' => mb_substr($r->title . (! empty($v['note']) ? ' — ' . $v['note'] : ''), 0, 250)]);

        return response()->json(['message' => $approve ? 'Approved' : 'Rejected', 'requisition' => $r]);
    }

    /** POST /requisitions/{id}/disburse {method, record_expense?, date?} — money paid out (optionally booked as an expense). */
    public function disburse(Request $request, int $id): JsonResponse
    {
        if (! $this->manages($request)) {
            return $this->forbiddenResponse('Only the admin or finance can pay out requests.');
        }
        $v = $request->validate(['method' => 'nullable|in:cash,bank_transfer,cheque,card', 'record_expense' => 'nullable|boolean', 'date' => 'nullable|date', 'reference' => 'nullable|string|max:100']);
        $r = Requisition::findOrFail($id);
        if ($r->status !== 'approved') {
            return response()->json(['message' => 'Approve the request before paying it out.'], 422);
        }
        $expenseId = null;
        if ($request->boolean('record_expense', true)) {
            $school = School::first();
            $expenseId = Expense::create([
                'school_id' => $school?->id, 'description' => "{$r->number} · {$r->title}", 'amount' => $r->amount_approved,
                'category' => $r->type === 'imprest' ? 'Imprest' : 'Requisition', 'date' => $v['date'] ?? now()->toDateString(),
                'payment_method' => $v['method'] ?? 'cash', 'vendor' => $r->requester?->name, 'receipt_number' => $v['reference'] ?? $r->number,
                'approved_by' => $r->approved_by, 'recorded_by' => $request->user()->id, 'notes' => $r->purpose, 'status' => 'paid',
            ])->id;
        }
        $r->update(['status' => 'disbursed', 'disbursed_by' => $request->user()->id, 'disbursed_at' => $v['date'] ?? now(),
            'disbursement_method' => $v['method'] ?? 'cash', 'expense_id' => $expenseId]);
        AuditLog::record('requisition', $r->id, 'disbursed', 'Paid out ₦' . number_format((float) $r->amount_approved, 2) . " for {$this->describe($r)}" . ($expenseId ? ' (recorded as an expense)' : ''));
        Notification::create(['user_id' => $r->requested_by, 'type' => 'success', 'title' => ucfirst($r->type) . ' paid out',
            'message' => mb_substr($r->title . ' — ₦' . number_format((float) $r->amount_approved, 2) . ($r->type === 'imprest' ? '. Please retire it with your receipts.' : ''), 0, 250)]);

        return response()->json(['message' => 'Marked as paid out', 'requisition' => $r]);
    }

    /** POST /requisitions/{id}/retire {amount_spent, note?, items?} — close an imprest with what was spent; the rest comes back. */
    public function retire(Request $request, int $id): JsonResponse
    {
        $r = Requisition::findOrFail($id);
        if (! $this->manages($request) && (int) $r->requested_by !== (int) $request->user()->id) {
            return $this->forbiddenResponse();
        }
        if ($r->status !== 'disbursed') {
            return response()->json(['message' => 'Only money that has been paid out can be retired.'], 422);
        }
        $v = $request->validate([
            'amount_spent' => 'required|numeric|min:0', 'amount_returned' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:5000',
            'items' => 'nullable|array|max:50', 'items.*.description' => 'required|string|max:300', 'items.*.amount' => 'nullable|numeric|min:0',
        ]);
        $returned = $v['amount_returned'] ?? max(0, (float) $r->amount_approved - (float) $v['amount_spent']);
        $r->update(['status' => 'retired', 'amount_spent' => $v['amount_spent'], 'amount_returned' => $returned,
            'retirement_note' => $v['note'] ?? null, 'retirement_items' => $v['items'] ?? null, 'retired_at' => now()]);
        // The expense becomes what was actually spent.
        if ($r->expense_id && ($e = Expense::find($r->expense_id))) {
            $e->update(['amount' => $v['amount_spent'], 'notes' => trim(($e->notes ? $e->notes . "\n" : '') . 'Retired: spent ₦' . number_format((float) $v['amount_spent'], 2) . ', returned ₦' . number_format((float) $returned, 2))]);
        }
        AuditLog::record('requisition', $r->id, 'retired', $request->user()->name . " retired {$this->describe($r)}: spent ₦" . number_format((float) $v['amount_spent'], 2) . ', returned ₦' . number_format((float) $returned, 2));

        return response()->json(['message' => 'Retired', 'requisition' => $r]);
    }

    /** POST /requisitions/{id}/cancel — the requester withdraws a pending request. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $r = Requisition::findOrFail($id);
        if ((int) $r->requested_by !== (int) $request->user()->id && ! $this->manages($request)) {
            return $this->forbiddenResponse();
        }
        if ($r->status !== 'pending') {
            return response()->json(['message' => 'Only a pending request can be withdrawn.'], 422);
        }
        $r->update(['status' => 'cancelled']);
        AuditLog::record('requisition', $r->id, 'cancelled', $request->user()->name . " withdrew {$this->describe($r)}");

        return response()->json(['message' => 'Withdrawn']);
    }

    /** DELETE /requisitions/{id} — admin / finance only; logged and kept in the recycle bin. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->manages($request)) {
            return $this->forbiddenResponse('Only the admin or finance can delete requests.');
        }
        $r = Requisition::findOrFail($id);
        $reason = trim((string) $request->input('reason', ''));
        AuditLog::record('requisition', $r->id, 'deleted', "Deleted {$this->describe($r)} ({$r->status})" . ($reason ? " — {$reason}" : ''));
        $r->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** GET /requisitions-history — every action, newest first (admin / finance). */
    public function history(Request $request): JsonResponse
    {
        if (! $this->manages($request)) {
            return $this->forbiddenResponse();
        }

        return response()->json(['history' => AuditLog::for('requisition')]);
    }

    private function describe(Requisition $r): string
    {
        return ($r->number ?: ucfirst($r->type)) . ' "' . $r->title . '" (₦' . number_format((float) $r->amount, 2) . ')';
    }
}
