# Super Admin

> **Base URL:** `https://compasse.africa/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** None — super admin operates on the central database, not a tenant

---

## Who is the Super Admin?

The super admin is the **platform owner**. They operate at the central-database level and have full visibility and control over every tenant (school) on the platform. Super admin accounts are stored in the **central database**, not in any tenant database.

---

## User Story

> As a super admin, I want to manage all schools on the platform — create new tenants, view their usage, suspend or activate them, manage subscription plans, and see platform-wide analytics — all from a single dashboard without needing to log into each school separately.

---

## Authentication

Super admins log in via the central auth endpoint. Their token is issued against the **central database**. They do **not** use a tenant subdomain and do **not** send an `X-Subdomain` header.

### Login

```
POST /api/v1/auth/login
```

**Request:**
```http
POST /api/v1/auth/login HTTP/1.1
Host: compasse.africa
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
  "email": "owner@compasse.africa",
  "password": "SuperSecureP@ss!"
}
```

**Response `200 OK`:**
```json
{
  "token": "1|superadmintoken9876xyz",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Platform Owner",
    "email": "owner@compasse.africa",
    "role": "super_admin",
    "created_at": "2025-01-01T00:00:00Z"
  }
}
```

**Response `401 Unauthorized`:**
```json
{
  "message": "Invalid credentials"
}
```

**Response `429 Too Many Requests`:**
```json
{
  "message": "Too many login attempts. Please try again in 60 seconds."
}
```

All super-admin-only routes are protected by `['auth:sanctum', 'role:super_admin']`.

---

## Platform Dashboard

### Get Super Admin Dashboard

```
GET /api/v1/dashboard/super-admin
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "overview": {
    "total_tenants": 42,
    "active_tenants": 38,
    "suspended_tenants": 4,
    "total_schools": 45,
    "total_students": 18340,
    "total_teachers": 1250,
    "total_revenue_ngn": 6750000
  },
  "recent_tenants": [
    {
      "id": "springfield",
      "name": "Springfield Secondary School",
      "status": "active",
      "plan": "Pro",
      "created_at": "2026-03-20T11:00:00Z"
    },
    {
      "id": "lakeside",
      "name": "Lakeside International",
      "status": "active",
      "plan": "Enterprise",
      "created_at": "2026-03-15T09:30:00Z"
    }
  ],
  "expiring_subscriptions": [
    {
      "tenant_id": "pearlbridge",
      "school_name": "Pearl Bridge Academy",
      "plan": "Starter",
      "end_date": "2026-04-05",
      "days_remaining": 6
    }
  ],
  "revenue_by_month": [
    { "month": "2026-01", "amount": 1200000 },
    { "month": "2026-02", "amount": 1350000 },
    { "month": "2026-03", "amount": 1500000 }
  ]
}
```

---

## Tenant Management

Full CRUD on tenants. Each tenant = one school on its own isolated database.

### List All Tenants

```
GET /api/v1/tenants
Authorization: Bearer {superadmin_token}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter by `active`, `suspended`, `trial` |
| `plan` | string | Filter by plan slug |
| `search` | string | Search by name or subdomain |
| `per_page` | integer | Items per page (default 20) |
| `page` | integer | Page number |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": "greenfield",
      "name": "Greenfield Academy",
      "subdomain": "greenfield",
      "status": "active",
      "db_name": "tenant_greenfield",
      "plan": {
        "name": "Pro",
        "slug": "pro"
      },
      "subscription_end_date": "2026-04-17",
      "school_count": 1,
      "created_at": "2025-09-01T08:00:00Z"
    },
    {
      "id": "lakeside",
      "name": "Lakeside International",
      "subdomain": "lakeside",
      "status": "active",
      "db_name": "tenant_lakeside",
      "plan": {
        "name": "Enterprise",
        "slug": "enterprise"
      },
      "subscription_end_date": "2027-01-01",
      "school_count": 2,
      "created_at": "2025-06-15T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 42,
    "last_page": 3
  }
}
```

---

### Create Tenant

Provisions a new school — creates the tenant record, a dedicated MySQL database, runs all tenant migrations, creates the school record, and generates the admin user account.

```
POST /api/v1/tenants
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "subdomain": "springfield",
  "name": "Springfield Secondary School",
  "email": "admin@springfield.edu.ng",
  "phone": "+2348098765432",
  "address": "12 Ajah Road, Lagos",
  "plan_id": 2
}
```

**What happens internally:**
1. Validates subdomain uniqueness
2. Creates tenant record in central DB
3. Provisions new MySQL database `tenant_springfield`
4. Runs all tenant migrations on the new database
5. Creates the school record inside the tenant DB
6. Creates the school admin user with a generated secure password
7. Sends welcome email to admin (if email integration configured)

**Response `201 Created`:**
```json
{
  "message": "Tenant created successfully",
  "tenant": {
    "id": "springfield",
    "name": "Springfield Secondary School",
    "subdomain": "springfield",
    "status": "active",
    "db_name": "tenant_springfield",
    "created_at": "2026-03-30T14:00:00Z"
  },
  "school": {
    "id": 1,
    "name": "Springfield Secondary School",
    "email": "admin@springfield.edu.ng"
  },
  "admin_credentials": {
    "email": "admin@springfield.edu.ng",
    "password": "Xk9$mP2qRv7T"
  }
}
```

> **Security Note:** `admin_credentials.password` is shown **once only** and is never stored in plaintext. Display it immediately to the super admin and instruct them to share it securely with the school admin.

**Response `422 Unprocessable Entity` (subdomain taken):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "subdomain": ["The subdomain has already been taken."]
  }
}
```

