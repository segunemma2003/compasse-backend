<?php

namespace App\Http\Controllers;

use App\Models\OnlinePaymentIntent;
use App\Models\School;
use App\Models\Student;
use App\Modules\Financial\Models\Fee;
use App\Modules\Financial\Models\Payment;
use App\Services\PayHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OnlineFeePaymentController extends Controller
{
    public function gatewayConfig(PayHubService $payhub): JsonResponse
    {
        return response()->json([
            'provider' => 'payhub',
            'enabled'  => $payhub->isConfigured(),
            'currency' => 'NGN',
        ]);
    }

    public function initialize(Request $request, PayHubService $payhub): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fee_id'       => 'required|exists:fees,id',
            'amount'       => 'required|numeric|min:100',
            'student_id'   => 'required|exists:students,id',
            'redirect_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'messages' => $validator->errors()], 422);
        }

        if (! $payhub->isConfigured()) {
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

        $school = School::find($fee->school_id);
        if (! $school) {
            return response()->json(['error' => 'School not found'], 404);
        }

        try {
            $charge = $payhub->initializeCharge(
                $school, $amount, $email, (string) $request->redirect_url,
                metadata: ['fee_id' => $fee->id, 'student_id' => $student->id],
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        OnlinePaymentIntent::create([
            'school_id'  => $school->id,
            'student_id' => $student->id,
            'fee_id'     => $fee->id,
            'amount'     => $amount,
            'reference'  => $charge['reference'],
            'provider'   => 'payhub',
            'status'     => 'pending',
            'meta'       => ['initiated_by' => Auth::id(), 'payhub_provider' => $charge['provider']],
        ]);

        return response()->json([
            'provider'          => 'payhub',
            'authorization_url' => $charge['checkout_url'],
            'reference'         => $charge['reference'],
        ]);
    }

    public function verify(Request $request, PayHubService $payhub): JsonResponse
    {
        $reference = $request->input('reference');
        if (! $reference) {
            return response()->json(['error' => 'reference is required'], 422);
        }

        $intent = OnlinePaymentIntent::where('reference', $reference)->first();
        if (! $intent) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        }

        $this->assertCanPayForStudent((int) $intent->student_id);

        if ($intent->status === 'success' && $intent->payment_id) {
            return response()->json([
                'status'  => 'success',
                'payment' => Payment::find($intent->payment_id),
            ]);
        }

        $school = School::find($intent->school_id);
        if (! $school) {
            return response()->json(['error' => 'School not found'], 404);
        }

        try {
            $verified = $payhub->verifyCharge($school, $reference);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        if ($verified['status'] === 'failed') {
            $intent->update(['status' => 'failed']);

            return response()->json(['status' => 'failed', 'message' => 'Payment was not successful'], 402);
        }

        if ($verified['status'] !== 'success') {
            return response()->json(['status' => $verified['status'], 'message' => 'Payment not yet confirmed by PayHub'], 202);
        }

        return response()->json($this->markIntentPaid($intent, $reference, 'payhub'));
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
            // Same enum bug fixed in FeeController::pay() and
            // PaymentController::store(): 'successful' was never a real
            // payments.status value, so this insert crashed on every
            // successful online payment — after the customer's card/bank
            // had already been charged. Fixed to 'confirmed'.
            'status'            => 'confirmed',
            'notes'             => ucfirst($provider) . ' online payment',
        ]);

        if ($fee) {
            $newAmountPaid = round((float) $fee->amount_paid + (float) $intent->amount, 2);
            $newBalance = max(0, round((float) $fee->amount - $newAmountPaid, 2));
            $fee->update([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'status' => $newBalance <= 0 ? 'paid' : 'partial',
            ]);
        }

        $intent->update(['status' => 'success', 'payment_id' => $payment->id]);

        return [
            'status'  => 'success',
            'payment' => $payment->load('fee'),
        ];
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
