# Hostel Management

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `hostel_management`

---

## Overview

The hostel module handles boarding student accommodation — room setup, student allocation, vacating, and maintenance requests.

---

## User Stories

> **As a school admin**, I want to create room records with capacity and pricing so I can manage the boarding house efficiently.

> **As a hostel master**, I want to allocate students to specific rooms and track occupancy in real time.

> **As a hostel master**, I want to vacate a student from their room (e.g. after term end) so the bed is freed for another student.

> **As any staff member**, I want to report maintenance issues in a room — plumbing, electrical, furniture — so they get tracked and resolved.

> **As an admin**, I want to see which rooms have available beds so I can quickly allocate new boarding students.

---

## Models

### HostelRoom
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `room_number` | string | Room identifier (e.g. `B204`) |
| `block` | string | Block / wing name (e.g. `Block B`) |
| `floor` | string | Floor level (e.g. `2nd Floor`) |
| `type` | enum | `single`, `double`, `triple`, `dormitory` |
| `capacity` | integer | Maximum beds |
| `occupied_count` | integer | Current occupants (auto-managed, default `0`) |
| `price_per_term` | decimal | Fee charged per term |
| `amenities` | JSON | Array of amenity strings (e.g. `["fan", "wardrobe", "toilet"]`) |
| `status` | enum | `available`, `occupied`, `maintenance`, `closed` |

**Computed accessor:** `available_beds` = `max(0, capacity - occupied_count)`

### HostelAllocation
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `room_id` | integer | Room assigned FK |
| `student_id` | integer | Student allocated FK |
| `academic_year_id` | integer | Academic year FK |
| `term_id` | integer | Term FK |
| `allocated_at` | date | Date allocated |
| `vacated_at` | date\|null | Date vacated (`null` if still active) |
| `status` | enum | `active`, `vacated`, `transferred` |
| `amount_paid` | decimal\|null | Payment for this allocation |
| `payment_status` | enum | `paid`, `partial`, `unpaid` |
| `notes` | string\|null | Additional notes |

### HostelMaintenance
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `room_id` | integer | Affected room FK |
| `title` | string | Short issue title |
| `description` | string | Full description of the problem |
| `reported_by` | integer | FK → User who reported |
| `assigned_to` | integer\|null | FK → User (maintenance staff) |
| `priority` | enum | `low`, `medium`, `high`, `urgent` |
| `status` | enum | `pending`, `in_progress`, `completed`, `cancelled` |
| `cost` | decimal\|null | Repair cost |
| `resolution_notes` | string\|null | Notes on how it was resolved |
| `completed_at` | timestamp\|null | Auto-set when status transitions to `completed` |

---

## API Endpoints

**Base path:** `/api/v1/hostel/`

### Rooms

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/rooms` | List rooms (filter: `status`, `type`, `block`, `available_only=1`) |
| POST | `/rooms` | Create room |
| GET | `/rooms/{id}` | Get room + active allocations + maintenance history |
| PUT | `/rooms/{id}` | Update room |
| DELETE | `/rooms/{id}` | Delete (blocked if active allocations exist) |

### Allocations

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/allocations` | List allocations (filter: `status`, `room_id`, `student_id`, `term_id`) |
| POST | `/allocations` | Allocate student to room |
| GET | `/allocations/{id}` | Get allocation details |
| PUT | `/allocations/{id}` | Update payment info or notes |
| DELETE | `/allocations/{id}` | Delete allocation record |
| POST | `/allocations/{id}/vacate` | Vacate student from room |

