# API Architecture Explained

## Overview

This document explains how the API handles:
1. **Subdomain-based requests**
2. **Superadmin access**
3. **Tenant-specific requests**
4. **All API endpoints**

---

## 1. Subdomain-Based API Calls

### How It Works

The system supports **multiple ways** to identify tenants:

#### Method 1: Subdomain in URL
```
https://school1.samschool.com/api/v1/schools
```
- The `TenantMiddleware` extracts `school1` from the subdomain
- Looks up tenant with `subdomain = 'school1'`
- Automatically switches to that tenant's database

#### Method 2: X-Tenant-ID Header (For api.compasse.net)
```
https://api.compasse.net/api/v1/schools
Headers: X-Tenant-ID: 1
```
- `api.compasse.net` is **excluded** from automatic tenant resolution
- Tenant is resolved from the `X-Tenant-ID` header
- Uses main database for tenant lookup, then switches to tenant DB

#### Method 3: tenant_id in Request Body
```
POST https://api.compasse.net/api/v1/schools
Body: { "tenant_id": 1, "name": "School Name", ... }
```
- Tenant ID can be provided in request body
- Works for POST/PUT/PATCH requests

#### Method 4: X-School-ID Header
```
Headers: X-School-ID: 5
```
- Resolves tenant from school's `tenant_id`

### Excluded Domains

These domains **bypass** automatic tenant resolution:
- `api.compasse.net` (main API domain)
- `localhost`
- `127.0.0.1`

For excluded domains:
- Uses **main database** by default
- Requires explicit tenant identification (header or body)
- Perfect for superadmin operations

### Code Flow

```php
// TenantMiddleware.php
1. Check if domain is excluded (api.compasse.net)
2. If excluded:
   - Try to get tenant from X-Tenant-ID header
   - Try to get tenant from request body (tenant_id)
   - If no tenant found, use main database
3. If not excluded:
   - Extract subdomain from host
   - Look up tenant by subdomain
   - If found, switch to tenant database
4. Store tenant in request attributes
5. Continue to controller
```

---

## 2. Superadmin Handling

### Superadmin Routes

Superadmin routes are **protected** by `role:super_admin` middleware:

```php
Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::apiResource('tenants', TenantController::class);
    Route::get('tenants/{tenant}/stats', [TenantController::class, 'stats']);
});
```

### How Superadmin Works

1. **Authentication**: Superadmin logs in via `POST /api/v1/auth/login`
   - Credentials: `superadmin@compasse.net` / `Nigeria@60`
   - Returns Bearer token

2. **Authorization**: `RoleMiddleware` checks user role
   ```php
   if (!$user->hasRole('super_admin')) {
       return 403 Forbidden;
   }
   ```

3. **Database Access**: Superadmin uses **main database**
   - `api.compasse.net` is excluded from tenant resolution
   - No tenant context required
   - Can access all tenants via main database

4. **Tenant Management**: Superadmin can:
   - Create tenants
   - List all tenants
   - View tenant stats
   - Manage tenant settings

### Superadmin vs Regular Users

| Feature | Superadmin | Regular User |
|---------|-----------|--------------|
| **Domain** | `api.compasse.net` | `subdomain.samschool.com` or `api.compasse.net` with header |
| **Database** | Main database | Tenant database |
| **Tenant Required** | No | Yes (via subdomain or header) |
| **Can Manage Tenants** | ✅ Yes | ❌ No |
| **Can Access All Data** | ✅ Yes (via main DB) | ❌ No (only their tenant) |

---

## 3. All API Endpoints

### Route Categories

#### 1. Public Routes (No Auth Required)
```
GET  /api/health
GET  /api/v1/schools/subdomain/{subdomain}
```

#### 2. Authentication Routes
```
POST   /api/v1/auth/login
POST   /api/v1/auth/register
POST   /api/v1/auth/logout      (requires auth)
POST   /api/v1/auth/refresh      (requires auth)
GET    /api/v1/auth/me           (requires auth)
```

#### 3. Superadmin Routes (Requires super_admin role)
```
GET    /api/v1/tenants
POST   /api/v1/tenants
GET    /api/v1/tenants/{id}
PUT    /api/v1/tenants/{id}
DELETE /api/v1/tenants/{id}
GET    /api/v1/tenants/{id}/stats
```

#### 4. Tenant-Specific Routes (Requires tenant middleware)
All routes below require:
- Authentication (`auth:sanctum`)
- Tenant context (`tenant` middleware)
- Optional: Module access middleware

**School Management:**
```
POST   /api/v1/schools
GET    /api/v1/schools/{id}
PUT    /api/v1/schools/{id}
GET    /api/v1/schools/{id}/stats
GET    /api/v1/schools/{id}/dashboard
GET    /api/v1/schools/{id}/organogram
```

