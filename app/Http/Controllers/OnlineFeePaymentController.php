<?php

namespace App\Http\Controllers;

use App\Models\OnlinePaymentIntent;
use App\Models\Student;
use App\Modules\Financial\Models\Fee;
use App\Modules\Financial\Models\Payment;
use App\Services\FlutterwaveService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OnlineFeePaymentController extends Controller
{
    public function gatewayConfig(PaystackService $paystack, FlutterwaveService $flutterwave): JsonResponse
    {
        $default = config('services.payments.default_provider', 'paystack');

        return response()->json([
            'default_provider'    => $default,
            'paystack_enabled'    => $paystack->isConfigured(),
            'paystack_public_key' => $paystack->isConfigured() ? config('services.paystack.public_key') : null,
            'flutterwave_enabled' => $flutterwave->isConfigured(),
            'flutterwave_public_key' => $flutterwave->isConfigured() ? config('services.flutterwave.public_key') : null,
            'currency'            => config('services.paystack.currency', 'NGN'),
            // legacy keys for older frontend
            'public_key'          => $paystack->isConfigured() ? config('services.paystack.public_key') : null,
        ]);
    }

    public function initialize(Request $request, PaystackService $paystack, FlutterwaveService $flutterwave): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fee_id'     => 'required|exists:fees,id',
            'amount'     => 'required|numeric|min:100',
            'student_id' => 'required|exists:students,id',
            'provider'   => 'nullable|in:paystack,flutterwave',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        $provider = $request->input('provider', config('services.payments.default_provider', 'paystack'));
        if ($provider === 'paystack' && ! $paystack->isConfigured()) {
            $provider = $flutterwave->isConfigured() ? 'flutterwave' : null;
        }
        if ($provider === 'flutterwave' && ! $flutterwave->isConfigured()) {
            $provider = $paystack->isConfigured() ? 'paystack' : null;
        }
        if (! $provider) {
            return response()->json(['error' => 'Online payments are not enabled for this school'], 503);
        }

        $this->assertCanPayForStudent((int) $request->student_id);

        $fee = Fee::findOrFail($request->fee_id);
        if ((int) $fee->student_id !== (int) $request->student_id) {
            return response()->json(['error' => 'Fee does not belong to this student'], 422);
        }

        $remaining = (float) $fee->getRemainingAmount();
        $amount    = (float) $request->amount;
        if ($amount > $remaining + 0.01) {
            return response()->json(['error' => 'Amount exceeds fee balance'], 422);
        }

        $student = Student::with('user')->findOrFail($request->student_id);
        $email   = $student->email ?? $student->user?->email ?? Auth::user()->email;
        if (! $email) {
            return response()->json(['error' => 'No email on file for payment receipt'], 422);
        }

        $reference = $provider === 'flutterwave'
            ? FlutterwaveService::generateReference()
            : PaystackService::generateReference();

        $school = $request->attributes->get('school') ?? \App\Models\School::first();

        OnlinePaymentIntent::create([
            'school_id'  => $school?->id ?? $fee->school_id,
            'student_id' => $student->id,
            'fee_id'     => $fee->id,
            'amount'     => $amount,
            'reference'  => $reference,
            'provider'   => $provider,
            'status'     => 'pending',
            'meta'       => ['initiated_by' => Auth::id()],
        ]);

        if ($provider === 'flutterwave') {
            $checkout = $flutterwave->initialize($amount, $email, $reference, [
                'fee_id'     => $fee->id,
                'student_id' => $student->id,
            ]);

            return response()->json([
                'provider'          => 'flutterwave',
                'authorization_url' => $checkout['link'],
                'reference'         => $checkout['reference'],
            ]);
        }

        $checkout = $paystack->initialize($amount, $email, $reference, [
            'fee_id'     => $fee->id,
            'student_id' => $student->id,
            'subdomain'  => Config::get('tenant.subdomain') ?? $request->header('X-Subdomain'),
        ]);

        return response()->json([
            'provider'          => 'paystack',
            'authorization_url' => $checkout['authorization_url'],
            'reference'         => $checkout['reference'],
            'access_code'       => $checkout['access_code'],
        ]);
    }

    public function verify(Request $request, PaystackService $paystack, FlutterwaveService $flutterwave): JsonResponse
    {
        $reference = $request->input('reference');
        $transactionId = $request->input('transaction_id');

        if (! $reference && ! $transactionId) {
            return response()->json(['error' => 'reference or transaction_id is required'], 422);
        }

        $intent = $reference
            ? OnlinePaymentIntent::where('reference', $reference)->first()
            : null;

        if ($intent) {
            $this->assertCanPayForStudent((int) $intent->student_id);

            if ($intent->status === 'success' && $intent->payment_id) {
                return response()->json([
                    'status'  => 'success',
                    'payment' => Payment::find($intent->payment_id),
                ]);
            }
        }

        $provider = $intent?->provider ?? $request->input('provider', 'paystack');
        $ok       = false;
        $ref      = $reference ?? $intent?->reference;

        if ($provider === 'flutterwave' && $transactionId) {
            $verified = $flutterwave->verifyByTransactionId($transactionId);
            $ok       = $verified['status'] === 'success';
            $ref      = $verified['reference'] ?: $ref;
            if (! $intent && $ref) {
                $intent = OnlinePaymentIntent::where('reference', $ref)->first();
            }
        } elseif ($ref) {
            $verified = $paystack->verify($ref);
            $ok       = $verified['status'] === 'success';
        }

        if (! $intent) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        }

        $this->assertCanPayForStudent((int) $intent->student_id);

        if (! $ok) {
            $intent->update(['status' => 'failed']);

            return response()->json(['status' => 'failed', 'message' => 'Payment was not successful'], 402);
        }

        return response()->json($this->markIntentPaid($intent, $ref ?? $intent->reference, $provider));
    }

    /**
     * Paystack server webhook (charge.success).
     */
    public function paystackWebhook(Request $request, PaystackService $paystack): JsonResponse
    {
        $reference = $request->input('data.reference') ?? $request->input('reference');
        if (! $reference) {
            return response()->json(['ok' => true]);
        }

        $meta = $request->input('data.metadata') ?? [];
        $subdomain = is_array($meta) ? ($meta['subdomain'] ?? null) : null;
        $subdomain = $subdomain ?? $request->header('X-Subdomain') ?? $request->input('subdomain');
        if ($subdomain) {
            $this->switchTenantBySubdomain($subdomain);
        }

        $intent = OnlinePaymentIntent::where('reference', $reference)->first();
        if (! $intent || $intent->status === 'success') {
            return response()->json(['ok' => true]);
        }

        try {
            $verified = $paystack->verify($reference);
            if ($verified['status'] === 'success') {
                $this->markIntentPaid($intent, $reference, 'paystack');
            }
        } catch (\Throwable $e) {
            Log::warning('Paystack webhook verify failed', ['ref' => $reference, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    protected function markIntentPaid(OnlinePaymentIntent $intent, string $reference, string $provider): array
    {
        if ($intent->status === 'success' && $intent->payment_id) {
            return [
                'status'  => 'success',
                'payment' => Payment::find($intent->payment_id),
            ];
        }

        $fee = $intent->fee_id ? Fee::find($intent->fee_id) : null;

        $payment = Payment::create([
            'school_id'         => $intent->school_id,
            'student_id'        => $intent->student_id,
            'fee_id'            => $intent->fee_id,
            'guardian_id'       => $this->resolveGuardianIdForPayment(),
            'amount'            => $intent->amount,
            'payment_method'    => 'online',
            'payment_reference' => $reference,
            'payment_date'      => now(),
            'status'            => 'successful',
            'notes'             => ucfirst($provider) . ' online payment',
        ]);

        if ($fee && $fee->getRemainingAmount() <= 0) {
            $fee->update(['status' => 'paid']);
        }

        $intent->update(['status' => 'success', 'payment_id' => $payment->id]);

        return [
            'status'  => 'success',
            'payment' => $payment->load('fee'),
        ];
    }

    protected function switchTenantBySubdomain(string $subdomain): void
    {
        $tenant = app(\App\Services\TenantService::class)->getTenantBySubdomain($subdomain);
        if ($tenant) {
            \Illuminate\Support\Facades\DB::purge('tenant');
            app(\App\Services\TenantService::class)->switchToTenant($tenant);
        }
    }

    protected function resolveGuardianIdForPayment(): ?int
    {
        $user = Auth::user();
        if (! $user || ! in_array($user->role, ['guardian', 'parent'], true)) {
            return null;
        }

        return \App\Models\Guardian::where('user_id', $user->id)->value('id');
    }

    protected function assertCanPayForStudent(int $studentId): void
    {
        $user = Auth::user();
        if ($user->role === 'student') {
            if ((int) ($user->student_id ?? 0) !== $studentId) {
                abort(403);
            }

            return;
        }
        if (in_array($user->role, ['guardian', 'parent'], true)) {
            $guardian = \App\Models\Guardian::where('user_id', $user->id)->first();
            if (! $guardian || ! $guardian->students()->where('students.id', $studentId)->exists()) {
                abort(403);
            }

            return;
        }
        if (! in_array($user->role, ['admin', 'school_admin', 'principal', 'vice_principal', 'accountant'], true)) {
            abort(403);
        }
    }
}