---

### Get Tenant Details

```
GET /api/v1/tenants/{id}
Authorization: Bearer {superadmin_token}
```

**Example:** `GET /api/v1/tenants/greenfield`

**Response `200 OK`:**
```json
{
  "tenant": {
    "id": "greenfield",
    "name": "Greenfield Academy",
    "subdomain": "greenfield",
    "status": "active",
    "db_name": "tenant_greenfield",
    "created_at": "2025-09-01T08:00:00Z",
    "updated_at": "2026-03-01T10:00:00Z"
  },
  "subscription": {
    "plan": "Pro",
    "status": "active",
    "end_date": "2026-04-17",
    "days_remaining": 18
  }
}
```

---

### Update Tenant

```
PUT /api/v1/tenants/{id}
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "name": "Greenfield Academy (Updated)",
  "status": "active"
}
```

**Response `200 OK`:**
```json
{
  "message": "Tenant updated successfully",
  "tenant": {
    "id": "greenfield",
    "name": "Greenfield Academy (Updated)",
    "status": "active",
    "updated_at": "2026-03-30T15:00:00Z"
  }
}
```

---

### Delete Tenant

Deletes the tenant record and **drops the entire tenant database**. This is irreversible.

```
DELETE /api/v1/tenants/{id}
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "message": "Tenant and associated database deleted successfully"
}
```

**Response `409 Conflict` (active subscription exists):**
```json
{
  "message": "Cannot delete a tenant with an active subscription. Cancel the subscription first."
}
```

---

### Get Tenant Stats

Switches to the tenant DB to count students, teachers, and classes.

```
GET /api/v1/tenants/{id}/stats
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "tenant_id": "greenfield",
  "stats": {
    "students": 842,
    "teachers": 64,
    "classes": 18,
    "staff": 30,
    "academic_years": 2,
    "active_academic_year": "2025/2026"
  }
}
```

---

## School Management

Super admins can view, update, suspend, and activate any school across all tenants.

### List All Schools

```
GET /api/v1/schools
Authorization: Bearer {superadmin_token}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `tenant_id` | string | Filter by tenant subdomain |
| `status` | string | Filter by `active`, `suspended` |
| `search` | string | Search by school name |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 1,
      "tenant_id": "greenfield",
      "name": "Greenfield Academy",
      "email": "info@greenfieldacademy.edu.ng",
      "phone": "+2348012345678",
      "status": "active",
      "student_count": 842,
      "teacher_count": 64,
      "created_at": "2025-09-01T08:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 45
  }
}
```

---

### Get School Details + Stats

```
GET /api/v1/admin/schools/{id}
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "school": {
    "id": 1,
    "tenant_id": "greenfield",
    "name": "Greenfield Academy",
    "email": "info@greenfieldacademy.edu.ng",
    "phone": "+2348012345678",
    "address": "14 Victoria Island, Lagos",
    "logo_url": "https://cdn.compasse.africa/schools/greenfield/logo.png",
    "status": "active",
    "created_at": "2025-09-01T08:00:00Z"
  },
  "stats": {
    "students": 842,
    "teachers": 64,
    "classes": 18,
    "pending_fees": 1240000,
    "subscription_status": "active",
    "subscription_end_date": "2026-04-17"
  }
}
```

---

### Get School Dashboard

```
GET /api/v1/admin/schools/{id}/dashboard
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "school_id": 1,
  "name": "Greenfield Academy",
  "dashboard": {
    "students": 842,
    "teachers": 64,
    "classes": 18,
    "attendance_rate_today": 91.4,
    "fees_collected_this_month": 4500000,
    "fees_outstanding": 1240000,
    "recent_registrations": 5,
    "upcoming_events": 2
  }
}
```

