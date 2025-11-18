<?php

namespace App\Http\Controllers;

use App\Modules\Financial\Models\Fee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class FeeController extends Controller
{
    /**
     * List fees
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Fee::with(['student', 'class']);

            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }

            if ($request->has('class_id')) {
                $query->where('class_id', $request->class_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('fee_type')) {
                $query->where('fee_type', $request->fee_type);
            }

            $fees = $query->orderBy('due_date', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json($fees);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 15,
                'total' => 0
            ]);
        }
    }

    /**
     * Get fee details
     */
    public function show($id): JsonResponse
    {
        $fee = Fee::with(['student', 'class', 'payments'])->find($id);

        if (!$fee) {
            return response()->json(['error' => 'Fee not found'], 404);
        }

        return response()->json([
            'fee' => $fee,
            'stats' => $fee->getStats()
        ]);
    }

    /**
     * Create fee
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'fee_type' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
            'due_date' => 'required|date|after:today',
            'description' => 'nullable|string',
            'class_id' => 'nullable|exists:classes,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'term_id' => 'nullable|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $fee = Fee::create([
            'school_id' => $request->school_id ?? 1,
            'student_id' => $request->student_id,
            'class_id' => $request->class_id,
            'fee_type' => $request->fee_type,
            'amount' => $request->amount,
            'due_date' => $request->due_date,
            'description' => $request->description,
            'academic_year_id' => $request->academic_year_id,
            'term_id' => $request->term_id,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Fee created successfully',
            'fee' => $fee
        ], 201);
    }

    /**
     * Update fee
     */
    public function update(Request $request, $id): JsonResponse
    {
        $fee = Fee::find($id);

        if (!$fee) {
            return response()->json(['error' => 'Fee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'sometimes|numeric|min:0',
            'due_date' => 'sometimes|date',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:pending,paid,overdue,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $fee->update($request->only(['amount', 'due_date', 'description', 'status']));

        return response()->json([
            'message' => 'Fee updated successfully',
            'fee' => $fee->fresh()
        ]);
    }

    /**
     * Delete fee
     */
    public function destroy($id): JsonResponse
    {
        $fee = Fee::find($id);

        if (!$fee) {
            return response()->json(['error' => 'Fee not found'], 404);
        }

        if ($fee->payments()->exists()) {
            return response()->json([
                'error' => 'Cannot delete fee',
                'message' => 'Fee has associated payments. Please remove them first.'
            ], 422);
        }

        $fee->delete();

        return response()->json([
            'message' => 'Fee deleted successfully'
        ]);
    }

    /**
     * Pay fee
     */
    public function pay(Request $request, $id): JsonResponse
    {
        $fee = Fee::find($id);

        if (!$fee) {
            return response()->json(['error' => 'Fee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0|max:' . $fee->getRemainingAmount(),
            'payment_method' => 'required|in:cash,bank_transfer,card,online',
            'payment_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $payment = \App\Modules\Financial\Models\Payment::create([
            'school_id' => $fee->school_id,
            'student_id' => $fee->student_id,
            'fee_id' => $fee->id,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'payment_reference' => $request->payment_reference,
            'payment_date' => now(),
            'status' => 'successful',
            'notes' => $request->notes,
        ]);

        // Update fee status if fully paid
        if ($fee->getRemainingAmount() <= 0) {
            $fee->update(['status' => 'paid']);
        }

        return response()->json([
            'message' => 'Payment processed successfully',
            'payment' => $payment,
            'fee' => $fee->fresh()
        ], 201);
    }

    /**
     * Get student fees
     */
    public function getStudentFees($studentId): JsonResponse
    {
        $fees = Fee::where('student_id', $studentId)
            ->with(['class'])
            ->orderBy('due_date', 'desc')
            ->get();

        return response()->json([
            'student_id' => $studentId,
            'fees' => $fees
        ]);
    }

    /**
     * Get fee structure
     */
    public function getFeeStructure(Request $request): JsonResponse
    {
        $query = Fee::select('fee_type', 'class_id')
            ->selectRaw('SUM(amount) as total_amount')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('fee_type', 'class_id');

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        $structure = $query->get();

        return response()->json(['fee_structure' => $structure]);
    }

    /**
     * Create fee structure
     */
    public function createFeeStructure(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fee_type' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
            'class_ids' => 'required|array',
            'class_ids.*' => 'exists:classes,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'term_id' => 'nullable|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $fees = [];
        foreach ($request->class_ids as $classId) {
            $students = \App\Models\Student::where('class_id', $classId)->pluck('id');
            
            foreach ($students as $studentId) {
                $fees[] = Fee::create([
                    'school_id' => $request->school_id ?? 1,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'fee_type' => $request->fee_type,
                    'amount' => $request->amount,
                    'due_date' => $request->due_date ?? now()->addMonth(),
                    'academic_year_id' => $request->academic_year_id,
                    'term_id' => $request->term_id,
                    'status' => 'pending',
                ]);
            }
        }

        return response()->json([
            'message' => 'Fee structure created successfully',
            'fees_created' => count($fees)
        ], 201);
    }

    /**
     * Update fee structure
     */
    public function updateFeeStructure(Request $request, $id): JsonResponse
    {
        // Similar to update, but for bulk fee structure updates
        return $this->update($request, $id);
    }
}
