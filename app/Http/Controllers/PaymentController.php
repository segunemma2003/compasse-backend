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
            'payment_date' => now(),
            'status' => 'confirmed',
            'notes' => $request->notes,
        ]);

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

        return response()->json([
            'message' => 'Payment created successfully',
            'payment' => $payment
        ], 201);
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

        $school  = $payment->school ?? School::first();
        $student = $payment->student;
        $fee     = $payment->fee;

        [$session, $term] = FinanceDocument::currentSession($school);
        if ($fee?->academic_year_id) {
            $session = AcademicYear::find($fee->academic_year_id)?->name ?? $session;
        }
        if ($fee?->term_id) {
            $term = Term::find($fee->term_id)?->name ?? $term;
        }

        $feeLabel = $fee
            ? ($fee->description ?: ucwords(str_replace('_', ' ', $fee->fee_type)) . ' fees')
            : 'Payment';
        if ($fee && $term) {
            $feeLabel .= ' for ' . $term . ($session ? ' ' . $session : '');
        }

        $rows = [['description' => $feeLabel, 'amount' => (float) $payment->amount]];
        if ($payment->notes) {
            $rows[] = ['description' => $payment->notes, 'amount' => null, 'muted' => true];
        }

        $totals = [['Amount Paid', (float) $payment->amount, true]];
        if ($fee) {
            $totals[] = ['Total Fee', (float) $fee->amount, false];
            $totals[] = ['Total Paid To Date', (float) $fee->amount_paid, false];
            $totals[] = ['Balance Outstanding', max((float) $fee->amount - (float) $fee->amount_paid, 0), true];
        }

        $rcpNo   = 'RCP-' . str_pad($payment->id, 8, '0', STR_PAD_LEFT);
        $payDate = $payment->payment_date ? date('d/m/Y', strtotime($payment->payment_date)) : date('d/m/Y');

        $html = FinanceDocument::render(
            $school,
            'Receipt ' . $rcpNo,
            [
                ['Received From', $student?->full_name ?? trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')) ?: 'N/A'],
                ['Session', $session ?: '—'],
                ['Receipt No.', $rcpNo],
                ['Payment Method', ucwords(str_replace('_', ' ', $payment->payment_method ?? ''))],
            ],
            [
                ['Class', $student?->class?->name ?? '—'],
                ['Term', $term ?: '—'],
                ['Payment Date', $payDate],
                ['Reference', $payment->payment_reference ?? '—'],
            ],
            $rows,
            $totals,
            [],
            $payment->status === 'confirmed' ? 'PAID' : strtoupper($payment->status ?? ''),
            'Payment Receipt',
        );

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
