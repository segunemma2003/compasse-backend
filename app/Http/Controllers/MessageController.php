<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function index(): JsonResponse
    {
        $messages = Message::all();
        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'sender_id' => 'required|exists:users,id',
            'recipient_id' => 'required|exists:users,id',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:sms,email,notification',
        ]);

        $message = Message::create($request->all());
        return response()->json($message, 201);
    }

    public function show(Message $message): JsonResponse
    {
        return response()->json($message);
    }

    public function update(Request $request, Message $message): JsonResponse
    {
        $request->validate([
            'subject' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
        ]);

        $message->update($request->all());
        return response()->json($message);
    }

    public function destroy(Message $message): JsonResponse
    {
        $message->delete();
        return response()->json(null, 204);
    }

    /**
     * Mark message as read
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $message = Message::find($id);

        if (!$message) {
            return response()->json(['error' => 'Message not found'], 404);
        }

        $message->update(['is_read' => true, 'read_at' => now()]);

        return response()->json([
            'message' => 'Message marked as read',
            'message' => $message
        ]);
    }
}
