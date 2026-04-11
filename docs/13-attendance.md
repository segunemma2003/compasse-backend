# Attendance Management

> **Base URL:** `https://{subdomain}.compasse.net/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `attendance_management`

---

## Overview

The attendance module tracks student and staff presence using a polymorphic model — the same table records attendance for any type of person (student, teacher, staff). Attendance can be marked individually or in bulk for an entire class. Staff check-in and check-out are also handled through the same endpoint by specifying `attendanceable_type`.

---

## User Stories

> **As a teacher**, I want to mark daily attendance for my class, recording each student as present, absent, or late.

> **As a school admin**, I want to view class-level and student-level attendance reports with percentages.

> **As a parent**, I want to see my child's attendance record so I am aware of any absences.

> **As an admin**, I want attendance to track check-in/check-out times for staff so I can generate time and payroll reports.

---

## Attendance Model

The model uses **polymorphic association** — `attendanceable_type` + `attendanceable_id` can point to `Student`, `Teacher`, or any other staff model.

| Field | Type | Description |
|-------|------|-------------|
| `attendanceable_id` | integer | ID of the person |
| `attendanceable_type` | string | Fully qualified model class, e.g. `App\Models\Student` |
| `date` | date | Attendance date |
| `status` | derived | `present` / `absent` / `completed` / `pending` (never stored) |
| `check_in_time` | datetime | Datetime of check-in |
| `check_out_time` | datetime | Datetime of check-out |
| `total_hours` | float | Computed hours worked |
| `break_duration` | integer | Break time in minutes |
| `overtime_hours` | float | Hours beyond standard 8 hrs |
| `is_late` | boolean | Whether check-in was after 08:00 |
| `late_minutes` | integer | Minutes late |
| `is_absent` | boolean | Explicitly marked absent |
| `absence_reason` | string | Reason if absent |
| `is_excused` | boolean | Whether the absence is excused |
| `excuse_notes` | string | Justification for excused absence |
| `marked_by` | integer | FK → User who recorded the attendance |
| `location` | string | GPS coordinates or room identifier |

**Status is derived** (not stored):
- `absent` — if `is_absent = true`
- `present` — if checked in but not yet checked out
- `completed` — if both `check_in_time` and `check_out_time` are set
- `pending` — otherwise (no check-in recorded yet)

---

## API Endpoints

**Base path:** `/api/v1/attendance/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | List all attendance records |
| GET | `/reports` | Attendance summary reports |
| GET | `/students` | All student attendance records |
| GET | `/teachers` | All teacher attendance records |
| GET | `/class/{class_id}` | Class attendance for a specific date |
| GET | `/student/{student_id}` | Individual student attendance history |
| POST | `/mark` | Mark attendance (single or bulk) |
| PUT | `/{id}` | Correct an attendance record |
| DELETE | `/{id}` | Delete an attendance record |
| GET | `/{id}` | Get a single record |

---

## Complete Request / Response Examples

### Bulk Mark Class Attendance (Students)

```http
POST /api/v1/attendance/mark
Authorization: Bearer {token}
Content-Type: application/json

{
  "date": "2026-03-30",
  "class_id": 3,
  "records": [
    {
      "student_id": 10,
      "is_absent": false,
      "check_in_time": "2026-03-30 07:55:00"
    },
    {
      "student_id": 11,
      "is_absent": false,
      "check_in_time": "2026-03-30 08:10:00",
      "is_late": true,
      "late_minutes": 10
    },
    {
      "student_id": 12,
      "is_absent": true,
      "absence_reason": "Sick — parent notified via phone"
    },
    {
      "student_id": 13,
      "is_absent": true,
      "absence_reason": "Family bereavement",
      "is_excused": true,
      "excuse_notes": "Parent submitted a written excuse letter on 2026-03-29"
    }
  ]
}
```

Response `200 OK`:
```json
{
  "message": "Attendance marked successfully",
  "data": {
    "date": "2026-03-30",
    "class": { "id": 3, "name": "JSS 2B" },
    "total_students": 4,
    "present": 2,
    "absent": 2,
    "late": 1,
    "excused": 1,
    "marked_by": { "id": 5, "name": "Mrs. Ngozi Eze" },
    "records": [
      {
        "id": 1001,
        "student": { "id": 10, "name": "Emeka Nwosu" },
        "status": "present",
        "is_late": false,
        "check_in_time": "2026-03-30T07:55:00.000000Z"
      },
      {
        "id": 1002,
        "student": { "id": 11, "name": "Amaka Obi" },
        "status": "present",
        "is_late": true,
        "late_minutes": 10,
        "check_in_time": "2026-03-30T08:10:00.000000Z"
      },
      {
        "id": 1003,
        "student": { "id": 12, "name": "Dare Adeyemi" },
        "status": "absent",
        "is_excused": false,
        "absence_reason": "Sick — parent notified via phone"
      },
      {
        "id": 1004,
        "student": { "id": 13, "name": "Chioma Dike" },
        "status": "absent",
        "is_excused": true,
        "absence_reason": "Family bereavement"
      }
    ]
  }
}
```

