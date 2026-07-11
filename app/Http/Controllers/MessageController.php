<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    /**
     * List the current user's messages.
     * type=inbox (default) -> messages received; type=sent -> messages sent.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $type   = $request->query('type', 'inbox');

        $query = $type === 'sent'
            ? Message::where('sender_id', $userId)
            : Message::where('receiver_id', $userId);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        $messages = $query->with(['sender:id,name,email', 'receiver:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($request->query('per_page', 50));

        return response()->json([
            'data'         => $messages->items(),
            'total'        => $messages->total(),
            'unread_count' => Message::where('receiver_id', $userId)->where('is_read', false)->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'subject'      => 'nullable|string|max:255',
            'message'      => 'required|string',
            'type'         => 'nullable|string|max:50',
        ]);

        $message = Message::create([
            'sender_id'   => $request->user()->id,
            'receiver_id' => $data['recipient_id'],
            'subject'     => $data['subject'] ?? null,
            'body'        => $data['message'],
            'type'        => $data['type'] ?? 'notification',
        ]);

        return response()->json(['message' => 'Message sent', 'data' => $message], 201);
    }

    public function show(Request $request, Message $message): JsonResponse
    {
        $userId = $request->user()->id;
        if ($message->sender_id !== $userId && $message->receiver_id !== $userId) {
            return $this->forbiddenResponse('You cannot view this message.');
        }

        return response()->json(['data' => $message->load(['sender:id,name,email', 'receiver:id,name,email'])]);
    }

    public function update(Request $request, Message $message): JsonResponse
    {
        if ($message->sender_id !== $request->user()->id) {
            return $this->forbiddenResponse('You can only edit messages you sent.');
        }

        $data = $request->validate([
            'subject' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
        ]);

        if (array_key_exists('message', $data)) {
            $data['body'] = $data['message'];
            unset($data['message']);
        }

        $message->update($data);

        return response()->json(['data' => $message]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $userId = $request->user()->id;
        if ($message->sender_id !== $userId && $message->receiver_id !== $userId) {
            return $this->forbiddenResponse('You cannot delete this message.');
        }

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

        if ($message->receiver_id !== $request->user()->id) {
            return $this->forbiddenResponse('You can only mark your own messages as read.');
        }

        $message->update(['is_read' => true, 'read_at' => now()]);

        return response()->json([
            'message' => 'Message marked as read',
            'data'    => $message,
        ]);
    }
}
