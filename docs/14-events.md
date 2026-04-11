# Events & School Calendar

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `event_management`

---

## Overview

The events module manages school events (sports days, open days, graduations, exams, holidays) and a structured academic calendar. Both are gated by the `event_management` module subscription. Events can target specific audiences or individual classes; calendar entries support iCal recurrence rules for recurring holidays and term dates.

---

## User Stories

> **As a school admin**, I want to create school events and publish them so students, parents, and staff can see what is coming up.

> **As a teacher**, I want to see upcoming events for my class so I can plan lessons around them.

> **As a parent**, I want to check the school calendar for holidays and exam dates.

> **As an admin**, I want to add recurring public holidays to the calendar linked to the academic year.

---

## Models

### Event

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Event name |
| `description` | text | Full event details |
| `event_type` | enum | `academic`, `sports`, `cultural`, `holiday`, `meeting`, `exam`, `other` |
| `start_date` | date | Start date |
| `end_date` | date | End date (same as start_date for single-day events) |
| `start_time` | time | Start time (HH:MM), null if `is_all_day` |
| `end_time` | time | End time (HH:MM), null if `is_all_day` |
| `location` | string | Venue or room |
| `organizer` | string | Organising person or team name |
| `target_audience` | enum | `all`, `students`, `staff`, `parents`, `teachers` |
| `class_id` | integer | Specific class (null = school-wide) |
| `is_all_day` | boolean | Whether the event spans the whole day |
| `status` | enum | `scheduled`, `ongoing`, `completed`, `cancelled` |
| `max_participants` | integer | Capacity limit (null = unlimited) |
| `attachments` | JSON | Array of file URLs |
| `created_by` | integer | FK → User who created the event |

**Scope `upcoming()`** — returns events where `start_date >= today` and `status != cancelled`, ordered by `start_date` ascending.

### Calendar

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Entry title |
| `description` | text | Details |
| `date` | date | Start date |
| `end_date` | date | End date (optional) |
| `type` | enum | `holiday`, `exam`, `event`, `term_start`, `term_end`, `other` |
| `color` | string | Hex colour for UI display (e.g. `#4CAF50`) |
| `is_recurring` | boolean | Whether the entry repeats |
| `recurrence_rule` | string | iCal RRULE string (e.g. `FREQ=YEARLY`) |
| `academic_year_id` | integer | Linked academic year |

---

## API Endpoints

**Required module:** `event_management`

### Events

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/events` | List events (filter: status, event_type, date range, search, class_id) |
| POST | `/api/v1/events` | Create an event |
| GET | `/api/v1/events/upcoming` | Upcoming events (paginated, limited via `?limit=`) |
| GET | `/api/v1/events/{id}` | Get event details |
| PUT | `/api/v1/events/{id}` | Update an event |
| DELETE | `/api/v1/events/{id}` | Delete an event |

> **Note on routing:** `/events/upcoming` is declared **before** `/events/{id}` in the route file so "upcoming" is not treated as an event ID.

### Calendar

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/calendars` | List calendar entries (filter: type, academic_year_id, month+year, date range) |
| POST | `/api/v1/calendars` | Create a calendar entry |
| GET | `/api/v1/calendars/{id}` | Get a calendar entry |
| PUT | `/api/v1/calendars/{id}` | Update a calendar entry |
| DELETE | `/api/v1/calendars/{id}` | Delete a calendar entry |

---

## Complete Request / Response Examples

### Create an Event (School-Wide)

```http
POST /api/v1/events
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Inter-House Sports Day",
  "description": "Annual inter-house sports competition. All houses compete in track, field, and ball events. Parents are invited.",
  "event_type": "sports",
  "start_date": "2026-05-15",
  "end_date": "2026-05-15",
  "start_time": "08:00",
  "end_time": "16:00",
  "location": "School Sports Complex",
  "organizer": "Mr. Emeka Eze (Sports HOD)",
  "target_audience": "all",
  "class_id": null,
  "is_all_day": true,
  "status": "scheduled",
  "max_participants": null,
  "attachments": [
    "https://storage.compasse.africa/events/sports-day-2026-schedule.pdf"
  ]
}
```

