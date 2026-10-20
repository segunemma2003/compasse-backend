<?php

namespace App\Support;

use App\Jobs\SendEmailJob;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Modules\Financial\Models\Fee;
use App\Modules\Financial\Models\Payment;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;

/**
 * Fee invoices and payment receipts: the HTML both print pages use, a PDF of it,
 * and emailing that PDF to the student's parents. The school's invoice NOTE terms
 * print on both, via FinanceDocument.
 */
class FeeDocuments
{
    private static function studentName(?Student $s): string
    {
        return $s?->full_name ?? trim(($s?->first_name ?? '') . ' ' . ($s?->last_name ?? '')) ?: 'N/A';
    }

    /** [session, term] names for a fee, else the current ones. */
    private static function sessionFor(?School $school, ?Fee $fee): array
    {
        [$session, $term] = FinanceDocument::currentSession($school);
        if ($fee?->academic_year_id) {
            $session = AcademicYear::find($fee->academic_year_id)?->name ?? $session;
        }
        if ($fee?->term_id) {
            $term = Term::find($fee->term_id)?->name ?? $term;
        }

        return [$session, $term];
    }

    public static function invoiceNumber(Fee $fee): string
    {
        return 'INV-F' . str_pad((string) $fee->id, 6, '0', STR_PAD_LEFT);
    }

