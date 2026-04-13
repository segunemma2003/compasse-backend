<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to'             => 'required|email',
            'subject'        => 'required|string|max:255',
            'message'        => 'nullable|string',
            'body'           => 'nullable|string',
            'cc'             => 'nullable|array',
            'cc.*'           => 'email',
            'bcc'            => 'nullable|array',
            'bcc.*'          => 'email',
            'attachment_url' => 'nullable|string|max:500',
        ]);

        $school = $request->attributes->get('school');
        $body   = $data['body'] ?? $data['message'] ?? '';

        SendEmailJob::dispatch(
            to:       $data['to'],
            subject:  $data['subject'],
            body:     $body,
            cc:       $data['cc']  ?? [],
            bcc:      $data['bcc'] ?? [],
            schoolId: $school?->id ? (string) $school->id : null,
        )->onQueue('emails');

        return response()->json([
            'message' => 'Email queued for delivery',
            'to'      => $data['to'],
            'status'  => 'queued',
        ]);
    }

    public function bulkSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipients'          => 'required|array|min:1',
            'recipients.*.email'  => 'required|email',
            'recipients.*.name'   => 'nullable|string',
            'subject'             => 'required|string|max:255',
            'body'                => 'required|string',
        ]);

        $school = $request->attributes->get('school');
        $queued = 0;

        foreach ($data['recipients'] as $recipient) {
            $personalised = str_replace('{name}', $recipient['name'] ?? $recipient['email'], $data['body']);
            SendEmailJob::dispatch(
                to:       $recipient['email'],
                subject:  $data['subject'],
                body:     $personalised,
                cc:       [],
                bcc:      [],
                schoolId: $school?->id ? (string) $school->id : null,
            )->onQueue('emails');
            $queued++;
        }

        return response()->json([
            'message' => "Bulk email queued for {$queued} recipient(s)",
            'queued'  => $queued,
            'status'  => 'queued',
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        try {
            $school = $request->attributes->get('school');
            $query  = EmailLog::orderByDesc('created_at');
            if ($school) {
                $query->where('school_id', $school->id);
            }
            return response()->json($query->paginate($request->get('per_page', 50)));
        } catch (\Exception $e) {
            return response()->json(['data' => [], 'total' => 0]);
        }
    }
}
