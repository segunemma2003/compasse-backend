# API Reference

## Base URL

```
https://{subdomain}.compasse.net/api/v1/
```

For super admin (no subdomain):
```
https://compasse.net/api/v1/
```

---

## Authentication

All protected routes require:
```
Authorization: Bearer {token}
```

Obtain a token:
```
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "admin@greenfield.compasse.net",
  "password": "YourPassword"
}
```
Response:
```json
{
  "token": "1|abc123...",
  "user": { "id": 1, "name": "John Doe", "role": "admin" }
}
```

For tenant routes, the subdomain in the URL resolves the tenant. Alternatively send:
```
X-Subdomain: greenfield
```

---

## HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | OK — successful GET/PUT |
| 201 | Created — successful POST |
| 400 | Bad Request — missing school context or invalid state |
| 401 | Unauthenticated — missing/invalid token |
| 403 | Forbidden — wrong role or module not in subscription |
| 404 | Not Found — record doesn't exist |
| 422 | Unprocessable — validation failed |
| 429 | Too Many Requests — rate limit hit |
| 500 | Server Error |
| 503 | Service Unavailable — subscription check failed |

---

## Pagination

All list endpoints return paginated responses:
```json
{
  "data": [...],
  "current_page": 1,
  "last_page": 5,
  "per_page": 15,
  "total": 73,
  "next_page_url": "...",
  "prev_page_url": null
}
```
Control with `?per_page=25&page=2`.

---

## Full Route Map

### Public Routes (no auth)
```
GET  /api/health
GET  /api/v1/schools/by-subdomain/{subdomain}
POST /api/v1/tenants/verify
GET  /api/v1/tenants/verify
GET  /api/v1/schools/landing-page/templates
GET  /api/v1/public/{subdomain}
```

### Auth Routes (rate-limited: 5/min)
```
POST /api/v1/auth/login
POST /api/v1/auth/register
POST /api/v1/auth/forgot-password
POST /api/v1/auth/reset-password
POST /api/v1/auth/logout               [auth required]
POST /api/v1/auth/refresh              [auth required]
GET  /api/v1/auth/me                   [tenant + auth]
```

### Super Admin Routes [auth + role:super_admin]
```
GET|POST|PUT|DELETE  /api/v1/tenants
GET                  /api/v1/tenants/{id}/stats
GET|POST|DELETE      /api/v1/schools
GET|PUT              /api/v1/admin/schools/{id}
GET                  /api/v1/admin/schools/{id}/stats
GET                  /api/v1/admin/schools/{id}/dashboard
POST                 /api/v1/admin/schools/{id}/suspend
POST                 /api/v1/admin/schools/{id}/activate
POST                 /api/v1/admin/schools/{id}/send-email
POST                 /api/v1/admin/schools/{id}/reset-admin-password
GET                  /api/v1/admin/schools/{id}/users-count
GET                  /api/v1/admin/schools/{id}/activity-logs
GET                  /api/v1/dashboard/super-admin
GET                  /api/v1/health/db
```

### Tenant + Auth Routes (all require tenant middleware + Bearer token)

#### Core — No Module Gate
```
GET|PUT              /api/v1/schools/me
GET|PUT              /api/v1/schools/{id}
GET                  /api/v1/schools/{id}/stats
GET                  /api/v1/schools/{id}/dashboard
GET                  /api/v1/schools/{id}/organogram
GET|POST|PUT|DELETE  /api/v1/users
POST                 /api/v1/users/{id}/activate
POST                 /api/v1/users/{id}/suspend
POST                 /api/v1/users/{id}/assign-role
POST                 /api/v1/users/{id}/remove-role
GET                  /api/v1/roles
GET                  /api/v1/dashboard
GET                  /api/v1/dashboard/stats
POST|DELETE          /api/v1/users/me/profile-picture
POST|DELETE          /api/v1/users/{id}/profile-picture
```