### Maintenance

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/maintenance` | List requests (filter: `status`, `priority`, `room_id`, `assigned_to`) |
| POST | `/maintenance` | Report a maintenance issue |
| GET | `/maintenance/{id}` | Get issue details |
| PUT | `/maintenance/{id}` | Update (assign staff, change status, add resolution) |
| DELETE | `/maintenance/{id}` | Delete request |

---

## Request / Response Examples

### Rooms

#### Create a Room

**Request**
```http
POST /api/v1/hostel/rooms HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "room_number": "B204",
  "block": "Block B",
  "floor": "2nd Floor",
  "type": "double",
  "capacity": 2,
  "price_per_term": 85000,
  "amenities": ["fan", "wardrobe", "study desk", "reading lamp"],
  "status": "available"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Room created successfully",
  "room": {
    "id": 8,
    "room_number": "B204",
    "block": "Block B",
    "floor": "2nd Floor",
    "type": "double",
    "capacity": 2,
    "occupied_count": 0,
    "available_beds": 2,
    "price_per_term": "85000.00",
    "amenities": ["fan", "wardrobe", "study desk", "reading lamp"],
    "status": "available",
    "created_at": "2026-03-30T08:00:00Z"
  }
}
```

#### List Rooms

**Request**
```http
GET /api/v1/hostel/rooms?block=Block+B&status=available HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 8,
      "room_number": "B204",
      "block": "Block B",
      "floor": "2nd Floor",
      "type": "double",
      "capacity": 2,
      "occupied_count": 1,
      "available_beds": 1,
      "price_per_term": "85000.00",
      "amenities": ["fan", "wardrobe", "study desk", "reading lamp"],
      "status": "available"
    },
    {
      "id": 9,
      "room_number": "B205",
      "block": "Block B",
      "floor": "2nd Floor",
      "type": "single",
      "capacity": 1,
      "occupied_count": 0,
      "available_beds": 1,
      "price_per_term": "95000.00",
      "amenities": ["ac", "wardrobe", "private toilet"],
      "status": "available"
    }
  ],
  "meta": {
    "total": 2,
    "per_page": 15,
    "current_page": 1
  }
}
```

#### List Available Rooms Only

**Request**
```http
GET /api/v1/hostel/rooms?available_only=1 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

Returns only rooms where `available_beds > 0`.

#### Get Single Room

**Request**
```http
GET /api/v1/hostel/rooms/8 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": {
    "id": 8,
    "room_number": "B204",
    "block": "Block B",
    "floor": "2nd Floor",
    "type": "double",
    "capacity": 2,
    "occupied_count": 1,
    "available_beds": 1,
    "price_per_term": "85000.00",
    "amenities": ["fan", "wardrobe", "study desk", "reading lamp"],
    "status": "available",
    "active_allocations": [
      {
        "id": 11,
        "student": { "id": 55, "name": "Amara Eze", "admission_number": "GFA/2024/0055" },
        "allocated_at": "2026-01-08",
        "status": "active",
        "payment_status": "paid"
      }
    ],
    "maintenance_history": [
      {
        "id": 5,
        "title": "Broken ceiling fan",
        "status": "completed",
        "priority": "medium",
        "completed_at": "2026-02-15T14:00:00Z"
      }
    ]
  }
}
```

#### Update a Room

**Request**
```http
PUT /api/v1/hostel/rooms/8 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "price_per_term": 90000,
  "amenities": ["fan", "wardrobe", "study desk", "reading lamp", "charging station"],
  "status": "available"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Room updated successfully",
  "room": {
    "id": 8,
    "room_number": "B204",
    "price_per_term": "90000.00",
    "amenities": ["fan", "wardrobe", "study desk", "reading lamp", "charging station"],
    "status": "available"
  }
}
```

#### Delete a Room

