# Communication — Messaging, Notifications, SMS & Email

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** None (messages & notifications) | `sms_integration` (SMS) | `email_integration` (Email)

---

## Overview

The communication module provides internal messaging, push-style notifications, and outbound SMS/Email dispatch. Messages and notifications are core features available to all authenticated tenant users. SMS and Email require their respective module subscriptions. All outbound SMS and email are **queued** — the API responds immediately and delivery happens asynchronously via Laravel Horizon queue workers.

---

## User Stories

> **As any user**, I want to send and receive internal messages to/from other users in my school.

> **As an admin**, I want to send a bulk SMS to all parents informing them of an event or emergency.

> **As a teacher**, I want to notify students about an assignment deadline via in-app notification.

> **As an accountant**, I want to email a parent a payment receipt.

---

## Internal Messages

**No module gate — available to all tenant users.**
**Base path:** `/api/v1/communication/messages`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/messages` | List messages (inbox / outbox filter) |
| POST | `/messages` | Send a message |
| GET | `/messages/{id}` | Read a message |
| PUT | `/messages/{id}` | Update message |
| DELETE | `/messages/{id}` | Delete message |
| PUT | `/messages/{id}/read` | Mark as read |

---

### Send a Message

```http
POST /api/v1/communication/messages
Authorization: Bearer {token}
Content-Type: application/json

{
  "to": [3, 7, 12],
  "subject": "Parent-Teacher Meeting — Friday 4 April",
  "body": "Dear colleagues, please be reminded that the parent-teacher meeting is scheduled for Friday 4 April at 3:00 PM in the main hall. Attendance is compulsory.",
  "priority": "normal"
}
```

Response `201 Created`:
```json
{
  "message": "Message sent successfully",
  "data": {
    "id": 88,
    "subject": "Parent-Teacher Meeting — Friday 4 April",
    "body": "Dear colleagues, please be reminded...",
    "from": { "id": 1, "name": "Mr. Chukwuemeka Obi" },
    "recipients": [
      { "id": 3, "name": "Mrs. Funmilayo Adesanya" },
      { "id": 7, "name": "Mr. Biodun Okonkwo" },
      { "id": 12, "name": "Miss Ngozi Eze" }
    ],
    "priority": "normal",
    "sent_at": "2026-03-30T08:30:00.000000Z"
  }
}
```

---

### List Messages (Inbox / Outbox)

```http
GET /api/v1/communication/messages?type=inbox&per_page=20&page=1
Authorization: Bearer {token}
```

Query parameters:
- `type` — `inbox` (received) or `outbox` (sent). Defaults to `inbox`.
- `is_read` — `true` / `false` to filter by read status.
- `search` — Full-text search on subject and body.

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 88,
      "subject": "Parent-Teacher Meeting — Friday 4 April",
      "body_preview": "Dear colleagues, please be reminded...",
      "from": { "id": 1, "name": "Mr. Chukwuemeka Obi" },
      "is_read": false,
      "priority": "normal",
      "sent_at": "2026-03-30T08:30:00.000000Z"
    },
    {
      "id": 85,
      "subject": "Timetable Update — Week 11",
      "body_preview": "Please note that Monday's schedule has been revised...",
      "from": { "id": 2, "name": "Mrs. Adaeze Ugwu" },
      "is_read": true,
      "priority": "high",
      "sent_at": "2026-03-28T10:00:00.000000Z"
    }
  ],
  "unread_count": 5,
  "current_page": 1,
  "last_page": 3,
  "per_page": 20,
  "total": 42
}
```

---

### Read a Message

```http
GET /api/v1/communication/messages/88
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": {
    "id": 88,
    "subject": "Parent-Teacher Meeting — Friday 4 April",
    "body": "Dear colleagues, please be reminded that the parent-teacher meeting is scheduled for Friday 4 April at 3:00 PM in the main hall. Attendance is compulsory.",
    "from": { "id": 1, "name": "Mr. Chukwuemeka Obi", "role": "admin" },
    "recipients": [
      { "id": 3, "name": "Mrs. Funmilayo Adesanya", "is_read": true, "read_at": "2026-03-30T09:15:00.000000Z" },
      { "id": 7, "name": "Mr. Biodun Okonkwo", "is_read": false, "read_at": null }
    ],
    "priority": "normal",
    "sent_at": "2026-03-30T08:30:00.000000Z",
    "is_read": false
  }
}
```

