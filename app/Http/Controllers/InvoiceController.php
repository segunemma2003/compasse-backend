<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\School;
use App\Models\SchoolSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Invoice management controller.
 *
 * Handles creating, reading, updating, and cancelling invoices.
 * The printInvoice() method returns a print-ready HTML page with school logo and signatures.
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

        $query = Invoice::with(['student', 'guardian', 'items'])
            ->where('school_id', $school?->id ?? 0);

        if ($request->filled('status'))     { $query->where('status', $request->status); }
        if ($request->filled('student_id')) { $query->where('student_id', $request->student_id); }
        if ($request->filled('search')) {
            $query->where('invoice_number', 'like', '%' . $request->search . '%');
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
        $school = $this->school($request);

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

    public function update(Request $request, int $id): JsonResponse
    {
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

    // ─────────────────────────────────────────────────────────────────────────
    // Print-ready HTML (opens in browser → Ctrl+P to save as PDF)
    // ─────────────────────────────────────────────────────────────────────────

    public function printInvoice(int $id): Response
    {
        $invoice = Invoice::with(['student', 'guardian', 'items', 'school', 'createdBy'])->findOrFail($id);

        $school     = $invoice->school;
        $signatures = $school ? SchoolSignature::activeForSchool($school->id) : collect();
        $logoHtml   = $school?->logo
            ? '<img src="' . e($school->logo) . '" style="max-height:70px;max-width:160px;" alt="logo">'
            : '<div style="font-size:22px;font-weight:bold;">' . e($school?->name ?? 'School') . '</div>';

        // Items rows
        $itemRows = '';
        foreach ($invoice->items as $item) {
            $desc  = e($item->description);
            $qty   = number_format($item->quantity, 2);
            $price = number_format($item->unit_price, 2);
            $total = number_format($item->total_price, 2);
            $itemRows .= "<tr><td>{$desc}</td><td style='text-align:center;'>{$qty}</td><td style='text-align:right;'>₦{$price}</td><td style='text-align:right;'>₦{$total}</td></tr>";
        }

        // Signatures
        $sigHtml = '';
        foreach ($signatures as $role => $sig) {
            $sigName = e($sig->name);
            $sigRole = e(ucwords(str_replace('_', ' ', $role)));
            $sigUrl  = $sig->signature_url;
            $sigImg  = $sigUrl
                ? "<img src=\"{$sigUrl}\" style=\"max-height:55px;max-width:140px;\" alt=\"{$sigRole}\">"
                : '<div style="border-bottom:1px solid #333;width:140px;height:55px;"></div>';
            $sigHtml .= "<div style='text-align:center;min-width:160px;'>{$sigImg}<div style='font-size:11px;margin-top:4px;'>{$sigName}</div><div style='font-size:10px;color:#666;'>{$sigRole}</div></div>";
        }
        if (! $sigHtml) {
            $sigHtml = '<div style="border-bottom:1px solid #333;width:160px;height:55px;margin:auto;"></div><div style="font-size:11px;text-align:center;margin-top:4px;">Authorised Signatory</div>';
        }

        $invNum      = e($invoice->invoice_number);
        $invDate     = $invoice->invoice_date?->format('d M Y') ?? '';
        $dueDate     = $invoice->due_date?->format('d M Y') ?? '';
        $studentName = e($invoice->student?->full_name ?? 'N/A');
        $schoolName  = e($school?->name ?? '');
        $schoolAddr  = e($school?->address ?? '');
        $schoolPhone = e($school?->phone ?? '');
        $schoolEmail = e($school?->email ?? '');
        $subtotal    = number_format($invoice->subtotal, 2);
        $tax         = number_format($invoice->tax_amount, 2);
        $discount    = number_format($invoice->discount_amount, 2);
        $total       = number_format($invoice->total_amount, 2);
        $notes       = e($invoice->notes ?? '');
        $status      = strtoupper($invoice->status);
        $statusColor = match ($invoice->status) {
            'paid'      => '#16a34a',
            'overdue'   => '#dc2626',
            'cancelled' => '#9ca3af',
            default     => '#2563eb',
        };

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice {$invNum}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; padding: 24px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1a3a6b; padding-bottom: 14px; margin-bottom: 18px; }
  .school-info h2 { font-size: 18px; color: #1a3a6b; margin-bottom: 4px; }
  .school-info p { font-size: 11px; color: #555; }
  .invoice-meta { text-align: right; }
  .invoice-meta h1 { font-size: 28px; color: #1a3a6b; letter-spacing: 2px; }
  .invoice-meta .number { font-size: 12px; color: #444; margin-top: 4px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; color: #fff; background: {$statusColor}; margin-top: 6px; }
  .bill-to { margin-bottom: 18px; }
  .bill-to h4 { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
  .bill-to p { font-size: 13px; font-weight: bold; }
  .meta-row { display: flex; gap: 40px; margin-bottom: 18px; }
  .meta-item { }
  .meta-item .lbl { font-size: 10px; color: #888; text-transform: uppercase; }
  .meta-item .val { font-size: 12px; font-weight: bold; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  th { background: #1a3a6b; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; }
  td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
  tr:nth-child(even) td { background: #f8faff; }
  .totals { margin-left: auto; width: 260px; margin-bottom: 18px; }
  .totals table { margin: 0; }
  .totals td { padding: 5px 8px; }
  .totals .grand td { font-weight: bold; font-size: 13px; background: #1a3a6b; color: #fff; }
  .notes { margin-bottom: 18px; font-size: 11px; color: #555; }
  .notes strong { color: #111; display: block; margin-bottom: 3px; }
  .signatures { display: flex; gap: 40px; flex-wrap: wrap; margin-top: 24px; padding-top: 14px; border-top: 1px solid #ddd; }
  @media print { body { padding: 0; } @page { margin: 1.5cm; } }
</style>
</head>
<body>

<div class="header">
  <div class="school-info">
    {$logoHtml}
    <p style="margin-top:6px;">{$schoolAddr}</p>
    <p>{$schoolPhone} &nbsp;|&nbsp; {$schoolEmail}</p>
  </div>
  <div class="invoice-meta">
    <h1>INVOICE</h1>
    <div class="number">{$invNum}</div>
    <div class="badge">{$status}</div>
  </div>
</div>

<div class="bill-to">
  <h4>Bill To</h4>
  <p>{$studentName}</p>
</div>

<div class="meta-row">
  <div class="meta-item"><div class="lbl">Invoice Date</div><div class="val">{$invDate}</div></div>
  <div class="meta-item"><div class="lbl">Due Date</div><div class="val">{$dueDate}</div></div>
</div>

<table>
  <thead><tr><th>Description</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Unit Price</th><th style="text-align:right;">Total</th></tr></thead>
  <tbody>{$itemRows}</tbody>
</table>

<div class="totals">
  <table>
    <tr><td>Subtotal</td><td style="text-align:right;">₦{$subtotal}</td></tr>
    <tr><td>Tax</td><td style="text-align:right;">₦{$tax}</td></tr>
    <tr><td>Discount</td><td style="text-align:right;">-₦{$discount}</td></tr>
    <tr class="grand"><td>TOTAL</td><td style="text-align:right;">₦{$total}</td></tr>
  </table>
</div>

HTML;
        if ($notes) {
            $html .= "<div class=\"notes\"><strong>Notes</strong>{$notes}</div>";
        }
        $html .= "<div class=\"signatures\">{$sigHtml}</div>";
        $html .= '<script>window.onload = function() { window.print(); }</script>';
        $html .= '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
