<?php

namespace App\Http\Controllers;

use App\Modules\Financial\Models\Payment;
use App\Modules\Financial\Models\Fee;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Term;
use App\Support\FinanceDocument;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

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

        $schoolId = $request->school_id ?? 1;

        $payment = Payment::create([
            'school_id' => $schoolId,
            'student_id' => $request->student_id,
            'fee_id' => $request->fee_id,
            'guardian_id' => $request->guardian_id,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            // Same fixes as FeeController::pay(): payment_reference is
            // NOT NULL/unique (generate one when not supplied, rather than
            // crashing the insert), and 'successful' was never a real
            // payments.status value (pending/confirmed/failed/refunded).
            'payment_reference' => $request->payment_reference ?: Payment::generateReference($schoolId),
            // The day the money actually moved (defaults to today), and for transfers the school account it went into.
            'payment_date' => $request->filled('payment_date') ? $request->payment_date : now(),
            'status' => 'confirmed',
            'notes' => $request->notes,
        ] + $this->bankAccountFields($request));

        // Apply the payment to the linked fee's running totals — this is
        // the same fee row FeeController's summary()/feeBreakdown()/
        // feeVoucher() read amount_paid/balance from directly, so recording
        // a payment through this endpoint (the "Record Payment" button)
        // must keep them in sync exactly like FeeController::pay() does.
        if ($request->fee_id) {
            $fee = Fee::find($request->fee_id);
            if ($fee) {
                $newAmountPaid = round((float) $fee->amount_paid + (float) $request->amount, 2);
                $newBalance = max(0, round((float) $fee->amount - $newAmountPaid, 2));
                $fee->update([
                    'amount_paid' => $newAmountPaid,
                    'balance' => $newBalance,
                    'status' => $newBalance <= 0 ? 'paid' : 'partial',
                ]);
            }
        }

        // Receipt (PDF) to the parents, unless the cashier unticked it.
        $emailed = $request->boolean('send_receipt', true) ? \App\Support\FeeDocuments::emailReceipt($payment) : 0;

        return response()->json([
            'message' => 'Payment created successfully' . ($emailed ? " — receipt emailed to {$emailed} parent(s)" : ''),
            'payment' => $payment,
            'receipt_emailed_to' => $emailed,
        ], 201);
    }

    /** bank_account_id + a readable "Bank · Name · Number" snapshot for the receipt. */
    private function bankAccountFields(Request $request): array
    {
        if (! $request->filled('bank_account_id') || ! \Illuminate\Support\Facades\Schema::hasColumn('payments', 'bank_account_id')) {
            return [];
        }
        $a = \App\Models\SchoolBankAccount::find($request->bank_account_id);

        return $a ? ['bank_account_id' => $a->id, 'paid_into' => trim("{$a->bank_name} · {$a->account_name} · {$a->account_number}")] : [];
    }

    /**
     * Delete a payment (e.g. recorded twice or against the wrong student). Its amount
     * comes off the fee's paid total; a copy goes to the recycle bin for restoring.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }
        $payment = Payment::find($id);
        if (! $payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($payment) {
            if ($payment->fee_id && $payment->status === 'confirmed') {
                $fee = Fee::find($payment->fee_id);
                if ($fee) {
                    $paid = max(0, round((float) $fee->amount_paid - (float) $payment->amount, 2));
                    $balance = max(0, round((float) $fee->amount - $paid, 2));
                    $fee->update(['amount_paid' => $paid, 'balance' => $balance,
                        'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'pending')]);
                }
            }
            $payment->delete();
        });

        return response()->json(['message' => 'Payment deleted. You can restore it from the Recycle Bin.']);
    }

    /**
     * Get student payments
     */
    public function getStudentPayments(Request $request, $studentId): JsonResponse
    {
        if (! $this->studentWithinScope($request->user(), (int) $studentId)) {
            return $this->forbiddenResponse('You may not view this student\'s payments.');
        }

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
     * Get payment receipt.
     * Response includes school logo and active signatures for document rendering.
     */
    public function getReceipt(Request $request, $id): JsonResponse
    {
        $payment = Payment::with(['student', 'fee', 'guardian', 'school'])->find($id);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        if (! $this->studentWithinScope($request->user(), (int) $payment->student_id)) {
            return $this->forbiddenResponse('You may not view this receipt.');
        }

        $school     = $payment->school;
        $signatures = $school
            ? \App\Models\SchoolSignature::activeForSchool($school->id)->map(function ($s) {
                $arr = $s->toArray();
                $arr['signature_url'] = $s->signature_url;
                return $arr;
            })
            : collect();

        $receipt = [
            'receipt_number'    => 'RCP-' . str_pad($payment->id, 8, '0', STR_PAD_LEFT),
            'payment_date'      => $payment->payment_date,
            'student'           => $payment->student,
            'fee'               => $payment->fee,
            'guardian'          => $payment->guardian,
            'amount'            => $payment->amount,
            'payment_method'    => $payment->payment_method,
            'payment_reference' => $payment->payment_reference,
            'notes'             => $payment->notes,
            'status'            => $payment->status,
            'school'            => [
                'name'    => $school?->name,
                'logo'    => $school?->logo,
                'address' => $school?->address,
                'phone'   => $school?->phone,
                'email'   => $school?->email,
            ],
            'signatures'        => $signatures,
        ];

        return response()->json(['receipt' => $receipt]);
    }

    /**
     * Return a print-ready HTML receipt page, in the same letter-headed
     * layout as invoices.
     * GET /financial/payments/receipt/{id}/print
     */
    public function printReceipt(Request $request, $id): Response
    {
        $payment = Payment::with(['student.class', 'fee', 'school'])->find($id);

        if (! $payment) {
            return response('<h2>Payment not found</h2>', 404)->header('Content-Type', 'text/html');
        }

        if (! $this->studentWithinScope($request->user(), (int) $payment->student_id)) {
            return response('<h2>You may not view this receipt.</h2>', 403)->header('Content-Type', 'text/html');
        }

        $html = \App\Support\FeeDocuments::receiptHtml($payment);
        if ($request->query('format') === 'pdf') {
            return \App\Support\FeeDocuments::pdfResponse($html, \App\Support\FeeDocuments::receiptNumber($payment) . '.pdf');
        }

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
