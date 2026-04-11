# System Overview

> **Base URL:** `https://{subdomain}.compasse.net/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** None — system-level endpoints

---

## What is Compasse?

Compasse is a **multi-tenant, multi-database SaaS school management platform**. Each school (tenant) runs in a completely isolated MySQL database. The central database tracks tenants, plans, and subscriptions. All school data — students, staff, fees, exams, attendance — lives in the tenant's own database.

---

## Architecture

```
Internet
   │
   ▼
Nginx / Load Balancer
   │
   ▼
Laravel 12 API (PHP 8.2)
   │
   ├── Central MySQL DB  ← tenants, plans, subscriptions, super-admin users
   │
   └── Tenant MySQL DB (one per school)
          ← students, teachers, classes, fees, payroll, attendance, …
```

### Multi-Tenancy (stancl/tenancy v3)

- Tenant is identified by **subdomain** (e.g. `greenfield.compasse.net`)
- `TenantMiddleware` resolves the subdomain → looks up tenant record → switches the active DB connection to the tenant's database
- All tenant-scoped routes require this middleware
- Sanctum tokens are stored **per-tenant** — a token from one school cannot authenticate at another

### Authentication

- **Laravel Sanctum** token-based auth
- Token is created on login and returned to the client
- All protected routes require `Authorization: Bearer {token}` header
- Throttle: 5 login attempts per minute per IP

---

## User Roles

| Role | Scope | Description |
|------|-------|-------------|
| `super_admin` | Central DB | Platform owner. Full access to all tenants and schools |
| `admin` | Tenant DB | School administrator. Full access to their school |
| `principal` | Tenant DB | Academic head. Read/write on academic operations |
| `vice_principal` | Tenant DB | Deputy academic head |
| `teacher` | Tenant DB | Manages classes, grades, assignments |
| `student` | Tenant DB | Read-only access to own data |
| `guardian` | Tenant DB | Read-only access to ward's data |
| `accountant` | Tenant DB | Full access to financial module |
| `librarian` | Tenant DB | Access to library module |
| `nurse` | Tenant DB | Access to health module |
| `driver` | Tenant DB | Access to transport module |
| `staff` | Tenant DB | General non-teaching staff |
| `security` | Tenant DB | Access to secure pickup verification |

---

## Subscription Plans & Module Gating

Every school must have an active subscription to use module-gated features. The subscription defines which **modules** are enabled. Routes protected by `ModuleAccessMiddleware` return HTTP 403 if the school's plan does not include that module.

| Module Slug | Feature |
|-------------|---------|
| `academic_management` | Classes, subjects, academic years, terms |
| `student_management` | Student enrollment, profiles |
| `teacher_management` | Teacher profiles, assignments |
| `cbt` | Computer-based testing, exams, results, report cards |
| `fee_management` | School fees, payments, receipts, payroll |
| `attendance_management` | Student and staff attendance |
| `transport_management` | Vehicles, drivers, routes, secure pickup |
| `hostel_management` | Rooms, allocations, maintenance |
| `health_management` | Health records, appointments, medications |
| `inventory_management` | Categories, items, checkout/return |
| `event_management` | Events, school calendar |
| `livestream` | Live classes |
| `sms_integration` | SMS notifications |
| `email_integration` | Email notifications |

---

## Request Flow

```
Client Request
      │
      ▼
TenantMiddleware          ← resolve subdomain → switch DB
      │
      ▼
auth:sanctum              ← validate Bearer token in tenant DB
      │
      ▼
ModuleAccessMiddleware    ← check school subscription (Redis-cached 5 min)
      │
      ▼
RoleMiddleware            ← check user role
      │
      ▼
Controller → Model → Response
```

### Middleware Chain — Code Reference

**TenantMiddleware** (`app/Http/Middleware/TenantMiddleware.php`):
```php
// Resolves subdomain from header, URL, or request host
$subdomain = $request->header('X-Subdomain')
    ?? $request->route('subdomain')
    ?? explode('.', $request->getHost())[0];