Response `201 Created`:
```json
{
  "message": "Event created successfully",
  "data": {
    "id": 14,
    "title": "Inter-House Sports Day",
    "description": "Annual inter-house sports competition...",
    "event_type": "sports",
    "start_date": "2026-05-15",
    "end_date": "2026-05-15",
    "start_time": "08:00",
    "end_time": "16:00",
    "location": "School Sports Complex",
    "organizer": "Mr. Emeka Eze (Sports HOD)",
    "target_audience": "all",
    "class_id": null,
    "is_all_day": true,
    "status": "scheduled",
    "attachments": [
      "https://storage.compasse.africa/events/sports-day-2026-schedule.pdf"
    ],
    "created_by": { "id": 1, "name": "Mr. Chukwuemeka Obi" },
    "created_at": "2026-03-30T09:00:00.000000Z"
  }
}
```

---

### Create a Class-Specific Event

```http
POST /api/v1/events
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "JSS 1A Science Fair Presentation",
  "description": "Students in JSS 1A will present their science fair projects to the judging panel.",
  "event_type": "academic",
  "start_date": "2026-04-22",
  "end_date": "2026-04-22",
  "start_time": "10:00",
  "end_time": "12:00",
  "location": "Science Laboratory Block B",
  "organizer": "Mr. Biodun Okonkwo",
  "target_audience": "students",
  "class_id": 7,
  "is_all_day": false,
  "status": "scheduled"
}
```

Response `201 Created`:
```json
{
  "message": "Event created successfully",
  "data": {
    "id": 15,
    "title": "JSS 1A Science Fair Presentation",
    "event_type": "academic",
    "start_date": "2026-04-22",
    "target_audience": "students",
    "class": { "id": 7, "name": "JSS 1A" },
    "status": "scheduled",
    "created_at": "2026-03-30T09:30:00.000000Z"
  }
}
```

---

### List Events (with Filters)

```http
GET /api/v1/events?status=scheduled&event_type=sports&date_from=2026-04-01&date_to=2026-06-30&search=sports
Authorization: Bearer {token}
```

Query parameters:

| Parameter | Description |
|-----------|-------------|
| `status` | Filter by `scheduled`, `ongoing`, `completed`, `cancelled` |
| `event_type` | Filter by `academic`, `sports`, `cultural`, `holiday`, `meeting`, `exam`, `other` |
| `target_audience` | Filter by audience |
| `class_id` | Filter to events for a specific class (includes school-wide events) |
| `date_from` | Events on or after this date |
| `date_to` | Events on or before this date |
| `search` | Search in title and description |
| `per_page` | Results per page (default 15) |

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 14,
      "title": "Inter-House Sports Day",
      "event_type": "sports",
      "start_date": "2026-05-15",
      "end_date": "2026-05-15",
      "location": "School Sports Complex",
      "target_audience": "all",
      "status": "scheduled",
      "is_all_day": true
    }
  ],
  "current_page": 1,
  "last_page": 1,
  "per_page": 15,
  "total": 1
}
```

---

### Get Upcoming Events

```http
GET /api/v1/events/upcoming?limit=5
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "upcoming_events": [
    {
      "id": 12,
      "title": "Mid-Term Break",
      "event_type": "holiday",
      "start_date": "2026-04-01",
      "end_date": "2026-04-04",
      "is_all_day": true,
      "target_audience": "all"
    },
    {
      "id": 13,
      "title": "Second Term Examinations Begin",
      "event_type": "exam",
      "start_date": "2026-04-14",
      "end_date": "2026-04-25",
      "is_all_day": false,
      "start_time": "08:00",
      "target_audience": "students"
    },
    {
      "id": 14,
      "title": "Inter-House Sports Day",
      "event_type": "sports",
      "start_date": "2026-05-15",
      "is_all_day": true,
      "target_audience": "all"
    },
    {
      "id": 15,
      "title": "JSS 1A Science Fair Presentation",
      "event_type": "academic",
      "start_date": "2026-04-22",
      "class": { "id": 7, "name": "JSS 1A" },
      "target_audience": "students"
    },
    {
      "id": 16,
      "title": "PTA Annual General Meeting",
      "event_type": "meeting",
      "start_date": "2026-05-03",
      "start_time": "10:00",
      "target_audience": "parents"
    }
  ],
  "count": 5
}
```

---

### Get Event Details

```http
GET /api/v1/events/14
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": {
    "id": 14,
    "title": "Inter-House Sports Day",
    "description": "Annual inter-house sports competition. All houses compete in track, field, and ball events. Parents are invited.",
    "event_type": "sports",
    "start_date": "2026-05-15",
    "end_date": "2026-05-15",
    "start_time": null,
    "end_time": null,
    "location": "School Sports Complex",
    "organizer": "Mr. Emeka Eze (Sports HOD)",
    "target_audience": "all",
    "class": null,
    "is_all_day": true,
    "status": "scheduled",
    "max_participants": null,
    "attachments": [
      "https://storage.compasse.africa/events/sports-day-2026-schedule.pdf"
    ],
    "created_by": { "id": 1, "name": "Mr. Chukwuemeka Obi" },
    "created_at": "2026-03-30T09:00:00.000000Z",
    "updated_at": "2026-03-30T09:00:00.000000Z"
  }
}
```

---

### Update an Event

```http
PUT /api/v1/events/14
Authorization: Bearer {token}
Content-Type: application/json

