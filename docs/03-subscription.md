# Subscription Management

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** None — all authenticated tenant users can check subscription status

---

## Overview

Every school must subscribe to a plan to access module-gated features. Plans define which modules are enabled and what usage limits apply (max students, storage, etc.). Subscriptions are stored in the **tenant database** and managed via `SubscriptionService`.

Module access decisions are **Redis-cached for 5 minutes** — changes to a school's plan take effect within 5 minutes system-wide.

---

## User Stories

> **As a super admin**, I want to create and manage subscription plans so that schools can choose the tier that fits their needs.

> **As a school admin**, I want to view my current subscription, see which modules I have access to, and upgrade my plan if needed.

> **As the system**, I want to block access to premium features when a school's subscription is expired or cancelled, returning a clear 403 response with an upgrade prompt.

---

## Plans

Plans are created by super admins and stored in the central database.

### Plan Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Plan display name (e.g. "Starter", "Pro", "Enterprise") |
| `slug` | string | Unique identifier (e.g. `starter`, `pro`) |
| `price` | decimal | Price in the plan's currency |
| `billing_cycle` | enum | `monthly`, `quarterly`, `yearly` |
| `currency` | string | e.g. `NGN`, `USD` |
| `trial_days` | integer | Free trial days (0 = no trial) |
| `features` | JSON array | Module slugs included in this plan |
| `limits` | JSON object | Usage limits e.g. `{"students": 500, "storage": 5000}` |
| `is_active` | boolean | Whether plan is available for purchase |
| `is_popular` | boolean | Highlighted in pricing UI |

### Example Plan Object

```json
{
  "id": 2,
  "name": "Pro",
  "slug": "pro",
  "price": 25000,
  "billing_cycle": "monthly",
  "currency": "NGN",
  "trial_days": 14,
  "is_active": true,
  "is_popular": true,
  "features": [
    "academic_management",
    "student_management",
    "teacher_management",
    "cbt",
    "fee_management",
    "attendance_management",
    "transport_management",
    "hostel_management",
    "health_management",
    "inventory_management",
    "event_management",
    "sms_integration",
    "email_integration"
  ],
  "limits": {
    "students": 2000,
    "teachers": 200,
    "storage": 50000
  }
}
```

---

## Subscription Lifecycle

```
No Subscription
      │
      ▼ createSubscription()
   Active (or Trial)
      │
      ├──▶ upgradeSubscription()  → Active (new plan)
      │
      ├──▶ renewSubscription()    → Active (extended end_date)
      │
      └──▶ cancelSubscription()
               │
               ├── immediate=true  → Cancelled (end_date = now)
               └── immediate=false → Cancelled (runs to end_date)
```

### Status Rules

| Status | isActive() | isTrial() | Module Access |
|--------|-----------|-----------|---------------|
| `active` + end_date future | Yes | No | Full plan modules |
| `active` + trial_end_date future | Yes | Yes | Full plan modules |
| `active` + end_date past | No | No | **None — denied** |
| `cancelled` | No | No | **None — denied** |
| No record | — | — | **None — denied** |

> **No subscription = no access.** There is no free tier bypass.

---

## API Endpoints

