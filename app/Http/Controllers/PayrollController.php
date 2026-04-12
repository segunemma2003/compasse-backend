<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\School;
use App\Models\SchoolSignature;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

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
     *
     * Response shape matches the PayStub interface in MyPayslips.tsx:
     * { stub_id, generated_at, school, employee, period, earnings, deductions, net_salary,
     *   payment_info, processed_by, notes, signatures }
     */
    public function payStub(string $id): JsonResponse
    {
        $payroll = Payroll::with(['staff', 'processedBy', 'school', 'academicYear'])->findOrFail($id);

        $staff  = $payroll->staff;   // User model
        $school = $payroll->school;

        // Attempt to load the teacher profile for bank details / department
        $teacher = $staff ? \App\Models\Teacher::where('user_id', $staff->id)->with('department')->first() : null;

        $signatures = $school
            ? \App\Models\SchoolSignature::activeForSchool($school->id)->map(fn ($s) => array_merge($s->toArray(), ['signature_url' => $s->signature_url]))
            : collect();

        $monthLabel = \Carbon\Carbon::createFromDate($payroll->year, $payroll->month, 1)
            ->format('F Y');

        $gross      = (float) $payroll->basic_salary + (float) $payroll->allowances;
        $deductions = (float) $payroll->deductions;

        return response()->json([
            'pay_stub' => [
                'stub_id'      => 'PS-' . str_pad($payroll->id, 8, '0', STR_PAD_LEFT),
                'generated_at' => now()->toIso8601String(),

                'school' => [
                    'name'     => $school?->name,
                    'address'  => $school?->address,
                    'phone'    => $school?->phone,
                    'email'    => $school?->email,
                    'logo_url' => $school?->logo,
                ],

                'employee' => [
                    'id'                 => $staff?->id,
                    'name'               => $staff?->name,
                    'email'              => $staff?->email,
                    'role'               => $staff?->role,
                    'department'         => $teacher?->department?->name ?? '—',
                    'employment_type'    => $teacher?->employment_type ?? 'full_time',
                    'bank_name'          => $teacher?->bank_name ?? '—',
                    'bank_account_number'=> $teacher?->bank_account_number ?? '—',
                ],

                'period' => [
                    'month'        => $payroll->month,
                    'year'         => $payroll->year,
                    'label'        => $monthLabel,
                    'payment_date' => $payroll->payment_date,
                ],

                'earnings' => [
                    'basic_salary' => (float) $payroll->basic_salary,
                    'allowances'   => (float) $payroll->allowances,
                    'gross_salary' => $gross,
                ],

                'deductions' => [
                    'total'     => $deductions,
                    'breakdown' => [
                        'tax_paye'       => 0,
                        'pension'        => 0,
                        'loan_repayment' => 0,
                        'other'          => $deductions,
                    ],
                ],

                'net_salary' => (float) $payroll->net_salary,

                'payment_info' => [
                    'method'    => $payroll->payment_method ?? 'bank_transfer',
                    'status'    => $payroll->status,
                    'reference' => null,
                ],

                'processed_by' => $payroll->processedBy?->name,
                'notes'        => $payroll->notes,
                'signatures'   => $signatures,
            ],
        ]);
    }

    /**
     * Return a print-ready HTML pay stub page.
     * GET /payroll/{payroll}/pay-stub/print
     */
    public function payStubPrint(string $id): Response
    {
        $payroll = Payroll::with(['staff', 'processedBy', 'school', 'academicYear'])->findOrFail($id);

        $staff      = $payroll->staff;
        $school     = $payroll->school;
        $teacher    = $staff ? \App\Models\Teacher::where('user_id', $staff->id)->with('department')->first() : null;
        $signatures = $school ? SchoolSignature::activeForSchool($school->id) : collect();

        $logoHtml = $school?->logo
            ? '<img src="' . e($school->logo) . '" style="max-height:70px;max-width:160px;" alt="logo">'
            : '<div style="font-size:22px;font-weight:bold;">' . e($school?->name ?? 'School') . '</div>';

        $monthLabel = \Carbon\Carbon::createFromDate($payroll->year, $payroll->month, 1)->format('F Y');
        $stubId     = 'PS-' . str_pad($payroll->id, 8, '0', STR_PAD_LEFT);
        $gross      = (float) $payroll->basic_salary + (float) $payroll->allowances;
        $deductions = (float) $payroll->deductions;
        $net        = (float) $payroll->net_salary;

        $schoolName = e($school?->name ?? '');
        $schoolAddr = e($school?->address ?? '');
        $schoolTel  = e($school?->phone ?? '');
        $staffName  = e($staff?->name ?? 'N/A');
        $staffRole  = e(ucwords(str_replace('_', ' ', $staff?->role ?? '')));
        $dept       = e($teacher?->department?->name ?? '—');
        $bankName   = e($teacher?->bank_name ?? '—');
        $bankAcct   = e($teacher?->bank_account_number ?? '—');
        $payDate    = $payroll->payment_date ? date('d M Y', strtotime($payroll->payment_date)) : 'Pending';
        $payMethod  = e(ucwords(str_replace('_', ' ', $payroll->payment_method ?? '')));
        $processedBy= e($payroll->processedBy?->name ?? '');
        $notes      = e($payroll->notes ?? '');

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
            $sigHtml = '<div style="border-bottom:1px solid #333;width:160px;height:55px;margin:auto;"></div><div style="font-size:11px;text-align:center;margin-top:4px;">Authorised Signatory</div>';
        }

        $bSalary = number_format($payroll->basic_salary, 2);
        $bAllow  = number_format($payroll->allowances, 2);
        $bGross  = number_format($gross, 2);
        $bDeduct = number_format($deductions, 2);
        $bNet    = number_format($net, 2);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pay Stub – {$staffName} – {$monthLabel}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; padding: 24px; max-width: 600px; margin: auto; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1a3a6b; padding-bottom: 14px; margin-bottom: 18px; }
  .school-info p { font-size: 11px; color: #555; margin-top: 3px; }
  .stub-meta { text-align: right; }
  .stub-meta h1 { font-size: 18px; color: #1a3a6b; letter-spacing: 1px; }
  .stub-meta p { font-size: 11px; color: #666; margin-top: 3px; }
  .emp-box { background: #f0f4ff; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .emp-box div span { display: block; font-size: 10px; color: #888; text-transform: uppercase; }
  .emp-box div strong { font-size: 12px; }
  .section h3 { font-size: 12px; color: #1a3a6b; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
  .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #f0f0f0; font-size: 12px; }
  .row.total { font-weight: bold; border-bottom: 2px solid #1a3a6b; }
  .net-box { background: #1a3a6b; color: #fff; text-align: center; padding: 14px; border-radius: 8px; margin: 18px 0; }
  .net-box .lbl { font-size: 11px; opacity: .8; }
  .net-box .val { font-size: 26px; font-weight: bold; }
  .signatures { display: flex; gap: 30px; flex-wrap: wrap; margin-top: 24px; padding-top: 14px; border-top: 1px solid #ddd; }
  .footer { text-align: center; font-size: 10px; color: #aaa; margin-top: 14px; }
  @media print { body { padding: 0; max-width: 100%; } @page { margin: 1.5cm; } }
</style>
</head>
<body>

<div class="header">
  <div class="school-info">
    {$logoHtml}
    <p>{$schoolAddr}</p>
    <p>{$schoolTel}</p>
  </div>
  <div class="stub-meta">
    <h1>PAY STUB</h1>
    <p>{$monthLabel}</p>
    <p>Stub ID: {$stubId}</p>
  </div>
</div>

<div class="emp-box">
  <div><span>Employee</span><strong>{$staffName}</strong></div>
  <div><span>Role</span><strong>{$staffRole}</strong></div>
  <div><span>Department</span><strong>{$dept}</strong></div>
  <div><span>Bank</span><strong>{$bankName}</strong></div>
  <div><span>Account No.</span><strong>{$bankAcct}</strong></div>
  <div><span>Payment Date</span><strong>{$payDate}</strong></div>
</div>

<div class="section" style="margin-bottom:16px;">
  <h3>Earnings</h3>
  <div class="row"><span>Basic Salary</span><span>₦{$bSalary}</span></div>
  <div class="row"><span>Allowances</span><span>₦{$bAllow}</span></div>
  <div class="row total"><span>Gross Salary</span><span>₦{$bGross}</span></div>
</div>

<div class="section" style="margin-bottom:8px;">
  <h3>Deductions</h3>
  <div class="row total"><span>Total Deductions</span><span>₦{$bDeduct}</span></div>
</div>

<div class="net-box">
  <div class="lbl">NET PAY</div>
  <div class="val">₦{$bNet}</div>
</div>

<div class="row"><span style="color:#666;">Payment Method</span><span>{$payMethod}</span></div>
<div class="row"><span style="color:#666;">Processed By</span><span>{$processedBy}</span></div>
HTML;
        if ($notes) {
            $html .= "<div class=\"row\"><span style=\"color:#666;\">Notes</span><span>{$notes}</span></div>";
        }
        $html .= "<div class=\"signatures\">{$sigHtml}</div>";
        $html .= "<div class=\"footer\">This is a system-generated pay stub. {$schoolName}</div>";
        $html .= '<script>window.onload = function() { window.print(); }</script>';
        $html .= '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
