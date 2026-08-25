<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    private const ADMIN_TIER = ['school_admin', 'principal', 'vice_principal', 'admin', 'super_admin'];

    /**
     * This route group is open to every authenticated role — without this,
     * any user could read/edit/delete any other user's notifications by
     * guessing a sequential id.
     */
    private function assertOwnNotification(Request $request, Notification $notification): ?JsonResponse
    {
        if (in_array($request->user()->role, self::ADMIN_TIER, true)) {
            return null;
        }
        if ($notification->user_id !== $request->user()->id) {
            return $this->forbiddenResponse();
        }
        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id();
        $perPage = min((int) $request->get('per_page', 20), 100);

        $notifications = Notification::when($userId, fn($q) => $q->where('user_id', $userId))
            ->orderByDesc('created_at')
            ->paginate($perPage);

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

        $data = $request->all();
        // Non-admin callers may only create notifications addressed to themselves —
        // otherwise any user could target arbitrary user_id values.
        if (! in_array($request->user()->role, self::ADMIN_TIER, true)) {
            $data['user_id'] = $request->user()->id;
        }

        $notification = Notification::create($data);
        return response()->json($notification, 201);
    }

    public function show(Request $request, Notification $notification): JsonResponse
    {
        if ($denied = $this->assertOwnNotification($request, $notification)) {
            return $denied;
        }
        return response()->json($notification);
    }

    public function update(Request $request, Notification $notification): JsonResponse
    {
        if ($denied = $this->assertOwnNotification($request, $notification)) {
            return $denied;
        }

        $request->validate([
            'is_read' => 'sometimes|boolean',
        ]);

        $notification->update($request->all());
        return response()->json($notification);
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        if ($denied = $this->assertOwnNotification($request, $notification)) {
            return $denied;
        }
        $notification->delete();
        return response()->json(null, 204);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $notification = Notification::find($id);

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        if ($denied = $this->assertOwnNotification($request, $notification)) {
            return $denied;
        }

        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json([
            'message' => 'Notification marked as read',
            'notification' => $notification
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $userId = auth()->id();
        
        Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now()
            ]);

        return response()->json([
            'message' => 'All notifications marked as read'
        ]);
    }
}
