# Health Management

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `health_management`

---

## Overview

The health module maintains medical records for students — physical measurements, allergies, immunisations, doctor appointments, and active medications.

---

## User Stories

> **As a school nurse**, I want to create and update each student's health record including blood group, allergies, and known medical conditions so I can respond quickly to emergencies.

> **As a nurse**, I want to schedule health appointments for students and record diagnoses and prescriptions after visits.

> **As a nurse**, I want to track current medications so I know which students need to receive daily doses.

> **As a parent**, I want to view my child's health record and any upcoming appointments.

> **As a nurse**, I want the BMI to be calculated automatically from height and weight.

---

## Models

### HealthRecord

One record per student (unique constraint on `student_id`).

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `student_id` | integer | Student FK (unique) |
| `blood_group` | string\|null | e.g. `O+`, `AB-`, `B+` |
| `height_cm` | decimal\|null | Height in centimetres |
| `weight_kg` | decimal\|null | Weight in kilograms |
| `allergies` | JSON\|null | Array of allergy strings |
| `medical_conditions` | JSON\|null | Array of condition strings |
| `current_medications` | JSON\|null | Array of medication name strings (informational snapshot) |
| `immunization_records` | JSON\|null | Array of `{ vaccine, date, dose }` objects |
| `emergency_contact_name` | string\|null | Emergency contact full name |
| `emergency_contact_phone` | string\|null | Emergency contact phone number |
| `family_doctor_name` | string\|null | Family doctor name |
| `family_doctor_phone` | string\|null | Family doctor phone |
| `last_checkup_date` | date\|null | Date of last physical exam |
| `notes` | string\|null | General nurse notes |

**Computed accessor:** `bmi` = `weight_kg / (height_m)²` rounded to 1 decimal. Returns `null` if either `height_cm` or `weight_kg` is missing.

### HealthAppointment
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `student_id` | integer | Patient FK |
| `doctor_name` | string | Attending doctor / nurse name |
| `appointment_date` | date | Date of appointment |
| `appointment_time` | string | `HH:MM` format |
| `reason` | string | Purpose of visit |
| `status` | enum | `scheduled`, `completed`, `cancelled`, `no_show` |
| `diagnosis` | string\|null | Doctor's diagnosis (filled after appointment) |
| `prescription` | string\|null | Prescribed treatment / instructions |
| `follow_up_date` | date\|null | Next recommended appointment date |
| `created_by` | integer | FK → User (nurse who scheduled; auto-set) |

### Medication
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Auto-increment primary key |
| `student_id` | integer | Student receiving medication FK |
| `name` | string | Medication name |
| `dosage` | string | e.g. `500mg`, `100mcg` |
| `frequency` | string | e.g. `Twice daily after meals` |
| `start_date` | date | Start date |
| `end_date` | date\|null | End date (`null` = indefinite / ongoing) |
| `prescribed_by` | string\|null | Prescribing doctor name |
| `reason` | string\|null | Condition being treated |
| `side_effects` | string\|null | Known side effects |
| `status` | enum | `active`, `completed`, `discontinued` |

---

## API Endpoints

**Base path:** `/api/v1/health/`

### Health Records

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/records` | List records (filter: `student_id`, `blood_group`) |
| POST | `/records` | Create health record (one per student) |
| GET | `/records/{id}` | Get record including computed BMI |
| PUT | `/records/{id}` | Update record |
| DELETE | `/records/{id}` | Delete record |

### Appointments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/appointments` | List appointments (filter: `student_id`, `status`, `date_from`, `date_to`) |
| POST | `/appointments` | Schedule an appointment |
| GET | `/appointments/{id}` | Get appointment details |
| PUT | `/appointments/{id}` | Update (add diagnosis, prescription, set status, follow-up) |
| DELETE | `/appointments/{id}` | Delete appointment |