---

### Mark as Read

```http
PUT /api/v1/communication/messages/88/read
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "message": "Message marked as read",
  "data": {
    "id": 88,
    "is_read": true,
    "read_at": "2026-03-30T09:20:00.000000Z"
  }
}
```

---

## Notifications

**No module gate — available to all tenant users.**
**Base path:** `/api/v1/communication/notifications`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notifications` | List notifications for the authenticated user |
| POST | `/notifications` | Create a notification (bulk, role-targeted) |
| GET | `/notifications/{id}` | Get a single notification |
| PUT | `/notifications/{id}` | Update notification |
| DELETE | `/notifications/{id}` | Delete notification |
| PUT | `/notifications/{id}/read` | Mark a single notification as read |
| PUT | `/notifications/read-all` | Mark all notifications as read |

---

### Create Notification (Bulk with Role Filter)

```http
POST /api/v1/communication/notifications
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Second Term Exams Begin Monday",
  "body": "Please remind your students that second term examinations begin on Monday 14 April. All students must come with their school ID.",
  "type": "announcement",
  "target_role": "teacher",
  "class_id": null,
  "action_url": "/events/13",
  "send_push": true
}
```

Request fields:
- `target_role` — `all`, `student`, `teacher`, `parent`, `admin`. Sends to every user with that role.
- `class_id` — Optionally scope to a single class. Pass `null` for school-wide.
- `action_url` — Deep link the notification to a specific screen.
- `send_push` — If `true`, also dispatches a push notification job.

Response `201 Created`:
```json
{
  "message": "Notification sent",
  "data": {
    "id": 204,
    "title": "Second Term Exams Begin Monday",
    "body": "Please remind your students...",
    "type": "announcement",
    "target_role": "teacher",
    "recipients_count": 28,
    "created_at": "2026-03-30T09:00:00.000000Z"
  }
}
```

---

### List Notifications

```http
GET /api/v1/communication/notifications?is_read=false&per_page=15
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 204,
      "title": "Second Term Exams Begin Monday",
      "body": "Please remind your students...",
      "type": "announcement",
      "is_read": false,
      "action_url": "/events/13",
      "created_at": "2026-03-30T09:00:00.000000Z"
    },
    {
      "id": 201,
      "title": "New Assignment: Essay on WWI",
      "body": "A new assignment has been posted for SS 2A — History.",
      "type": "assignment",
      "is_read": false,
      "action_url": "/assignments/33",
      "created_at": "2026-03-28T14:00:00.000000Z"
    }
  ],
  "unread_count": 7,
  "current_page": 1,
  "last_page": 1,
  "per_page": 15,
  "total": 7
}
```

---

### Mark All as Read

```http
PUT /api/v1/communication/notifications/read-all
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "message": "All notifications marked as read",
  "marked_count": 7
}
```

---

## SMS

**Required module:** `sms_integration`
**Base path:** `/api/v1/communication/sms`

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/sms/send` | Send a single SMS |
| POST | `/sms/bulk` | Send SMS to multiple recipients |
| GET | `/sms/logs` | View SMS delivery logs |

---

### Send a Single SMS

```http
POST /api/v1/communication/sms/send
Authorization: Bearer {token}
Content-Type: application/json

{
  "to": "+2348012345678",
  "message": "Dear Parent, second term fees are due by April 30th. Please visit the school portal to pay. — Greenfield Academy",
  "sender_id": "Greenfield"
}
```

Response `200 OK`:
```json
{
  "message": "SMS queued for delivery",
  "data": {
    "id": 512,
    "to": "+2348012345678",
    "message": "Dear Parent, second term fees are due by April 30th...",
    "sender_id": "Greenfield",
    "status": "queued",
    "provider": "termii",
    "queued_at": "2026-03-30T10:00:00.000000Z"
  }
}
```

The API returns immediately. Delivery happens via `SendSMSJob` on the `sms` queue.

---

