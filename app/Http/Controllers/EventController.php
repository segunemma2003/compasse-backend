<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    public function index(Request $request): JsonResponse
    {
        $query = Event::where('school_id', $this->school($request)?->id ?? 0);

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('event_type')) $query->where('event_type', $request->event_type);
        // Overlap, so an event spanning into the range (started last month,
        // ends this month) is included too.
        if ($request->filled('date_from'))  $query->where(fn ($q) => $q->whereDate('start_date', '>=', $request->date_from)
                                                                       ->orWhereDate('end_date', '>=', $request->date_from));
        if ($request->filled('date_to'))    $query->whereDate('start_date', '<=', $request->date_to);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('title',    'like', "%$s%")
                                      ->orWhere('location','like', "%$s%"));
        }

        return response()->json(
            $query->orderBy('start_date')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'           => 'required|string|max:200',
            'description'     => 'nullable|string',
            'event_type'      => 'required|in:academic,sports,cultural,holiday,meeting,exam,other',
            'start_date'      => 'required|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'start_time'      => 'nullable|date_format:H:i',
            'end_time'        => 'nullable|date_format:H:i',
            'location'        => 'nullable|string|max:200',
            'organizer'       => 'nullable|string|max:150',
            'target_audience' => 'nullable|in:all,students,staff,parents,teachers',
            'class_id'        => 'nullable|exists:classes,id',
            'is_all_day'      => 'boolean',
            'status'          => 'nullable|in:scheduled,ongoing,completed,cancelled',
            'max_participants'=> 'nullable|integer|min:1',
            'attachments'     => 'nullable|array',
        ]);

        $data['created_by'] = auth()->id();
        $data['status']     = $data['status'] ?? 'scheduled';

        $event = Event::create(array_merge($data, ['school_id' => $this->school($request)?->id]));
        $this->scheduleDayBeforeReminder($request, $event);

        return response()->json(['message' => 'Event created', 'event' => $event], 201);
    }

    public function show(string $id): JsonResponse
    {
        $event = Event::with(['class', 'createdBy'])->findOrFail($id);
        return response()->json(['event' => $event]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $data  = $request->validate([
            'title'           => 'sometimes|string|max:200',
            'description'     => 'nullable|string',
            'event_type'      => 'sometimes|in:academic,sports,cultural,holiday,meeting,exam,other',
            'start_date'      => 'sometimes|date',
            'end_date'        => 'nullable|date',
            'start_time'      => 'nullable|date_format:H:i',
            'end_time'        => 'nullable|date_format:H:i',
            'location'        => 'nullable|string|max:200',
            'organizer'       => 'nullable|string|max:150',
            'target_audience' => 'nullable|in:all,students,staff,parents,teachers',
            'class_id'        => 'nullable|exists:classes,id',
            'is_all_day'      => 'boolean',
            'status'          => 'nullable|in:scheduled,ongoing,completed,cancelled',
            'max_participants'=> 'nullable|integer|min:1',
            'attachments'     => 'nullable|array',
        ]);
        $event->update($data);
        return response()->json(['message' => 'Event updated', 'event' => $event]);
    }

    public function destroy(string $id): JsonResponse
    {
        Event::findOrFail($id)->delete();
        return response()->json(['message' => 'Event deleted']);
    }

    public function getUpcoming(Request $request): JsonResponse
    {
        $schoolId = $this->school($request)?->id ?? 0;
        $limit    = min((int) $request->get('limit', 10), 50);

        $events = Event::where('school_id', $schoolId)
                       ->upcoming()
                       ->limit($limit)
                       ->get();

        return response()->json(['upcoming_events' => $events, 'count' => $events->count()]);
    }

    /**
     * Unless switched off ("remind_day_before": false), queue an email + notification reminder
     * for the people the event is for, at 8am the day before it starts.
     */
    private function scheduleDayBeforeReminder(Request $request, Event $event): void
    {
        if (! $request->boolean('remind_day_before', true) || ! $event->start_date) {
            return;
        }
        try {
            $sendAt = \Illuminate\Support\Carbon::parse($event->start_date)->subDay()->setTime(8, 0);
            if ($sendAt->isPast()) {
                return;
            }
            $audience = $event->class_id ? 'class_parents' : match ($event->target_audience ?? 'all') {
                'parents' => 'parents', 'staff' => 'staff', 'teachers' => 'teachers', 'students' => 'students', default => 'everyone',
            };
            $draft = \App\Services\EventReminderService::defaultMessage($event);
            \App\Models\EventReminder::create([
                'event_id' => $event->id, 'audience' => $audience, 'class_id' => $event->class_id,
                'subject' => 'Reminder: ' . $event->title . ' is tomorrow',
                'message' => $draft['message'] ?? $draft['body'] ?? (string) ($event->description ?? $event->title),
                'send_at' => $sendAt, 'status' => 'pending', 'created_by' => $request->user()?->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
