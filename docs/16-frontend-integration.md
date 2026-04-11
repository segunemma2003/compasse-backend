# Frontend Integration Guide — Multi-Tenant Architecture

> **Target audience:** Frontend developers building the Compasse school portal (React / Next.js / Vue / React Native)
> **Backend:** Laravel 12 + stancl/tenancy v3 · One database per school
> **Auth:** Laravel Sanctum (opaque bearer tokens per tenant)

---

## How Multi-Tenancy Works

Each school gets its own subdomain and isolated database:

```
https://greenfield.compasse.africa   → Greenfield Academy (tenant DB: db_greenfield)
https://stmarys.compasse.africa      → St Mary's School   (tenant DB: db_stmarys)
https://compasse.africa              → Super Admin portal  (central DB)
```

The backend identifies the tenant from the `Host` header subdomain. All data — users, students, teachers, tokens — lives in that tenant's database. A token from `greenfield` is completely unknown to `stmarys`.

---

## Base URL Strategy

### Option A — Subdomain URL (recommended for web)
```
https://{subdomain}.compasse.africa/api/v1/{endpoint}
```
The backend auto-resolves the tenant from the subdomain.

### Option B — X-Subdomain header (recommended for mobile / cross-origin)
```
https://compasse.africa/api/v1/{endpoint}
X-Subdomain: greenfield
```
Use this when you cannot control the subdomain (e.g. React Native, Electron).

### Option C — X-School-ID header (multi-school tenants)
Some tenants manage more than one school. After login, pass:
```
X-School-ID: 3
```
to scope requests to a specific school within the tenant.

---

## Required Headers (all authenticated requests)

```http
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
X-Subdomain: {subdomain}          (if not using subdomain URL)
X-School-ID: {school_id}          (optional — multi-school tenants only)
```

---

## Authentication Flow

### Step 1 — Detect subdomain (web)

```typescript
// utils/tenant.ts
export function getSubdomain(): string | null {
  const host = window.location.hostname;            // e.g. greenfield.compasse.africa
  const parts = host.split('.');
  if (parts.length >= 3) return parts[0];           // "greenfield"
  return null;                                       // top-level domain — super admin or landing
}
```

### Step 2 — Verify tenant exists

```typescript
// Before showing login screen, verify the subdomain is a valid tenant
const subdomain = getSubdomain();

const res = await fetch(`https://compasse.africa/api/v1/tenants/verify`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ subdomain }),
});

if (!res.ok) {
  // Redirect to compasse.africa — invalid subdomain
  window.location.href = 'https://compasse.africa';
}
const { tenant, school } = await res.json();
// Store school info for display on login page
```

### Step 3 — Login

```typescript
const login = async (email: string, password: string) => {
  const res = await fetch(
    `https://${subdomain}.compasse.africa/api/v1/auth/login`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    }
  );

  if (!res.ok) throw new Error('Invalid credentials');

  const { token, user } = await res.json();

  // Store token keyed by subdomain — supports multiple schools open at once
  localStorage.setItem(`compasse_token_${subdomain}`, token);
  localStorage.setItem(`compasse_user_${subdomain}`, JSON.stringify(user));
  localStorage.setItem('compasse_active_subdomain', subdomain);

  return { token, user };
};
```

### Step 4 — All subsequent requests

```typescript
// utils/api.ts
const subdomain = localStorage.getItem('compasse_active_subdomain');
const token     = localStorage.getItem(`compasse_token_${subdomain}`);

const api = async (path: string, options: RequestInit = {}) => {
  const res = await fetch(
    `https://${subdomain}.compasse.africa/api/v1/${path}`,
    {
      ...options,
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(options.headers ?? {}),
      },
    }
  );

  if (res.status === 401) {
    // Token expired — redirect to login
    localStorage.removeItem(`compasse_token_${subdomain}`);
    window.location.href = '/login';
  }

  if (res.status === 403) {
    const body = await res.json();
    if (body.upgrade_required) {
      // Show subscription upgrade modal
      showUpgradeModal(body.module);
    }
    throw new Error(body.message);
  }

  return res;
};
```

---

## Super Admin Authentication

The super admin does NOT use a subdomain. The frontend for the super admin portal lives at `https://compasse.africa/admin`.

