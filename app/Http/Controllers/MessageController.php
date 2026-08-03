<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\Message;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * List the current user's messages.
     * type=inbox|received (default) -> messages received; type=sent|outbox -> messages sent.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $type   = $request->query('type', 'inbox');
        $isSent = in_array($type, ['sent', 'outbox'], true);

        $query = $isSent
            ? Message::where('sender_id', $userId)
            : Message::where('receiver_id', $userId);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        $paginator = $query->with(['sender:id,name,email,role', 'receiver:id,name,email,role'])
            ->orderByDesc('created_at')
            ->paginate($request->query('per_page', 50));

        $items = collect($paginator->items())
            ->map(fn (Message $message) => $this->formatMessage($message, $userId, $isSent))
            ->values()
            ->all();

        return response()->json([
            'data'         => $items,
            'total'        => $paginator->total(),
            'unread_count' => Message::where('receiver_id', $userId)->where('is_read', false)->count(),
        ]);
    }

    /**
     * People the current user can message (students: teachers + classmates; staff: students in scope).
     */
    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();
        $contacts = collect();

        if ($user->role === 'student') {
            $student = Student::where('user_id', $user->id)->first();
            if ($student?->class_id) {
                $class = ClassModel::find($student->class_id);
                if ($class?->class_teacher_id) {
                    $teacherUserId = Teacher::where('id', $class->class_teacher_id)->value('user_id');
                    if ($teacherUserId) {
                        $contacts->push($this->contactRow((int) $teacherUserId, 'teacher', 'Class teacher'));
                    }
                }

                $subjectTeacherIds = Subject::where('class_id', $student->class_id)
                    ->whereNotNull('teacher_id')
                    ->pluck('teacher_id')
                    ->unique();
                if ($subjectTeacherIds->isNotEmpty()) {
                    Teacher::whereIn('id', $subjectTeacherIds)
                        ->whereNotNull('user_id')
                        ->with('user:id,name,email,role')
                        ->get()
                        ->each(function (Teacher $teacher) use (&$contacts) {
                            if ($teacher->user) {
                                $contacts->push($this->contactRow(
                                    (int) $teacher->user->id,
                                    $teacher->user->role ?? 'teacher',
                                    'Subject teacher'
                                ));
                            }
                        });
                }

                Student::where('class_id', $student->class_id)
                    ->where('id', '!=', $student->id)
                    ->whereNotNull('user_id')
                    ->with('user:id,name,email,role')
                    ->get()
                    ->each(function (Student $peer) use (&$contacts) {
                        if ($peer->user) {
                            $contacts->push($this->contactRow(
                                (int) $peer->user->id,
                                'student',
                                'Classmate'
                            ));
                        }
                    });
            }

            User::query()
                ->whereIn('role', ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'])
                ->where('status', 'active')
                ->where('id', '!=', $user->id)
                ->orderBy('name')
                ->limit(30)
                ->get(['id', 'name', 'email', 'role'])
                ->each(function (User $u) use (&$contacts) {
                    $contacts->push($this->contactRow((int) $u->id, $u->role, 'Staff'));
                });
        } elseif (in_array($user->role, ['guardian', 'parent'], true)) {
            $studentIds = $this->accessibleStudentIdsForGuardian($user) ?? [];
            if ($studentIds !== []) {
                $classIds = Student::whereIn('id', $studentIds)->pluck('class_id')->filter()->unique();
                User::query()
                    ->whereIn('role', ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod', 'school_admin', 'admin'])
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->limit(40)
                    ->get(['id', 'name', 'email', 'role'])
                    ->each(function (User $u) use (&$contacts) {
                        $contacts->push($this->contactRow((int) $u->id, $u->role, 'School staff'));
                    });
                unset($classIds);
            }
        } else {
            User::query()
                ->where('id', '!=', $user->id)
                ->where('status', 'active')
                ->whereNotIn('role', ['super_admin'])
                ->orderBy('name')
                ->limit(80)
                ->get(['id', 'name', 'email', 'role'])
                ->each(function (User $u) use (&$contacts) {
                    $contacts->push($this->contactRow((int) $u->id, $u->role, ucfirst(str_replace('_', ' ', $u->role))));
                });
        }

        $unique = $contacts->unique('id')->values()->sortBy('name')->values();

        return response()->json(['data' => $unique]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'subject'      => 'nullable|string|max:255',
            'message'      => 'required|string',
            'type'         => 'nullable|string|max:50',
        ]);

        if ((int) $data['recipient_id'] === (int) $request->user()->id) {
            return response()->json(['error' => 'You cannot message yourself.'], 422);
        }

        $message = Message::create([
            'sender_id'   => $request->user()->id,
            'receiver_id' => $data['recipient_id'],
            'subject'     => $data['subject'] ?? null,
            'body'        => $data['message'],
            'type'        => $data['type'] ?? 'direct',
        ]);

        $message->load(['sender:id,name,email,role', 'receiver:id,name,email,role']);

        return response()->json([
            'message' => 'Message sent',
            'data'    => $this->formatMessage($message, $request->user()->id, true),
        ], 201);
    }

    public function show(Request $request, Message $message): JsonResponse
    {
        $userId = $request->user()->id;
        if ($message->sender_id !== $userId && $message->receiver_id !== $userId) {
            return $this->forbiddenResponse('You cannot view this message.');
        }

        $message->load(['sender:id,name,email,role', 'receiver:id,name,email,role']);
        $isSent = $message->sender_id === $userId;

        return response()->json(['data' => $this->formatMessage($message, $userId, $isSent, true)]);
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

        if (! $message) {
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

    /**
     * @return array<string, mixed>
     */
    private function formatMessage(Message $message, int $viewerId, bool $isSent, bool $includeBody = false): array
    {
        $counterparty = $isSent ? $message->receiver : $message->sender;
        $preview      = mb_strlen($message->body) > 120
            ? mb_substr($message->body, 0, 120).'…'
            : $message->body;

        $row = [
            'id'           => $message->id,
            'subject'      => $message->subject ?? '(No subject)',
            'body_preview' => $preview,
            'is_read'      => (bool) $message->is_read,
            'priority'     => 'normal',
            'type'         => $message->type ?? 'direct',
            'sent_at'      => $message->created_at?->toIso8601String(),
            'from'         => [
                'id'   => $message->sender?->id,
                'name' => $message->sender?->name ?? 'Unknown',
                'role' => $message->sender?->role,
            ],
            'to'           => [
                'id'   => $message->receiver?->id,
                'name' => $message->receiver?->name ?? 'Unknown',
                'role' => $message->receiver?->role,
            ],
            'counterparty' => [
                'id'   => $counterparty?->id,
                'name' => $counterparty?->name ?? 'Unknown',
                'role' => $counterparty?->role,
            ],
            'is_mine'      => $message->sender_id === $viewerId,
        ];

        if ($includeBody) {
            $row['body'] = $message->body;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRow(int $userId, string $role, string $group): array
    {
        $user = User::find($userId, ['id', 'name', 'email', 'role']);

        return [
            'id'    => $userId,
            'name'  => $user?->name ?? 'User',
            'email' => $user?->email,
            'role'  => $user?->role ?? $role,
            'group' => $group,
        ];
    }
}