### Medications

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/medications` | List medications (filter: `student_id`, `status`) |
| POST | `/medications` | Prescribe a medication |
| GET | `/medications/{id}` | Get medication details |
| PUT | `/medications/{id}` | Update medication (status, dosage, end date) |
| DELETE | `/medications/{id}` | Delete medication record |

---

## Request / Response Examples

### Health Records

#### Create Health Record

**Request**
```http
POST /api/v1/health/records HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 42,
  "blood_group": "O+",
  "height_cm": 162,
  "weight_kg": 58,
  "allergies": ["Penicillin", "Peanuts"],
  "medical_conditions": ["Mild asthma"],
  "immunization_records": [
    { "vaccine": "Hepatitis B", "date": "2010-03-15", "dose": "3rd" },
    { "vaccine": "Yellow Fever", "date": "2018-09-01", "dose": "1st" }
  ],
  "emergency_contact_name": "Mrs. Ngozi Bello",
  "emergency_contact_phone": "08098765432",
  "family_doctor_name": "Dr. Adaeze Nwachukwu",
  "family_doctor_phone": "08011223344",
  "last_checkup_date": "2025-09-01",
  "notes": "Student uses an inhaler. Keep one in the sick bay."
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Health record created successfully",
  "record": {
    "id": 31,
    "student_id": 42,
    "student": {
      "id": 42,
      "name": "Adaora Bello",
      "admission_number": "GFA/2024/0042",
      "class": "SS2",
      "arm": "B"
    },
    "blood_group": "O+",
    "height_cm": "162.00",
    "weight_kg": "58.00",
    "bmi": 22.1,
    "allergies": ["Penicillin", "Peanuts"],
    "medical_conditions": ["Mild asthma"],
    "immunization_records": [
      { "vaccine": "Hepatitis B", "date": "2010-03-15", "dose": "3rd" },
      { "vaccine": "Yellow Fever", "date": "2018-09-01", "dose": "1st" }
    ],
    "emergency_contact_name": "Mrs. Ngozi Bello",
    "emergency_contact_phone": "08098765432",
    "family_doctor_name": "Dr. Adaeze Nwachukwu",
    "family_doctor_phone": "08011223344",
    "last_checkup_date": "2025-09-01",
    "notes": "Student uses an inhaler. Keep one in the sick bay.",
    "created_at": "2026-03-30T09:00:00Z"
  }
}
```

**Duplicate record** `HTTP 422 Unprocessable Entity`
```json
{
  "message": "A health record already exists for this student."
}
```

#### Get Health Record (with BMI)

**Request**
```http
GET /api/v1/health/records/31 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": {
    "id": 31,
    "student": {
      "id": 42,
      "name": "Adaora Bello",
      "admission_number": "GFA/2024/0042"
    },
    "blood_group": "O+",
    "height_cm": "162.00",
    "weight_kg": "58.00",
    "bmi": 22.1,
    "allergies": ["Penicillin", "Peanuts"],
    "medical_conditions": ["Mild asthma"],
    "current_medications": ["Salbutamol Inhaler"],
    "immunization_records": [
      { "vaccine": "Hepatitis B", "date": "2010-03-15", "dose": "3rd" },
      { "vaccine": "Yellow Fever", "date": "2018-09-01", "dose": "1st" }
    ],
    "emergency_contact_name": "Mrs. Ngozi Bello",
    "emergency_contact_phone": "08098765432",
    "family_doctor_name": "Dr. Adaeze Nwachukwu",
    "family_doctor_phone": "08011223344",
    "last_checkup_date": "2025-09-01",
    "notes": "Student uses an inhaler. Keep one in the sick bay.",
    "updated_at": "2026-03-30T09:00:00Z"
  }
}
```

#### Update Health Record

**Request**
```http
PUT /api/v1/health/records/31 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "height_cm": 164,
  "weight_kg": 60,
  "last_checkup_date": "2026-03-30",
  "notes": "Student uses an inhaler. Keep one in the sick bay. Weight has increased since last check."
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Health record updated successfully",
  "record": {
    "id": 31,
    "height_cm": "164.00",
    "weight_kg": "60.00",
    "bmi": 22.3,
    "last_checkup_date": "2026-03-30"
  }
}
```

---

### Appointments

#### Schedule an Appointment

**Request**
```http
POST /api/v1/health/appointments HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 42,
  "doctor_name": "Dr. Adaeze Nwachukwu",
  "appointment_date": "2026-04-05",
  "appointment_time": "10:00",
  "reason": "Routine asthma review and inhaler technique assessment"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Appointment scheduled successfully",
  "appointment": {
    "id": 9,
    "student": {
      "id": 42,
      "name": "Adaora Bello",
      "class": "SS2B"
    },
    "doctor_name": "Dr. Adaeze Nwachukwu",
    "appointment_date": "2026-04-05",
    "appointment_time": "10:00",
    "reason": "Routine asthma review and inhaler technique assessment",
    "status": "scheduled",
    "diagnosis": null,
    "prescription": null,
    "follow_up_date": null,
    "created_by": { "id": 5, "name": "Nurse Amaka" },
    "created_at": "2026-03-30T10:00:00Z"
  }
}
```

#### List Appointments

**Request**
```http
GET /api/v1/health/appointments?student_id=42&status=scheduled HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 9,
      "student": { "id": 42, "name": "Adaora Bello" },
      "doctor_name": "Dr. Adaeze Nwachukwu",
      "appointment_date": "2026-04-05",
      "appointment_time": "10:00",
      "reason": "Routine asthma review",
      "status": "scheduled",
      "follow_up_date": null
    }
  ],
  "meta": { "total": 1 }
}
```

#### List Appointments by Date Range

**Request**
```http
GET /api/v1/health/appointments?date_from=2026-04-01&date_to=2026-04-30 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

