<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SMSController extends Controller
{
    /**
     * Send SMS
     */
    public function send(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|string',
            'message' => 'required|string|max:1600',
            'sender_id' => 'nullable|string|max:11',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // TODO: Implement actual SMS sending logic
        return response()->json([
            'message' => 'SMS sent successfully',
            'to' => $request->to,
            'status' => 'sent'
        ]);
    }
}
