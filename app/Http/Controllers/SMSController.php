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
}