**Request**
```http
DELETE /api/v1/hostel/rooms/8 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Blocked response** (has active allocations) `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Cannot delete room with active student allocations."
}
```

---

### Allocations

#### Allocate Student to Room

**Request**
```http
POST /api/v1/hostel/allocations HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "room_id": 8,
  "student_id": 55,
  "academic_year_id": 1,
  "term_id": 2,
  "allocated_at": "2026-01-08",
  "amount_paid": 85000,
  "payment_status": "paid"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Student allocated to room successfully",
  "allocation": {
    "id": 11,
    "room": {
      "id": 8,
      "room_number": "B204",
      "block": "Block B",
      "capacity": 2,
      "occupied_count": 2,
      "available_beds": 0
    },
    "student": {
      "id": 55,
      "name": "Amara Eze",
      "admission_number": "GFA/2024/0055"
    },
    "academic_year_id": 1,
    "term_id": 2,
    "allocated_at": "2026-01-08",
    "vacated_at": null,
    "status": "active",
    "amount_paid": "85000.00",
    "payment_status": "paid"
  }
}
```

On success, `rooms.occupied_count` is incremented atomically inside a `DB::transaction()`.

**Room full** `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Room is at full capacity. No available beds."
}
```

**Student already allocated** `HTTP 422 Unprocessable Entity`
```json
{
  "message": "Student already has an active hostel allocation."
}
```

#### List Allocations

**Request**
```http
GET /api/v1/hostel/allocations?status=active&room_id=8 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 11,
      "room": { "id": 8, "room_number": "B204", "block": "Block B" },
      "student": { "id": 55, "name": "Amara Eze" },
      "allocated_at": "2026-01-08",
      "vacated_at": null,
      "status": "active",
      "payment_status": "paid",
      "amount_paid": "85000.00"
    }
  ],
  "meta": { "total": 1 }
}
```

#### Update Allocation (Payment Info)

**Request**
```http
PUT /api/v1/hostel/allocations/11 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "payment_status": "paid",
  "amount_paid": 90000,
  "notes": "Balance of 5,000 received on 2026-03-01"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Allocation updated successfully",
  "allocation": {
    "id": 11,
    "payment_status": "paid",
    "amount_paid": "90000.00",
    "notes": "Balance of 5,000 received on 2026-03-01"
  }
}
```

#### Vacate a Student

**Request**
```http
POST /api/v1/hostel/allocations/11/vacate HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "vacated_at": "2026-03-28"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Student vacated from room successfully",
  "allocation": {
    "id": 11,
    "status": "vacated",
    "vacated_at": "2026-03-28",
    "room": {
      "id": 8,
      "room_number": "B204",
      "occupied_count": 1,
      "available_beds": 1
    }
  }
}
```

On success, `rooms.occupied_count` is decremented atomically inside a `DB::transaction()`.

---

### Maintenance

#### Report a Maintenance Issue

**Request**
```http
POST /api/v1/hostel/maintenance HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "room_id": 8,
  "title": "Broken ceiling fan",
  "description": "The fan in room B204 stopped working. Possible motor fault. Students are complaining about the heat.",
  "priority": "medium"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Maintenance request submitted successfully",
  "maintenance": {
    "id": 5,
    "room": { "id": 8, "room_number": "B204", "block": "Block B" },
    "title": "Broken ceiling fan",
    "description": "The fan in room B204 stopped working. Possible motor fault. Students are complaining about the heat.",
    "priority": "medium",
    "status": "pending",
    "reported_by": { "id": 4, "name": "Mr. Chukwuemeka Obi" },
    "assigned_to": null,
    "cost": null,
    "resolution_notes": null,
    "completed_at": null,
    "created_at": "2026-03-30T12:00:00Z"
  }
}
```

#### List Maintenance Requests

**Request**
```http
GET /api/v1/hostel/maintenance?status=pending&priority=urgent HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 7,
      "room": { "room_number": "A101", "block": "Block A" },
      "title": "Burst water pipe",
      "priority": "urgent",
      "status": "pending",
      "reported_by": { "name": "Miss Fatima Sule" },
      "assigned_to": null,
      "created_at": "2026-03-30T07:30:00Z"
    }
  ],
  "meta": { "total": 1 }
}
```

#### Assign Maintenance Staff (Move to In Progress)

**Request**
```http
PUT /api/v1/hostel/maintenance/5 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "assigned_to": 7,
  "status": "in_progress"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Maintenance request updated successfully",
  "maintenance": {
    "id": 5,
    "status": "in_progress",
    "assigned_to": { "id": 7, "name": "Mr. Kola Maintenance" },
    "completed_at": null
  }
}
```

#### Mark as Completed (auto-sets `completed_at`)

**Request**
```http
PUT /api/v1/hostel/maintenance/5 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "status": "completed",
  "cost": 12000,
  "resolution_notes": "Fan motor replaced with a new unit. Tested and confirmed working."
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Maintenance request updated successfully",
  "maintenance": {
    "id": 5,
    "title": "Broken ceiling fan",
    "status": "completed",
    "cost": "12000.00",
    "resolution_notes": "Fan motor replaced with a new unit. Tested and confirmed working.",
    "assigned_to": { "id": 7, "name": "Mr. Kola Maintenance" },
    "completed_at": "2026-03-30T16:45:00Z"
  }
}
```

`completed_at` is set automatically by the model when `status` transitions to `completed`.

---

## Business Rules

1. **Capacity guard** — Allocation is rejected if `room.occupied_count >= room.capacity`.
2. **Duplicate active allocation** — A student cannot have two active hostel allocations simultaneously.
3. **Atomic occupancy** — `occupied_count` increments on allocation and decrements on vacate, both inside `DB::transaction()`.
4. **Delete cascade** — A room cannot be deleted while it has active allocations.
5. **Auto timestamp** — `completed_at` is set automatically when a maintenance record's status changes to `completed`.
6. **Available beds accessor** — `available_beds` is computed as `max(0, capacity - occupied_count)` and is included in all room responses.

---

## Frontend Integration

### How the Frontend Handles Tenancy

Hostel API calls are made to `https://{school}.compasse.africa/api/v1/hostel/...` with `Authorization: Bearer {token}`. The tenant is resolved by subdomain. If the school does not have `hostel_management` in its enabled modules list, the Hostel section is hidden from the navigation.