All endpoints require `tenant` + `auth:sanctum` middleware. No additional module gate.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/subscriptions/plans` | List all active plans |
| GET | `/api/v1/subscriptions/modules` | List all available modules |
| GET | `/api/v1/subscriptions/status` | Current school subscription status |
| GET | `/api/v1/subscriptions/school/modules` | Modules enabled for this school |
| GET | `/api/v1/subscriptions/school/limits` | Usage limits for this school |
| GET | `/api/v1/subscriptions/modules/{module}/access` | Check single module access |
| GET | `/api/v1/subscriptions/features/{feature}/access` | Check single feature access |
| GET | `/api/v1/subscriptions` | List subscriptions for this school |
| GET | `/api/v1/subscriptions/{id}` | Get subscription details |
| POST | `/api/v1/subscriptions/create` | Create subscription |
| PUT | `/api/v1/subscriptions/{id}/upgrade` | Upgrade to new plan |
| POST | `/api/v1/subscriptions/{id}/renew` | Renew subscription |
| DELETE | `/api/v1/subscriptions/{id}/cancel` | Cancel subscription |

---

## Full Request / Response Examples

### List All Active Plans

```
GET /api/v1/subscriptions/plans
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "plans": [
    {
      "id": 1,
      "name": "Starter",
      "slug": "starter",
      "price": 8000,
      "billing_cycle": "monthly",
      "currency": "NGN",
      "trial_days": 7,
      "is_popular": false,
      "features": [
        "academic_management",
        "student_management",
        "teacher_management",
        "fee_management"
      ],
      "limits": {
        "students": 300,
        "teachers": 30,
        "storage": 5000
      }
    },
    {
      "id": 2,
      "name": "Pro",
      "slug": "pro",
      "price": 25000,
      "billing_cycle": "monthly",
      "currency": "NGN",
      "trial_days": 14,
      "is_popular": true,
      "features": [
        "academic_management",
        "student_management",
        "teacher_management",
        "cbt",
        "fee_management",
        "attendance_management",
        "transport_management",
        "hostel_management",
        "health_management",
        "inventory_management",
        "event_management",
        "sms_integration",
        "email_integration"
      ],
      "limits": {
        "students": 2000,
        "teachers": 200,
        "storage": 50000
      }
    },
    {
      "id": 3,
      "name": "Enterprise",
      "slug": "enterprise",
      "price": 60000,
      "billing_cycle": "monthly",
      "currency": "NGN",
      "trial_days": 30,
      "is_popular": false,
      "features": [
        "academic_management",
        "student_management",
        "teacher_management",
        "cbt",
        "fee_management",
        "attendance_management",
        "transport_management",
        "hostel_management",
        "health_management",
        "inventory_management",
        "event_management",
        "livestream",
        "sms_integration",
        "email_integration"
      ],
      "limits": {
        "students": -1,
        "teachers": -1,
        "storage": -1
      }
    }
  ]
}
```

> `"students": -1` means unlimited.

---

### List All Available Modules

```
GET /api/v1/subscriptions/modules
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "modules": [
    { "slug": "academic_management", "name": "Academic Management", "description": "Classes, subjects, academic years, terms" },
    { "slug": "student_management",  "name": "Student Management",  "description": "Student enrollment and profiles" },
    { "slug": "teacher_management",  "name": "Teacher Management",  "description": "Teacher profiles and class assignments" },
    { "slug": "cbt",                 "name": "CBT & Exams",         "description": "Computer-based testing, results, report cards" },
    { "slug": "fee_management",      "name": "Fee Management",      "description": "School fees, payments, payroll, receipts" },
    { "slug": "attendance_management","name": "Attendance",          "description": "Student and staff attendance tracking" },
    { "slug": "transport_management","name": "Transport",            "description": "Vehicles, drivers, routes, secure pickup" },
    { "slug": "hostel_management",   "name": "Hostel",              "description": "Rooms, allocations, maintenance" },
    { "slug": "health_management",   "name": "Health",              "description": "Health records, appointments, medications" },
    { "slug": "inventory_management","name": "Inventory",           "description": "Categories, items, checkout and return" },
    { "slug": "event_management",    "name": "Events",              "description": "Events and school calendar" },
    { "slug": "livestream",          "name": "Livestream",          "description": "Live classes and virtual sessions" },
    { "slug": "sms_integration",     "name": "SMS Notifications",   "description": "SMS alerts and notifications" },
    { "slug": "email_integration",   "name": "Email Notifications", "description": "Email alerts and notifications" }
  ]
}
```

---

### Check Subscription Status

```
GET /api/v1/subscriptions/status
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK` (active subscription):**
```json
{
  "status": "active",
  "plan": "Pro",
  "plan_slug": "pro",
  "plan_id": 2,
  "features": [
    "academic_management",
    "student_management",
    "teacher_management",
    "cbt",
    "fee_management",
    "attendance_management",
    "transport_management",
    "hostel_management",
    "health_management",
    "inventory_management",
    "event_management",
    "sms_integration",
    "email_integration"
  ],
  "limits": {
    "students": 2000,
    "teachers": 200,
    "storage": 50000
  },
  "start_date": "2026-03-01",
  "end_date": "2026-04-17",
  "days_remaining": 18,
  "is_trial": false,
  "trial_end_date": null,
  "auto_renew": true,
  "next_billing_date": "2026-04-17"
}
```

**Response `200 OK` (trial subscription):**
```json
{
  "status": "active",
  "plan": "Pro",
  "plan_slug": "pro",
  "plan_id": 2,
  "features": ["academic_management", "fee_management"],
  "limits": { "students": 2000 },
  "start_date": "2026-03-25",
  "end_date": "2026-04-25",
  "days_remaining": 26,
  "is_trial": true,
  "trial_end_date": "2026-04-08",
  "auto_renew": false,
  "next_billing_date": null
}
```

**Response `200 OK` (no subscription):**
```json
{
  "status": "none",
  "plan": null,
  "message": "This school has no active subscription. Please subscribe to access features."
}
```

---

### Get School's Enabled Modules

```
GET /api/v1/subscriptions/school/modules
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "school_id": 1,
  "plan": "Pro",
  "modules": [
    "academic_management",
    "student_management",
    "teacher_management",
    "cbt",
    "fee_management",
    "attendance_management",
    "transport_management",
    "hostel_management",
    "health_management",
    "inventory_management",
    "event_management",
    "sms_integration",
    "email_integration"
  ],
  "cached_at": "2026-03-30T10:00:00Z",
  "cache_ttl_seconds": 300
}
```

---

### Get School Usage Limits

```
GET /api/v1/subscriptions/school/limits
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "school_id": 1,
  "plan": "Pro",
  "limits": {
    "students": {
      "max": 2000,
      "current": 842,
      "remaining": 1158,
      "percent_used": 42.1
    },
    "teachers": {
      "max": 200,
      "current": 64,
      "remaining": 136,
      "percent_used": 32.0
    },
    "storage_mb": {
      "max": 50000,
      "current": 1245,
      "remaining": 48755,
      "percent_used": 2.5
    }
  }
}
```

---

### Check Single Module Access

```
GET /api/v1/subscriptions/modules/{module}/access
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Example:** `GET /api/v1/subscriptions/modules/hostel_management/access`

