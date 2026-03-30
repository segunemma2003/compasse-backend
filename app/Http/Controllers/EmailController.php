<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to'      => 'required|email',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'cc'      => 'nullable|array',
            'cc.*'    => 'email',
            'bcc'     => 'nullable|array',
            'bcc.*'   => 'email',
        ]);

        $school = $request->attributes->get('school');

        SendEmailJob::dispatch(
            to:       $data['to'],
            subject:  $data['subject'],
            body:     $data['message'],
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
}