---

### Get School Usage Statistics

```
GET /api/v1/admin/schools/{id}/stats
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "school_id": 1,
  "usage": {
    "students": 842,
    "teachers": 64,
    "staff": 30,
    "classes": 18,
    "subjects": 72,
    "academic_years": 2,
    "fee_records": 3600,
    "payments_recorded": 2901,
    "payroll_records": 180,
    "attendance_records": 42000,
    "storage_used_mb": 1245
  }
}
```

---

### Update School

```
PUT /api/v1/admin/schools/{id}
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "name": "Greenfield Academy",
  "email": "info@greenfieldacademy.edu.ng",
  "phone": "+2348012345678",
  "address": "14 Victoria Island, Lagos",
  "website": "https://greenfieldacademy.edu.ng"
}
```

**Response `200 OK`:**
```json
{
  "message": "School updated successfully",
  "school": {
    "id": 1,
    "name": "Greenfield Academy",
    "email": "info@greenfieldacademy.edu.ng",
    "updated_at": "2026-03-30T15:30:00Z"
  }
}
```

---

### Suspend School

Blocks all login and API access for users of that school.

```
POST /api/v1/admin/schools/{id}/suspend
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "reason": "Outstanding subscription payment"
}
```

**Response `200 OK`:**
```json
{
  "message": "School suspended successfully",
  "school_id": 1,
  "status": "suspended",
  "reason": "Outstanding subscription payment"
}
```

---

### Activate School

Re-activates a previously suspended school.

```
POST /api/v1/admin/schools/{id}/activate
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "message": "School activated successfully",
  "school_id": 1,
  "status": "active"
}
```

---

### Send Email to School Admin

```
POST /api/v1/admin/schools/{id}/send-email
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "subject": "Subscription Renewal Reminder",
  "message": "Your Compasse Pro subscription expires in 7 days. Please renew to avoid service interruption.",
  "type": "notification"
}
```

**Response `200 OK`:**
```json
{
  "message": "Email sent successfully",
  "recipient": "admin@greenfieldacademy.edu.ng"
}
```

---

### Reset School Admin Password

Generates a new secure password for the school admin and emails it to them.

```
POST /api/v1/admin/schools/{id}/reset-admin-password
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "message": "Admin password reset successfully. New credentials sent to admin@greenfieldacademy.edu.ng",
  "email": "admin@greenfieldacademy.edu.ng"
}
```

---

### Get User Count

```
GET /api/v1/admin/schools/{id}/users-count
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "school_id": 1,
  "users": {
    "total": 970,
    "admin": 1,
    "principal": 1,
    "vice_principal": 1,
    "teacher": 64,
    "student": 842,
    "guardian": 45,
    "accountant": 2,
    "staff": 10,
    "librarian": 1,
    "nurse": 1,
    "driver": 2
  }
}
```

---

### Get Activity Logs

```
GET /api/v1/admin/schools/{id}/activity-logs
Authorization: Bearer {superadmin_token}
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `per_page` | integer | Items per page (default 20) |
| `page` | integer | Page number |
| `from` | date | Start date filter (YYYY-MM-DD) |
| `to` | date | End date filter (YYYY-MM-DD) |

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 5501,
      "user": "Mrs. Adaobi Nwosu",
      "role": "admin",
      "action": "student.created",
      "description": "Created student record for John Okafor (JS1A)",
      "ip_address": "105.112.44.8",
      "created_at": "2026-03-30T09:14:22Z"
    },
    {
      "id": 5500,
      "user": "Mr. Emeka Eze",
      "role": "accountant",
      "action": "payment.recorded",
      "description": "Recorded ₦50,000 payment for Jane Doe (Fee ID: 12)",
      "ip_address": "105.112.44.9",
      "created_at": "2026-03-30T08:45:10Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 5501
  }
}
```

---

### Delete School

```
DELETE /api/v1/schools/{id}
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "message": "School deleted successfully"
}
```

---

## Access to School Data — Implementation Pattern

**Yes — super admin has access to all school information**, but the access pattern is intentional:

- For **tenant-level data** (subdomain, DB name, status) → central DB, direct access
- For **school-level data** (students, teachers, fees) → the system switches to the tenant's database using `tenancy()->initialize($tenant)` before querying

The `TenantService::getTenantStats()` method demonstrates this pattern:
```php
tenancy()->initialize($tenant);
try {
    $stats = [
        'students' => Student::count(),
        'teachers' => Teacher::count(),
        'classes'  => SchoolClass::count(),
    ];
} finally {
    tenancy()->end();   // always restores central DB context
}
```