**Response `200 OK` (access granted):**
```json
{
  "module": "hostel_management",
  "has_access": true,
  "plan": "Pro"
}
```

**Response `200 OK` (access denied):**
```json
{
  "module": "livestream",
  "has_access": false,
  "plan": "Pro",
  "message": "The livestream module is not included in your current plan. Upgrade to Enterprise to access it.",
  "upgrade_required": true
}
```

---

### Check Single Feature Access

```
GET /api/v1/subscriptions/features/{feature}/access
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "feature": "sms_integration",
  "has_access": true
}
```

---

### List Subscriptions for School

```
GET /api/v1/subscriptions
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 3,
      "plan_id": 2,
      "plan_name": "Pro",
      "status": "active",
      "start_date": "2026-03-01",
      "end_date": "2026-04-17",
      "payment_method": "bank_transfer",
      "auto_renew": true,
      "created_at": "2026-03-01T09:00:00Z"
    },
    {
      "id": 2,
      "plan_id": 1,
      "plan_name": "Starter",
      "status": "cancelled",
      "start_date": "2025-09-01",
      "end_date": "2026-02-28",
      "payment_method": "bank_transfer",
      "auto_renew": false,
      "created_at": "2025-09-01T08:00:00Z"
    }
  ]
}
```

---

### Get Subscription Details

```
GET /api/v1/subscriptions/{id}
Authorization: Bearer {token}
X-Subdomain: greenfield
```

**Response `200 OK`:**
```json
{
  "subscription": {
    "id": 3,
    "school_id": 1,
    "plan_id": 2,
    "plan": {
      "name": "Pro",
      "slug": "pro",
      "price": 25000,
      "billing_cycle": "monthly",
      "currency": "NGN"
    },
    "status": "active",
    "start_date": "2026-03-01",
    "end_date": "2026-04-17",
    "trial_end_date": null,
    "is_trial": false,
    "payment_method": "bank_transfer",
    "auto_renew": true,
    "notes": null,
    "created_at": "2026-03-01T09:00:00Z",
    "updated_at": "2026-03-01T09:00:00Z"
  }
}
```

---

### Create Subscription

```
POST /api/v1/subscriptions/create
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "plan_id": 2,
  "payment_method": "bank_transfer",
  "auto_renew": true,
  "start_date": "2026-04-01"
}
```

**Response `201 Created`:**
```json
{
  "message": "Subscription created successfully",
  "subscription": {
    "id": 4,
    "school_id": 1,
    "plan_id": 2,
    "plan_name": "Pro",
    "status": "active",
    "start_date": "2026-04-01",
    "end_date": "2026-05-01",
    "is_trial": false,
    "trial_end_date": null,
    "payment_method": "bank_transfer",
    "auto_renew": true,
    "created_at": "2026-03-30T16:00:00Z"
  }
}
```

**Response `409 Conflict` (active subscription already exists):**
```json
{
  "message": "This school already has an active subscription. Please upgrade or wait for renewal."
}
```

---

### Upgrade Plan

```
PUT /api/v1/subscriptions/{id}/upgrade
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "plan_id": 3
}
```