---

### Staff Check-In

```http
POST /api/v1/attendance/mark
Authorization: Bearer {token}
Content-Type: application/json

{
  "attendanceable_type": "App\\Models\\Teacher",
  "attendanceable_id": 5,
  "date": "2026-03-30",
  "check_in_time": "2026-03-30 07:48:00",
  "location": "Main Gate — GPS: 6.5244,3.3792"
}
```

Response `201 Created`:
```json
{
  "message": "Check-in recorded",
  "data": {
    "id": 2055,
    "attendanceable_type": "App\\Models\\Teacher",
    "attendanceable_id": 5,
    "teacher": { "id": 5, "name": "Mr. Biodun Okonkwo" },
    "date": "2026-03-30",
    "check_in_time": "2026-03-30T07:48:00.000000Z",
    "is_late": false,
    "status": "present",
    "location": "Main Gate — GPS: 6.5244,3.3792"
  }
}
```

---

### Staff Check-Out

```http
PUT /api/v1/attendance/2055
Authorization: Bearer {token}
Content-Type: application/json

{
  "check_out_time": "2026-03-30 15:30:00",
  "break_duration": 60
}
```

Response `200 OK`:
```json
{
  "message": "Check-out recorded",
  "data": {
    "id": 2055,
    "teacher": { "id": 5, "name": "Mr. Biodun Okonkwo" },
    "date": "2026-03-30",
    "check_in_time": "2026-03-30T07:48:00.000000Z",
    "check_out_time": "2026-03-30T15:30:00.000000Z",
    "total_hours": 6.7,
    "break_duration": 60,
    "overtime_hours": 0,
    "status": "completed"
  }
}
```

---

### Get Class Attendance for a Specific Date

```http
GET /api/v1/attendance/class/3?date=2026-03-30
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "class": { "id": 3, "name": "JSS 2B" },
  "date": "2026-03-30",
  "summary": {
    "total_students": 32,
    "present": 28,
    "absent": 4,
    "late": 3,
    "not_marked": 0,
    "attendance_rate": 87.5
  },
  "records": [
    {
      "id": 1001,
      "student": {
        "id": 10,
        "name": "Emeka Nwosu",
        "admission_number": "GF/2024/001"
      },
      "status": "present",
      "is_late": false,
      "check_in_time": "2026-03-30T07:55:00.000000Z"
    },
    {
      "id": 1002,
      "student": {
        "id": 11,
        "name": "Amaka Obi",
        "admission_number": "GF/2024/002"
      },
      "status": "present",
      "is_late": true,
      "late_minutes": 10,
      "check_in_time": "2026-03-30T08:10:00.000000Z"
    },
    {
      "id": 1003,
      "student": {
        "id": 12,
        "name": "Dare Adeyemi",
        "admission_number": "GF/2024/003"
      },
      "status": "absent",
      "is_excused": false,
      "absence_reason": "Sick — parent notified via phone"
    }
  ]
}
```

---

### Get Student Attendance with Date Range and Summary Stats

```http
GET /api/v1/attendance/student/10?date_from=2026-01-01&date_to=2026-03-31
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "student": {
    "id": 10,
    "name": "Emeka Nwosu",
    "admission_number": "GF/2024/001",
    "class": "JSS 2B"
  },
  "date_range": {
    "from": "2026-01-01",
    "to": "2026-03-31"
  },
  "summary": {
    "total_school_days": 58,
    "present_days": 52,
    "absent_days": 6,
    "late_arrivals": 4,
    "excused_absences": 2,
    "unexcused_absences": 4,
    "attendance_percentage": 89.7
  },
  "records": [
    {
      "id": 1001,
      "date": "2026-03-30",
      "status": "present",
      "is_late": false,
      "check_in_time": "2026-03-30T07:55:00.000000Z",
      "check_out_time": null
    },
    {
      "id": 998,
      "date": "2026-03-25",
      "status": "absent",
      "is_excused": false,
      "absence_reason": "Sick — parent notified via phone"
    }
  ],
  "current_page": 1,
  "last_page": 4,
  "per_page": 15,
  "total": 58
}
```

---

### Attendance Reports

```http
GET /api/v1/attendance/reports?class_id=3&term_id=2&academic_year_id=3&type=summary
Authorization: Bearer {token}
```

Query parameters:

| Parameter | Description |
|-----------|-------------|
| `class_id` | Filter by class |
| `term_id` | Filter by term |
| `academic_year_id` | Filter by academic year |
| `type` | `summary` (aggregated) or `detailed` (per-day records) |
| `date_from` | Start date filter |
| `date_to` | End date filter |