$tenant = Tenant::where('id', $subdomain)->firstOrFail();
tenancy()->initialize($tenant);
```

**ModuleAccessMiddleware** (`app/Http/Middleware/ModuleAccessMiddleware.php`):
```php
// Cache key: sub:school:{id}:module:{slug}
$hasAccess = Cache::tags(["school:{$schoolId}:subscription"])
    ->remember("sub:school:{$schoolId}:module:{$module}", 300, fn() =>
        SubscriptionService::schoolHasModule($schoolId, $module)
    );

if (!$hasAccess) {
    return response()->json([
        'error'            => 'Module access denied',
        'message'          => "This school does not have access to the {$module} module. Please upgrade your subscription.",
        'module'           => $module,
        'upgrade_required' => true,
    ], 403);
}
```

**RoleMiddleware** (`app/Http/Middleware/RoleMiddleware.php`):
```php
// Usage in routes: ->middleware('role:admin,accountant')
$allowedRoles = explode(',', $role);
if (!in_array(auth()->user()->role, $allowedRoles)) {
    return response()->json(['error' => 'Unauthorized role'], 403);
}
```

---

## Public (No-Auth) Endpoints

These endpoints require no token. They are used to bootstrap the frontend before a user logs in.

### Health Check

```
GET /api/v1/health
```

**Request:**
```http
GET /api/v1/health HTTP/1.1
Host: compasse.net
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "status": "ok",
  "timestamp": "2026-03-30T10:15:00Z",
  "version": "1.0.0"
}
```

---

### Database Health (Super Admin Only)

```
GET /api/v1/health/db
Authorization: Bearer {superadmin_token}
```

**Response `200 OK`:**
```json
{
  "status": "ok",
  "central_db": "connected",
  "queue": "running",
  "cache": "connected",
  "timestamp": "2026-03-30T10:15:02Z"
}
```

**Response `500 Internal Server Error` (degraded):**
```json
{
  "status": "degraded",
  "central_db": "connected",
  "queue": "not_running",
  "cache": "disconnected",
  "timestamp": "2026-03-30T10:15:02Z"
}
```

---

### Subdomain / Tenant Lookup

Used by the SPA to verify that a school exists before showing the login form.

```
GET /api/v1/schools/by-subdomain/{subdomain}
```

**Request:**
```http
GET /api/v1/schools/by-subdomain/greenfield HTTP/1.1
Host: compasse.net
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "exists": true,
  "tenant": {
    "id": "greenfield",
    "name": "Greenfield Academy",
    "subdomain": "greenfield",
    "status": "active",
    "logo_url": "https://cdn.compasse.net/schools/greenfield/logo.png",
    "primary_color": "#2E7D32",
    "address": "14 Victoria Island, Lagos",
    "phone": "+2348012345678",
    "email": "info@greenfieldacademy.edu.ng"
  }
}
```

**Response `404 Not Found`:**
```json
{
  "exists": false,
  "message": "School not found"
}
```

**Response `403 Forbidden` (suspended tenant):**
```json
{
  "exists": true,
  "status": "suspended",
  "message": "This school account has been suspended. Please contact support."
}
```

---

### Public School Info

Returns publicly visible school information (used for landing pages / login pages).

```
GET /api/v1/public/{subdomain}
```

**Request:**
```http
GET /api/v1/public/greenfield HTTP/1.1
Host: compasse.net
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "school": {
    "name": "Greenfield Academy",
    "subdomain": "greenfield",
    "logo_url": "https://cdn.compasse.net/schools/greenfield/logo.png",
    "cover_image_url": "https://cdn.compasse.net/schools/greenfield/cover.jpg",
    "primary_color": "#2E7D32",
    "tagline": "Nurturing Excellence",
    "address": "14 Victoria Island, Lagos",
    "phone": "+2348012345678",
    "email": "info@greenfieldacademy.edu.ng",
    "website": "https://greenfieldacademy.edu.ng"
  }
}
```

---

### Tenant Verify (POST)

Alternative subdomain verification accepting JSON body.

```
POST /api/v1/tenants/verify
Content-Type: application/json
```

**Request Body:**
```json
{
  "subdomain": "greenfield"
}
```

**Response `200 OK`:**
```json
{
  "valid": true,
  "tenant_id": "greenfield",
  "school_name": "Greenfield Academy",
  "status": "active"
}
```

**Response `422 Unprocessable Entity`:**
```json
{
  "valid": false,
  "message": "The subdomain field is required."
}
```

---

## Authentication Endpoints

### Login (Tenant User)

```
POST /api/v1/auth/login
```

**Request:**
```http
POST /api/v1/auth/login HTTP/1.1
Host: greenfield.compasse.net
Content-Type: application/json
Accept: application/json
X-Subdomain: greenfield
```

**Request Body:**
```json
{
  "email": "admin@greenfieldacademy.edu.ng",
  "password": "SecureP@ss123"
}
```

**Response `200 OK`:**
```json
{
  "token": "3|abc123xyz456tokenvalue",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Mrs. Adaobi Nwosu",
    "email": "admin@greenfieldacademy.edu.ng",
    "role": "admin",
    "school_id": 1,
    "avatar_url": null,
    "created_at": "2025-09-01T08:00:00Z"
  },
  "school": {
    "id": 1,
    "name": "Greenfield Academy",
    "subdomain": "greenfield",
    "logo_url": "https://cdn.compasse.net/schools/greenfield/logo.png"
  }
}
```

**Response `401 Unauthorized`:**
```json
{
  "message": "Invalid credentials"
}
```

**Response `429 Too Many Requests` (throttle):**
```json
{
  "message": "Too many login attempts. Please try again in 60 seconds."
}
```

---

### Logout

```
POST /api/v1/auth/logout
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "message": "Logged out successfully"
}
```

---

### Get Authenticated User

```
GET /api/v1/auth/me
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "user": {
    "id": 1,
    "name": "Mrs. Adaobi Nwosu",
    "email": "admin@greenfieldacademy.edu.ng",
    "role": "admin",
    "school_id": 1,
    "avatar_url": null
  }
}
```

---

## Key Directories

```
app/
  Http/
    Controllers/          ← All API controllers
    Middleware/           ← TenantMiddleware, ModuleAccessMiddleware, RoleMiddleware
  Models/                 ← Eloquent models
  Services/               ← TenantService, SubscriptionService
  Jobs/                   ← SendEmailJob, SendSMSJob (queued)

