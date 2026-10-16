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
use Illuminate\Support\Facades\DB;

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
                foreach (\App\Support\ClassTeachers::teacherIdsFor((int) $student->class_id, $student->arm_id ? (int) $student->arm_id : null) as $ctId) {
                    $teacherUserId = Teacher::where('id', $ctId)->value('user_id');
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
                // Each child's class teacher and subject teachers, then the school office.
                foreach (Student::with('class:id,name')->whereIn('id', $studentIds)->get() as $child) {
                    if (! $child->class_id) {
                        continue;
                    }
                    $className = $child->class?->name ?? 'class';
                    foreach (\App\Support\ClassTeachers::teacherIdsFor((int) $child->class_id, $child->arm_id ? (int) $child->arm_id : null) as $tid) {
                        if ($uid = Teacher::where('id', $tid)->value('user_id')) {
                            $contacts->push($this->contactRow((int) $uid, 'teacher', "Class teacher · {$child->first_name} ({$className})"));
                        }
                    }
                    $subjectTeachers = DB::table('teacher_subjects')->join('subjects', 'subjects.id', '=', 'teacher_subjects.subject_id')
                        ->join('teachers', 'teachers.id', '=', 'teacher_subjects.teacher_id')
                        ->where('subjects.class_id', $child->class_id)->where('teacher_subjects.status', 'active')->whereNotNull('teachers.user_id')
                        ->get(['teachers.user_id', 'subjects.name']);
                    foreach ($subjectTeachers->groupBy('user_id') as $uid => $rows) {
                        $contacts->push($this->contactRow((int) $uid, 'teacher', $rows->pluck('name')->unique()->take(3)->implode(', ') . " · {$className}"));
                    }
                }
                User::query()->whereIn('role', ['school_admin', 'principal', 'admin'])->where('status', 'active')->orderBy('name')->limit(5)
                    ->get(['id', 'role'])->each(fn (User $u) => $contacts->push($this->contactRow((int) $u->id, $u->role, 'School office')));
            }
        } elseif (in_array($user->role, ['teacher', 'class_teacher', 'subject_teacher', 'year_tutor', 'hod'], true) && $user->teacher) {
            // Parents and students of the teacher's classes, then colleagues.
            $classIds = $this->accessibleClassIds($user) ?? [];
            $classIds = array_unique(array_merge($classIds, \App\Support\ClassTeachers::classIdsFor((int) $user->teacher->id)));
            $students = Student::with('class:id,name')->whereIn('class_id', $classIds ?: [0])->orderBy('first_name')->get();
            foreach ($students as $st) {
                $className = $st->class?->name ?? '';
                foreach (DB::table('guardian_students')->join('guardians', 'guardians.id', '=', 'guardian_students.guardian_id')
                    ->where('guardian_students.student_id', $st->id)->whereNotNull('guardians.user_id')->pluck('guardians.user_id') as $gid) {
                    $contacts->push($this->contactRow((int) $gid, 'guardian', "Parent of {$st->first_name} ({$className})"));
                }
            }
            foreach ($students as $st) {
                if ($st->user_id) {
                    $contacts->push($this->contactRow((int) $st->user_id, 'student', 'Student · ' . ($st->class?->name ?? '')));
                }
            }
            User::query()->where('id', '!=', $user->id)->where('status', 'active')
                ->whereNotIn('role', ['super_admin', 'student', 'guardian', 'parent'])->orderBy('name')->limit(80)->get(['id', 'role'])
                ->each(fn (User $u) => $contacts->push($this->contactRow((int) $u->id, $u->role, 'Staff')));
        } else {
            User::query()
                ->where('id', '!=', $user->id)
                ->where('status', 'active')
                ->whereNotIn('role', ['super_admin'])
                // Type to find anyone in the school (name or email); otherwise the first 200.
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%' . $request->search . '%')->orWhere('email', 'like', '%' . $request->search . '%')))
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'email', 'role'])
                ->each(function (User $u) use (&$contacts) {
                    $contacts->push(['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'group' => ucfirst(str_replace('_', ' ', $u->role))]);
                });
        }

        $unique = $contacts->unique('id')->values()->sortBy('name')->values();

        return response()->json(['data' => $unique]);
    }

    /**
     * GET /communication/chats — chat list: everyone this person talks to (or can talk to),
     * with the last message and unread count, newest first. ?search= ?unread=1
     */
    public function chats(Request $request): JsonResponse
    {
        $me = $request->user()->id;
        $contacts = collect($this->contacts($request)->getData(true)['data'] ?? [])->keyBy('id');

        $messages = Message::where(fn ($q) => $q->where('sender_id', $me)->orWhere('receiver_id', $me))
            ->orderByDesc('created_at')->limit(2000)->get(['id', 'sender_id', 'receiver_id', 'body', 'is_read', 'created_at']);
        $byOther = $messages->groupBy(fn ($m) => (int) $m->sender_id === (int) $me ? $m->receiver_id : $m->sender_id);

        // People with a conversation who aren't in the contacts list (e.g. the office wrote first).
        $missing = $byOther->keys()->diff($contacts->keys());
        foreach (User::whereIn('id', $missing)->get(['id', 'name', 'email', 'role']) as $u) {
            $contacts[$u->id] = ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'group' => \Illuminate\Support\Str::headline($u->role)];
        }

        $rows = $contacts->map(function ($c) use ($byOther, $me) {
            $thread = $byOther[$c['id']] ?? collect();
            $last = $thread->first();

            return $c + [
                'last_message' => $last ? mb_substr($last->body, 0, 120) : null,
                'last_from_me' => $last ? (int) $last->sender_id === (int) $me : false,
                'last_at' => $last?->created_at,
                'unread' => $thread->where('receiver_id', $me)->where('is_read', false)->count(),
            ];
        });

        if ($q = mb_strtolower(trim((string) $request->query('search')))) {
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower("{$r['name']} {$r['group']} {$r['role']}"), $q));
        }
        if ($request->boolean('unread')) {
            $rows = $rows->filter(fn ($r) => $r['unread'] > 0);
        }
        $rows = $rows->sortBy([fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']), fn ($a, $b) => strcmp($a['name'], $b['name'])]);

        return response()->json([
            'chats' => $rows->values(),
            'unread_total' => $messages->where('receiver_id', $me)->where('is_read', false)->count(),
        ]);
    }

    /** GET /communication/chats/{userId} — the conversation with one person (marks their messages read). */
    public function thread(Request $request, int $userId): JsonResponse
    {
        $me = $request->user()->id;
        $other = User::find($userId, ['id', 'name', 'role']);
        if (! $other) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $q = Message::where(fn ($w) => $w->where('sender_id', $me)->where('receiver_id', $userId))
            ->orWhere(fn ($w) => $w->where('sender_id', $userId)->where('receiver_id', $me));
        $messages = (clone $q)->orderByDesc('id')->limit(200)->get(['id', 'sender_id', 'subject', 'body', 'is_read', 'created_at'])->reverse()->values();
        Message::where('sender_id', $userId)->where('receiver_id', $me)->where('is_read', false)->update(['is_read' => true, 'read_at' => now()]);

        return response()->json([
            'with' => $other,
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id, 'mine' => (int) $m->sender_id === (int) $me, 'body' => $m->body,
                'subject' => $m->subject, 'is_read' => (bool) $m->is_read, 'created_at' => $m->created_at,
            ]),
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
        $this->notifyRecipient($message);

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
     * One-click reminder from a student to their class teacher — sends both an
     * in-app message (shows in the teacher's Messages inbox) and a notification
     * (shows in the teacher's notification bell). No compose dialog: student
     * clicks a button, teacher gets pinged.
     */
    public function remindTeacher(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'student') {
            return $this->forbiddenResponse('Only students can send a class-teacher reminder.');
        }

        $note = trim((string) $request->input('note', ''));
        if (mb_strlen($note) > 500) {
            return response()->json(['error' => 'Note is too long (max 500 characters).'], 422);
        }

        $student = Student::where('user_id', $user->id)->first();
        if (! $student || ! $student->class_id) {
            return response()->json(['error' => 'No class assigned yet.'], 422);
        }

        $teacherUserId = null;
        $ctId = \App\Support\ClassTeachers::teacherIdsFor((int) $student->class_id, $student->arm_id ? (int) $student->arm_id : null)[0] ?? null;
        if ($ctId) {
            $teacherUserId = Teacher::where('id', $ctId)->value('user_id');
        }
        if (! $teacherUserId) {
            // No named class teacher — fall back to the first subject teacher for this class.
            $subjectTeacherId = Subject::where('class_id', $student->class_id)
                ->whereNotNull('teacher_id')
                ->value('teacher_id');
            if ($subjectTeacherId) {
                $teacherUserId = Teacher::where('id', $subjectTeacherId)->value('user_id');
            }
        }
        if (! $teacherUserId) {
            return response()->json(['error' => 'No teacher is assigned to your class yet.'], 422);
        }

        // Throttle: one reminder per student->teacher pair every 10 minutes, so a
        // double-click (or an impatient student) doesn't flood the teacher.
        $recent = Message::where('sender_id', $user->id)
            ->where('receiver_id', $teacherUserId)
            ->where('type', 'reminder')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();
        if ($recent) {
            return response()->json(['message' => 'You already reminded this teacher recently — sit tight.'], 200);
        }

        $body = $note !== ''
            ? "{$user->name} needs your attention: {$note}"
            : "{$user->name} would like your attention when you have a moment.";

        $message = Message::create([
            'sender_id'   => $user->id,
            'receiver_id' => $teacherUserId,
            'subject'     => 'Reminder from a student',
            'body'        => $body,
            'type'        => 'reminder',
        ]);

        DB::table('notifications')->insert([
            'user_id'    => $teacherUserId,
            'title'      => 'Student reminder',
            'message'    => $body,
            'type'       => 'info',
            'is_read'    => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacherName = User::where('id', $teacherUserId)->value('name');

        return response()->json([
            'message'      => 'Reminder sent'.($teacherName ? " to {$teacherName}" : '').'.',
            'teacher_name' => $teacherName,
            'message_id'   => $message->id,
        ], 201);
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

    /**
     * Tell the recipient: an in-app notification, and an email (to a real address) with a
     * link back to the portal to read and reply.
     */
    private function notifyRecipient(Message $message): void
    {
        try {
            $sender = $message->sender;
            $to = $message->receiver;
            if (! $to) {
                return;
            }
            \App\Models\Notification::create([
                'user_id' => $to->id, 'type' => 'info',
                'title' => 'New message from ' . ($sender?->name ?? 'someone'),
                'message' => mb_substr(($message->subject ? $message->subject . ' — ' : '') . $message->body, 0, 250),
            ]);

            if (! \App\Support\EmailAddress::isReal($to->email) || \App\Support\EmailAddress::isPlaceholder($to->email) || $to->email_generated) {
                return;
            }
            // One email per unread conversation: if they already have unread messages from this
            // sender they've been emailed about it — don't send one per chat line.
            $unreadFromSender = Message::where('sender_id', $message->sender_id)->where('receiver_id', $to->id)->where('is_read', false)->count();
            if ($unreadFromSender > 1) {
                return;
            }
            $sub = null;
            try {
                $sub = tenant()?->subdomain;
            } catch (\Throwable) {
            }
            $page = in_array($to->role, ['student', 'guardian', 'parent'], true) ? '/school/my-messages' : '/school/communication';
            $link = $sub ? 'https://' . $sub . '.' . config('app.root_domain', 'compasse.net') . $page : '';
            $school = \App\Models\School::first();
            $body = "Hello {$to->name},\n\n" . ($sender?->name ?? 'Someone') . " sent you a message"
                . ($message->subject ? " — \"{$message->subject}\"" : '') . ":\n\n"
                . $message->body . "\n\n"
                . ($link ? "Read and reply on the portal: {$link}\n\n" : '')
                . ($school?->name ?? '');
            \App\Jobs\SendEmailJob::dispatch($to->email, 'New message from ' . ($sender?->name ?? 'school'), $body, [], [],
                $school ? (string) $school->id : null, false, 'message');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
