<?php

namespace App\Http\Controllers;

use App\Modules\Financial\Models\Fee;
use App\Models\School;
use App\Models\SchoolSignature;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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

    /**
     * Return a print-ready HTML fee voucher (demand notice) for a student.
     *
     * GET /fees/voucher/{studentId}
     *
     * Shows all outstanding fees for the student, school logo, and signatures.
     * Opens in a new tab; browser print dialog triggered automatically.
     */
    public function feeVoucher(Request $request, $studentId): Response
    {
        $school  = $request->attributes->get('school') ?? School::first();
        $student = Student::with(['class', 'user'])->find($studentId);

        if (! $student) {
            return response('<h2>Student not found</h2>', 404)->header('Content-Type', 'text/html');
        }

        $fees = Fee::where('student_id', $studentId)
            ->with(['term', 'academicYear'])
            ->orderBy('due_date')
            ->get();

        $signatures = $school ? SchoolSignature::activeForSchool($school->id) : collect();
        $logoHtml   = $school?->logo
            ? '<img src="' . e($school->logo) . '" style="max-height:70px;max-width:160px;" alt="logo">'
            : '<div style="font-size:22px;font-weight:bold;">' . e($school?->name ?? 'School') . '</div>';

        $schoolName  = e($school?->name ?? 'School');
        $schoolAddr  = e($school?->address ?? '');
        $schoolPhone = e($school?->phone ?? '');
        $schoolEmail = e($school?->email ?? '');

        $studentName  = e($student->full_name ?? ($student->first_name . ' ' . $student->last_name));
        $admission    = e($student->admission_number ?? '');
        $className    = e($student->class?->name ?? '—');

        $totalFees       = 0;
        $totalPaid       = 0;
        $feeRows         = '';

        foreach ($fees as $fee) {
            $feeType  = e(ucwords(str_replace('_', ' ', $fee->fee_type)));
            $term     = e($fee->term?->name ?? '—');
            $year     = e($fee->academicYear?->year ?? '—');
            $amount   = number_format($fee->amount, 2);
            $paid     = number_format($fee->amount_paid ?? 0, 2);
            $balance  = number_format(max((float)$fee->amount - (float)($fee->amount_paid ?? 0), 0), 2);
            $due      = $fee->due_date ? date('d M Y', strtotime($fee->due_date)) : '—';
            $status   = e(ucfirst($fee->status ?? 'pending'));
            $color    = match(strtolower($fee->status ?? '')) {
                'paid' => '#16a34a', 'overdue' => '#dc2626', default => '#2563eb',
            };

            $totalFees += (float) $fee->amount;
            $totalPaid += (float) ($fee->amount_paid ?? 0);

            $feeRows .= "<tr>
              <td>{$feeType}</td><td>{$term} / {$year}</td>
              <td style='text-align:right;'>₦{$amount}</td>
              <td style='text-align:right;color:#16a34a;'>₦{$paid}</td>
              <td style='text-align:right;font-weight:bold;'>₦{$balance}</td>
              <td>{$due}</td>
              <td><span style='padding:2px 8px;border-radius:10px;font-size:10px;font-weight:bold;color:#fff;background:{$color};'>{$status}</span></td>
            </tr>";
        }

        $totalBalance = number_format(max($totalFees - $totalPaid, 0), 2);
        $totalFeesFmt = number_format($totalFees, 2);
        $totalPaidFmt = number_format($totalPaid, 2);
        $voucherNo    = 'VCH-' . strtoupper(substr(md5($studentId . date('Ymd')), 0, 8));

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
            $sigHtml = '<div style="border-bottom:1px solid #333;width:160px;height:55px;margin:auto;"></div><div style="font-size:11px;text-align:center;margin-top:4px;">Bursar / Accountant</div>';
        }

        $generated = date('d M Y, H:i');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fee Voucher – {$studentName}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; padding: 24px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1a3a6b; padding-bottom: 14px; margin-bottom: 18px; }
  .school-info p { font-size: 11px; color: #555; margin-top: 3px; }
  .voucher-title { text-align: right; }
  .voucher-title h1 { font-size: 20px; color: #1a3a6b; letter-spacing: 1px; }
  .voucher-title p { font-size: 11px; color: #666; margin-top: 3px; }
  .student-box { background: #f0f4ff; border-radius: 8px; padding: 12px 16px; margin-bottom: 18px; display: flex; gap: 40px; }
  .student-box div span { display: block; font-size: 10px; color: #888; text-transform: uppercase; }
  .student-box div strong { font-size: 13px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  th { background: #1a3a6b; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; }
  td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
  tr:nth-child(even) td { background: #f8faff; }
  .summary { margin-left: auto; width: 260px; margin-bottom: 16px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
  .summary table { margin: 0; }
  .summary td { padding: 6px 12px; }
  .summary .outstanding td { background: #1a3a6b; color: #fff; font-weight: bold; font-size: 13px; }
  .signatures { display: flex; gap: 40px; flex-wrap: wrap; margin-top: 24px; padding-top: 14px; border-top: 1px solid #ddd; }
  .footer { margin-top: 12px; font-size: 10px; color: #888; }
  @media print { body { padding: 0; } @page { margin: 1.5cm; } }
</style>
</head>
<body>

<div class="header">
  <div class="school-info">
    {$logoHtml}
    <p>{$schoolAddr}</p>
    <p>{$schoolPhone} &nbsp;|&nbsp; {$schoolEmail}</p>
  </div>
  <div class="voucher-title">
    <h1>FEE VOUCHER</h1>
    <p>Ref: {$voucherNo}</p>
    <p>Date: {$generated}</p>
  </div>
</div>

<div class="student-box">
  <div><span>Student Name</span><strong>{$studentName}</strong></div>
  <div><span>Admission No.</span><strong>{$admission}</strong></div>
  <div><span>Class</span><strong>{$className}</strong></div>
</div>

<table>
  <thead>
    <tr>
      <th>Fee Type</th><th>Term / Year</th>
      <th style="text-align:right;">Amount</th>
      <th style="text-align:right;">Paid</th>
      <th style="text-align:right;">Balance</th>
      <th>Due Date</th><th>Status</th>
    </tr>
  </thead>
  <tbody>{$feeRows}</tbody>
</table>

<div class="summary">
  <table>
    <tr><td>Total Fees</td><td style="text-align:right;">₦{$totalFeesFmt}</td></tr>
    <tr><td>Total Paid</td><td style="text-align:right;color:#16a34a;">₦{$totalPaidFmt}</td></tr>
    <tr class="outstanding"><td>Outstanding</td><td style="text-align:right;">₦{$totalBalance}</td></tr>
  </table>
</div>

<div class="signatures">{$sigHtml}</div>

<div class="footer">Generated on {$generated} &nbsp;|&nbsp; {$schoolName} &nbsp;|&nbsp; Voucher No: {$voucherNo}</div>

<script>window.onload = function() { window.print(); }</script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