database/
  migrations/             ← Central DB migrations
  migrations/tenant/      ← Per-tenant DB migrations (run on tenant creation)

routes/
  api.php                 ← All API routes

config/
  tenancy.php             ← stancl/tenancy configuration
  queue.php               ← Queue driver config (Redis recommended)
```

---

## Environment Variables

| Variable | Example Value | Description |
|----------|--------------|-------------|
| `APP_NAME` | `Compasse` | Application name |
| `APP_ENV` | `production` | Environment (`local`, `production`) |
| `APP_KEY` | `base64:...` | Laravel encryption key |
| `APP_URL` | `https://compasse.net` | Central app URL |
| `APP_DEBUG` | `false` | Debug mode (always `false` in prod) |
| `DB_CONNECTION` | `mysql` | Central DB driver |
| `DB_HOST` | `127.0.0.1` | Central DB host |
| `DB_PORT` | `3306` | Central DB port |
| `DB_DATABASE` | `compasse_central` | Central DB name |
| `DB_USERNAME` | `compasse_user` | Central DB username |
| `DB_PASSWORD` | `secret` | Central DB password |
| `TENANCY_DB_PREFIX` | `tenant_` | Tenant DB name prefix (e.g. `tenant_greenfield`) |
| `QUEUE_CONNECTION` | `redis` | Queue driver (`redis` recommended in prod) |
| `CACHE_STORE` | `redis` | Cache driver (required for module-access caching) |
| `REDIS_HOST` | `127.0.0.1` | Redis host |
| `REDIS_PORT` | `6379` | Redis port |
| `SESSION_DRIVER` | `redis` | Session driver |
| `MAIL_MAILER` | `smtp` | Mail driver |
| `MAIL_HOST` | `smtp.mailgun.org` | SMTP host |
| `MAIL_PORT` | `587` | SMTP port |
| `MAIL_USERNAME` | `postmaster@...` | SMTP username |
| `MAIL_PASSWORD` | `secret` | SMTP password |
| `MAIL_FROM_ADDRESS` | `noreply@compasse.net` | Default sender address |
| `SERVICES_SMS_PROVIDER` | `termii` | SMS provider (`twilio`, `vonage`, `termii`, `log`) |
| `SERVICES_SMS_SENDER_ID` | `Compasse` | SMS sender ID |
| `SERVICES_SMS_API_KEY` | `...` | SMS provider API key |
| `SANCTUM_STATEFUL_DOMAINS` | `compasse.net,*.compasse.net` | Stateful domains for Sanctum |