#### Subscriptions — No Module Gate
```
GET   /api/v1/subscriptions/plans
GET   /api/v1/subscriptions/modules
GET   /api/v1/subscriptions/status
GET   /api/v1/subscriptions/school/modules
GET   /api/v1/subscriptions/school/limits
GET   /api/v1/subscriptions/modules/{module}/access
GET   /api/v1/subscriptions/features/{feature}/access
GET|POST|PUT|DELETE /api/v1/subscriptions
GET   /api/v1/subscriptions/{id}
POST  /api/v1/subscriptions/create
PUT   /api/v1/subscriptions/{id}/upgrade
POST  /api/v1/subscriptions/{id}/renew
DELETE /api/v1/subscriptions/{id}/cancel
```

#### Module: academic_management
```
CRUD /api/v1/academic-years
CRUD /api/v1/terms
CRUD /api/v1/departments
CRUD /api/v1/classes
GET  /api/v1/classes/{id}/students
CRUD /api/v1/subjects
CRUD /api/v1/arms
POST /api/v1/arms/assign-to-class
POST /api/v1/arms/remove-from-class
GET  /api/v1/arms/class/{classId}
GET  /api/v1/arms/{armId}/students
```

#### Module: student_management
```
CRUD /api/v1/students
GET  /api/v1/students/{id}/attendance
GET  /api/v1/students/{id}/results
GET  /api/v1/students/{id}/assignments
GET  /api/v1/students/{id}/subjects
POST /api/v1/students/generate-admission-number
POST /api/v1/students/generate-credentials
CRUD /api/v1/guardians
POST /api/v1/guardians/{id}/assign-student
DELETE /api/v1/guardians/{id}/remove-student
GET  /api/v1/guardians/{id}/students
```

#### Module: teacher_management
```
CRUD /api/v1/teachers
GET  /api/v1/teachers/{id}/classes
GET  /api/v1/teachers/{id}/subjects
GET  /api/v1/teachers/{id}/students
```

#### Module: cbt
```
CRUD    /api/v1/assessments/exams
CRUD    /api/v1/assessments/assignments
GET     /api/v1/assessments/assignments/{id}/submissions
POST    /api/v1/assessments/assignments/{id}/submit
PUT     /api/v1/assessments/assignments/{id}/grade
POST    /api/v1/assessments/cbt/start
POST    /api/v1/assessments/cbt/submit
POST    /api/v1/assessments/cbt/submit-answer
GET     /api/v1/assessments/cbt/{exam}/questions
GET     /api/v1/assessments/cbt/attempts/{attempt}/status
GET     /api/v1/assessments/cbt/session/{id}/results
CRUD    /api/v1/assessments/results
POST    /api/v1/assessments/results/generate
GET     /api/v1/assessments/results/student/{id}/{termId}/{yearId}
GET     /api/v1/assessments/results/class/{classId}
POST    /api/v1/assessments/results/{id}/approve
POST    /api/v1/assessments/results/publish
CRUD    /api/v1/assessments/grading-systems
POST    /api/v1/assessments/grading-systems/calculate-grade
CRUD    /api/v1/assessments/continuous-assessments
POST    /api/v1/assessments/continuous-assessments/{id}/record-scores
GET     /api/v1/assessments/continuous-assessments/{id}/scores
CRUD    /api/v1/assessments/psychomotor-assessments
GET     /api/v1/assessments/scoreboards/class/{classId}
GET     /api/v1/assessments/scoreboards/top-performers
GET     /api/v1/assessments/scoreboards/subject/{id}/toppers
GET     /api/v1/assessments/report-cards/{student}/{term}/{year}
GET     /api/v1/assessments/report-cards/{student}/{term}/{year}/pdf
POST    /api/v1/assessments/report-cards/{student}/{term}/{year}/email
POST    /api/v1/assessments/report-cards/bulk-download
GET     /api/v1/assessments/analytics/school
GET     /api/v1/assessments/analytics/class/{id}
GET     /api/v1/assessments/analytics/student/{id}/trend
POST    /api/v1/assessments/promotions/promote
POST    /api/v1/assessments/promotions/bulk-promote
POST    /api/v1/assessments/promotions/auto-promote
POST    /api/v1/assessments/promotions/graduate
CRUD    /api/v1/quizzes
CRUD    /api/v1/grades
CRUD    /api/v1/timetable
CRUD    /api/v1/announcements
CRUD    /api/v1/question-bank
```

