<?php

namespace App\Http\Controllers;

use App\Jobs\SendSMSJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SMSController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to'        => 'required|string',
            'message'   => 'required|string|max:1600',
            'sender_id' => 'nullable|string|max:11',
        ]);

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
            'recipients'   => 'required|array|min:1',
            'recipients.*.phone' => 'required|string',
            'recipients.*.name'  => 'nullable|string',
            'message'      => 'required|string|max:1600',
            'sender_id'    => 'nullable|string|max:11',
        ]);

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
