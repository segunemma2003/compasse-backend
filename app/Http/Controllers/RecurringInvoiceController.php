<?php

namespace App\Http\Controllers;

use App\Support\RecurringInvoices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Finance → Invoices → Recurring: plans that bill and email invoices automatically on a set date. */
class RecurringInvoiceController extends Controller
{
    private function rules(): array
    {
        return [
            'name' => 'required|string|max:100', 'lines' => 'required|array|min:1', 'lines.*.name' => 'required|string|max:100', 'lines.*.amount' => 'required|numeric',
            'class_ids' => 'nullable|array', 'class_ids.*' => 'integer', 'arm_ids' => 'nullable|array', 'arm_ids.*' => 'integer',
            'student_ids' => 'nullable|array', 'student_ids.*' => 'integer',
            'frequency' => 'required|in:monthly,weekly,termly', 'next_run_on' => 'required|date', 'due_in_days' => 'nullable|integer|min:0|max:90',
            'send_email' => 'nullable|boolean', 'active' => 'nullable|boolean', 'notes' => 'nullable|string|max:2000',
        ];
    }

    private function row(array $v): array
    {
        return [
            'name' => $v['name'], 'lines' => json_encode(array_values($v['lines'])),
            'class_ids' => json_encode(array_values($v['class_ids'] ?? [])), 'arm_ids' => json_encode(array_values($v['arm_ids'] ?? [])),
            'student_ids' => json_encode(array_values($v['student_ids'] ?? [])), 'frequency' => $v['frequency'], 'next_run_on' => $v['next_run_on'],
            'due_in_days' => $v['due_in_days'] ?? 7, 'send_email' => $v['send_email'] ?? true, 'active' => $v['active'] ?? true, 'notes' => $v['notes'] ?? null,
            'updated_at' => now(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $rows = DB::table('recurring_invoices')->orderBy('next_run_on')->get()->map(function ($r) {
            foreach (['lines', 'class_ids', 'arm_ids', 'student_ids'] as $k) {
                $r->$k = json_decode($r->$k ?? '[]', true) ?: [];
            }
            $r->total = array_sum(array_column($r->lines, 'amount'));

            return $r;
        });

        return response()->json(['plans' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $v = $request->validate($this->rules());
        if (empty($v['class_ids']) && empty($v['student_ids'])) {
            return response()->json(['error' => 'Choose the class(es) or students to bill.'], 422);
        }
        $id = DB::table('recurring_invoices')->insertGetId($this->row($v) + ['created_by' => $request->user()?->id, 'created_at' => now()]);
        \App\Support\AuditLog::record('recurring_invoice', $id, 'created', "Recurring invoice \"{$v['name']}\" ({$v['frequency']}, from {$v['next_run_on']})");

        return response()->json(['message' => 'Recurring invoice saved', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        DB::table('recurring_invoices')->where('id', $id)->update($this->row($request->validate($this->rules())));

        return response()->json(['message' => 'Saved']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        DB::table('recurring_invoices')->where('id', $id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /** POST /financial/recurring-invoices/{id}/run — bill now (the next date stays the same). */
    public function run(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $r = RecurringInvoices::runDue($id, true);

        return response()->json(['message' => "{$r['invoices']} invoice(s) created", 'count' => $r['invoices']]);
    }
}