Super admin cannot accidentally leak data between tenants because the `tenancy()->end()` call in a `finally` block ensures the DB connection is always restored.

---

## What Super Admin Cannot Do

- Log in as a school user (no impersonation endpoint exists)
- Read raw student records, exam results, or financial records directly — these require switching to the tenant DB and are not exposed through super-admin endpoints
- Bypass the subscription check for individual schools (they can only change the plan/subscription)

---

## Security Notes

- Super admin role is **not assignable** via the public registration endpoint (`super_admin` is excluded from the `role` validation whitelist)
- Super admin accounts should be created via `php artisan tinker` or a seeder, never via API
- The `/api/v1/health/db` diagnostic endpoint is restricted to `super_admin` only
- All super-admin routes are wrapped in `['auth:sanctum', 'role:super_admin']` middleware

---

## Frontend Integration — Super Admin Portal

The super admin portal operates on the **central domain** (`compasse.africa`), not a subdomain. There is no `X-Subdomain` header required.

### Login Flow

```typescript
// services/superAdminAuth.ts

const SUPER_ADMIN_TOKEN_KEY = 'compasse_superadmin_token';

export async function superAdminLogin(email: string, password: string) {
  const res = await fetch('https://compasse.africa/api/v1/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      // No X-Subdomain header — this is the central domain
    },
    body: JSON.stringify({ email, password }),
  });

  if (!res.ok) {
    const err = await res.json();
    throw new Error(err.message || 'Login failed');
  }

  const data = await res.json();

  if (data.user.role !== 'super_admin') {
    throw new Error('Access denied. Super admin credentials required.');
  }

  localStorage.setItem(SUPER_ADMIN_TOKEN_KEY, data.token);
  localStorage.setItem('compasse_superadmin_user', JSON.stringify(data.user));

  return data;
}

export function getSuperAdminToken(): string | null {
  return localStorage.getItem(SUPER_ADMIN_TOKEN_KEY);
}

export function superAdminLogout() {
  localStorage.removeItem(SUPER_ADMIN_TOKEN_KEY);
  localStorage.removeItem('compasse_superadmin_user');
}
```

### API Client for Super Admin

```typescript
// services/superAdminApi.ts

export function createSuperAdminApiClient() {
  const token = localStorage.getItem('compasse_superadmin_token');

  const headers = {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    // No X-Subdomain — central database operations
  };

  return {
    get: (path: string) =>
      fetch(`https://compasse.africa/api/v1${path}`, { headers })
        .then(handleSuperAdminResponse),

    post: (path: string, body: object) =>
      fetch(`https://compasse.africa/api/v1${path}`, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
      }).then(handleSuperAdminResponse),

    put: (path: string, body: object) =>
      fetch(`https://compasse.africa/api/v1${path}`, {
        method: 'PUT',
        headers,
        body: JSON.stringify(body),
      }).then(handleSuperAdminResponse),

    delete: (path: string) =>
      fetch(`https://compasse.africa/api/v1${path}`, {
        method: 'DELETE',
        headers,
      }).then(handleSuperAdminResponse),
  };
}

async function handleSuperAdminResponse(res: Response) {
  if (res.status === 401) {
    superAdminLogout();
    window.location.href = '/admin/login';
    return;
  }
  if (!res.ok) throw await res.json();
  return res.json();
}
```

### Dashboard Usage Example

```typescript
// pages/admin/dashboard.tsx

import { createSuperAdminApiClient } from '@/services/superAdminApi';

export default function SuperAdminDashboard() {
  const [stats, setStats] = useState(null);
  const api = createSuperAdminApiClient();

  useEffect(() => {
    api.get('/dashboard/super-admin').then(setStats);
  }, []);

  return (
    <div>
      <h1>Platform Overview</h1>
      <p>Total Tenants: {stats?.overview.total_tenants}</p>
      <p>Active Tenants: {stats?.overview.active_tenants}</p>
      <p>Total Students: {stats?.overview.total_students}</p>
    </div>
  );
}
```

### Displaying the One-Time Admin Password After Tenant Creation

```typescript
// After POST /api/v1/tenants
const result = await api.post('/tenants', formData);

// Show once — never store
showModal({
  title: 'Tenant Created Successfully',
  body: `
    School admin credentials (SHOW ONCE — copy before closing):
    Email: ${result.admin_credentials.email}
    Password: ${result.admin_credentials.password}
  `,
  onClose: () => {
    // Clear from state immediately after user dismisses
    clearSensitiveData();
  }
});
```