{
  "status": "ongoing",
  "location": "Main School Field (relocated from Sports Complex)"
}
```

Response `200 OK`:
```json
{
  "message": "Event updated successfully",
  "data": {
    "id": 14,
    "title": "Inter-House Sports Day",
    "status": "ongoing",
    "location": "Main School Field (relocated from Sports Complex)",
    "updated_at": "2026-05-15T08:05:00.000000Z"
  }
}
```

---

### Delete an Event

```http
DELETE /api/v1/events/14
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "message": "Event deleted successfully"
}
```

---

### Create a Calendar Entry (Holiday)

```http
POST /api/v1/calendars
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Easter Monday",
  "description": "Public holiday — school closed",
  "date": "2026-04-06",
  "end_date": "2026-04-06",
  "type": "holiday",
  "color": "#4CAF50",
  "is_recurring": true,
  "recurrence_rule": "FREQ=YEARLY",
  "academic_year_id": 3
}
```

Response `201 Created`:
```json
{
  "message": "Calendar entry created successfully",
  "data": {
    "id": 55,
    "title": "Easter Monday",
    "date": "2026-04-06",
    "type": "holiday",
    "color": "#4CAF50",
    "is_recurring": true,
    "recurrence_rule": "FREQ=YEARLY",
    "academic_year": { "id": 3, "name": "2025/2026" },
    "created_at": "2026-03-30T10:00:00.000000Z"
  }
}
```

---

### Create a Calendar Entry (Term Date)

```http
POST /api/v1/calendars
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Third Term Begins",
  "description": "Students resume for the third and final term of the 2025/2026 academic year.",
  "date": "2026-05-06",
  "type": "term_start",
  "color": "#2196F3",
  "is_recurring": false,
  "recurrence_rule": null,
  "academic_year_id": 3
}
```

Response `201 Created`:
```json
{
  "message": "Calendar entry created successfully",
  "data": {
    "id": 56,
    "title": "Third Term Begins",
    "date": "2026-05-06",
    "type": "term_start",
    "color": "#2196F3",
    "is_recurring": false,
    "academic_year": { "id": 3, "name": "2025/2026" },
    "created_at": "2026-03-30T10:05:00.000000Z"
  }
}
```

---

### View Calendar Entries for a Specific Month

```http
GET /api/v1/calendars?month=4&year=2026
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "month": 4,
  "year": 2026,
  "entries": [
    {
      "id": 55,
      "title": "Easter Monday",
      "date": "2026-04-06",
      "type": "holiday",
      "color": "#4CAF50",
      "is_recurring": true
    },
    {
      "id": 57,
      "title": "Second Term Examinations Begin",
      "date": "2026-04-14",
      "end_date": "2026-04-25",
      "type": "exam",
      "color": "#F44336",
      "is_recurring": false
    },
    {
      "id": 58,
      "title": "Second Term Ends",
      "date": "2026-04-30",
      "type": "term_end",
      "color": "#FF9800",
      "is_recurring": false
    }
  ],
  "count": 3
}
```

---

### List All Calendar Entries (with Filters)

```http
GET /api/v1/calendars?type=holiday&academic_year_id=3&date_from=2026-01-01&date_to=2026-12-31
Authorization: Bearer {token}
```

Query parameters:

| Parameter | Description |
|-----------|-------------|
| `type` | Filter by `holiday`, `exam`, `event`, `term_start`, `term_end`, `other` |
| `academic_year_id` | Filter by academic year |
| `month` | Month number (1–12). Combine with `year` for monthly view. |
| `year` | Calendar year |
| `date_from` | Entries on or after this date |
| `date_to` | Entries on or before this date |

---

### Update a Calendar Entry

```http
PUT /api/v1/calendars/55
Authorization: Bearer {token}
Content-Type: application/json

