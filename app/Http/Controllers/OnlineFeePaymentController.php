<?php

namespace App\Http\Controllers;

use App\Models\OnlinePaymentIntent;
use App\Models\School;
use App\Models\Student;
use App\Modules\Financial\Models\Fee;
use App\Modules\Financial\Models\Payment;
use App\Services\OnlineFeePaymentService;
use App\Services\PayHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OnlineFeePaymentController extends Controller
{
    public function gatewayConfig(PayHubService $payhub): JsonResponse
    {
        // The school's accounts (as printed on invoices), for paying by bank transfer instead.
        $accounts = \Illuminate\Support\Facades\Schema::hasTable('school_bank_accounts')
            ? \App\Models\SchoolBankAccount::orderByDesc('is_primary')->orderBy('id')->get(['bank_name', 'account_name', 'account_number', 'notes'])
            : collect();

        return response()->json([
            'provider' => 'payhub',
            'enabled'  => $payhub->isConfigured(),
            'currency' => 'NGN',
            'bank_accounts' => $accounts,
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

        $reason     = $this->paymentReason($fee, $student, $amount);
        $trackingId = $this->trackingId($fee, $student);

        try {
            $charge = $payhub->initializeCharge(
                $school, $amount, $email, (string) $request->redirect_url,
                metadata: ['fee_id' => $fee->id, 'student_id' => $student->id],
                description: $reason,
                clientReference: $trackingId,
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
            'meta'       => ['initiated_by' => Auth::id(), 'payhub_provider' => $charge['provider'], 'reason' => $reason, 'tracking_id' => $trackingId],
        ]);

        return response()->json([
            'provider'          => 'payhub',
            'authorization_url' => $charge['checkout_url'],
            'reference'         => $charge['reference'],
        ]);
    }

    public function verify(Request $request, OnlineFeePaymentService $payments): JsonResponse
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

        try {
            $status = $payments->confirm($intent, $this->resolveGuardianIdForPayment());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return match ($status) {
            'success' => response()->json(['status' => 'success', 'payment' => Payment::with('fee')->find($intent->fresh()->payment_id)]),
            'failed'  => response()->json(['status' => 'failed', 'message' => 'Payment was not successful'], 402),
            default   => response()->json(['status' => 'pending', 'message' => 'Payment not yet confirmed by PayHub'], 202),
        };
    }

    /**
     * GET /financial/payments/online — online (PayHub) payments for the
     * finance/admin view: who paid, for what, tracking id and status.
     */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $query = OnlinePaymentIntent::query()->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $page = $query->paginate(min((int) $request->get('per_page', 20), 100));
        $students = Student::whereIn('id', collect($page->items())->pluck('student_id'))->get()->keyBy('id');

        $page->getCollection()->transform(fn (OnlinePaymentIntent $i) => [
            'id'          => $i->id,
            'reference'   => $i->reference,
            'tracking_id' => $i->meta['tracking_id'] ?? null,
            'reason'      => $i->meta['reason'] ?? null,
            'student'     => ($s = $students->get($i->student_id)) ? trim("{$s->first_name} {$s->last_name}") : null,
            'admission_number' => $s?->admission_number,
            'amount'      => (float) $i->amount,
            'status'      => $i->status,
            'payment_id'  => $i->payment_id,
            'created_at'  => $i->created_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }

    /**
     * POST /financial/payments/online/refresh — re-checks every pending
     * online payment with PayHub and records the ones that went through.
     */
    public function refresh(Request $request, OnlineFeePaymentService $payments): JsonResponse
    {
        if ($denied = $this->requireCapability($request, 'finance.manage')) {
            return $denied;
        }

        $counts = $payments->reconcilePending();
        $parts = array_filter([
            $counts['success'] ? "{$counts['success']} confirmed" : null,
            $counts['failed'] ? "{$counts['failed']} failed" : null,
            $counts['pending'] ? "{$counts['pending']} still pending" : null,
            $counts['errors'] ? "{$counts['errors']} could not be checked" : null,
        ]);

        return response()->json(['message' => $parts ? implode(', ', $parts) : 'No pending online payments', 'counts' => $counts]);
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

    /**
     * The reason PayHub records for the payment, e.g.
     * "School fees – Tuition – Ruth Okegbuna (THE20250001) – First Term 2026/2027",
     * marked as a part payment when it doesn't clear the balance.
     */
    private function paymentReason(Fee $fee, Student $student, float $amount): string
    {
        $what = trim((string) ($fee->description ?: ucwords(str_replace('_', ' ', (string) $fee->fee_type)))) ?: 'Fees';
        $name = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')) ?: ($student->full_name ?? 'Student');
        $term = $fee->term_id ? \App\Models\Term::find($fee->term_id)?->name : null;
        $year = $fee->academic_year_id ? \App\Models\AcademicYear::find($fee->academic_year_id)?->name : null;

        $parts = array_filter([
            'School fees',
            $what,
            $name . ($student->admission_number ? " ({$student->admission_number})" : ''),
            trim(($term ?? '') . ' ' . ($year ?? '')) ?: null,
            $amount + 0.01 < (float) $fee->getRemainingAmount() ? 'part payment' : null,
        ]);

        return implode(' – ', $parts);
    }

    /** The school's tracking id for an online fee payment, e.g. "FEE-4-DEMO-STU-004". */
    private function trackingId(Fee $fee, Student $student): string
    {
        return 'FEE-' . $fee->id . ($student->admission_number ? '-' . $student->admission_number : '-STU' . $student->id);
    }
}
