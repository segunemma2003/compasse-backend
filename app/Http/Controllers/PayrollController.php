<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('payrolls');

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $payrolls = $query->orderBy('pay_period', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($payrolls);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:teachers,id',
            'pay_period' => 'required|date',
            'basic_salary' => 'required|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $netSalary = $request->basic_salary 
            + ($request->allowances ?? 0) 
            - ($request->deductions ?? 0);

        $payrollId = DB::table('payrolls')->insertGetId([
            'school_id' => $request->school_id ?? 1,
            'employee_id' => $request->employee_id,
            'pay_period' => $request->pay_period,
            'basic_salary' => $request->basic_salary,
            'allowances' => $request->allowances ?? 0,
            'deductions' => $request->deductions ?? 0,
            'net_salary' => $netSalary,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payroll = DB::table('payrolls')->find($payrollId);

        return response()->json([
            'message' => 'Payroll created successfully',
            'payroll' => $payroll
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id): JsonResponse
    {
        $payroll = DB::table('payrolls')->find($id);

        if (!$payroll) {
            return response()->json(['error' => 'Payroll not found'], 404);
        }

        return response()->json(['payroll' => $payroll]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $payroll = DB::table('payrolls')->find($id);

        if (!$payroll) {
            return response()->json(['error' => 'Payroll not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'basic_salary' => 'sometimes|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:pending,paid,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $updateData = $request->only(['basic_salary', 'allowances', 'deductions', 'status']);
        
        if (isset($updateData['basic_salary']) || isset($updateData['allowances']) || isset($updateData['deductions'])) {
            $basicSalary = $updateData['basic_salary'] ?? $payroll->basic_salary;
            $allowances = $updateData['allowances'] ?? $payroll->allowances ?? 0;
            $deductions = $updateData['deductions'] ?? $payroll->deductions ?? 0;
            $updateData['net_salary'] = $basicSalary + $allowances - $deductions;
        }

        DB::table('payrolls')
            ->where('id', $id)
            ->update(array_merge($updateData, ['updated_at' => now()]));

        $payroll = DB::table('payrolls')->find($id);

        return response()->json([
            'message' => 'Payroll updated successfully',
            'payroll' => $payroll
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id): JsonResponse
    {
        $payroll = DB::table('payrolls')->find($id);

        if (!$payroll) {
            return response()->json(['error' => 'Payroll not found'], 404);
        }

        DB::table('payrolls')->where('id', $id)->delete();

        return response()->json([
            'message' => 'Payroll deleted successfully'
        ]);
    }
}
