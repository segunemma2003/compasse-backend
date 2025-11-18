<?php

namespace App\Http\Controllers;

use App\Modules\Financial\Models\Payment;
use App\Modules\Financial\Models\Fee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    /**
     * List payments
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Payment::with(['student', 'fee', 'guardian']);

            if ($request->has('student_id')) {
                $query->where('student_id', $request->student_id);
            }

            if ($request->has('fee_id')) {
                $query->where('fee_id', $request->fee_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            $payments = $query->orderBy('payment_date', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json($payments);
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
     * Get payment details
     */
    public function show($id): JsonResponse
    {
        $payment = Payment::with(['student', 'fee', 'guardian'])->find($id);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        return response()->json(['payment' => $payment]);
    }

    /**
     * Create payment
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'fee_id' => 'nullable|exists:fees,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,bank_transfer,card,online',
            'payment_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'guardian_id' => 'nullable|exists:guardians,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $payment = Payment::create([
            'school_id' => $request->school_id ?? 1,
            'student_id' => $request->student_id,
            'fee_id' => $request->fee_id,
            'guardian_id' => $request->guardian_id,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'payment_reference' => $request->payment_reference,
            'payment_date' => now(),
            'status' => 'successful',
            'notes' => $request->notes,
        ]);

        // Update fee status if fee_id is provided
        if ($request->fee_id) {
            $fee = Fee::find($request->fee_id);
            if ($fee && $fee->getRemainingAmount() <= 0) {
                $fee->update(['status' => 'paid']);
            }
        }

        return response()->json([
            'message' => 'Payment created successfully',
            'payment' => $payment
        ], 201);
    }

    /**
     * Get student payments
     */
    public function getStudentPayments($studentId): JsonResponse
    {
        $payments = Payment::where('student_id', $studentId)
            ->with(['fee'])
            ->orderBy('payment_date', 'desc')
            ->get();

        return response()->json([
            'student_id' => $studentId,
            'payments' => $payments
        ]);
    }

    /**
     * Get payment receipt
     */
    public function getReceipt($id): JsonResponse
    {
        $payment = Payment::with(['student', 'fee', 'guardian', 'school'])->find($id);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $receipt = [
            'receipt_number' => 'RCP-' . str_pad($payment->id, 8, '0', STR_PAD_LEFT),
            'payment_date' => $payment->payment_date,
            'student' => $payment->student,
            'fee' => $payment->fee,
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'payment_reference' => $payment->payment_reference,
            'school' => $payment->school,
        ];

        return response()->json([
            'receipt' => $receipt
        ]);
    }
}