#### Module: fee_management
```
CRUD    /api/v1/financial/fees
POST    /api/v1/financial/fees/{id}/pay
GET     /api/v1/financial/fees/student/{id}
GET|POST /api/v1/financial/fees/structure
CRUD    /api/v1/financial/payments
GET     /api/v1/financial/payments/student/{id}
GET     /api/v1/financial/payments/receipt/{id}
CRUD    /api/v1/financial/expenses
CRUD    /api/v1/financial/payroll
GET     /api/v1/financial/payroll/{id}/pay-stub
```

#### Module: attendance_management
```
GET|POST|PUT|DELETE /api/v1/attendance
GET  /api/v1/attendance/reports
GET  /api/v1/attendance/class/{classId}
GET  /api/v1/attendance/student/{studentId}
POST /api/v1/attendance/mark
```

#### Module: transport_management
```
CRUD    /api/v1/transport/vehicles
CRUD    /api/v1/transport/drivers
CRUD    /api/v1/transport/routes
GET     /api/v1/transport/routes/{id}/students
POST    /api/v1/transport/routes/{id}/students
DELETE  /api/v1/transport/routes/{id}/students
CRUD    /api/v1/transport/secure-pickup
POST    /api/v1/transport/secure-pickup/verify
```

#### Module: hostel_management
```
CRUD    /api/v1/hostel/rooms
CRUD    /api/v1/hostel/allocations
POST    /api/v1/hostel/allocations/{id}/vacate
CRUD    /api/v1/hostel/maintenance
```

#### Module: health_management
```
CRUD    /api/v1/health/records
CRUD    /api/v1/health/appointments
CRUD    /api/v1/health/medications
```

#### Module: inventory_management
```
CRUD    /api/v1/inventory/categories
CRUD    /api/v1/inventory/items
CRUD    /api/v1/inventory/transactions
POST    /api/v1/inventory/transactions/checkout
POST    /api/v1/inventory/transactions/{id}/return
```

#### Module: event_management
```
CRUD    /api/v1/events
GET     /api/v1/events/upcoming
CRUD    /api/v1/calendars
```

#### Module: livestream
```
CRUD    /api/v1/livestreams
POST    /api/v1/livestreams/{id}/start
POST    /api/v1/livestreams/{id}/end
POST    /api/v1/livestreams/{id}/join
POST    /api/v1/livestreams/{id}/leave
GET     /api/v1/livestreams/{id}/attendance
```

#### Module: sms_integration
```
POST    /api/v1/communication/sms/send
```

#### Module: email_integration
```
POST    /api/v1/communication/email/send
```

#### Landing Page (No Module Gate)
```
GET     /api/v1/schools/landing-page
PUT     /api/v1/schools/landing-page
POST    /api/v1/schools/landing-page/upload-asset
```

#### No Module Gate
```
CRUD    /api/v1/communication/messages
PUT     /api/v1/communication/messages/{id}/read
CRUD    /api/v1/communication/notifications
PUT     /api/v1/communication/notifications/{id}/read
PUT     /api/v1/communication/notifications/read-all
GET     /api/v1/reports/academic
GET     /api/v1/reports/financial
GET     /api/v1/reports/attendance
GET     /api/v1/reports/performance
GET     /api/v1/reports/{type}/export
CRUD    /api/v1/staff
CRUD    /api/v1/achievements
CRUD    /api/v1/library/books
POST    /api/v1/library/borrow
POST    /api/v1/library/return
CRUD    /api/v1/houses
CRUD    /api/v1/sports/activities
GET|PUT /api/v1/settings
POST    /api/v1/bulk/students/register
POST    /api/v1/bulk/teachers/register
POST    /api/v1/bulk/attendance/mark
POST    /api/v1/bulk/notifications/send
POST    /api/v1/uploads/upload
```