**Response `200 OK`:**
```json
{
  "message": "Subscription upgraded successfully",
  "subscription": {
    "id": 3,
    "plan_id": 3,
    "plan_name": "Enterprise",
    "status": "active",
    "end_date": "2026-04-30",
    "upgraded_at": "2026-03-30T16:05:00Z"
  }
}
```

**Response `422 Unprocessable Entity` (downgrade attempt):**
```json
{
  "message": "You cannot downgrade to a lower-tier plan through this endpoint. Cancel and create a new subscription."
}
```

---

### Renew Subscription

Extends the subscription end date by one billing cycle.

```
POST /api/v1/subscriptions/{id}/renew
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "payment_method": "bank_transfer",
  "payment_reference": "TRF-2026-03-RENEW"
}
```

**Response `200 OK`:**
```json
{
  "message": "Subscription renewed successfully",
  "subscription": {
    "id": 3,
    "plan_name": "Pro",
    "status": "active",
    "previous_end_date": "2026-04-17",
    "new_end_date": "2026-05-17",
    "renewed_at": "2026-03-30T16:10:00Z"
  }
}
```

---

### Cancel Subscription

```
DELETE /api/v1/subscriptions/{id}/cancel
Authorization: Bearer {token}
X-Subdomain: greenfield
Content-Type: application/json
```

**Request Body:**
```json
{
  "immediate": false,
  "reason": "Switching to a different provider"
}
```

| Field | Description |
|-------|-------------|
| `immediate` | `true` = cancel now (access ends immediately). `false` = cancel at period end. |
| `reason` | Optional cancellation reason |

**Response `200 OK` (cancel at period end):**
```json
{
  "message": "Subscription cancelled. Access will continue until 2026-04-17.",
  "subscription": {
    "id": 3,
    "status": "cancelled",
    "access_until": "2026-04-17",
    "cancelled_at": "2026-03-30T16:15:00Z"
  }
}
```

**Response `200 OK` (immediate cancellation):**
```json
{
  "message": "Subscription cancelled immediately. Access has been revoked.",
  "subscription": {
    "id": 3,
    "status": "cancelled",
    "access_until": "2026-03-30",
    "cancelled_at": "2026-03-30T16:15:00Z"
  }
}
```

---

## Module Access Check (HTTP 403 Response)

When a school tries to access a gated route without the required module:

```json
HTTP 403 Forbidden
{
  "error": "Module access denied",
  "message": "This school does not have access to the hostel_management module. Please upgrade your subscription.",
  "module": "hostel_management",
  "upgrade_required": true
}
```

---

## Caching Details

`SubscriptionService` uses Laravel Cache (Redis) to avoid a DB hit on every request:

| Cache Key | TTL | Invalidated On |
|-----------|-----|----------------|
| `sub:school:{id}:module:{slug}` | 5 min | create / upgrade / renew / cancel |
| `sub:school:{id}:modules` | 5 min | same |
| `sub:school:{id}:status` | 1 min | same |

Redis tags (`school:{id}:subscription`) are used when the cache driver supports them (Redis), giving instant full invalidation. File/database drivers rely on TTL expiry.

---

## Frontend Integration — Subscription & Module Gating

### Step 1 — Fetch and Cache Modules on Login

Immediately after a successful login, fetch the school's enabled modules and cache them. This allows the frontend to conditionally show/hide UI sections without a round-trip on every navigation.

```typescript
// services/modules.ts

const MODULES_KEY = (subdomain: string) => `compasse_modules_${subdomain}`;

export async function fetchAndCacheModules(subdomain: string, token: string) {
  const res = await fetch('https://compasse.africa/api/v1/subscriptions/school/modules', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Subdomain': subdomain,
      'Accept': 'application/json',
    },
  });

  if (!res.ok) return [];

  const data = await res.json();
  const modules: string[] = data.modules;

  // Cache in localStorage — refresh on every login
  localStorage.setItem(MODULES_KEY(subdomain), JSON.stringify(modules));
  localStorage.setItem(`${MODULES_KEY(subdomain)}_at`, Date.now().toString());

  return modules;
}

export function getCachedModules(subdomain: string): string[] {
  const raw = localStorage.getItem(MODULES_KEY(subdomain));
  return raw ? JSON.parse(raw) : [];
}

export function hasModule(subdomain: string, moduleSlug: string): boolean {
  return getCachedModules(subdomain).includes(moduleSlug);
}
```

### Step 2 — Protect Navigation Items

