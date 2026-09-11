<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use App\Modules\Financial\Models\Fee;
use App\Modules\Financial\Models\FeeItem;
use App\Modules\Financial\Models\FeeStructure;
use App\Modules\Financial\Models\Payment;
use App\Models\School;
use App\Models\SchoolBankAccount;
use App\Models\SchoolSignature;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FeeController extends Controller
{
    /**
     * List fees
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Fee::with(['student', 'class', 'items']);

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

            if ($request->filled('search')) {
                $s = $request->search;
                $query->whereHas('student', fn($q) =>
                    $q->where('first_name', 'like', "%{$s}%")
                      ->orWhere('last_name', 'like', "%{$s}%")
                      ->orWhere('admission_number', 'like', "%{$s}%")
                );
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
        $fee = Fee::with(['student', 'class', 'payments', 'items'])->find($id);

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
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'fee_type' => 'required|string|max:100',
            'amount' => 'required_without:items|nullable|numeric|min:0',
            'items' => 'nullable|array|min:1',
            'items.*.name' => 'required_with:items|string|max:100',
            'items.*.amount' => 'required_with:items|numeric|min:0',
            'due_date' => 'required|date|after_or_equal:today',
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

        $school = School::find($request->school_id) ?? School::first();
        $academicYearId = $request->academic_year_id ?? $school?->getCurrentAcademicYear()?->id;
        $termId = $request->term_id ?? $school?->getCurrentTerm()?->id;

        if (! $academicYearId) {
            return response()->json([
                'error' => 'No academic year set. Set a current academic year, or pass academic_year_id explicitly.',
            ], 422);
        }

        $items = $request->input('items');
        $amount = $items ? array_sum(array_column($items, 'amount')) : (float) $request->amount;

        $fee = Fee::create([
            'school_id' => $school?->id ?? 1,
            'student_id' => $request->student_id,
            'class_id' => $request->class_id,
            'fee_type' => $request->fee_type,
            'amount' => $amount,
            // fees.balance is NOT NULL with no default — Fee::create() never
            // set it here, so every single fee creation 500'd under MySQL
            // strict mode ("Field 'balance' doesn't have a default value").
            // Set explicitly rather than relying solely on the migration's
            // new column default, since a fee's real starting balance is
            // its full amount (amount_paid is 0 until pay() runs).
            'amount_paid' => 0,
            'balance' => $amount,
            'due_date' => $request->due_date,
            'description' => $request->description,
            'academic_year_id' => $academicYearId,
            'term_id' => $termId,
            'status' => 'pending',
            'is_customized' => (bool) $items,
        ]);

        if ($items) {
            foreach ($items as $item) {
                $fee->items()->create(['name' => $item['name'], 'amount' => $item['amount']]);
            }
        }

        return response()->json([
            'message' => 'Fee created successfully',
            'fee' => $fee->load('items')
        ], 201);
    }

    /**
     * Update fee
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $fee = Fee::find($id);

        if (!$fee) {
            return response()->json(['error' => 'Fee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'sometimes|numeric|min:0',
            'items' => 'nullable|array|min:1',
            'items.*.name' => 'required_with:items|string|max:100',
            'items.*.amount' => 'required_with:items|numeric|min:0',
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

        // Editing this student's breakdown directly detaches them from further
        // class-wide fee-structure updates — this student's own edit wins from
        // here on, even if the class plan changes later.
        if ($request->has('items')) {
            $fee->items()->delete();
            foreach ($request->input('items') as $item) {
                $fee->items()->create(['name' => $item['name'], 'amount' => $item['amount']]);
            }
            $fee->update([
                'amount' => array_sum(array_column($request->input('items'), 'amount')),
                'is_customized' => true,
            ]);
        }

        return response()->json([
            'message' => 'Fee updated successfully',
            'fee' => $fee->fresh('items')
        ]);
    }

    /**
     * Delete fee
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

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
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

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
            // payment_reference is NOT NULL and unique — most cash/bank
            // payments never come with an external reference, and passing
            // null through crashed the insert outright. Generate one when
            // the caller didn't supply it.
            'payment_reference' => $request->payment_reference ?: \App\Modules\Financial\Models\Payment::generateReference($fee->school_id),
            'payment_date' => now(),
            // payments.status is an enum of pending/confirmed/failed/refunded
            // — 'successful' isn't a real value, so this insert always threw
            // a "Data truncated for column 'status'" error under strict
            // mode. Every attempt to record a payment against a fee failed.
            'status' => 'confirmed',
            'notes' => $request->notes,
        ]);

        // Apply the payment to the fee's own running totals. Every other
        // read path in this controller (summary(), feeBreakdown(),
        // feeVoucher()) reads fees.amount_paid/balance directly via raw SQL
        // rather than deriving them from the payments table, so this fee
        // row is the actual source of truth those rely on — leaving it
        // unmodified (as before) meant a fee never reflected a payment at
        // all: it stayed "unpaid" for reminders/reports, and a second
        // payment could re-use the original max() bound above and overpay it.
        $newAmountPaid = round((float) $fee->amount_paid + (float) $request->amount, 2);
        $newBalance = max(0, round((float) $fee->amount - $newAmountPaid, 2));
        $fee->update([
            'amount_paid' => $newAmountPaid,
            'balance' => $newBalance,
            'status' => $newBalance <= 0 ? 'paid' : 'partial',
        ]);

        return response()->json([
            'message' => 'Payment processed successfully',
            'payment' => $payment,
            'fee' => $fee->fresh()
        ], 201);
    }

    /**
     * Get student fees
     */
    public function getStudentFees(Request $request, $studentId): JsonResponse
    {
        if (! $this->studentWithinScope($request->user(), (int) $studentId)) {
            return $this->forbiddenResponse('You may not view this student\'s fees.');
        }

        $fees = Fee::where('student_id', $studentId)
            ->with(['class', 'items'])
            ->orderBy('due_date', 'desc')
            ->get();

        return response()->json([
            'student_id' => $studentId,
            'fees' => $fees
        ]);
    }

    /**
     * List fee structures (class fee plans), each with its line-item breakdown.
     */
    public function getFeeStructure(Request $request): JsonResponse
    {
        $school = School::find($request->school_id) ?? School::first();

        $query = FeeStructure::with(['items', 'class'])
            ->where('school_id', $school?->id ?? 1)
            ->withCount('fees');

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }
        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->academic_year_id);
        }
        if ($request->filled('term_id')) {
            $query->where('term_id', $request->term_id);
        }

        return response()->json(['fee_structures' => $query->orderByDesc('id')->get()]);
    }

    /**
     * One fee structure with its breakdown and how many students it's applied to.
     */
    public function showFeeStructure($id): JsonResponse
    {
        $structure = FeeStructure::with(['items', 'class'])->withCount('fees')->find($id);
        if (! $structure) {
            return response()->json(['error' => 'Fee structure not found'], 404);
        }

        return response()->json(['fee_structure' => $structure]);
    }

    /**
     * Create a class fee plan: a named breakdown (e.g. Tuition + Sports + PTA)
     * whose sum becomes each student's fee amount. Applies immediately to
     * every student in the given class(es)/arm(s), generating one `fees` row
     * (with a matching item-for-item breakdown) per student.
     */
    public function createFeeStructure(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:100',
            'items.*.amount' => 'required|numeric|min:0',
            'class_ids' => 'required|array|min:1',
            'class_ids.*' => 'exists:classes,id',
            'arm_ids' => 'nullable|array',
            'arm_ids.*' => 'exists:arms,id',
            'due_date' => 'nullable|date',
            'description' => 'nullable|string',
            'is_mandatory' => 'nullable|boolean',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'term_id' => 'nullable|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $school = School::find($request->school_id) ?? School::first();
        $academicYearId = $request->academic_year_id ?? $school?->getCurrentAcademicYear()?->id;
        $termId = $request->term_id ?? $school?->getCurrentTerm()?->id;

        if (! $academicYearId) {
            return response()->json([
                'error' => 'No academic year set. Set a current academic year, or pass academic_year_id explicitly.',
            ], 422);
        }

        $totalAmount = array_sum(array_column($request->items, 'amount'));
        $dueDate = $request->due_date ?? now()->addMonth();
        $studentsCreated = 0;
        $structures = [];

        DB::beginTransaction();
        try {
            foreach ($request->class_ids as $classId) {
                $structure = FeeStructure::create([
                    'school_id' => $school?->id ?? 1,
                    'name' => $request->name,
                    'class_id' => $classId,
                    'arm_id' => $request->filled('arm_ids') && count($request->arm_ids) === 1 ? $request->arm_ids[0] : null,
                    'academic_year_id' => $academicYearId,
                    'term_id' => $termId,
                    'total_amount' => $totalAmount,
                    'due_date' => $dueDate,
                    'description' => $request->description,
                    'is_mandatory' => $request->boolean('is_mandatory', true),
                    'status' => 'active',
                ]);

                foreach ($request->items as $item) {
                    $structure->items()->create(['name' => $item['name'], 'amount' => $item['amount']]);
                }

                $studentQuery = Student::where('class_id', $classId);
                if ($request->filled('arm_ids') && count($request->arm_ids) > 0) {
                    $studentQuery->whereIn('arm_id', $request->arm_ids);
                }

                foreach ($studentQuery->pluck('id') as $studentId) {
                    $fee = Fee::create([
                        'school_id' => $school?->id ?? 1,
                        'student_id' => $studentId,
                        'class_id' => $classId,
                        'fee_structure_id' => $structure->id,
                        'fee_type' => $request->name,
                        'amount' => $totalAmount,
                        // Same "balance has no default" crash as store()
                        // above, hit here on every single "Assign Fee by
                        // Class" attempt, for every school, since launch.
                        'amount_paid' => 0,
                        'balance' => $totalAmount,
                        'due_date' => $dueDate,
                        'description' => $request->description,
                        'academic_year_id' => $academicYearId,
                        'term_id' => $termId,
                        'status' => 'pending',
                    ]);

                    foreach ($request->items as $item) {
                        $fee->items()->create(['name' => $item['name'], 'amount' => $item['amount']]);
                    }

                    $studentsCreated++;
                }

                $structures[] = $structure;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create fee structure', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Fee structure created successfully',
            'fee_structures' => $structures,
            'fees_created' => $studentsCreated,
        ], 201);
    }

    /**
     * Update a fee structure's breakdown. Propagates the new items/total to
     * every student fee still linked to this plan — except ones that have
     * been individually customized (Fee::is_customized), which keep their
     * own breakdown untouched.
     */
    public function updateFeeStructure(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $structure = FeeStructure::find($id);
        if (! $structure) {
            return response()->json(['error' => 'Fee structure not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:150',
            'items' => 'sometimes|array|min:1',
            'items.*.name' => 'required_with:items|string|max:100',
            'items.*.amount' => 'required_with:items|numeric|min:0',
            'due_date' => 'nullable|date',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $structure->update($request->only(['name', 'due_date', 'description', 'status']));

            $items = $request->input('items');
            if ($items) {
                $structure->items()->delete();
                foreach ($items as $item) {
                    $structure->items()->create(['name' => $item['name'], 'amount' => $item['amount']]);
                }
                $totalAmount = array_sum(array_column($items, 'amount'));
                $structure->update(['total_amount' => $totalAmount]);

                $linkedFees = Fee::where('fee_structure_id', $structure->id)
                    ->where('is_customized', false)
                    ->get();

                foreach ($linkedFees as $fee) {
                    $fee->items()->delete();
                    foreach ($items as $item) {
                        $fee->items()->create(['name' => $item['name'], 'amount' => $item['amount']]);
                    }
                    $fee->update([
                        'amount' => $totalAmount,
                        'fee_type' => $structure->name,
                        'due_date' => $structure->due_date ?? $fee->due_date,
                    ]);
                }
            } elseif ($request->filled('due_date') || $request->filled('name')) {
                Fee::where('fee_structure_id', $structure->id)
                    ->where('is_customized', false)
                    ->update(array_filter([
                        'due_date' => $request->due_date,
                        'fee_type' => $request->name,
                    ]));
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to update fee structure', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Fee structure updated successfully',
            'fee_structure' => $structure->fresh(['items']),
        ]);
    }

    /**
     * Delete a fee structure. Refuses if any linked student fee already has
     * a payment recorded against it — settle or reassign those first.
     */
    public function destroyFeeStructure(Request $request, $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $structure = FeeStructure::find($id);
        if (! $structure) {
            return response()->json(['error' => 'Fee structure not found'], 404);
        }

        $hasPayments = Fee::where('fee_structure_id', $structure->id)
            ->whereHas('payments')
            ->exists();

        if ($hasPayments) {
            return response()->json([
                'error' => 'Cannot delete fee structure',
                'message' => 'One or more students linked to this plan already have payments recorded.',
            ], 422);
        }

        DB::transaction(function () use ($structure) {
            // Fees still following the plan are deleted with it; a student whose
            // fee was individually customized keeps their own edited bill.
            Fee::where('fee_structure_id', $structure->id)->where('is_customized', false)->delete();
            Fee::where('fee_structure_id', $structure->id)->update(['fee_structure_id' => null]);
            $structure->delete();
        });

        return response()->json(['message' => 'Fee structure deleted successfully']);
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
        if (! $this->studentWithinScope($request->user(), (int) $studentId)) {
            return response('<h2>You may not view this student\'s fee voucher.</h2>', 403)->header('Content-Type', 'text/html');
        }

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

    /**
     * GET /financial/summary
     * Aggregate fee collection statistics for the Finance dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $query = Fee::query();

            $total         = (float) $query->sum('amount');
            $collected     = (float) \App\Modules\Financial\Models\Payment::sum('amount');
            $outstanding   = max($total - $collected, 0);
            $rate          = $total > 0 ? round(($collected / $total) * 100, 1) : 0;

            $studentStats  = Fee::selectRaw('student_id, status, SUM(amount) as total, SUM(COALESCE(amount_paid,0)) as paid')
                ->groupBy('student_id', 'status')
                ->get()
                ->groupBy('student_id');

            $fullyPaid     = 0;
            $partiallyPaid = 0;
            $unpaid        = 0;
            foreach ($studentStats as $stuId => $rows) {
                $t = $rows->sum('total');
                $p = $rows->sum('paid');
                if ($p <= 0)       $unpaid++;
                elseif ($p >= $t)  $fullyPaid++;
                else               $partiallyPaid++;
            }

            return response()->json([
                'total_fees_expected'      => $total,
                'total_collected'          => $collected,
                'total_outstanding'        => $outstanding,
                'collection_rate'          => $rate,
                'total_discounts'          => 0,
                'total_students_with_fees' => $studentStats->count(),
                'students_fully_paid'      => $fullyPaid,
                'students_partially_paid'  => $partiallyPaid,
                'students_unpaid'          => $unpaid,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'total_fees_expected' => 0, 'total_collected' => 0,
                'total_outstanding' => 0, 'collection_rate' => 0,
                'total_discounts' => 0, 'total_students_with_fees' => 0,
                'students_fully_paid' => 0, 'students_partially_paid' => 0, 'students_unpaid' => 0,
            ]);
        }
    }

    /**
     * GET /financial/fees/breakdown
     * Collection totals grouped by class, arm (section), and student.
     */
    public function feeBreakdown(Request $request): JsonResponse
    {
        try {
            $byClass = Fee::query()
                ->join('students', 'fees.student_id', '=', 'students.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->selectRaw('classes.id as class_id, classes.name as class_name,
                    COUNT(DISTINCT fees.student_id) as students,
                    COALESCE(SUM(fees.amount),0) as expected,
                    COALESCE(SUM(COALESCE(fees.amount_paid,0)),0) as collected,
                    COALESCE(SUM(COALESCE(fees.balance, fees.amount - COALESCE(fees.amount_paid,0))),0) as outstanding')
                ->groupBy('classes.id', 'classes.name')
                ->orderBy('classes.name')
                ->get();

            $byArm = Fee::query()
                ->join('students', 'fees.student_id', '=', 'students.id')
                ->leftJoin('arms', 'students.arm_id', '=', 'arms.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->selectRaw('classes.name as class_name,
                    COALESCE(arms.name, \'All / No arm\') as arm_name,
                    COUNT(DISTINCT fees.student_id) as students,
                    COALESCE(SUM(fees.amount),0) as expected,
                    COALESCE(SUM(COALESCE(fees.amount_paid,0)),0) as collected')
                ->groupBy('classes.name', 'arms.name')
                ->orderBy('classes.name')
                ->orderBy('arm_name')
                ->get();

            $studentQuery = Fee::query()
                ->join('students', 'fees.student_id', '=', 'students.id')
                ->join('users', 'students.user_id', '=', 'users.id')
                ->join('classes', 'students.class_id', '=', 'classes.id')
                ->leftJoin('arms', 'students.arm_id', '=', 'arms.id')
                ->selectRaw('students.id as student_id, users.name as student_name,
                    classes.name as class_name,
                    COALESCE(arms.name, \'—\') as arm_name,
                    COALESCE(SUM(fees.amount),0) as expected,
                    COALESCE(SUM(COALESCE(fees.amount_paid,0)),0) as collected,
                    COALESCE(SUM(COALESCE(fees.balance, fees.amount - COALESCE(fees.amount_paid,0))),0) as outstanding')
                ->groupBy('students.id', 'users.name', 'classes.name', 'arms.name');

            if ($request->filled('class_id')) {
                $studentQuery->where('students.class_id', $request->class_id);
            }
            if ($request->filled('arm_id')) {
                $studentQuery->where('students.arm_id', $request->arm_id);
            }

            $byStudent = $studentQuery->orderBy('users.name')->limit(500)->get();

            return response()->json([
                'by_class'   => $byClass,
                'by_arm'     => $byArm,
                'by_student' => $byStudent,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'by_class' => [], 'by_arm' => [], 'by_student' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /financial/revenue-chart
     * Monthly revenue for the last 12 months.
     */
    public function revenueChart(Request $request): JsonResponse
    {
        try {
            $rows = \App\Modules\Financial\Models\Payment::selectRaw(
                    "DATE_FORMAT(created_at, '%Y-%m') as month, SUM(amount) as collected, COUNT(*) as payments"
                )
                ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month');

            $months = [];
            for ($i = 11; $i >= 0; $i--) {
                $key    = now()->subMonths($i)->format('Y-m');
                $label  = now()->subMonths($i)->format('M Y');
                $row    = $rows->get($key);
                $months[] = [
                    'month'     => $key,
                    'label'     => $label,
                    'collected' => $row ? (float) $row->collected : 0,
                    'payments'  => $row ? (int)   $row->payments  : 0,
                ];
            }

            return response()->json(['data' => $months]);
        } catch (\Exception $e) {
            return response()->json(['data' => []]);
        }
    }

    /**
     * GET /financial/fee-types
     * Returns distinct fee types in use (used for dropdowns).
     */
    public function feeTypes(Request $request): JsonResponse
    {
        try {
            $defaults = [
                ['id' => 'tuition',      'name' => 'Tuition Fee',      'amount' => 0, 'description' => null],
                ['id' => 'library',      'name' => 'Library Fee',      'amount' => 0, 'description' => null],
                ['id' => 'lab',          'name' => 'Laboratory Fee',   'amount' => 0, 'description' => null],
                ['id' => 'sports',       'name' => 'Sports Fee',       'amount' => 0, 'description' => null],
                ['id' => 'development',  'name' => 'Development Levy', 'amount' => 0, 'description' => null],
                ['id' => 'pta',          'name' => 'PTA Levy',         'amount' => 0, 'description' => null],
                ['id' => 'examination',  'name' => 'Examination Fee',  'amount' => 0, 'description' => null],
                ['id' => 'boarding',     'name' => 'Boarding Fee',     'amount' => 0, 'description' => null],
                ['id' => 'uniform',      'name' => 'Uniform Fee',      'amount' => 0, 'description' => null],
                ['id' => 'other',        'name' => 'Other',            'amount' => 0, 'description' => null],
            ];

            // Also surface any custom types already in use
            $inUse = Fee::selectRaw('DISTINCT fee_type')->pluck('fee_type')->toArray();
            $defaultIds = array_column($defaults, 'id');
            foreach ($inUse as $type) {
                if (!in_array($type, $defaultIds)) {
                    $defaults[] = [
                        'id' => $type, 'name' => ucwords(str_replace('_', ' ', $type)),
                        'amount' => 0, 'description' => null,
                    ];
                }
            }

            return response()->json(['data' => $defaults]);
        } catch (\Exception $e) {
            return response()->json(['data' => []]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fee reminders
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Email a single fee's guardians (falling back to the student's own
     * email if there is no guardian on file) about the outstanding balance,
     * including the school's bank account details so they know where to pay.
     */
    public function remind(Request $request, int $fee): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $fee = Fee::with('student.guardians')->find($fee);
        if (! $fee) {
            return response()->json(['error' => 'Fee not found'], 404);
        }

        if ((float) $fee->balance <= 0) {
            return response()->json(['error' => 'This fee has no outstanding balance to remind about.'], 422);
        }

        $sent = $this->sendFeeReminder($fee);

        if ($sent === 0) {
            return response()->json([
                'error' => 'No email address found for this student or their guardians.',
            ], 422);
        }

        return response()->json([
            'message' => "Reminder sent to {$sent} recipient(s).",
            'fee' => $fee->fresh(),
        ]);
    }

    /**
     * Email every guardian (or student, as a fallback) with an outstanding
     * balance. Accepts the same filters as index()/feeBreakdown() so a
     * school can remind "everyone in JSS2" rather than the whole school;
     * pass explicit fee_ids to remind a hand-picked set instead.
     */
    public function remindBulk(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $school = $this->schoolFromRequest($request);

        $validator = Validator::make($request->all(), [
            'fee_ids'          => 'nullable|array|min:1',
            'fee_ids.*'        => 'integer|exists:fees,id',
            'class_id'         => 'nullable|exists:classes,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $query = Fee::with('student.guardians')
            ->where('school_id', $school?->id ?? 0)
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['paid', 'cancelled']);

        if ($request->filled('fee_ids')) {
            $query->whereIn('id', $request->fee_ids);
        } else {
            if ($request->filled('class_id')) {
                $query->where('class_id', $request->class_id);
            }
            if ($request->filled('academic_year_id')) {
                $query->where('academic_year_id', $request->academic_year_id);
            }
            if ($request->filled('term_id')) {
                $query->where('term_id', $request->term_id);
            }
        }

        $fees = $query->get();

        $feesReminded = 0;
        $emailsSent = 0;
        foreach ($fees as $fee) {
            $sent = $this->sendFeeReminder($fee);
            if ($sent > 0) {
                $feesReminded++;
                $emailsSent += $sent;
            }
        }

        return response()->json([
            'message' => "Reminded {$feesReminded} of {$fees->count()} outstanding fee(s), {$emailsSent} email(s) sent.",
            'fees_matched' => $fees->count(),
            'fees_reminded' => $feesReminded,
            'emails_sent' => $emailsSent,
        ]);
    }

    /**
     * Dispatch the reminder email(s) for one fee and stamp last_reminded_at.
     * Returns the number of recipients emailed (0 means nobody had an
     * address on file — caller decides how to report that).
     */
    private function sendFeeReminder(Fee $fee): int
    {
        $student = $fee->student;
        $recipients = collect();

        if ($student) {
            foreach ($student->guardians as $guardian) {
                if (! empty($guardian->email)) {
                    $recipients->push($guardian->email);
                }
            }
            if ($recipients->isEmpty() && ! empty($student->email)) {
                $recipients->push($student->email);
            }
        }
        $recipients = $recipients->unique()->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $studentName = $student?->full_name ?? 'your child';
        $balance = number_format((float) $fee->balance, 2);
        $dueDate = optional($fee->due_date)->format('d M Y') ?? 'N/A';

        $bankAccounts = SchoolBankAccount::where('school_id', $fee->school_id)
            ->orderByDesc('is_primary')
            ->get();

        $paymentInfo = $bankAccounts->isEmpty()
            ? ''
            : "\n\nPlease make payment to:\n" . $bankAccounts->map(fn ($a) => sprintf(
                "%s\n  Bank: %s\n  Account Name: %s\n  Account Number: %s",
                $a->is_primary ? '(Primary)' : '',
                $a->bank_name,
                $a->account_name,
                $a->account_number
            ))->implode("\n\n");

        $subject = "Fee Payment Reminder — {$fee->fee_type}";
        $body = "Dear Parent/Guardian,\n\n"
            . "This is a reminder that {$studentName}'s {$fee->fee_type} fee has an outstanding balance of "
            . "₦{$balance}, due {$dueDate}.{$paymentInfo}\n\n"
            . "If you have already made this payment, please disregard this message.\n\n"
            . "Thank you.";

        foreach ($recipients as $email) {
            SendEmailJob::dispatch($email, $subject, $body, [], [], (string) $fee->school_id, false, 'fee_reminder');
        }

        $fee->update(['last_reminded_at' => now()]);

        return $recipients->count();
    }

    /**
     * Resolve the acting school the same way the rest of this controller
     * does: an explicit school_id, falling back to the tenant's only school.
     */
    private function schoolFromRequest(Request $request): ?School
    {
        return School::find($request->school_id) ?? School::first();
    }
}