### Bulk SMS (Multiple Recipients)

```http
POST /api/v1/communication/sms/bulk
Authorization: Bearer {token}
Content-Type: application/json

{
  "recipients": [
    { "phone": "+2348012345678", "name": "Mrs. Okafor" },
    { "phone": "+2347089001234", "name": "Mr. Adeyemi" },
    { "phone": "+2348055667788", "name": "Mrs. Eze" }
  ],
  "message": "Dear {name}, the next PTA meeting holds on Saturday 12 April at 10 AM. Please attend.",
  "sender_id": "Greenfield",
  "schedule_at": null
}
```

The `{name}` placeholder is replaced per recipient. Set `schedule_at` to an ISO datetime string to delay dispatch.

Response `200 OK`:
```json
{
  "message": "Bulk SMS queued for delivery",
  "data": {
    "batch_id": "sms-batch-20260330-001",
    "total_recipients": 3,
    "status": "queued",
    "estimated_cost": 3,
    "queued_at": "2026-03-30T10:05:00.000000Z"
  }
}
```

---

### SMS Delivery Logs

```http
GET /api/v1/communication/sms/logs?status=failed&date_from=2026-03-01&date_to=2026-03-31
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 510,
      "to": "+2348099887766",
      "message": "Dear Parent...",
      "status": "failed",
      "error": "Invalid phone number",
      "provider": "termii",
      "attempted_at": "2026-03-29T11:00:00.000000Z"
    }
  ],
  "current_page": 1,
  "total": 1
}
```

---

### Supported SMS Providers

Configure via `SERVICES_SMS_PROVIDER` in `.env`:

| Value | Provider |
|-------|----------|
| `twilio` | Twilio (international) |
| `vonage` | Vonage / Nexmo |
| `termii` | Termii (Nigeria-focused) |
| `log` | Log-only (development / testing) |

Provider credentials in `config/services.php`:

```env
# Termii
SERVICES_TERMII_KEY=your_api_key
SERVICES_SMS_SENDER_ID=Compasse

# Twilio
SERVICES_TWILIO_SID=ACxxxxxx
SERVICES_TWILIO_TOKEN=xxxxxxxx
SERVICES_TWILIO_FROM=+15551234567

# Vonage
SERVICES_VONAGE_KEY=your_key
SERVICES_VONAGE_SECRET=your_secret
SERVICES_VONAGE_FROM=Compasse
```

---

## Email

**Required module:** `email_integration`
**Base path:** `/api/v1/communication/email`

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/email/send` | Send a single email |
| POST | `/email/bulk` | Send email to multiple recipients |
| GET | `/email/logs` | View email delivery logs |

---

### Send a Single Email

```http
POST /api/v1/communication/email/send
Authorization: Bearer {token}
Content-Type: application/json

{
  "to": "parent@example.com",
  "cc": ["admin@greenfield.edu"],
  "bcc": [],
  "subject": "Fee Receipt — Greenfield Academy",
  "body": "Dear Mrs Okafor,\n\nPlease find attached your payment receipt for the second term fee payment of ₦85,000.\n\nThank you.\n\nGreenfield Academy Accounts",
  "attachment_url": "https://storage.compasse.africa/receipts/receipt-1045.pdf",
  "template": null
}
```

Response `200 OK`:
```json
{
  "message": "Email queued for delivery",
  "data": {
    "id": 310,
    "to": "parent@example.com",
    "cc": ["admin@greenfield.edu"],
    "subject": "Fee Receipt — Greenfield Academy",
    "status": "queued",
    "queued_at": "2026-03-30T11:00:00.000000Z"
  }
}
```

Delivery happens via `SendEmailJob` on the `emails` queue.

---

### Bulk Email

```http
POST /api/v1/communication/email/bulk
Authorization: Bearer {token}
Content-Type: application/json