---

## Common Request Headers

```
Authorization: Bearer {sanctum_token}
Content-Type: application/json
Accept: application/json
X-Subdomain: {tenant_subdomain}        (alternative to using subdomain in URL)
X-School-ID: {school_id}               (if tenant has multiple schools)
```

---

## Validation Errors

```json
HTTP 422
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "amount": ["The amount must be a number."]
  }
}
```

## Module Denied

```json
HTTP 403
{
  "error": "Module access denied",
  "message": "This school does not have access to the transport_management module. Please upgrade your subscription.",
  "module": "transport_management",
  "upgrade_required": true
}
```

---

## Frontend Integration

### Domain Architecture

| Domain | Purpose |
|--------|---------|
| `compasse.net` | Company marketing website |
| `{school}.compasse.net` | School portal (tenanted) |

### API Base URLs

```typescript
// src/utils/tenancy.ts
export const ROOT_DOMAIN = 'compasse.net';
export const API_BASE = `https://${ROOT_DOMAIN}/api/v1`;

// For school tenant requests (called on subdomain):
// https://greenfield.compasse.net/api/v1/...
// The Laravel tenancy middleware resolves the tenant from the subdomain.
```

### Detecting Context

```typescript
import { getSubdomain, isCompanyDomain } from '@/utils/tenancy';

const subdomain = getSubdomain();
// greenfield.compasse.net  → "greenfield"
// compasse.net             → null
// localhost (dev)             → process.env.VITE_DEV_SUBDOMAIN || null

if (isCompanyDomain()) {
  // Render company marketing page (compasse.net)
} else {
  // Render school portal (subdomain.compasse.net)
}
```

Local dev: set `VITE_DEV_SUBDOMAIN=greenfield` in `.env.local` to simulate a school subdomain.

### Token Storage per Subdomain

```typescript
// Each school gets its own token key, allowing multiple tabs across schools.
const key = `compasse_token_${subdomain}`;
localStorage.setItem(key, token);
const token = localStorage.getItem(key);
```

### Making Authenticated Requests

```typescript
// src/services/schoolApi.ts
export function createSchoolApi(subdomain: string) {
  const base = `https://${subdomain}.${ROOT_DOMAIN}/api/v1`;
  const headers = () => ({
    'Authorization': `Bearer ${localStorage.getItem(`compasse_token_${subdomain}`)}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  });

  return {
    get: (path: string) => fetch(`${base}${path}`, { headers: headers() }),
    post: (path: string, body: unknown) =>
      fetch(`${base}${path}`, { method: 'POST', headers: headers(), body: JSON.stringify(body) }),
    put: (path: string, body: unknown) =>
      fetch(`${base}${path}`, { method: 'PUT', headers: headers(), body: JSON.stringify(body) }),
    delete: (path: string) =>
      fetch(`${base}${path}`, { method: 'DELETE', headers: headers() }),
  };
}
```

### Module Gating in Routes

```tsx
// Only renders if the school's subscription includes "transport_management"
<Route
  path="/school/transport"
  element={
    <SchoolLayout requiredModule="transport_management">
      <TransportPage />
    </SchoolLayout>
  }
/>

// Only renders for specific roles
<Route
  path="/school/finance"
  element={
    <SchoolLayout requiredModule="fee_management" requiredRoles={['admin', 'accountant']}>
      <Finance />
    </SchoolLayout>
  }
/>
```

### Public School Landing Page

Fetch school data without authentication:
```typescript
// GET https://compasse.net/api/v1/public/{subdomain}
const res = await fetch(`https://compasse.net/api/v1/public/${subdomain}`);
const { school, settings, template } = await res.json();
// school: { id, name, logo_url, ... }
// settings: { hero_title, hero_subtitle, contact_email, ... }
// template: { id, name, colors, ... }
```

The `"/"` route renders `<PublicLandingPage />` which:
- Shows `<CompanyMarketingPage />` when `getSubdomain() === null` (i.e., `compasse.net`)
- Fetches and renders the school's configured landing page when a subdomain is detected