```typescript
const superAdminLogin = async (email: string, password: string) => {
  const res = await fetch('https://compasse.africa/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });

  const { token, user } = await res.json();
  localStorage.setItem('compasse_superadmin_token', token);
  return { token, user };
};

// All super admin API calls use this helper
const adminApi = async (path: string, options: RequestInit = {}) => {
  const token = localStorage.getItem('compasse_superadmin_token');
  return fetch(`https://compasse.africa/api/v1/${path}`, {
    ...options,
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(options.headers ?? {}),
    },
  });
};
```

---

## Module-Based UI Gating

After login, fetch the school's enabled modules and cache them. Use this list to show/hide navigation items and feature sections.

```typescript
// On login success — fetch enabled modules
const loadModules = async () => {
  const res = await api('subscriptions/school/modules');
  const { modules } = await res.json();

  // modules = ["academic_management", "student_management", "cbt", ...]
  sessionStorage.setItem(`modules_${subdomain}`, JSON.stringify(modules));
  return modules;
};

// Helper to check if a module is active
export const hasModule = (module: string): boolean => {
  const modules = JSON.parse(
    sessionStorage.getItem(`modules_${subdomain}`) ?? '[]'
  );
  return modules.includes(module);
};

// Usage in navigation
{hasModule('fee_management') && <NavItem href="/fees" label="Fees" />}
{hasModule('cbt') && <NavItem href="/assessments" label="Assessments" />}
```

### Handling 403 Module Denied

```typescript
// When backend returns 403 with upgrade_required: true
{
  "error": "Module access denied",
  "message": "This school does not have access to the transport_management module.",
  "module": "transport_management",
  "upgrade_required": true
}

// Frontend: intercept in API wrapper and show upgrade modal
const handleUpgradeRequired = (module: string) => {
  // Fetch available plans
  api('subscriptions/plans').then(r => r.json()).then(({ plans }) => {
    showModal(<UpgradeModal module={module} plans={plans} />);
  });
};
```

---

## Pagination

All list endpoints return paginated responses. Use the standard pagination wrapper:

```typescript
interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  next_page_url: string | null;
  prev_page_url: string | null;
}

// Example — paginated student list
const fetchStudents = async (page = 1, perPage = 25) => {
  const res = await api(`students?page=${page}&per_page=${perPage}`);
  return res.json() as Promise<PaginatedResponse<Student>>;
};
```

---

## File Uploads

```typescript
const uploadProfilePicture = async (userId: number, file: File) => {
  const formData = new FormData();
  formData.append('file', file);

  const res = await fetch(
    `https://${subdomain}.compasse.africa/api/v1/users/${userId}/profile-picture`,
    {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
        // Do NOT set Content-Type — browser sets it with boundary for multipart
      },
      body: formData,
    }
  );

  return res.json(); // { url: "https://..." }
};
```

---

## Error Handling Reference

| HTTP Status | Meaning | Frontend Action |
|-------------|---------|-----------------|
| 200 | OK | Process response |
| 201 | Created | Show success, refresh list |
| 400 | Bad request / missing school context | Show error message |
| 401 | Token missing or expired | Clear token, redirect to login |
| 403 `upgrade_required: true` | Module not subscribed | Show upgrade modal |
| 403 (role denied) | Insufficient permissions | Show "Access Denied" |
| 404 | Record not found | Show 404 UI |
| 422 | Validation failed | Show field-level errors from `errors` object |
| 429 | Rate limit hit | Back off and retry after `Retry-After` header |
| 500 | Server error | Show generic error, log to Sentry |
| 503 | Subscription check failed | Show "Service Unavailable" with retry |

### Validation error handling (422)

```typescript
if (res.status === 422) {
  const { errors } = await res.json();
  // errors = { "email": ["The email field is required."], "amount": [...] }
  // Map to form field errors
  Object.entries(errors).forEach(([field, messages]) => {
    form.setError(field, { message: messages[0] });
  });
}
```

---

## React Query / SWR Integration

```typescript
// hooks/useStudents.ts
import { useQuery } from '@tanstack/react-query';

export const useStudents = (page = 1) =>
  useQuery({
    queryKey: ['students', page],
    queryFn: () => api(`students?page=${page}`).then(r => r.json()),
    staleTime: 30_000,
  });