### Room Availability Grid

The hostel dashboard shows a grid of room cards, one per room, colour-coded by status:
- **Green** — `available` (has free beds)
- **Yellow** — `available` but `occupied_count = capacity - 1` (nearly full)
- **Orange** — `status = occupied` (full)
- **Red** — `status = maintenance` or `closed`

Data source: `GET /api/v1/hostel/rooms` — fetched on page load and refreshed after every allocation or vacate action.

Each room card displays:
- Room number + block + floor
- Occupancy bar: `{occupied_count} / {capacity}` with a visual progress bar
- Amenity chips: icons for `fan`, `ac`, `wardrobe`, etc.
- `price_per_term` formatted as currency

### Allocation Form with Capacity Indicator

When the hostel master clicks "Allocate Student" on a room card, a slide-over panel opens:

1. **Room summary** at top: room number, capacity, `available_beds` (live from the most recent GET response)
2. **Student search** — debounced autocomplete calls `GET /api/v1/students?search={query}`. If the selected student already has an active allocation, a warning is shown before submission.
3. **Academic year / term selectors** — dropdowns populated from `GET /api/v1/academic-years` and `/terms`.
4. **Payment fields** — `amount_paid` and `payment_status` (paid / partial / unpaid).
5. On submit, calls `POST /api/v1/hostel/allocations`. On success, the room card's occupancy bar updates immediately.

### Maintenance Tracker

The Maintenance section shows a Kanban-style board with four columns: **Pending**, **In Progress**, **Completed**, **Cancelled**.

- Staff can drag a card to change its status; the frontend calls `PUT /api/v1/hostel/maintenance/{id}` with the new `status`.
- When a card is moved to **Completed**, a form pops up requesting `cost` and `resolution_notes`.
- Completed cards show the `completed_at` timestamp and the assigned staff name.
- Filter buttons at the top allow filtering by `priority` (Low / Medium / High / Urgent) and by `block`.
