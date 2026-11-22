<?php

namespace App\Http\Controllers;

use App\Support\FeeSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Fee split: the parts of a fee line (e.g. School Fees = Tuition + Lesson fees + PTA) for the school's books. */
class FeeSplitController extends Controller
{
    /** GET /financial/fee-splits — the school's rules and the fee line names in use. */
    public function index(): JsonResponse
    {
        $names = \Illuminate\Support\Facades\Schema::hasTable('fee_items')
            ? \Illuminate\Support\Facades\DB::table('fee_items')->select('name')->distinct()->orderBy('name')->limit(200)->pluck('name')
            : collect();

        return response()->json(['rules' => FeeSplit::rules(), 'line_names' => $names]);
    }

    /** PUT /financial/fee-splits {rules: [...]} */
    public function update(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $request->validate(['rules' => 'present|array', 'rules.*.item' => 'required|string|max:120', 'rules.*.rest' => 'nullable|string|max:60',
            'rules.*.parts' => 'required|array|min:1', 'rules.*.parts.*.name' => 'required|string|max:60',
            'rules.*.parts.*.amount' => 'nullable|numeric|min:0', 'rules.*.parts.*.percent' => 'nullable|numeric|min:0|max:100']);
        $rules = FeeSplit::save($request->input('rules', []));
        \App\Support\AuditLog::record('fee_splits', 0, 'updated', 'Fee split updated: ' . collect($rules)->pluck('item')->implode(', '));

        return response()->json(['rules' => $rules]);
    }

    /** GET /financial/reports/fee-split?term_id&academic_year_id&class_id&format=csv */
    public function report(Request $request): JsonResponse|Response
    {
        $report = FeeSplit::report($request->integer('term_id') ?: null, $request->integer('academic_year_id') ?: null, $request->integer('class_id') ?: null);
        if ($request->query('format') === 'csv') {
            return response(FeeSplit::csv($report), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="fee-split-' . now()->format('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json($report);
    }
}
