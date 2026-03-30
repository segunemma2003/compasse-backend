<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PayrollController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Payroll::where('school_id', $this->school($request)?->id ?? 0)
            ->with(['staff', 'processedBy', 'academicYear']);

        if ($request->filled('staff_id'))       $query->where('staff_id', $request->staff_id);
        if ($request->filled('month'))          $query->where('month', $request->month);
        if ($request->filled('year'))           $query->where('year', $request->year);
        if ($request->filled('status'))         $query->where('status', $request->status);
        if ($request->filled('academic_year_id')) $query->where('academic_year_id', $request->academic_year_id);

        return response()->json(
            $query->orderByDesc('year')->orderByDesc('month')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->school($request);
        if (!$school) {
            return response()->json(['error' => 'School context not found'], 400);
        }

        $data = $request->validate([
            'staff_id'        => 'required|exists:users,id',
            'academic_year_id'=> 'nullable|exists:academic_years,id',
            'month'           => 'required|integer|between:1,12',
            'year'            => 'required|integer|min:2000|max:2100',
            'basic_salary'    => 'required|numeric|min:0',
            'allowances'      => 'nullable|numeric|min:0',
            'deductions'      => 'nullable|numeric|min:0',
            'payment_date'    => 'nullable|date',
            'payment_method'  => 'nullable|in:bank_transfer,cash,cheque',
            'notes'           => 'nullable|string',
        ]);

        // Prevent duplicate payroll for same staff/month/year
        $exists = Payroll::where('school_id', $school->id)
            ->where('staff_id', $data['staff_id'])
            ->where('month', $data['month'])
            ->where('year', $data['year'])
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'Payroll already exists for this staff member for the given month/year.',
            ], 422);
        }

        $data['net_salary']   = $data['basic_salary'] + ($data['allowances'] ?? 0) - ($data['deductions'] ?? 0);
        $data['school_id']    = $school->id;
        $data['status']       = 'pending';
        $data['processed_by'] = auth()->id();

        $payroll = Payroll::create($data);

        return response()->json([
            'message' => 'Payroll created successfully',
            'payroll' => $payroll->load(['staff', 'processedBy']),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $payroll = Payroll::with(['staff', 'processedBy', 'academicYear', 'school'])->findOrFail($id);
        return response()->json(['payroll' => $payroll]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $payroll = Payroll::findOrFail($id);

        $data = $request->validate([
            'basic_salary'   => 'sometimes|numeric|min:0',
            'allowances'     => 'nullable|numeric|min:0',
            'deductions'     => 'nullable|numeric|min:0',
            'payment_date'   => 'nullable|date',
            'payment_method' => 'nullable|in:bank_transfer,cash,cheque',
            'status'         => 'sometimes|in:pending,paid,cancelled',
            'notes'          => 'nullable|string',
        ]);

        // Recalculate net salary if any component changes
        if (isset($data['basic_salary']) || isset($data['allowances']) || isset($data['deductions'])) {
            $data['net_salary'] = ($data['basic_salary']  ?? (float) $payroll->basic_salary)
                                + ($data['allowances']    ?? (float) $payroll->allowances)
                                - ($data['deductions']    ?? (float) $payroll->deductions);
        }

        $payroll->update($data);

        return response()->json([
            'message' => 'Payroll updated successfully',
            'payroll' => $payroll->load(['staff', 'processedBy']),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $payroll = Payroll::findOrFail($id);

        if ($payroll->status === 'paid') {
            return response()->json(['error' => 'Cannot delete a paid payroll record.'], 422);
        }

        $payroll->delete();
        return response()->json(['message' => 'Payroll deleted successfully']);
    }

    /**
     * Generate a pay stub for a single payroll record.
     */
    public function payStub(string $id): JsonResponse
    {
        $payroll = Payroll::with(['staff', 'processedBy', 'school', 'academicYear'])->findOrFail($id);

        return response()->json([
            'pay_stub' => [
                'employee'       => $payroll->staff?->name,
                'school'         => $payroll->school?->name,
                'period'         => sprintf('%04d-%02d', $payroll->year, $payroll->month),
                'basic_salary'   => $payroll->basic_salary,
                'allowances'     => $payroll->allowances,
                'gross_salary'   => (float) $payroll->basic_salary + (float) $payroll->allowances,
                'deductions'     => $payroll->deductions,
                'net_salary'     => $payroll->net_salary,
                'payment_date'   => $payroll->payment_date,
                'payment_method' => $payroll->payment_method,
                'status'         => $payroll->status,
                'processed_by'   => $payroll->processedBy?->name,
            ],
        ]);
    }
}
