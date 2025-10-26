<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $notifications = Notification::all();
        return response()->json($notifications);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:info,warning,error,success',
            'is_read' => 'boolean',
        ]);

        $notification = Notification::create($request->all());
        return response()->json($notification, 201);
    }

    public function show(Notification $notification): JsonResponse
    {
        return response()->json($notification);
    }

    public function update(Request $request, Notification $notification): JsonResponse
    {
        $request->validate([
            'is_read' => 'sometimes|boolean',
        ]);

        $notification->update($request->all());
        return response()->json($notification);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $notification->delete();
        return response()->json(null, 204);
    }
}
