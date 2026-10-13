<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out who an event reminder goes to and sends it: one email per
 * person (from the school's name) plus an in-app notification for anyone
 * with a login.
 */
class EventReminderService
{
    private const STAFF_ROLES = ['admin', 'school_admin', 'principal', 'vice_principal', 'staff', 'accountant', 'librarian',
        'driver', 'security', 'cleaner', 'caterer', 'nurse', 'care_assistant', 'sen_facilitator', 'sen_therapist', 'counsellor'];

    /**
     * @return Collection<int, array{email: ?string, name: string, user_id: ?int}>
     */
    public function recipients(string $audience, ?int $classId = null): Collection
    {
        $people = collect();

        if (in_array($audience, ['everyone', 'parents'], true)) {
            $people = $people->merge($this->parents());
        }
        if ($audience === 'class_parents' && $classId) {
            $people = $people->merge($this->parents($classId));
        }
        if (in_array($audience, ['everyone', 'staff', 'teachers'], true)) {
            $people = $people->merge(Teacher::get(['first_name', 'last_name', 'email', 'user_id'])
                ->map(fn ($t) => ['email' => $t->email, 'name' => trim("{$t->first_name} {$t->last_name}"), 'user_id' => $t->user_id]));
        }
        if (in_array($audience, ['everyone', 'staff'], true)) {
            $people = $people->merge(User::whereIn('role', self::STAFF_ROLES)->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['email' => $u->email, 'name' => $u->name, 'user_id' => $u->id]));
        }
        if (in_array($audience, ['everyone', 'students'], true)) {
            $people = $people->merge(Student::where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->get(['first_name', 'last_name', 'email', 'user_id'])
                ->map(fn ($s) => ['email' => $s->email, 'name' => trim("{$s->first_name} {$s->last_name}"), 'user_id' => $s->user_id]));
        }

        // One message per person, even if they're on two lists.
        return $people->unique(fn ($p) => $p['user_id'] ? 'u' . $p['user_id'] : 'e' . strtolower((string) $p['email']))->values();
    }

    private function parents(?int $classId = null): Collection
    {
        $query = Guardian::query();
        if ($classId) {
            $query->whereIn('id', DB::table('guardian_students')
                ->join('students', 'students.id', '=', 'guardian_students.student_id')
                ->where('students.class_id', $classId)
                ->pluck('guardian_students.guardian_id'));
        }

        return $query->get(['first_name', 'last_name', 'email', 'user_id'])
            ->map(fn ($g) => ['email' => $g->email, 'name' => trim("{$g->first_name} {$g->last_name}"), 'user_id' => $g->user_id]);
    }

    /** Sends a pending reminder now. Returns how many people it reached. */
    public function send(EventReminder $reminder): int
    {
        if ($reminder->status !== 'pending') {
            return $reminder->recipients_count;
        }

        $schoolId = School::value('id');
        $count = 0;

        foreach ($this->recipients($reminder->audience, $reminder->class_id) as $person) {
            $reached = false;
            if (EmailAddress::isReal($person['email'])) {
                SendEmailJob::dispatch(
                    to: $person['email'],
                    subject: $reminder->subject,
                    body: "Dear {$person['name']},\n\n{$reminder->message}",
                    schoolId: $schoolId ? (string) $schoolId : null,
                    type: 'event_reminder',
                )->onQueue('emails');
                $reached = true;
            }
            if ($person['user_id']) {
                Notification::create([
                    'user_id' => $person['user_id'],
                    'title'   => $reminder->subject,
                    'message' => $reminder->message,
                    'type'    => 'info',
                ]);
                $reached = true;
            }
            $count += $reached ? 1 : 0;
        }

        $reminder->update(['status' => 'sent', 'sent_at' => now(), 'recipients_count' => $count]);

        return $count;
    }

    /** A ready-to-edit default message for an event. */
    public static function defaultMessage(Event $event): array
    {
        $when = $event->start_date?->format('l, j F Y');
        if ($event->end_date && $event->end_date->ne($event->start_date)) {
            $when .= ' – ' . $event->end_date->format('l, j F Y');
        }
        $time = ! $event->is_all_day && $event->start_time ? ' at ' . substr((string) $event->start_time, 0, 5) : '';

        return [
            'subject' => "Reminder: {$event->title}",
            'message' => "This is a reminder about {$event->title} on {$when}{$time}"
                . ($event->location ? " at {$event->location}" : '') . ".\n\n"
                . ($event->description ? trim($event->description) . "\n\n" : '')
                . "Thank you.",
        ];
    }
}