#### Record Appointment Outcome (Completed)

**Request**
```http
PUT /api/v1/health/appointments/9 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "status": "completed",
  "diagnosis": "Mild persistent asthma — well-controlled on current regimen",
  "prescription": "Continue Salbutamol Inhaler 100mcg. 2 puffs before exercise. Increase to 4 puffs if symptoms worsen.",
  "follow_up_date": "2026-07-05"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Appointment updated successfully",
  "appointment": {
    "id": 9,
    "status": "completed",
    "diagnosis": "Mild persistent asthma — well-controlled on current regimen",
    "prescription": "Continue Salbutamol Inhaler 100mcg. 2 puffs before exercise. Increase to 4 puffs if symptoms worsen.",
    "follow_up_date": "2026-07-05",
    "student": { "id": 42, "name": "Adaora Bello" }
  }
}
```

#### Mark Appointment as No-Show

**Request**
```http
PUT /api/v1/health/appointments/9 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "status": "no_show"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Appointment updated successfully",
  "appointment": {
    "id": 9,
    "status": "no_show"
  }
}
```

---

### Medications

#### Prescribe a Medication

**Request**
```http
POST /api/v1/health/medications HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 42,
  "name": "Salbutamol Inhaler",
  "dosage": "100mcg",
  "frequency": "As needed — max 4 puffs daily. 2 puffs before exercise.",
  "start_date": "2026-01-10",
  "end_date": null,
  "prescribed_by": "Dr. Adaeze Nwachukwu",
  "reason": "Mild persistent asthma",
  "side_effects": "Mild tremor or palpitations at high doses",
  "status": "active"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Medication added successfully",
  "medication": {
    "id": 17,
    "student_id": 42,
    "student": { "id": 42, "name": "Adaora Bello" },
    "name": "Salbutamol Inhaler",
    "dosage": "100mcg",
    "frequency": "As needed — max 4 puffs daily. 2 puffs before exercise.",
    "start_date": "2026-01-10",
    "end_date": null,
    "prescribed_by": "Dr. Adaeze Nwachukwu",
    "reason": "Mild persistent asthma",
    "side_effects": "Mild tremor or palpitations at high doses",
    "status": "active",
    "created_at": "2026-03-30T11:00:00Z"
  }
}
```

#### List Medications

