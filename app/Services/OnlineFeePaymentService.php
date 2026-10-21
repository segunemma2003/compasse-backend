<?php

namespace App\Services;

use App\Models\OnlinePaymentIntent;
use App\Models\School;
use App\Modules\Financial\Models\Fee;
use App\Modules\Financial\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Confirms an online fee payment with PayHub and records it against the
 * fee. One place for every path that can confirm a payment: the payer's
 * return page, the finance "Refresh" button, and the scheduled reconcile
 * (payments:reconcile-online) for payers who never came back to the app.
 */
class OnlineFeePaymentService
{
    public function __construct(private PayHubService $payhub)
    {
    }

    /**
     * Asks PayHub for the charge's status and settles the intent if paid.
     * Returns "success", "failed" or "pending" (still awaiting the payer or
     * the provider). Safe to call repeatedly — a settled intent is a no-op.
     */
    public function confirm(OnlinePaymentIntent $intent, ?int $guardianId = null): string
    {
        if ($intent->status === 'success' && $intent->payment_id) {
            return 'success';
        }

        $school = School::find($intent->school_id);
        if (! $school) {
            throw new \RuntimeException('School not found');
        }

        $verified = $this->payhub->verifyCharge($school, $intent->reference);

        if ($verified['status'] === 'failed') {
            $intent->update(['status' => 'failed']);

            return 'failed';
        }
        if ($verified['status'] !== 'success') {
            return 'pending';
        }

        $this->markPaid($intent, $guardianId);

        return 'success';
    }

    /** Records the payment and applies it to the fee's running totals, once. */
    public function markPaid(OnlinePaymentIntent $intent, ?int $guardianId = null): Payment
    {
        return DB::transaction(function () use ($intent, $guardianId) {
            // Lock the intent so two confirmations racing (return page +
            // reconcile) can't both record the payment.
            $intent = OnlinePaymentIntent::whereKey($intent->id)->lockForUpdate()->first();
            if ($intent->status === 'success' && $intent->payment_id) {
                return Payment::find($intent->payment_id);
            }

            $meta = $intent->meta ?? [];
            $fee  = $intent->fee_id ? Fee::find($intent->fee_id) : null;

            $payment = Payment::create([
                'school_id'         => $intent->school_id,
                'student_id'        => $intent->student_id,
                'fee_id'            => $intent->fee_id,
                'guardian_id'       => $guardianId,
                'amount'            => $intent->amount,
                'payment_method'    => 'online',
                'payment_reference' => $intent->reference,
                'payment_date'      => now(),
                'status'            => 'confirmed',
                'notes'             => trim('PayHub online payment. ' . ($meta['reason'] ?? '') . (isset($meta['tracking_id']) ? " [{$meta['tracking_id']}]" : '')
                    . (! empty($meta['service_charge']) ? ' (paid ₦' . number_format((float) ($meta['total_charged'] ?? 0), 2) . ' incl. ₦' . number_format((float) $meta['service_charge'], 2) . ' service charge)' : '')),
            ]);

            if ($fee) {
                $newAmountPaid = round((float) $fee->amount_paid + (float) $intent->amount, 2);
                $newBalance = max(0, round((float) $fee->amount - $newAmountPaid, 2));
                $fee->update([
                    'amount_paid' => $newAmountPaid,
                    'balance'     => $newBalance,
                    'status'      => $newBalance <= 0 ? 'paid' : 'partial',
                ]);
            }

            $intent->update(['status' => 'success', 'payment_id' => $payment->id]);

            // Receipt to the parents once the payment is safely recorded.
            DB::afterCommit(fn () => \App\Support\FeeDocuments::emailReceipt($payment));

            return $payment;
        });
    }

    /**
     * Re-checks this school's pending online payments (newest first, last
     * 30 days). Returns counts per outcome.
     *
     * @return array{success:int, failed:int, pending:int, errors:int}
     */
    public function reconcilePending(int $limit = 100): array
    {
        $counts = ['success' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];

        $intents = OnlinePaymentIntent::where('status', 'pending')
            ->where('provider', 'payhub')
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->limit($limit)
            ->get();

        foreach ($intents as $intent) {
            try {
                $counts[$this->confirm($intent)]++;
            } catch (\Throwable $e) {
                $counts['errors']++;
                Log::warning('Online payment reconcile failed', ['reference' => $intent->reference, 'error' => $e->getMessage()]);
            }
        }

        return $counts;
    }
}