**Subscription Management:**
```
GET    /api/v1/subscriptions/plans
GET    /api/v1/subscriptions/modules
GET    /api/v1/subscriptions/status
POST   /api/v1/subscriptions/create
PUT    /api/v1/subscriptions/{id}/upgrade
DELETE /api/v1/subscriptions/{id}/cancel
GET    /api/v1/subscriptions/modules/{module}/access
GET    /api/v1/subscriptions/features/{feature}/access
GET    /api/v1/subscriptions/school/modules
GET    /api/v1/subscriptions/school/limits
```

**Academic Management** (requires `module:academic_management`):
```
GET    /api/v1/academic-years
POST   /api/v1/academic-years
GET    /api/v1/terms
POST   /api/v1/terms
GET    /api/v1/departments
POST   /api/v1/departments
GET    /api/v1/classes
POST   /api/v1/classes
GET    /api/v1/subjects
POST   /api/v1/subjects
```

**Student Management** (requires `module:student_management`):
```
GET    /api/v1/students
POST   /api/v1/students
GET    /api/v1/students/{id}
PUT    /api/v1/students/{id}
DELETE /api/v1/students/{id}
GET    /api/v1/students/{id}/attendance
GET    /api/v1/students/{id}/results
GET    /api/v1/students/{id}/assignments
GET    /api/v1/students/{id}/subjects
POST   /api/v1/students/generate-admission-number
POST   /api/v1/students/generate-credentials
```

**Teacher Management** (requires `module:teacher_management`):
```
GET    /api/v1/teachers
POST   /api/v1/teachers
GET    /api/v1/teachers/{id}/classes
GET    /api/v1/teachers/{id}/subjects
GET    /api/v1/teachers/{id}/students
```

**And many more...** (See `routes/api.php` for complete list)

---

## 4. Request Flow Examples

### Example 1: Superadmin Creating a Tenant

```
1. POST https://api.compasse.net/api/v1/auth/login
   Body: { "email": "superadmin@compasse.net", "password": "Nigeria@60" }
   → Returns: { "token": "..." }

2. POST https://api.compasse.net/api/v1/tenants
   Headers: { "Authorization": "Bearer ..." }
   Body: { "name": "New School", "subdomain": "newschool", ... }
   → TenantMiddleware: api.compasse.net is excluded, no tenant needed
   → RoleMiddleware: Checks super_admin role ✅
   → Creates tenant in main database
```

### Example 2: Regular User Creating a School

```
1. POST https://api.compasse.net/api/v1/auth/login
   Body: { "email": "user@school.com", "password": "password" }
   → Returns: { "token": "...", "tenant": { "id": 1 } }

2. POST https://api.compasse.net/api/v1/schools
   Headers: { 
     "Authorization": "Bearer ...",
     "X-Tenant-ID": "1"
   }
   Body: { "name": "My School", ... }
   → TenantMiddleware: Gets tenant from X-Tenant-ID header
   → Switches to tenant database
   → Creates school in tenant database
```

### Example 3: Subdomain-Based Request

```
1. GET https://school1.samschool.com/api/v1/schools
   Headers: { "Authorization": "Bearer ..." }
   → TenantMiddleware: Extracts "school1" from subdomain
   → Looks up tenant with subdomain = "school1"
   → Switches to tenant database
   → Returns schools from tenant database
```

---

## 5. Database Isolation

### Main Database (`api.compasse.net`)
- Stores: `tenants`, `users`, `schools` (reference), `plans`, `modules`
- Used by: Superadmin, tenant management
- No tenant context needed

### Tenant Databases (`tenant_*`)
- Stores: `schools`, `students`, `teachers`, `classes`, etc.
- Isolated per tenant
- Automatically switched based on tenant resolution

---

## 6. Security Considerations

1. **Authentication**: All tenant-specific routes require valid Bearer token
2. **Authorization**: Role-based access control (superadmin vs regular users)
3. **Tenant Isolation**: Each tenant can only access their own data
4. **Module Access**: Some routes require specific module subscriptions
5. **Permission Checks**: Fine-grained permissions for specific actions

---

## Summary

- **Subdomain requests**: Automatically resolve tenant from subdomain
- **api.compasse.net**: Excluded domain, requires explicit tenant ID (header/body)
- **Superadmin**: Uses main database, no tenant required, can manage all tenants
- **Regular users**: Must provide tenant context (subdomain or header)
- **All APIs**: Protected by authentication and appropriate middleware

For testing, use the comprehensive test script:
```bash
php test-all-apis-comprehensive.php
```

