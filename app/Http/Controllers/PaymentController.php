<?php

namespace App\Http\Controllers;

use App\Modules\Financial\Models\Payment;
use App\Modules\Financial\Models\Fee;
use App\Models\SchoolSignature;
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
     * Get payment receipt.
     * Response includes school logo and active signatures for document rendering.
     */
    public function getReceipt($id): JsonResponse
    {
        $payment = Payment::with(['student', 'fee', 'guardian', 'school'])->find($id);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
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
     * Return a print-ready HTML receipt page.
     * GET /financial/payments/receipt/{id}/print
     */
    public function printReceipt($id): Response
    {
        $payment = Payment::with(['student', 'fee', 'guardian', 'school'])->find($id);

        if (! $payment) {
            return response('<h2>Payment not found</h2>', 404)->header('Content-Type', 'text/html');
        }

        $school     = $payment->school;
        $signatures = $school ? SchoolSignature::activeForSchool($school->id) : collect();

        $logoHtml = $school?->logo
            ? '<img src="' . e($school->logo) . '" style="max-height:70px;max-width:160px;" alt="logo">'
            : '<div style="font-size:22px;font-weight:bold;">' . e($school?->name ?? 'School') . '</div>';

        $rcpNo      = 'RCP-' . str_pad($payment->id, 8, '0', STR_PAD_LEFT);
        $schoolName = e($school?->name ?? '');
        $schoolAddr = e($school?->address ?? '');
        $schoolTel  = e($school?->phone ?? '');
        $schoolMail = e($school?->email ?? '');
        $student    = $payment->student;
        $studName   = e($student?->full_name ?? ($student?->first_name . ' ' . $student?->last_name) ?? 'N/A');
        $admNo      = e($student?->admission_number ?? '');
        $feeType    = e($payment->fee?->fee_type ?? 'Payment');
        $amount     = number_format($payment->amount, 2);
        $method     = e(ucwords(str_replace('_', ' ', $payment->payment_method ?? '')));
        $reference  = e($payment->payment_reference ?? '—');
        $payDate    = $payment->payment_date ? date('d M Y', strtotime($payment->payment_date)) : date('d M Y');
        $notes      = e($payment->notes ?? '');

        // Signatures
        $sigHtml = '';
        foreach ($signatures as $role => $sig) {
            $sigName = e($sig->name);
            $sigRole = e(ucwords(str_replace('_', ' ', $role)));
            $sigUrl  = $sig->signature_url;
            $sigImg  = $sigUrl
                ? "<img src=\"{$sigUrl}\" style=\"max-height:55px;max-width:140px;\">"
                : '<div style="border-bottom:1px solid #333;width:140px;height:55px;"></div>';
            $sigHtml .= "<div style='text-align:center;min-width:160px;'>{$sigImg}<div style='font-size:11px;margin-top:4px;'>{$sigName}</div><div style='font-size:10px;color:#666;'>{$sigRole}</div></div>";
        }
        if (! $sigHtml) {
            $sigHtml = '<div style="border-bottom:1px solid #333;width:160px;height:55px;margin:auto;"></div><div style="font-size:11px;text-align:center;margin-top:4px;">Cashier / Bursar</div>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt {$rcpNo}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; padding: 24px; max-width: 500px; margin: auto; }
  .header { text-align: center; border-bottom: 3px solid #1a3a6b; padding-bottom: 14px; margin-bottom: 18px; }
  .header h2 { font-size: 16px; color: #1a3a6b; margin-top: 8px; }
  .header p { font-size: 11px; color: #555; }
  .receipt-title { text-align: center; font-size: 18px; font-weight: bold; letter-spacing: 2px; color: #1a3a6b; margin-bottom: 4px; }
  .rcpno { text-align: center; font-size: 11px; color: #888; margin-bottom: 16px; }
  .row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px dashed #e5e7eb; font-size: 12px; }
  .row:last-child { border-bottom: none; }
  .row .lbl { color: #666; }
  .amount-box { background: #1a3a6b; color: #fff; text-align: center; padding: 14px; border-radius: 8px; margin: 18px 0; }
  .amount-box .lbl { font-size: 11px; opacity: .8; }
  .amount-box .val { font-size: 26px; font-weight: bold; }
  .signatures { display: flex; gap: 30px; flex-wrap: wrap; margin-top: 24px; padding-top: 14px; border-top: 1px solid #ddd; justify-content: center; }
  .footer { text-align: center; font-size: 10px; color: #aaa; margin-top: 14px; }
  @media print { body { padding: 0; max-width: 100%; } @page { margin: 1.5cm; } }
</style>
</head>
<body>

<div class="header">
  {$logoHtml}
  <h2>{$schoolName}</h2>
  <p>{$schoolAddr}</p>
  <p>{$schoolTel} &nbsp;|&nbsp; {$schoolMail}</p>
</div>

<div class="receipt-title">OFFICIAL RECEIPT</div>
<div class="rcpno">Receipt No: {$rcpNo} &nbsp;|&nbsp; Date: {$payDate}</div>

<div class="amount-box">
  <div class="lbl">AMOUNT PAID</div>
  <div class="val">₦{$amount}</div>
</div>

<div class="row"><span class="lbl">Student</span><span>{$studName}</span></div>
<div class="row"><span class="lbl">Admission No.</span><span>{$admNo}</span></div>
<div class="row"><span class="lbl">Payment For</span><span>{$feeType}</span></div>
<div class="row"><span class="lbl">Payment Method</span><span>{$method}</span></div>
<div class="row"><span class="lbl">Reference</span><span>{$reference}</span></div>
<div class="row"><span class="lbl">Date</span><span>{$payDate}</span></div>
HTML;

        if ($notes) {
            $html .= "<div class=\"row\"><span class=\"lbl\">Notes</span><span>{$notes}</span></div>";
        }

        $html .= "<div class=\"signatures\">{$sigHtml}</div>";
        $html .= "<div class=\"footer\">This is a system-generated receipt. {$schoolName}</div>";
        $html .= '<script>window.onload = function() { window.print(); }</script>';
        $html .= '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