// Cache invalidation after mutation
const queryClient = useQueryClient();
const createStudent = useMutation({
  mutationFn: (data) => api('students', { method: 'POST', body: JSON.stringify(data) }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['students'] }),
});
```

---

## Real-Time Notifications (WebSocket / Polling)

Compasse uses a queue-based notification system. Frontend has two options:

### Option A — Polling (simple)
```typescript
// Poll every 30 seconds for new notifications
setInterval(async () => {
  const res = await api('communication/notifications?unread=1&per_page=5');
  const { data } = await res.json();
  setUnreadCount(data.length);
}, 30_000);
```

### Option B — Laravel Echo + Pusher (real-time)
```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const echo = new Echo({
  broadcaster: 'pusher',
  key: process.env.NEXT_PUBLIC_PUSHER_KEY,
  cluster: process.env.NEXT_PUBLIC_PUSHER_CLUSTER,
  forceTLS: true,
  auth: { headers: { Authorization: `Bearer ${token}` } },
});

echo.private(`school.${schoolId}`)
  .listen('.notification.created', (e) => {
    addNotification(e.notification);
    incrementBadge();
  });
```

---

## Multi-School Support (within one tenant)

Some tenants operate multiple schools. After login, check if the user has access to multiple schools:

```typescript
const { user } = await api('auth/me').then(r => r.json());

if (user.schools && user.schools.length > 1) {
  // Show school picker
  showSchoolSelector(user.schools);
}

// After school selection, include X-School-ID in all subsequent requests
const switchSchool = (schoolId: number) => {
  localStorage.setItem(`compasse_school_${subdomain}`, String(schoolId));
};

// In API wrapper, add school header when set
const schoolId = localStorage.getItem(`compasse_school_${subdomain}`);
if (schoolId) headers['X-School-ID'] = schoolId;
```

---

## Landing Page (Public)

School landing pages are publicly accessible — no auth required.

```typescript
// Fetch a school's public landing page data
const fetchLandingPage = async (subdomain: string) => {
  const res = await fetch(
    `https://compasse.africa/api/v1/public/${subdomain}`,
    { headers: { 'Accept': 'application/json' } }
  );
  return res.json();
};

// Response includes:
// - school info (name, logo, contact)
// - template (id, colors, features)
// - hero (headline, image, CTA)
// - about (tagline, vision, mission)
// - social links
// - SEO metadata
```

---

## Environment Variables (Frontend)

```env
NEXT_PUBLIC_API_BASE=https://compasse.africa/api/v1
NEXT_PUBLIC_SUBDOMAIN_BASE=compasse.africa
NEXT_PUBLIC_PUSHER_KEY=your_pusher_key
NEXT_PUBLIC_PUSHER_CLUSTER=eu
NEXT_PUBLIC_SENTRY_DSN=https://...
```

---

## React Native / Mobile

For mobile apps, use the `X-Subdomain` header approach — no subdomain in URL required.

```typescript
// Secure token storage for mobile
import * as SecureStore from 'expo-secure-store';

const saveToken = async (subdomain: string, token: string) => {
  await SecureStore.setItemAsync(`compasse_token_${subdomain}`, token);
};

const getToken = async (subdomain: string) => {
  return SecureStore.getItemAsync(`compasse_token_${subdomain}`);
};

// API wrapper with X-Subdomain header
const mobileApi = async (subdomain: string, path: string, options = {}) => {
  const token = await getToken(subdomain);
  return fetch(`https://compasse.africa/api/v1/${path}`, {
    ...options,
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Subdomain': subdomain,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
  });
};
```

---

## TypeScript Types Reference

```typescript
// Core types

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  roles: string[];
  profile_picture?: string;
}

interface School {
  id: number;
  name: string;
  code: string;
  logo?: string;
  email: string;
  phone: string;
  address: string;
  status: 'active' | 'suspended';
}

interface Subscription {
  id: number;
  plan_name: string;
  status: 'active' | 'trial' | 'cancelled' | 'expired';
  modules: string[];
  end_date: string;
  days_remaining: number;
}

interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  error?: string;
  module?: string;
  upgrade_required?: boolean;
}
```