Response `200 OK`:
```json
{
  "report_type": "summary",
  "class": { "id": 3, "name": "JSS 2B" },
  "term": { "id": 2, "name": "Second Term" },
  "academic_year": { "id": 3, "name": "2025/2026" },
  "school_days_in_term": 58,
  "generated_at": "2026-03-30T12:00:00.000000Z",
  "students": [
    {
      "student": { "id": 10, "name": "Emeka Nwosu", "admission_number": "GF/2024/001" },
      "present_days": 52,
      "absent_days": 6,
      "late_arrivals": 4,
      "attendance_percentage": 89.7
    },
    {
      "student": { "id": 11, "name": "Amaka Obi", "admission_number": "GF/2024/002" },
      "present_days": 55,
      "absent_days": 3,
      "late_arrivals": 2,
      "attendance_percentage": 94.8
    }
  ],
  "class_summary": {
    "average_attendance_percentage": 91.3,
    "perfect_attendance_count": 5,
    "chronic_absentees": 2
  }
}
```

---

### Correct an Attendance Record

```http
PUT /api/v1/attendance/1003
Authorization: Bearer {token}
Content-Type: application/json

{
  "is_absent": false,
  "check_in_time": "2026-03-30 08:45:00",
  "is_late": true,
  "late_minutes": 45,
  "correction_reason": "Student was present but not marked due to teacher error"
}
```

Response `200 OK`:
```json
{
  "message": "Attendance record updated",
  "data": {
    "id": 1003,
    "student": { "id": 12, "name": "Dare Adeyemi" },
    "date": "2026-03-30",
    "status": "present",
    "is_late": true,
    "late_minutes": 45,
    "updated_by": { "id": 1, "name": "Mr. Chukwuemeka Obi" },
    "updated_at": "2026-03-30T13:00:00.000000Z"
  }
}
```

---

### Get All Student Attendance

```http
GET /api/v1/attendance/students?class_id=3&date=2026-03-30
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 1001,
      "student": { "id": 10, "name": "Emeka Nwosu", "class": "JSS 2B" },
      "date": "2026-03-30",
      "status": "present",
      "is_late": false
    }
  ],
  "current_page": 1,
  "last_page": 3,
  "per_page": 15,
  "total": 32
}
```

---

### Get All Teacher Attendance

```http
GET /api/v1/attendance/teachers?date=2026-03-30
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 2055,
      "teacher": { "id": 5, "name": "Mr. Biodun Okonkwo", "department": "Mathematics" },
      "date": "2026-03-30",
      "status": "completed",
      "check_in_time": "2026-03-30T07:48:00.000000Z",
      "check_out_time": "2026-03-30T15:30:00.000000Z",
      "total_hours": 6.7,
      "is_late": false
    }
  ],
  "current_page": 1,
  "total": 28
}
```

---

## Business Rules

1. **Polymorphic model** — One attendance table covers students, teachers, and any staff. Differentiate using `attendanceable_type`.
2. **Status is derived** — Never stored; always computed from `is_absent`, `check_in_time`, and `check_out_time`.
3. **Late detection** — `isLate()` compares `check_in_time` against expected `08:00`; `getLateMinutes()` returns the difference in minutes.
4. **Overtime detection** — `isOvertime()` checks if `total_hours > 8` (i.e., more than a standard working day).
5. **Attendance percentage** — Calculated as `(present_days / total_school_days) × 100` in the student report.
6. **Bulk mark is idempotent** — Re-submitting attendance for the same date and class updates existing records rather than creating duplicates.
7. **Correction audit** — Any update to a record must include `correction_reason` and stores `updated_by` for audit trail.

---

## Frontend Integration

### Tenancy

All attendance requests go to `https://{school_subdomain}.compasse.net/api/v1/attendance/...`. The subdomain is resolved from local state and injected via an Axios interceptor.

### Daily Class Register UI

The daily register is the primary teacher-facing attendance UI:

1. **Load class list** — `GET /classes/{classId}/students` to populate the student roster for the day.
2. **Pre-fill from existing records** — Call `GET /attendance/class/{classId}?date=YYYY-MM-DD` on mount. If records exist (attendance already marked), pre-populate each student's status row.
3. **Mark each student** — Render a row per student with three toggle buttons: Present, Late, Absent. Selecting "Late" reveals a "Minutes late" input. Selecting "Absent" reveals a text field for the absence reason and an "Excused" checkbox.
4. **Submit** — On form submit call `POST /attendance/mark` with the full `records` array. Disable the submit button after the first click.
5. **Correction mode** — If attendance was already submitted today, show an "Edit" button. On edit, each row calls `PUT /attendance/{id}` individually with `correction_reason`.

### Attendance Calendar Heatmap (Individual Student)

The parent and student portal shows a monthly heatmap of attendance:

1. Fetch the student's attendance records for the current academic term via `GET /attendance/student/{studentId}?date_from={termStart}&date_to={termEnd}`.
2. Map each date to a colour: green (present), orange (late), red (absent), grey (school holiday / non-school day).
3. Render a calendar grid (month view) with coloured dots on each date.
4. On date click, show a tooltip with the full record details (check-in time, reason if absent, etc.).
5. Display the summary stats (attendance %, total present, total absent) above the calendar.

```js
// Example: Fetch student attendance for heatmap
const { data } = await axios.get(
  `/attendance/student/${studentId}`,
  { params: { date_from: termStart, date_to: termEnd, per_page: 0 } }
);
// data.records is an array of { date, status, is_late, ... }
// data.summary holds { attendance_percentage, present_days, absent_days, ... }
```