---

## Frontend Integration — Tenancy Bootstrapping

This section explains how a React/Next.js SPA should handle the multi-tenant context.

### Step 1 — Detect the Subdomain

```typescript
// utils/tenancy.ts

export function getSubdomain(): string | null {
  if (typeof window === 'undefined') return null;

  const hostname = window.location.hostname; // e.g. "greenfield.compasse.net"
  const parts = hostname.split('.');

  // "greenfield.compasse.net" → ["greenfield", "compasse", "com"]
  // "compasse.net" → ["compasse", "com"]  (no subdomain → central/super-admin)
  if (parts.length >= 3 && parts[0] !== 'www') {
    return parts[0]; // "greenfield"
  }
  return null;
}
```

### Step 2 — Verify the School Exists

Before rendering the login form, call the public endpoint:

```typescript
// hooks/useSchoolBootstrap.ts
import { useEffect, useState } from 'react';

export function useSchoolBootstrap(subdomain: string) {
  const [school, setSchool] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetch(`https://compasse.net/api/v1/schools/by-subdomain/${subdomain}`)
      .then(res => res.json())
      .then(data => {
        if (data.exists && data.tenant.status === 'active') {
          setSchool(data.tenant);
        } else if (data.status === 'suspended') {
          setError('This school account is suspended.');
        } else {
          setError('School not found.');
        }
      })
      .catch(() => setError('Unable to connect to server.'))
      .finally(() => setLoading(false));
  }, [subdomain]);

  return { school, loading, error };
}
```

### Step 3 — Login and Store the Token

Tokens are per-tenant. Store them with a subdomain-specific key to support users who access multiple schools.

```typescript
// services/auth.ts

const BASE_URL = (subdomain: string) =>
  `https://compasse.net/api/v1`;

const TOKEN_KEY = (subdomain: string) =>
  `compasse_token_${subdomain}`;