**Request**
```http
GET /api/v1/health/medications?student_id=42&status=active HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 17,
      "student": { "id": 42, "name": "Adaora Bello" },
      "name": "Salbutamol Inhaler",
      "dosage": "100mcg",
      "frequency": "As needed — max 4 puffs daily",
      "start_date": "2026-01-10",
      "end_date": null,
      "prescribed_by": "Dr. Adaeze Nwachukwu",
      "reason": "Mild persistent asthma",
      "status": "active"
    }
  ],
  "meta": { "total": 1 }
}
```

#### Update Medication Status (Discontinue)

**Request**
```http
PUT /api/v1/health/medications/17 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "status": "discontinued",
  "end_date": "2026-03-30"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Medication updated successfully",
  "medication": {
    "id": 17,
    "name": "Salbutamol Inhaler",
    "status": "discontinued",
    "end_date": "2026-03-30"
  }
}
```

#### Mark Medication as Completed (Course Finished)

**Request**
```http
PUT /api/v1/health/medications/17 HTTP/1.1
Host: greenfield.compasse.africa
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "status": "completed",
  "end_date": "2026-04-10"
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Medication updated successfully",
  "medication": {
    "id": 17,
    "name": "Salbutamol Inhaler",
    "status": "completed",
    "end_date": "2026-04-10"
  }
}
```

---

## Business Rules

1. **One record per student** — Creating a second health record for the same student is rejected with `HTTP 422`.
2. **BMI auto-calculation** — Computed on-the-fly from `height_cm` and `weight_kg`; returns `null` if either field is missing.
3. **Appointment `created_by`** — Auto-set to the authenticated nurse/user on creation; not exposed as a writable field.
4. **Date filters** — Appointment listing supports `date_from` and `date_to` query parameters to power calendar views.
5. **Medication default status** — Medications default to `active` if no `status` is provided on creation.
6. **Medication lifecycle** — Valid status transitions are: `active` → `completed` (course finished), `active` → `discontinued` (stopped early).

---

## Frontend Integration

### How the Frontend Handles Tenancy

All health API calls go to `https://{school}.compasse.africa/api/v1/health/...` with `Authorization: Bearer {token}`. The `health_management` module flag is checked on boot; if not enabled the Health section is hidden from navigation. Health data is sensitive — the frontend should never cache it in a shared location (use in-memory state only).

### Student Health Card

The student health card is a dedicated panel within the student profile page (or accessible directly from the nurse's dashboard by searching for a student). It displays:

- **Vitals row:** Blood group badge (e.g. `O+`), Height, Weight, and computed BMI with a colour indicator:
  - BMI < 18.5 — blue "Underweight"
  - BMI 18.5–24.9 — green "Normal"
  - BMI 25–29.9 — yellow "Overweight"
  - BMI ≥ 30 — red "Obese"
- **Allergies list** — Rendered as red warning chips (e.g. `Penicillin`, `Peanuts`). Highly visible.
- **Active Medications** — Each active medication is shown as a card with name, dosage, frequency, and prescribing doctor.
- **Known Conditions** — Bulleted list of `medical_conditions`.
- **Emergency Contact** — Name + phone, with a tel-link for one-tap calling.
- **Last Checkup Date** — Displayed with a warning if older than 6 months.

Data source: `GET /api/v1/health/records?student_id={id}` (returns the single record or empty array).

### Appointment Calendar

The appointments page uses a calendar view (monthly grid). Data is fetched per month:

```
GET /api/v1/health/appointments?date_from=2026-04-01&date_to=2026-04-30
```

Each day with an appointment shows a coloured dot. Clicking a day opens a list of that day's appointments. Each appointment card shows:
- Student name + class
- Time + doctor name
- Status badge (Scheduled / Completed / Cancelled / No-Show)
- An "Update" button that opens a form pre-filled with `diagnosis`, `prescription`, and `follow_up_date` fields.

Upcoming follow-ups (appointments where `follow_up_date` is within 7 days) are surfaced as a banner at the top of the page.