```typescript
// components/Sidebar.tsx

import { hasModule } from '@/services/modules';
import { getSubdomain } from '@/utils/tenancy';

const subdomain = getSubdomain()!;

const navItems = [
  { label: 'Dashboard',    path: '/dashboard',    module: null },
  { label: 'Students',     path: '/students',     module: 'student_management' },
  { label: 'Fees',         path: '/fees',         module: 'fee_management' },
  { label: 'Payroll',      path: '/payroll',      module: 'fee_management' },
  { label: 'CBT / Exams',  path: '/cbt',          module: 'cbt' },
  { label: 'Attendance',   path: '/attendance',   module: 'attendance_management' },
  { label: 'Transport',    path: '/transport',    module: 'transport_management' },
  { label: 'Hostel',       path: '/hostel',       module: 'hostel_management' },
  { label: 'Health',       path: '/health',       module: 'health_management' },
  { label: 'Livestream',   path: '/livestream',   module: 'livestream' },
];

export function Sidebar() {
  return (
    <nav>
      {navItems
        .filter(item => !item.module || hasModule(subdomain, item.module))
        .map(item => (
          <NavLink key={item.path} to={item.path}>{item.label}</NavLink>
        ))
      }
    </nav>
  );
}
```

### Step 3 — Handle the 403 Upgrade Prompt

Listen for the global upgrade event (emitted by the API client on any `403 upgrade_required` response) and show an upgrade modal.

```typescript
// components/UpgradeModal.tsx

import { useEffect, useState } from 'react';

export function UpgradeModal() {
  const [upgradeData, setUpgradeData] = useState<{
    module: string;
    message: string;
  } | null>(null);

  useEffect(() => {
    const handler = (e: CustomEvent) => {
      setUpgradeData(e.detail);
    };
    window.addEventListener('module:upgrade-required', handler as EventListener);
    return () => window.removeEventListener('module:upgrade-required', handler as EventListener);
  }, []);

  if (!upgradeData) return null;

  return (
    <Modal onClose={() => setUpgradeData(null)}>
      <h2>Upgrade Required</h2>
      <p>{upgradeData.message}</p>
      <p>
        Module: <strong>{upgradeData.module}</strong>
      </p>
      <Button onClick={() => navigate('/settings/subscription')}>
        View Plans & Upgrade
      </Button>
    </Modal>
  );
}
```

### Step 4 — Subscription Settings Page

```typescript
// pages/settings/subscription.tsx

import { createApiClient } from '@/services/api';
import { getSubdomain } from '@/utils/tenancy';

export default function SubscriptionPage() {
  const subdomain = getSubdomain()!;
  const api = createApiClient(subdomain);
  const [status, setStatus] = useState(null);
  const [plans, setPlans] = useState([]);

  useEffect(() => {
    Promise.all([
      api.get('/subscriptions/status'),
      api.get('/subscriptions/plans'),
    ]).then(([statusData, plansData]) => {
      setStatus(statusData);
      setPlans(plansData.plans);
    });
  }, []);

  const handleUpgrade = async (planId: number, subscriptionId: number) => {
    await api.put(`/subscriptions/${subscriptionId}/upgrade`, { plan_id: planId });
    // Refresh modules cache after upgrade
    const token = localStorage.getItem(`compasse_token_${subdomain}`);
    await fetchAndCacheModules(subdomain, token!);
    alert('Plan upgraded! New features are now available.');
  };

  return (
    <div>
      <h1>Subscription</h1>
      {status && (
        <div>
          <p>Current Plan: <strong>{status.plan}</strong></p>
          <p>Status: <strong>{status.status}</strong></p>
          <p>Expires: <strong>{status.end_date}</strong></p>
          <p>Days Remaining: <strong>{status.days_remaining}</strong></p>
        </div>
      )}
      <h2>Available Plans</h2>
      {plans.map(plan => (
        <PlanCard
          key={plan.id}
          plan={plan}
          isCurrent={plan.slug === status?.plan_slug}
          onUpgrade={() => handleUpgrade(plan.id, status.subscription_id)}
        />
      ))}
    </div>
  );
}
```

### Module Cache Invalidation Strategy

After any subscription change (upgrade, renew, cancel), always re-fetch and re-cache the modules list:

```typescript
// After any subscription mutation
await api.put(`/subscriptions/${id}/upgrade`, { plan_id: newPlanId });

// Invalidate and refresh the module cache
const token = localStorage.getItem(`compasse_token_${subdomain}`);
await fetchAndCacheModules(subdomain, token!);

// Optionally reload the page to reflect new module access in nav
window.location.reload();
```