export async function login(subdomain: string, email: string, password: string) {
  const res = await fetch(`${BASE_URL(subdomain)}/auth/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Subdomain': subdomain,
    },
    body: JSON.stringify({ email, password }),
  });

  if (!res.ok) {
    const err = await res.json();
    throw new Error(err.message || 'Login failed');
  }

  const data = await res.json();

  // Store token scoped to this school's subdomain
  localStorage.setItem(TOKEN_KEY(subdomain), data.token);
  localStorage.setItem(`compasse_user_${subdomain}`, JSON.stringify(data.user));
  localStorage.setItem(`compasse_school_${subdomain}`, JSON.stringify(data.school));

  return data;
}

export function getToken(subdomain: string): string | null {
  return localStorage.getItem(TOKEN_KEY(subdomain));
}

export function logout(subdomain: string) {
  localStorage.removeItem(TOKEN_KEY(subdomain));
  localStorage.removeItem(`compasse_user_${subdomain}`);
  localStorage.removeItem(`compasse_school_${subdomain}`);
}
```

### Step 4 — Attach Headers to All API Calls

```typescript
// services/api.ts

export function createApiClient(subdomain: string) {
  const token = localStorage.getItem(`compasse_token_${subdomain}`);

  return {
    get: (path: string) =>
      fetch(`https://compasse.net/api/v1${path}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Subdomain': subdomain,
          'Accept': 'application/json',
        },
      }).then(handleResponse),

    post: (path: string, body: object) =>
      fetch(`https://compasse.net/api/v1${path}`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Subdomain': subdomain,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify(body),
      }).then(handleResponse),

    put: (path: string, body: object) =>
      fetch(`https://compasse.net/api/v1${path}`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Subdomain': subdomain,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify(body),
      }).then(handleResponse),

    delete: (path: string) =>
      fetch(`https://compasse.net/api/v1${path}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Subdomain': subdomain,
          'Accept': 'application/json',
        },
      }).then(handleResponse),
  };
}

async function handleResponse(res: Response) {
  if (res.status === 401) {
    // Token expired or invalid — redirect to login
    const subdomain = getSubdomain();
    if (subdomain) logout(subdomain);
    window.location.href = '/login';
    return;
  }
  if (res.status === 403) {
    const data = await res.json();
    if (data.upgrade_required) {
      // Dispatch a global event to show upgrade modal
      window.dispatchEvent(new CustomEvent('module:upgrade-required', { detail: data }));
    }
    throw data;
  }
  if (!res.ok) throw await res.json();
  return res.json();
}
```

### Step 5 — Full Bootstrapping Flow (App Entry Point)

```typescript
// app/[school]/layout.tsx  (Next.js App Router example)

export default async function SchoolLayout({
  params,
  children,
}: {
  params: { school: string };
  children: React.ReactNode;
}) {
  // Verify school exists server-side
  const res = await fetch(
    `https://compasse.net/api/v1/schools/by-subdomain/${params.school}`
  );
  const data = await res.json();

  if (!data.exists || data.tenant?.status !== 'active') {
    notFound();
  }

  return (
    <SchoolProvider school={data.tenant}>
      {children}
    </SchoolProvider>
  );
}
```

### Token Storage Summary

| Key | Value | Purpose |
|-----|-------|---------|
| `compasse_token_{subdomain}` | Bearer token string | Auth token for that school |
| `compasse_user_{subdomain}` | JSON user object | Cached logged-in user info |
| `compasse_school_{subdomain}` | JSON school object | Cached school branding/info |
| `compasse_modules_{subdomain}` | JSON array of module slugs | Cached enabled modules (set on login) |
| `compasse_superadmin_token` | Bearer token string | Super admin token (central domain only) |

---

## Common Error Responses

| HTTP Status | Meaning | When It Occurs |
|-------------|---------|----------------|
| `400` | Bad Request | Malformed request body |
| `401` | Unauthenticated | Missing or invalid/expired token |
| `403` | Forbidden | Wrong role, or module not in subscription |
| `404` | Not Found | Resource does not exist |
| `409` | Conflict | Duplicate record (e.g. same payroll month/year) |
| `422` | Validation Error | Request fields failed validation |
| `429` | Too Many Requests | Login throttle exceeded |
| `500` | Server Error | Unexpected backend error |

### Validation Error Shape (`422`):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

### Module Access Denied Shape (`403`):
```json
{
  "error": "Module access denied",
  "message": "This school does not have access to the hostel_management module. Please upgrade your subscription.",
  "module": "hostel_management",
  "upgrade_required": true
}
```
