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
        return 'RCP-' . str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT);
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
                $rows[] = ['description' => $item->name, 'amount' => (float) $item->amount];
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

    public static function receiptHtml(Payment $payment): string
    {
        $payment->loadMissing(['student.class', 'fee']);
        $school = School::find($payment->school_id) ?? School::first();
        $student = $payment->student;
        $fee = $payment->fee;
        [$session, $term] = self::sessionFor($school, $fee);

        $feeLabel = $fee
            ? ($fee->description ? strtok($fee->description, "\n") : ucwords(str_replace('_', ' ', $fee->fee_type)) . ' fees')
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

        $no = self::receiptNumber($payment);

        return FinanceDocument::render(
            $school,
            'Receipt ' . $no,
            [
                ['Received From', self::studentName($student)],
                ['Session', $session ?: '—'],
                ['Receipt No.', $no],
                ['Payment Method', ucwords(str_replace('_', ' ', $payment->payment_method ?? ''))],
            ],
            [
                ['Class', $student?->class?->name ?? '—'],
                ['Term', $term ?: '—'],
                ['Payment Date', $payment->payment_date ? date('d/m/Y', strtotime((string) $payment->payment_date)) : date('d/m/Y')],
                ['Reference', $payment->payment_reference ?? '—'],
            ],
            $rows,
            $totals,
            [],
            $payment->status === 'confirmed' ? 'PAID' : strtoupper($payment->status ?? ''),
            'Payment Receipt',
        );
    }

    public static function pdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);       // school logo URL
        $options->set('defaultFont', 'DejaVu Sans');   // has the ₦ sign
        $dompdf = new Dompdf($options);
        // The print template's fonts have no ₦; DejaVu Serif does and keeps the serif look.
        $font = '<style>*{font-family:"DejaVu Serif",serif !important}</style>';
        $html = str_contains($html, '</head>') ? str_replace('</head>', $font . '</head>', $html) : $font . $html;
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
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
            . 'Your receipt ' . self::receiptNumber($payment) . " is attached.\n\n"
            . ($school?->name ?? '');

        foreach ($to as $email) {
            SendEmailJob::dispatch($email, 'Payment receipt ' . self::receiptNumber($payment) . " — {$name}", $body, [], [],
                (string) $payment->school_id, false, 'fee_receipt',
                $pdf ? [['name' => self::receiptNumber($payment) . '.pdf', 'data' => base64_encode($pdf), 'mime' => 'application/pdf']] : []);
        }

        return count($to);
    }
}
