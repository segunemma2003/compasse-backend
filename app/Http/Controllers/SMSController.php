<?php

namespace App\Http\Controllers;

use App\Jobs\SendSMSJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SMSController extends Controller
{
    /** No provider means messages would only be written to the log — say so instead of "queued". */
    private function notConfigured(Request $request): ?JsonResponse
    {
        $school = $request->attributes->get('school');
        $settings = \App\Support\SchoolIntegrationSettings::forSchool($school?->id ? (int) $school->id : null);
        $serverProvider = strtolower((string) config('services.sms.provider', 'log'));
        if ($settings->hasSms() || ($serverProvider !== 'log' && $serverProvider !== '')) {
            return null;
        }

        return response()->json([
            'error' => 'SMS is not set up yet.',
            'message' => 'Add your SMS provider (Termii or Twilio), API key and sender ID in Settings → Integrations → SMS, then try again.',
        ], 422);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to'        => 'required|string',
            'message'   => 'required|string|max:1600',
            'sender_id' => 'nullable|string|max:11',
        ]);

        if ($denied = $this->notConfigured($request)) {
            return $denied;
        }
        $school   = $request->attributes->get('school');
        $senderId = $data['sender_id'] ?? config('services.sms.sender_id', 'School');

        SendSMSJob::dispatch(
            to:       $data['to'],
            message:  $data['message'],
            senderId: $senderId,
            schoolId: $school?->id ? (string) $school->id : null,
        )->onQueue('sms');

        return response()->json([
            'message' => 'SMS queued for delivery',
            'to'      => $data['to'],
            'status'  => 'queued',
        ]);
    }

    public function bulkSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipients'   => 'nullable|array',
            'recipients.*.phone' => 'required|string',
            'recipients.*.name'  => 'nullable|string',
            // Instead of typing numbers: every parent, a class's parents, or all staff.
            'audience'     => 'nullable|in:parents,staff',
            'class_id'     => 'nullable|integer',
            'message'      => 'required|string|max:1600',
            'sender_id'    => 'nullable|string|max:11',
        ]);

        if ($denied = $this->notConfigured($request)) {
            return $denied;
        }
        $data['recipients'] = array_merge($data['recipients'] ?? [], $this->audiencePhones($data['audience'] ?? null, $data['class_id'] ?? null));
        // One message per number (a parent of two children gets it once).
        $data['recipients'] = collect($data['recipients'])
            ->filter(fn ($r) => strlen(SendSMSJob::normaliseNumber((string) $r['phone'])) >= 10)
            ->unique(fn ($r) => SendSMSJob::normaliseNumber((string) $r['phone']))->values()->all();
        if (! $data['recipients']) {
            return response()->json(['error' => 'No phone numbers to send to.', 'message' => 'No one in that group has a phone number on their record.'], 422);
        }
        $school   = $request->attributes->get('school');
        $senderId = $data['sender_id'] ?? config('services.sms.sender_id', 'School');
        $queued   = 0;

        foreach ($data['recipients'] as $recipient) {
            $personalised = str_replace('{name}', $recipient['name'] ?? $recipient['phone'], $data['message']);
            SendSMSJob::dispatch(
                to:       $recipient['phone'],
                message:  $personalised,
                senderId: $senderId,
                schoolId: $school?->id ? (string) $school->id : null,
            )->onQueue('sms');
            $queued++;
        }

        return response()->json([
            'message' => "Bulk SMS queued for {$queued} recipient(s)",
            'queued'  => $queued,
            'status'  => 'queued',
        ]);
    }

    /** @return array<int, array{phone: string, name: string}> */
    private function audiencePhones(?string $audience, ?int $classId): array
    {
        $db = \Illuminate\Support\Facades\DB::class;
        if ($audience === 'parents') {
            $q = $db::table('guardians')
                ->join('guardian_students', 'guardian_students.guardian_id', '=', 'guardians.id')
                ->join('students', 'students.id', '=', 'guardian_students.student_id')
                ->leftJoin('users', 'users.id', '=', 'guardians.user_id')
                ->whereNull('students.deleted_at')
                ->where(fn ($w) => $w->whereNull('students.status')->orWhere('students.status', 'active'))
                ->when($classId, fn ($w) => $w->where('students.class_id', $classId))
                ->select($db::raw('COALESCE(NULLIF(guardians.phone, \'\'), users.phone) as phone'), 'guardians.first_name', 'guardians.last_name');

            return $q->get()->filter(fn ($g) => $g->phone)
                ->map(fn ($g) => ['phone' => (string) $g->phone, 'name' => trim("{$g->first_name} {$g->last_name}") ?: (string) $g->phone])->values()->all();
        }
        if ($audience === 'staff') {
            return $db::table('users')->whereNull('deleted_at')
                ->whereNotIn('role', ['student', 'parent', 'guardian', 'super_admin'])
                ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['inactive', 'suspended']))
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->get(['phone', 'name'])->map(fn ($u) => ['phone' => (string) $u->phone, 'name' => (string) ($u->name ?: $u->phone)])->all();
        }

        return [];
    }

    /** GET /communication/sms/status — whether messages are going through (the provider's last refusal). */
    public function status(Request $request): JsonResponse
    {
        $school = $request->attributes->get('school') ?? \App\Models\School::first();
        $settings = \App\Support\SchoolIntegrationSettings::forSchool($school?->id ? (int) $school->id : null);
        $out = ['configured' => $settings->hasSms(), 'provider' => $settings->smsProvider, 'sender_id' => $settings->smsSenderId,
            'whatsapp' => \App\Jobs\SendWhatsAppJob::isConfigured(), 'sent_24h' => 0, 'failed_24h' => 0, 'last_error' => null, 'last_error_at' => null];
        if (\Illuminate\Support\Facades\Schema::hasTable('sms_logs')) {
            $since = now()->subDay();
            $t = fn () => \Illuminate\Support\Facades\DB::table('sms_logs')->where('created_at', '>=', $since);
            $out['sent_24h'] = $t()->where('status', 'sent')->count();
            $out['failed_24h'] = $t()->where('status', 'failed')->count();
            $lastFail = \Illuminate\Support\Facades\DB::table('sms_logs')->where('status', 'failed')->orderByDesc('id')->first();
            $lastOk = \Illuminate\Support\Facades\DB::table('sms_logs')->where('status', 'sent')->max('id');
            // Only while the failures are the latest news (a message has not gone through since).
            if ($lastFail && $lastFail->id > (int) $lastOk) {
                $out['last_error'] = $lastFail->error;
                $out['last_error_at'] = $lastFail->created_at;
            }
        }

        return response()->json($out);
    }

    public function logs(Request $request): JsonResponse
    {
        // Returns paginated SMS log records if a sms_logs table exists,
        // otherwise returns an empty result gracefully.
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('sms_logs')) {
                return response()->json(['data' => [], 'total' => 0]);
            }
            $logs = \Illuminate\Support\Facades\DB::table('sms_logs')
                ->orderByDesc('created_at')
                ->paginate($request->get('per_page', 50));
            return response()->json($logs);
        } catch (\Exception $e) {
            return response()->json(['data' => [], 'total' => 0]);
        }
    }
}