{
  "color": "#8BC34A",
  "description": "Public holiday — school closed. No activities scheduled."
}
```

Response `200 OK`:
```json
{
  "message": "Calendar entry updated successfully",
  "data": {
    "id": 55,
    "title": "Easter Monday",
    "color": "#8BC34A",
    "description": "Public holiday — school closed. No activities scheduled.",
    "updated_at": "2026-03-30T10:15:00.000000Z"
  }
}
```

---

### Delete a Calendar Entry

```http
DELETE /api/v1/calendars/55
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "message": "Calendar entry deleted successfully"
}
```

---

## Business Rules

1. **`created_by` auto-set** — Set to the authenticated user's ID on event creation; not required in the request body.
2. **Default status** — New events default to `scheduled`.
3. **Upcoming scope** — `GET /events/upcoming` uses the `scopeUpcoming()` model scope: `start_date >= today AND status != cancelled`, ordered by `start_date` ascending.
4. **Route ordering** — `/events/upcoming` must be declared before `/events/{id}` in the routes file to prevent "upcoming" being resolved as a numeric event ID.
5. **Calendar month filter** — Pass `?month=4&year=2026` to retrieve all entries for a specific month and year. The backend uses a `whereMonth` / `whereYear` query.
6. **Class-specific visibility** — Events with a `class_id` are only returned to users who belong to that class (student, class teacher). School-wide events (`class_id = null`) are visible to all.
7. **Recurrence rule** — The `recurrence_rule` field stores a standard iCal RRULE string. The backend does not automatically expand recurrences; the frontend is responsible for rendering repeating entries from the rule.

---

## Frontend Integration

### Tenancy

All event and calendar requests are sent to `https://{school_subdomain}.compasse.africa/api/v1/events/...` and `.../calendars/...`. The subdomain comes from local state set at login and is injected into all Axios requests.

### School Calendar Widget

The school calendar widget (monthly grid view) combines events and calendar entries into a unified display:

1. On month change, make two parallel requests:
   - `GET /events?date_from={firstDayOfMonth}&date_to={lastDayOfMonth}`
   - `GET /calendars?month={m}&year={y}`
2. Merge both response arrays and display each item on its corresponding date cell.
3. Use the calendar entry's `color` field to colour-code the dot or badge on each date.
4. Render multi-day events (where `end_date > start_date`) as a horizontal bar spanning multiple date cells.
5. On a date cell click, open a popover listing all events and calendar entries for that day.

### Upcoming Events Sidebar

The sidebar widget on the dashboard or parent portal:

1. Call `GET /events/upcoming?limit=5` on page load.
2. Render a list with event title, date, and a coloured event-type badge.
3. Refresh on tab focus or on a 5-minute polling interval.
4. Each list item links to the event detail modal.

### Event Detail Modal with RSVP

When a user clicks an event:

1. Fetch `GET /events/{id}` and render all fields in a modal.
2. Show title, description, date/time, location, organizer, and any attachments (as downloadable links).
3. If `target_audience` includes the current user's role, show a confirmation/RSVP button (if RSVP is enabled via `max_participants`).
4. For admin users, show Edit and Delete action buttons that call `PUT /events/{id}` and `DELETE /events/{id}`.
5. If `status === "cancelled"`, show a prominent "This event has been cancelled" banner.

```js
// Example: Load upcoming events for sidebar
const { data } = await axios.get('/events/upcoming', { params: { limit: 5 } });
setSidebarEvents(data.upcoming_events);

// Example: Load monthly calendar
const [eventsRes, calendarRes] = await Promise.all([
  axios.get('/events', { params: { date_from: firstDay, date_to: lastDay } }),
  axios.get('/calendars', { params: { month: currentMonth, year: currentYear } })
]);
const allEntries = [...eventsRes.data.data, ...calendarRes.data.entries];
setCalendarEntries(allEntries);
```