    public static function receiptNumber(Payment $payment): string
    {
        return str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT);
    }

    /** Invoice for one fee: its items (or the fee itself), what's paid, and the balance. */
    public static function invoiceHtml(Fee $fee): string
    {
        $fee->loadMissing(['student.class', 'items']);
        $school = School::find($fee->school_id) ?? School::first();
        $student = $fee->student;
        [$session, $term] = self::sessionFor($school, $fee);

        $label = ucwords(str_replace('_', ' ', (string) $fee->fee_type));
        $rows = [];
        if ($fee->relationLoaded('items') && $fee->items->isNotEmpty()) {
            foreach ($fee->items as $item) {
                $amt = (float) $item->amount;
                $rows[] = ['description' => ($amt < 0 ? 'Less: ' : '') . $item->name, 'amount' => $amt];
            }
        } else {
            $rows[] = ['description' => $label . ($term ? " fees for {$term}" . ($session ? " {$session}" : '') : ' fees'), 'amount' => (float) $fee->amount];
        }

        $totals = [['Total Amount', (float) $fee->amount]];
        if ((float) $fee->amount_paid > 0) {
            $totals[] = ['Amount Paid', (float) $fee->amount_paid];
            $totals[] = ['Balance Due', max((float) $fee->balance, 0)];
        }

        return FinanceDocument::render(
            $school,
            'Invoice ' . self::invoiceNumber($fee),
            [
                ['Invoiced To', self::studentName($student)],
                ['Session', $session ?: '—'],
                ['Invoice No.', self::invoiceNumber($fee)],
                ['Details', $label],
            ],
            [
                ['Class', $student?->class?->name ?? '—'],
                ['Term', $term ?: '—'],
                ['Invoice Date', $fee->created_at?->format('d/m/Y') ?? date('d/m/Y')],
                ['Due Date', $fee->due_date ? date('d/m/Y', strtotime((string) $fee->due_date)) : '—'],
            ],
            $rows,
            $totals,
            // The fee's own note (from "Notes" when it was created), after the school-wide terms.
            preg_split('/\r?\n/', (string) $fee->description),
            match ($fee->status) {
                'paid' => 'PAID',
                'cancelled' => 'CANCELLED',
                default => null,
            },
        );
    }

    /** The receipt, laid out like the school's receipt book (ReceiptDocument). */
    public static function receiptHtml(Payment $payment): string
    {
        $payment->loadMissing(['student.class', 'fee']);
        $school = School::find($payment->school_id) ?? School::first();
        $student = $payment->student;
        $fee = $payment->fee;
        [$session, $term] = self::sessionFor($school, $fee);

        $feeLabel = $fee
            ? ($fee->description ? strtok($fee->description, "\n") : ucwords(str_replace('_', ' ', $fee->fee_type)) . ' fees')
            : 'School fees';
        if ($fee && $term) {
            $feeLabel .= ' for ' . $term . ($session ? ' ' . $session : '');
        }

        // Who paid: the parent when known, and always which child it was for.
        $child = self::studentName($student) . ($student?->class?->name ? ' (' . $student->class->name . ')' : '');
        $payer = null;
        if ($payment->guardian_id) {
            $g = \Illuminate\Support\Facades\DB::table('guardians')->where('id', $payment->guardian_id)->first(['first_name', 'last_name']);
            $payer = $g ? trim("{$g->first_name} {$g->last_name}") : null;
        }

        // Balance as it stood after this payment, so reprinting an old receipt still shows its own balance.
        $balance = null;
        if ($fee) {
            $paidUpTo = (float) \Illuminate\Support\Facades\DB::table('payments')->where('fee_id', $fee->id)->where('status', 'confirmed')
                ->where('id', '<=', $payment->id)->sum('amount');
            $balance = max((float) $fee->amount - $paidUpTo, 0);
        }

        $cashier = null;
        if (! empty($payment->recorded_by)) {
            $cashier = \Illuminate\Support\Facades\DB::table('users')->where('id', $payment->recorded_by)->value('name');
        }

        return ReceiptDocument::render($school, [
            'number' => self::receiptNumber($payment),
            'date' => $payment->payment_date ? date('d/m/Y', strtotime((string) $payment->payment_date)) : date('d/m/Y'),
            'received_from' => $payer ? "{$payer} — for {$child}" : $child,
            'words' => ReceiptDocument::words((float) $payment->amount),
            'payment_for' => $feeLabel,
            'deposit' => (float) $payment->amount,
            'balance' => $balance,
            'amount' => (float) $payment->amount,
            'cashier' => $cashier,
            'method' => $payment->payment_method ? ucwords(str_replace('_', ' ', $payment->payment_method)) : null,
            'reference' => $payment->payment_reference,
            'paid_into' => $payment->paid_into,
            'stamp' => $payment->status === 'confirmed' ? 'PAID' : strtoupper((string) $payment->status),
            'notes' => $payment->notes,
        ]);
    }

    /** A PDF download response for an invoice/receipt page. */
    public static function pdfResponse(string $html, string $filename): \Illuminate\Http\Response
    {
        return response(self::pdf($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public static function pdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);       // school logo URL
        $options->set('defaultFont', 'DejaVu Sans');   // has the ₦ sign
        $dompdf = new Dompdf($options);
        // The print templates' fonts have no ₦; DejaVu does (serif for invoices, sans for the receipt).
        $sans = str_contains($html, 'data-font="sans"');
        $font = '<style>*{font-family:"DejaVu ' . ($sans ? 'Sans' : 'Serif') . '",' . ($sans ? 'sans-serif' : 'serif') . ' !important}'
            . ($sans ? '.no{font-family:"DejaVu Sans Mono",monospace !important}' : '') . '</style>';
        $html = str_contains($html, '</head>') ? str_replace('</head>', $font . '</head>', $html) : $font . $html;
        $dompdf->loadHtml($html, 'UTF-8');
        // Receipts are receipt-book size (A5 landscape); invoices A4.
        str_contains($html, 'data-paper="a5l"') ? $dompdf->setPaper('A5', 'landscape') : $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /** Parents' real email addresses for a student (the student's own as a fallback). */
    public static function recipients(?Student $student): array
    {
        if (! $student) {
            return [];
        }
        $student->loadMissing('guardians');
        $emails = $student->guardians->pluck('email')->filter()->all();
        if (! $emails && $student->email) {
            $emails = [$student->email];
        }

        return array_values(array_unique(array_filter($emails, fn ($e) => EmailAddress::isReal($e) && ! EmailAddress::isPlaceholder($e))));
    }

    private static function portalLink(): string
    {
        try {
            $sub = tenant()?->subdomain;
        } catch (\Throwable) {
            $sub = null;
        }

        return $sub ? 'https://' . $sub . '.' . config('app.root_domain', 'compasse.net') . '/school/ward-fees' : '';
    }

    /** Email the fee's invoice (PDF attached) to the parents. Returns how many were sent. */
    public static function emailInvoice(Fee $fee): int
    {
        // Never let emailing break creating the fee.
        try {
            return self::sendInvoice($fee);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    private static function sendInvoice(Fee $fee): int
    {
        $fee->loadMissing('student');
        $to = self::recipients($fee->student);
        if (! $to) {
            return 0;
        }
        try {
            $pdf = self::pdf(self::invoiceHtml($fee));
        } catch (\Throwable $e) {
            Log::error('Invoice PDF failed', ['fee_id' => $fee->id, 'error' => $e->getMessage()]);
            $pdf = null;
        }
        $school = School::find($fee->school_id) ?? School::first();
        $name = self::studentName($fee->student);
        $link = self::portalLink();
        $body = "Dear Parent/Guardian,\n\n"
            . "Please find attached invoice " . self::invoiceNumber($fee) . " for {$name}: "
            . ucwords(str_replace('_', ' ', (string) $fee->fee_type)) . ', ₦' . number_format((float) $fee->balance, 2) . ' due'
            . ($fee->due_date ? ' by ' . date('d M Y', strtotime((string) $fee->due_date)) : '') . ".\n\n"
            . ($link ? "You can view and pay it online on the parent portal: {$link}\n\n" : '')
            . "Thank you,\n" . ($school?->name ?? '');

        foreach ($to as $email) {
            SendEmailJob::dispatch($email, 'Invoice ' . self::invoiceNumber($fee) . " — {$name}", $body, [], [],
                (string) $fee->school_id, false, 'fee_invoice',
                $pdf ? [['name' => self::invoiceNumber($fee) . '.pdf', 'data' => base64_encode($pdf), 'mime' => 'application/pdf']] : []);
        }

        return count($to);
    }

    /** Email the payment's receipt (PDF attached) to the parents. Returns how many were sent. */
    public static function emailReceipt(Payment $payment): int
    {
        // Never let emailing break recording the payment.
        try {
            return self::sendReceipt($payment);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    private static function sendReceipt(Payment $payment): int
    {
        $payment->loadMissing('student');
        $to = self::recipients($payment->student);
        if (! $to) {
            return 0;
        }
        try {
            $pdf = self::pdf(self::receiptHtml($payment));
        } catch (\Throwable $e) {
            Log::error('Receipt PDF failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            $pdf = null;
        }
        $school = School::find($payment->school_id) ?? School::first();
        $name = self::studentName($payment->student);
        $body = "Dear Parent/Guardian,\n\n"
            . 'We have received ₦' . number_format((float) $payment->amount, 2) . " for {$name}. Thank you.\n"
            . 'Your receipt No. ' . self::receiptNumber($payment) . " is attached.\n\n"
            . ($school?->name ?? '');

        foreach ($to as $email) {
            SendEmailJob::dispatch($email, 'Payment receipt No. ' . self::receiptNumber($payment) . " — {$name}", $body, [], [],
                (string) $payment->school_id, false, 'fee_receipt',
                $pdf ? [['name' => 'Receipt-' . self::receiptNumber($payment) . '.pdf', 'data' => base64_encode($pdf), 'mime' => 'application/pdf']] : []);
        }

        return count($to);
    }
}