{
  "recipients": [
    { "email": "parent1@example.com", "name": "Mrs Okafor" },
    { "email": "parent2@example.com", "name": "Mr Adeyemi" }
  ],
  "subject": "End of Term Report Cards Now Available",
  "body": "Dear {name},\n\nYour child's second term report card is now available on the Compasse parent portal. Log in at https://greenfield.compasse.africa to view it.\n\nRegards,\nGreenfield Academy",
  "schedule_at": null
}
```

Response `200 OK`:
```json
{
  "message": "Bulk email queued for delivery",
  "data": {
    "batch_id": "email-batch-20260330-001",
    "total_recipients": 2,
    "status": "queued",
    "queued_at": "2026-03-30T11:05:00.000000Z"
  }
}
```

---

### Email Delivery Logs

```http
GET /api/v1/communication/email/logs?status=failed&per_page=20
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 308,
      "to": "bounce@example.com",
      "subject": "Fee Receipt",
      "status": "failed",
      "error": "550 Mailbox does not exist",
      "attempts": 3,
      "last_attempted_at": "2026-03-29T12:30:00.000000Z"
    }
  ],
  "current_page": 1,
  "total": 1
}
```

---

### Queue Worker Setup

```bash
# Development (single worker covering all queues)
php artisan queue:work --queue=emails,sms,default

# Production (Laravel Horizon — recommended)
php artisan horizon
```

Failed jobs are logged via the `failed()` hook on each job class and stored in the `failed_jobs` table. Inspect them with:

```bash
php artisan queue:failed
php artisan queue:retry {job_id}
php artisan queue:retry all
```

### Retry Policy

Both `SendSMSJob` and `SendEmailJob` follow the same retry policy:

| Attempt | Delay Before Retry |
|---------|--------------------|
| 1st retry | 30 seconds |
| 2nd retry | 2 minutes |
| 3rd retry | 5 minutes |
| Permanent failure | Logged + stored in `failed_jobs` table |

Maximum attempts: `3`. After all retries are exhausted the job is moved to the failed queue, an error is logged, and the delivery record is updated with `status: failed`.

---

## Guardian Communication

Guardians have dedicated read-only endpoints to view their own notifications, messages, and payment history:

```http
GET /api/v1/guardians/{id}/notifications
GET /api/v1/guardians/{id}/messages
GET /api/v1/guardians/{id}/payments
```

These return the same JSON shape as the general endpoints but scoped to the specific guardian's data.

---

## Frontend Integration

### Tenancy

All communication requests go to `https://{school_subdomain}.compasse.africa/api/v1/communication/...`. The subdomain is resolved from `localStorage` (set at login) and injected into every Axios request via a base URL or request interceptor.

### Notification Bell with Unread Count

1. On app load, call `GET /communication/notifications?is_read=false&per_page=1` and read the `unread_count` field from the response.
2. Display the count as a badge on the bell icon.
3. Poll every 60 seconds (or use WebSocket/SSE if available) to keep the count fresh.
4. On bell click, open a dropdown fetching `GET /communication/notifications?per_page=10`.
5. On notification click, navigate to `action_url` and call `PUT /communication/notifications/{id}/read`.
6. Provide a "Mark all as read" button that calls `PUT /communication/notifications/read-all` and resets the badge to zero.

```js
// Example: fetch unread count on mount
const { data } = await axios.get('/communication/notifications?is_read=false&per_page=1');
setUnreadCount(data.unread_count);
```

### Message Thread View

- Inbox list: `GET /communication/messages?type=inbox` — render as a list with sender name, subject preview, and relative timestamp.
- On message click: `GET /communication/messages/{id}` and call `PUT /communication/messages/{id}/read` simultaneously.
- Compose form: `POST /communication/messages`. The `to` field is a multi-select user picker populated from `GET /users`.
- Show the recipients' read receipts (name + read_at) on the sent message detail view.

### Bulk SMS Composer for Admins

The admin bulk SMS UI should:
1. Allow selecting a target group (class, role, or custom list) from a dropdown.
2. Load phone numbers for the selected group from the relevant `GET /students` or `GET /teachers` list.
3. Show a character counter (160 chars = 1 SMS unit; beyond 160 = concatenated SMS, cost multiplies).
4. Support `{name}` merge tags; show a live preview of the personalised message for the first recipient.
5. On submit, call `POST /communication/sms/bulk`.
6. After sending, poll `GET /communication/sms/logs?batch_id={batch_id}` to show delivery status per recipient.
