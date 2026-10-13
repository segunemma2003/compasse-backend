<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventReminder;
use App\Services\EventReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reminders about an event: draft a message, choose who it goes to, send
 * now or schedule it (payments:… style scheduler sends due ones).
 */
class EventReminderController extends Controller
{
    /** GET /events/{event}/reminders — the event's reminders plus a suggested draft. */
    public function index(Event $event): JsonResponse
    {
        return response()->json([
            'reminders' => EventReminder::where('event_id', $event->id)->latest()->get(),
            'draft'     => EventReminderService::defaultMessage($event),
        ]);
    }

    /** GET /events/{event}/reminders/preview?audience=&class_id= — how many people it would reach. */
    public function preview(Request $request, Event $event, EventReminderService $service): JsonResponse
    {
        $request->validate(['audience' => 'required|in:' . implode(',', EventReminder::AUDIENCES), 'class_id' => 'nullable|integer']);
        $people = $service->recipients($request->audience, $request->integer('class_id') ?: null);

        return response()->json([
            'recipients' => $people->count(),
            'with_email' => $people->filter(fn ($p) => \App\Support\EmailAddress::isReal($p['email']))->count(),
        ]);
    }

    /** POST /events/{event}/reminders — { audience, class_id?, subject, message, send_at? } */
    public function store(Request $request, Event $event, EventReminderService $service): JsonResponse
    {
        $data = $request->validate([
            'audience' => 'required|in:' . implode(',', EventReminder::AUDIENCES),
            'class_id' => 'required_if:audience,class_parents|nullable|exists:classes,id',
            'subject'  => 'required|string|max:200',
            'message'  => 'required|string|max:5000',
            'send_at'  => 'nullable|date|after:now',
        ]);

        $reminder = EventReminder::create($data + [
            'event_id'   => $event->id,
            'status'     => 'pending',
            'created_by' => $request->user()?->id,
        ]);

        if (empty($data['send_at'])) {
            $count = $service->send($reminder);

            return response()->json(['message' => "Reminder sent to {$count} " . ($count === 1 ? 'person' : 'people'), 'reminder' => $reminder->fresh()], 201);
        }

        return response()->json(['message' => 'Reminder scheduled for ' . $reminder->send_at->format('j M Y, H:i'), 'reminder' => $reminder], 201);
    }

    /** DELETE /events/{event}/reminders/{reminder} — cancel a scheduled one. */
    public function destroy(Event $event, EventReminder $reminder): JsonResponse
    {
        if ($reminder->event_id !== $event->id) {
            return response()->json(['error' => 'Reminder not found'], 404);
        }
        if ($reminder->status !== 'pending') {
            return response()->json(['error' => 'Only a scheduled reminder that has not been sent can be cancelled.'], 422);
        }
        $reminder->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Scheduled reminder cancelled']);
    }
}
