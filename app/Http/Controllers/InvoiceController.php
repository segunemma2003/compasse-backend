<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\School;
use App\Support\FinanceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Invoice management controller.
 *
 * Handles creating, reading, updating, and cancelling invoices.
 * The printInvoice() method returns a print-ready HTML page in the school's letter-headed invoice layout.
 */
class InvoiceController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $school = $this->school($request);

        \App\Support\FeeInvoices::flush();
        $query = Invoice::with(['student.class', 'guardian', 'items'])
            ->where('school_id', $school?->id ?? 0);

        if ($request->filled('status'))     { $query->where('status', $request->status); }
        if ($request->filled('student_id')) { $query->where('student_id', $request->student_id); }
        if ($request->filled('class_id')) { $query->whereHas('student', fn ($q) => $q->where('class_id', $request->class_id)); }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(fn ($w) => $w->where('invoice_number', 'like', $s)
                ->orWhereHas('student', fn ($q) => $q->where('first_name', 'like', $s)->orWhere('last_name', 'like', $s)->orWhere('admission_number', 'like', $s)));
        }

        return response()->json(
            $query->orderByDesc('invoice_date')->paginate($request->get('per_page', 20))
        );
    }

    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with(['student', 'guardian', 'items', 'school', 'createdBy'])->findOrFail($id);

        return response()->json(['invoice' => $invoice]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $school = $this->school($request);

        // One student, or a whole class (optionally some arms): each student is billed a fee, and its
        // invoice is created from it — so it shows under Fees and Invoices and can be paid online.
        if (! $request->filled('student_id') && $request->filled('class_ids')) {
            return $this->storeForClasses($request, $school);
        }
        if ($request->boolean('as_fee', true) && \App\Support\FeeInvoices::ready()) {
            return $this->storeForClasses($request, $school, [(int) $request->input('student_id')]);
        }

        $validated = $request->validate([
            'student_id'       => 'required|exists:students,id',
            'guardian_id'      => 'nullable|exists:guardians,id',
            'invoice_date'     => 'required|date',
            'due_date'         => 'required|date|after_or_equal:invoice_date',
            'payment_terms'    => 'nullable|string|max:255',
            'notes'            => 'nullable|string',
            'discount_amount'  => 'nullable|numeric|min:0',
            'tax_amount'       => 'nullable|numeric|min:0',
            'billing_address'  => 'nullable|array',
            'items'            => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity'    => 'required|numeric|min:0',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $invoice = DB::transaction(function () use ($validated, $school) {
            $subtotal = collect($validated['items'])->sum(
                fn ($i) => (float) $i['quantity'] * (float) $i['unit_price']
            );

            $invoice = Invoice::create([
                'school_id'       => $school?->id,
                'student_id'      => $validated['student_id'],
                'guardian_id'     => $validated['guardian_id'] ?? null,
                'invoice_number'  => Invoice::generateInvoiceNumber($school?->id ?? 0),
                'invoice_date'    => $validated['invoice_date'],
                'due_date'        => $validated['due_date'],
                'subtotal'        => $subtotal,
                'tax_amount'      => $validated['tax_amount'] ?? 0,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'total_amount'    => $subtotal + ($validated['tax_amount'] ?? 0) - ($validated['discount_amount'] ?? 0),
                'status'          => 'draft',
                'payment_terms'   => $validated['payment_terms'] ?? null,
                'notes'           => $validated['notes'] ?? null,
                'billing_address' => $validated['billing_address'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'total_price' => (float) $item['quantity'] * (float) $item['unit_price'],
                ]);
            }

            return $invoice;
        });

        return response()->json([
            'message' => 'Invoice created',
            'invoice' => $invoice->load(['student', 'items']),
        ], 201);
    }

    /** Bill students (given, or everyone in the classes / arms) a fee each; the invoices follow. */
    private function storeForClasses(Request $request, ?School $school, ?array $studentIds = null): JsonResponse
    {
        $v = $request->validate([
            'student_id' => $studentIds ? 'required|exists:students,id' : 'nullable',
            'class_ids' => $studentIds ? 'nullable|array' : 'required|array|min:1', 'class_ids.*' => 'exists:classes,id',
            'arm_ids' => 'nullable|array', 'arm_ids.*' => 'integer',
            'title' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date', 'due_date' => 'required|date', 'notes' => 'nullable|string',
            'items' => 'required|array|min:1', 'items.*.description' => 'required|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0', 'items.*.unit_price' => 'required|numeric',
            'term_id' => 'nullable|integer', 'academic_year_id' => 'nullable|integer',
        ]);
        $ids = $studentIds ?? \App\Support\FeeInvoices::studentsIn($v['class_ids'], $v['arm_ids'] ?? []);
        if (! $ids) {
            return response()->json(['error' => 'No active students found in the chosen class(es).'], 422);
        }
        $lines = array_map(fn ($i) => ['name' => $i['description'] . ((float) $i['quantity'] > 1 ? ' (x' . rtrim(rtrim(number_format((float) $i['quantity'], 2), '0'), '.') . ')' : ''),
            'amount' => round((float) $i['quantity'] * (float) $i['unit_price'], 2)], $v['items']);
        if (array_sum(array_column($lines, 'amount')) < 0) {
            return response()->json(['error' => 'The total cannot be below zero.'], 422);
        }
        $title = $v['title'] ?: (count($lines) === 1 ? $lines[0]['name'] : 'School fees');
        $made = \App\Support\FeeInvoices::bill($ids, $lines, $title, $v['due_date'], $v['notes'] ?? null, $v['term_id'] ?? null, $v['academic_year_id'] ?? null);
        $invoiceIds = DB::table('invoices')->whereIn('fee_id', $made)->pluck('id')->all();
        $emailed = $request->boolean('send_invoice', false) ? array_sum(array_map(fn ($fid) => \App\Support\FeeDocuments::emailInvoice(\App\Modules\Financial\Models\Fee::find($fid)), $made)) : 0;

        return response()->json([
            'message' => count($made) === 1 ? 'Invoice created' : count($made) . ' invoices created' . ($emailed ? " — emailed to {$emailed} parent(s)" : ''),
            'invoice_ids' => array_values(array_filter($invoiceIds)), 'count' => count($made),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $invoice = Invoice::findOrFail($id);

        if (in_array($invoice->status, ['paid', 'cancelled'], true)) {
            return response()->json(['error' => 'Cannot edit a paid or cancelled invoice.'], 422);
        }

        $validated = $request->validate([
            'due_date'        => 'sometimes|date',
            'payment_terms'   => 'nullable|string|max:255',
            'notes'           => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount'      => 'nullable|numeric|min:0',
            'status'          => 'sometimes|in:draft,sent,overdue',
        ]);

        // Recalculate total if tax or discount changed
        if (isset($validated['tax_amount']) || isset($validated['discount_amount'])) {
            $validated['total_amount'] = $invoice->subtotal
                + ($validated['tax_amount']      ?? (float) $invoice->tax_amount)
                - ($validated['discount_amount'] ?? (float) $invoice->discount_amount);
        }

        if (($validated['status'] ?? null) === 'sent' && ! $invoice->sent_at) {
            $validated['sent_at'] = now();
        }

        $invoice->update($validated);

        return response()->json([
            'message' => 'Invoice updated',
            'invoice' => $invoice->fresh(['student', 'items']),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $invoice = Invoice::findOrFail($id);

        if ($invoice->status === 'paid') {
            return response()->json(['error' => 'Cannot cancel a paid invoice.'], 422);
        }

        $invoice->update([
            'status'               => 'cancelled',
            'cancelled_at'         => now(),
            'cancellation_reason'  => $request->input('reason'),
        ]);

        return response()->json(['message' => 'Invoice cancelled', 'invoice' => $invoice->fresh()]);
    }

    /** DELETE /financial/invoices/{id} — school administrator only, with a reason; kept in the recycle bin. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! in_array($request->user()?->role, ['super_admin', 'school_admin'], true)) {
            return $this->forbiddenResponse('Only the school administrator can delete an invoice.');
        }
        $invoice = Invoice::findOrFail($id);
        $reason = trim((string) $request->input('reason', ''));
        // A fee's invoice: delete the fee itself (unless money has been paid against it).
        if ($invoice->fee_id && ($fee = \App\Modules\Financial\Models\Fee::find($invoice->fee_id))) {
            if ((float) $fee->amount_paid > 0) {
                return response()->json(['error' => 'Payments have been made on this invoice. Delete the payments first (school administrator only), then the invoice.'], 422);
            }
            $fee->items()->delete();
            $fee->delete();
        }
        DB::transaction(function () use ($invoice) {
            InvoiceItem::where('invoice_id', $invoice->id)->delete();
            $invoice->delete();
        });
        \App\Support\AuditLog::record('invoice', $id, 'deleted', 'Deleted invoice ' . ($invoice->invoice_number ?? "#{$id}") . ' (₦' . number_format((float) ($invoice->total_amount ?? 0), 2) . ')' . ($reason ? " — {$reason}" : ''));

        return response()->json(['message' => 'Invoice deleted. You can restore it from the Recycle Bin.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Print-ready HTML (opens in browser → Ctrl+P to save as PDF)
    // ─────────────────────────────────────────────────────────────────────────

    public function printInvoice(int $id): Response
    {
        $invoice = Invoice::with(['student.class', 'items', 'school'])->findOrFail($id);
        $school  = $invoice->school ?? School::first();
        $student = $invoice->student;

        [$session, $term] = FinanceDocument::currentSession($school);

        $rows = [];
        foreach ($invoice->items as $item) {
            $qty  = (float) $item->quantity;
            $desc = $item->description . ($qty > 1 ? ' (x' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . ')' : '');
            $rows[] = ['description' => $desc, 'amount' => (float) $item->total_price];
        }
        if ((float) $invoice->tax_amount > 0) {
            $rows[] = ['description' => 'Tax', 'amount' => (float) $invoice->tax_amount];
        }
        if ((float) $invoice->discount_amount > 0) {
            $rows[] = ['description' => 'Discount', 'amount' => -(float) $invoice->discount_amount];
        }

        $totals = [['Total Amount', (float) $invoice->total_amount]];
        $paid   = $invoice->getPaidAmount();
        if ($paid > 0) {
            $totals[] = ['Amount Paid', $paid];
            $totals[] = ['Balance Due', max($invoice->getOutstandingAmount(), 0)];
        }

        $html = FinanceDocument::render(
            $school,
            'Invoice ' . $invoice->invoice_number,
            [
                ['Invoiced To', $student?->full_name ?? trim(($student?->first_name ?? '') . ' ' . ($student?->last_name ?? '')) ?: 'N/A'],
                ['Session', $session ?: '—'],
                ['Invoice No.', $invoice->invoice_number],
                ['Details', $invoice->payment_terms ?: 'School Fees'],
            ],
            [
                ['Class', $student?->class?->name ?? '—'],
                ['Term', $term ?: '—'],
                ['Invoice Date', $invoice->invoice_date?->format('d/m/Y') ?? ''],
                ['Due Date', $invoice->due_date?->format('d/m/Y') ?? ''],
            ],
            $rows,
            $totals,
            // This invoice's own instructions (one per line), printed after
            // the school-wide NOTE terms.
            preg_split('/\r?\n/', (string) $invoice->notes),
            match ($invoice->status) {
                'paid'      => 'PAID',
                'cancelled' => 'CANCELLED',
                default     => null,
            },
        );

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
