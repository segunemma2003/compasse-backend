# SuperAdmin & Public API Documentation

**Version:** 1.0.0  
**Last Updated:** January 18, 2026  
**Base URL:** `https://api.compasse.net` or `http://localhost:8000`

---

## Table of Contents

1. [Authentication](#authentication)
2. [Public APIs](#public-apis)
3. [SuperAdmin APIs](#superadmin-apis)
   - [School Management](#school-management)
   - [School Control Actions](#school-control-actions)
   - [Tenant Management](#tenant-management)
   - [Platform Monitoring](#platform-monitoring)
4. [Query Parameters & Filtering](#query-parameters--filtering)
5. [Error Responses](#error-responses)
6. [Rate Limiting](#rate-limiting)

---

## Authentication

### SuperAdmin Credentials
```
Email: superadmin@compasse.net
Password: Nigeria@60
Role: super_admin
```

### Login

**Endpoint:** `POST /api/v1/auth/login`

**Request:**
```json
{
  "email": "superadmin@compasse.net",
  "password": "Nigeria@60"
}
```

**Response:**
```json
{
  "message": "Login successful",
  "user": {
    "id": 2,
    "tenant_id": null,
    "name": "Super Administrator",
    "email": "superadmin@compasse.net",
    "role": "super_admin",
    "status": "active"
  },
  "token": "173|98pXAxd2dLqD0QYtd98vBb5RLsrlUeSG6KF7BKfOb0e0c29e",
  "token_type": "Bearer"
}
```

**Usage:**
```bash
curl -X POST https://api.compasse.net/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}'
```

---

## Public APIs

### 🌐 No Authentication Required

---

### 1. Health Check

**Endpoint:** `GET /api/health`

**Description:** Check if the API server is running.

**Response:**
```json
{
  "status": "ok",
  "timestamp": "2026-01-18T19:12:37.046580Z",
  "version": "1.0.0"
}
```

**Example:**
```bash
curl https://api.compasse.net/api/health
```

---

### 2. Database Health Check

**Endpoint:** `GET /api/health/db`

**Description:** Check database connectivity and configuration.

**Response:**
```json
{
  "default_connection": "mysql",
  "connection_status": "success",
  "server_version": "8.4.5",
  "mysql_config": {
    "host": "127.0.0.1",
    "port": "3306",
    "database": "sam_compasse"
  }
}
```

---

### 3. Check if School Exists (Quick)

**Endpoint:** `GET /api/v1/schools/by-subdomain/{subdomain}`

**Description:** Quickly check if a school exists by subdomain.

**Path Parameters:**
- `subdomain` (string, required) - School subdomain

**Response (School Found):**
```json
{
  "exists": true,
  "success": true,
  "tenant": {
    "id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
    "name": "Green Valley School",
    "subdomain": "greenvalley",
    "domain": null,
    "status": "active",
    "has_database": true
  }
}
```

**Response (School Not Found):**
```json
{
  "exists": false,
  "error": "School not found",
  "message": "No school found with subdomain: nonexistent"
}
```

**Example:**
```bash
curl https://api.compasse.net/api/v1/schools/by-subdomain/greenvalley
```

**Alternative (Query Parameter):**
```bash
GET /api/v1/schools/by-subdomain?subdomain=greenvalley
```

---

### 4. Get Full School Information

**Endpoint:** `GET /api/v1/schools/subdomain/{subdomain}`

**Description:** Get detailed school information including statistics.

**Response:**
```json
{
  "success": true,
  "subdomain": "greenvalley",
  "tenant": {
    "id": "...",
    "name": "Green Valley School",
    "subdomain": "greenvalley",
    "status": "active"
  },
  "school": {
    "id": 1,
    "name": "Green Valley School",
    "address": "123 Main Street, Lagos, Nigeria",
    "phone": "+234-800-555-0123",
    "email": "info@greenvalley.edu",
    "website": "https://greenvalley.edu",
    "logo": "https://...",
    "status": "active",
    "academic_year": "2025-2026",
    "term": "First Term"
  },
  "stats": {
    "teachers": 50,
    "students": 500,
    "classes": 30,
    "subjects": 20,
    "departments": 5
  }
}
```

---

### 5. Verify Tenant

**Endpoint:** `POST /api/v1/tenants/verify`

**Description:** Verify if a tenant exists and is active.

**Request:**
```json
{
  "subdomain": "greenvalley"
}
```

**Response:**
```json
{
  "exists": true,
  "tenant_id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
  "name": "Green Valley School",
  "subdomain": "greenvalley",
  "status": "active"
}
```

---

## SuperAdmin APIs

### 🔐 Requires Authentication

**All SuperAdmin endpoints require:**
- `Authorization: Bearer {token}` header
- User role must be `super_admin`

---

## School Management

### 1. List All Schools

**Endpoint:** `GET /api/v1/schools`

**Description:** Get a paginated list of all schools in the platform.

**Query Parameters:**
- `search` (string, optional) - Search in name, code, or email
- `status` (string, optional) - Filter by status: `active`, `inactive`, `suspended`
- `per_page` (integer, optional) - Results per page (default: 15)
- `page` (integer, optional) - Page number (default: 1)

**Request:**
```bash
GET /api/v1/schools?search=valley&status=active&per_page=10&page=1
Authorization: Bearer {token}
```

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 23,
      "tenant_id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
      "name": "Green Valley School",
      "code": "GVS",
      "address": "123 Main St, Lagos",
      "phone": "+234-800-555-0123",
      "email": "info@greenvalley.edu",
      "website": "https://greenvalley.edu",
      "status": "active",
      "created_at": "2025-11-23T19:48:53.000000Z",
      "tenant": {
        "id": "...",
        "subdomain": "greenvalley",
        "database_name": "20251123194840_green-valley",
        "status": "active"
      }
    }
  ],
  "first_page_url": "https://api.compasse.net/api/v1/schools?page=1",
  "from": 1,
  "last_page": 3,
  "last_page_url": "https://api.compasse.net/api/v1/schools?page=3",
  "next_page_url": "https://api.compasse.net/api/v1/schools?page=2",
  "per_page": 10,
  "prev_page_url": null,
  "to": 10,
  "total": 25
}
```

---

### 2. Create New School

**Endpoint:** `POST /api/v1/schools`

**Description:** Create a new school with automatic tenant database provisioning.

**Request:**
```json
{
  "name": "Green Valley School",
  "subdomain": "greenvalley",
  "email": "admin@greenvalley.edu",
  "phone": "+234-800-555-0123",
  "address": "123 Main Street, Lagos, Nigeria",
  "website": "https://greenvalley.edu",
  "plan_id": 1
}
```

**Response:**
```json
{
  "message": "School created successfully",
  "school": {
    "id": 26,
    "tenant_id": "6ee94112-49f6-4450-9166-0c03891bb61f",
    "name": "Green Valley School",
    "code": "GREEN_VALLEY_SCHOOL",
    "address": "123 Main Street, Lagos, Nigeria",
    "phone": "+234-800-555-0123",
    "email": "admin@greenvalley.edu",
    "status": "active",
    "created_at": "2026-01-18T19:25:24.000000Z"
  },
  "tenant": {
    "id": "6ee94112-49f6-4450-9166-0c03891bb61f",
    "name": "Green Valley School",
    "subdomain": "greenvalley",
    "database_name": "20260118192504_green-valley",
    "status": "active",
    "admin_credentials": {
      "email": "admin@greenvalley.samschool.com",
      "password": "Password@12345",
      "role": "school_admin"
    }
  },
  "admin_account": {
    "email": "admin@greenvalley.com",
    "password": "Password@12345",
    "note": "Please change this password on first login"
  }
}
```

**What Gets Created:**
1. ✅ New tenant database
2. ✅ School record in main database
3. ✅ School record in tenant database
4. ✅ School admin account with credentials
5. ✅ All migrations run automatically

---

### 3. Get School Details

**Endpoint:** `GET /api/v1/schools/{school_id}`

**Description:** Get detailed information about a specific school.

**Headers:**
- `Authorization: Bearer {token}`
- `X-Subdomain: {subdomain}` (required for tenant context)

**Response:**
```json
{
  "school": {
    "id": 1,
    "name": "Green Valley School",
    "code": "GVS",
    "address": "123 Main St",
    "phone": "+234-800-555-0123",
    "email": "info@greenvalley.edu",
    "website": "https://greenvalley.edu",
    "logo": "https://...",
    "status": "active",
    "academic_year": "2025-2026",
    "term": "First Term"
  },
  "stats": {
    "teachers": 50,
    "students": 500,
    "classes": 30
  }
}
```

---

### 4. Update School

**Endpoint:** `PUT /api/v1/schools/{school_id}`

**Description:** Update school information.

**Request:**
```json
{
  "name": "Green Valley International School",
  "address": "456 Updated Street, Lagos",
  "phone": "+234-800-NEW-NUMBER",
  "website": "https://greenvalley-international.edu"
}
```

**Response:**
```json
{
  "message": "School updated successfully",
  "school": {
    "id": 1,
    "name": "Green Valley International School",
    "address": "456 Updated Street, Lagos",
    "updated_at": "2026-01-18T20:00:00.000000Z"
  }
}
```

---

### 5. Delete School

**Endpoint:** `DELETE /api/v1/schools/{school_id}`

**Description:** Delete a school (SuperAdmin only).

**Query Parameters:**
- `force` (boolean, optional) - Skip validation checks (default: false)
- `delete_database` (boolean, optional) - Also drop tenant database (default: false)

**Request:**
```bash
DELETE /api/v1/schools/26?force=true&delete_database=true
Authorization: Bearer {token}
```

**Response:**
```json
{
  "message": "School deleted successfully",
  "deleted": {
    "school": true,
    "tenant_database": true
  }
}
```

**Error Response (Has Students/Teachers):**
```json
{
  "error": "Cannot delete school",
  "message": "School has associated data. Use force=true to delete anyway.",
  "data": {
    "students": 150,
    "teachers": 25
  }
}
```

---

## School Control Actions

### 1. Suspend School

**Endpoint:** `POST /api/v1/schools/{school_id}/suspend`

**Description:** Temporarily disable a school (blocks all access).

**Response:**
```json
{
  "message": "School suspended successfully",
  "school": {
    "id": 28,
    "name": "Demo School",
    "status": "suspended",
    "updated_at": "2026-01-18T19:25:25.000000Z"
  }
}
```

---

### 2. Activate School

**Endpoint:** `POST /api/v1/schools/{school_id}/activate`

**Description:** Re-enable a suspended school.

**Response:**
```json
{
  "message": "School activated successfully",
  "school": {
    "id": 28,
    "name": "Demo School",
    "status": "active"
  }
}
```

---

### 3. Send Email to School

**Endpoint:** `POST /api/v1/schools/{school_id}/send-email`

**Description:** Send email to school administrators or users.

**Request:**
```json
{
  "subject": "Important Announcement",
  "message": "Your school subscription will expire in 7 days.",
  "send_to": "admin"
}
```

**`send_to` Options:**
- `admin` - Send to school admin only
- `all_admins` - Send to all admins
- `all_users` - Send to all active users (max 100)

**Response:**
```json
{
  "message": "Email queued successfully",
  "recipients_count": 1,
  "recipients": [
    "admin@greenvalley.samschool.com"
  ]
}
```

---

### 4. Reset Admin Password

**Endpoint:** `POST /api/v1/schools/{school_id}/reset-admin-password`

**Description:** Reset school admin password remotely.

**Request (Optional):**
```json
{
  "password": "NewSecurePassword@2026"
}
```

**Response:**
```json
{
  "message": "Admin password reset successfully",
  "admin_email": "admin@greenvalley.samschool.com",
  "new_password": "Password@20260118",
  "note": "Please communicate this password securely to the school admin"
}
```

---

### 5. Get School Users Count

**Endpoint:** `GET /api/v1/schools/{school_id}/users-count`

**Description:** Get detailed user statistics for a school.

**Response:**
```json
{
  "users_count": 575,
  "breakdown": {
    "total": 575,
    "admins": 5,
    "teachers": 50,
    "students": 500,
    "parents": 20,
    "active": 570,
    "inactive": 5
  }
}
```

---

### 6. Get School Activity Logs

**Endpoint:** `GET /api/v1/schools/{school_id}/activity-logs`

**Description:** View activity logs for a school.

**Response:**
```json
{
  "logs": [
    {
      "id": 1,
      "action": "school_created",
      "description": "School was created",
      "user": "SuperAdmin",
      "timestamp": "2026-01-18T19:25:24.000000Z"
    },
    {
      "id": 2,
      "action": "school_suspended",
      "description": "School was suspended by superadmin",
      "user": "superadmin@compasse.net",
      "timestamp": "2026-01-18T20:00:00.000000Z"
    }
  ],
  "total": 2
}
```

---

### 7. Get School Statistics

**Endpoint:** `GET /api/v1/schools/{school_id}/stats`

**Description:** Get comprehensive school statistics.

**Headers Required:**
- `Authorization: Bearer {token}`
- `X-Subdomain: {subdomain}`

**Response:**
```json
{
  "stats": {
    "teachers": 50,
    "students": 500,
    "classes": 30,
    "subjects": 20,
    "departments": 5,
    "academic_years": 3,
    "terms": 9
  }
}
```

---

### 8. Get School Dashboard

**Endpoint:** `GET /api/v1/schools/{school_id}/dashboard`

**Description:** Get school dashboard with overview data.

**Headers Required:**
- `X-Subdomain: {subdomain}`

**Response:**
```json
{
  "dashboard": {
    "school": {
      "id": 1,
      "name": "Green Valley School",
      "status": "active"
    },
    "current_academic_year": "2025-2026",
    "current_term": "First Term",
    "stats": {
      "teachers": 50,
      "students": 500,
      "classes": 30
    },
    "recent_activities": [...],
    "upcoming_events": [...]
  }
}
```

---

### 9. Get School Organogram

**Endpoint:** `GET /api/v1/schools/{school_id}/organogram`

**Description:** Get school organizational structure.

**Headers Required:**
- `X-Subdomain: {subdomain}`

**Response:**
```json
{
  "organogram": {
    "principal": {
      "id": 1,
      "name": "Dr. John Smith",
      "email": "principal@greenvalley.edu"
    },
    "vice_principal": {
      "id": 2,
      "name": "Mrs. Jane Doe",
      "email": "vp@greenvalley.edu"
    },
    "departments": [
      {
        "id": 1,
        "name": "Science Department",
        "head": {
          "name": "Mr. Physics Teacher"
        }
      }
    ]
  }
}
```

---

## Tenant Management

### 1. List All Tenants

**Endpoint:** `GET /api/v1/tenants`

**Description:** Get all tenants (databases) in the system.

**Response:**
```json
{
  "tenants": {
    "current_page": 1,
    "data": [
      {
        "id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
        "name": "Green Valley School",
        "subdomain": "greenvalley",
        "database_name": "20251123194840_green-valley",
        "status": "active",
        "created_at": "2025-11-23T19:48:40.000000Z",
        "schools": [
          {
            "id": 23,
            "name": "Green Valley School",
            "status": "active"
          }
        ]
      }
    ],
    "total": 7
  }
}
```

---

### 2. Get Tenant Details

**Endpoint:** `GET /api/v1/tenants/{tenant_id}`

**Description:** Get detailed information about a specific tenant.

**Response:**
```json
{
  "id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
  "name": "Green Valley School",
  "subdomain": "greenvalley",
  "database_name": "20251123194840_green-valley",
  "database_host": "127.0.0.1",
  "database_port": "3306",
  "status": "active",
  "created_at": "2025-11-23T19:48:40.000000Z",
  "schools": [...]
}
```

---

### 3. Get Tenant Statistics

**Endpoint:** `GET /api/v1/tenants/{tenant_id}/stats`

**Description:** Get statistics for a specific tenant.

**Response:**
```json
{
  "tenant_id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
  "stats": {
    "schools": 1,
    "total_users": 575,
    "database_size": "45.2 MB",
    "created_at": "2025-11-23T19:48:40.000000Z"
  }
}
```

---

## Platform Monitoring

### 1. SuperAdmin Dashboard

**Endpoint:** `GET /api/v1/dashboard/super-admin`

**Description:** Get platform-wide overview and statistics.

**Response:**
```json
{
  "user": {
    "id": 2,
    "name": "Super Administrator",
    "email": "superadmin@compasse.net",
    "role": "super_admin"
  },
  "stats": {
    "total_tenants": 7,
    "active_tenants": 7,
    "total_schools": 5,
    "active_schools": 5,
    "total_users": 1250,
    "system_health": {
      "database": "healthy",
      "cache": "healthy",
      "queue": "healthy"
    }
  },
  "role": "super_admin"
}
```

---

### 2. Platform Analytics

**Endpoint:** `GET /api/v1/super-admin/analytics`

**Description:** Get detailed platform analytics.

**Response:**
```json
{
  "stats": {
    "total_tenants": 7,
    "active_tenants": 7,
    "total_schools": 5,
    "active_schools": 5,
    "system_health": {
      "database": "healthy",
      "cache": "healthy",
      "queue": "healthy"
    }
  }
}
```

---

### 3. Database Status

**Endpoint:** `GET /api/v1/super-admin/database`

**Description:** Get database connection information.

**Response:**
```json
{
  "status": "healthy",
  "connections": {
    "main": "sam_compasse",
    "tenants": 7
  }
}
```

---

### 4. Security Logs

**Endpoint:** `GET /api/v1/super-admin/security`

**Description:** Get security logs and failed login attempts.

**Response:**
```json
{
  "security_logs": [],
  "active_sessions": 15,
  "failed_logins": 0
}
```

---

## Authentication Management

### 1. Get Current User

**Endpoint:** `GET /api/v1/auth/me`

**Description:** Get current authenticated user details.

**Response:**
```json
{
  "user": {
    "id": 2,
    "tenant_id": null,
    "name": "Super Administrator",
    "email": "superadmin@compasse.net",
    "role": "super_admin",
    "status": "active",
    "last_login_at": "2026-01-18T19:19:47.000000Z"
  }
}
```

---

### 2. Refresh Token

**Endpoint:** `POST /api/v1/auth/refresh`

**Description:** Refresh authentication token.

**Response:**
```json
{
  "token": "177|HgJdqAFimKVaUUBbiGecgxgqgQKFJ96fdoBBIzqvc75cf1e9",
  "token_type": "Bearer"
}
```

---

### 3. Logout

**Endpoint:** `POST /api/v1/auth/logout`

**Description:** Logout and invalidate token.

**Response:**
```json
{
  "message": "Logged out successfully"
}
```

---

## Query Parameters & Filtering

### Available Query Parameters

| Parameter | Type | Description | Default |
|-----------|------|-------------|---------|
| `search` | string | Search in name, code, email | - |
| `status` | enum | Filter by status: `active`, `inactive`, `suspended` | all |
| `per_page` | integer | Results per page (1-100) | 15 |
| `page` | integer | Page number (1+) | 1 |

### Examples

#### Search Schools
```bash
GET /api/v1/schools?search=valley
```

#### Filter by Status
```bash
GET /api/v1/schools?status=active
```

#### Pagination
```bash
GET /api/v1/schools?per_page=20&page=2
```

#### Combined Filters
```bash
GET /api/v1/schools?search=international&status=active&per_page=10&page=1
```

---

## Error Responses

### 400 Bad Request
```json
{
  "error": "Validation failed",
  "messages": {
    "name": ["The name field is required."],
    "email": ["The email must be a valid email address."]
  }
}
```

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "error": "Unauthorized",
  "message": "Only superadmin can perform this action"
}
```

### 404 Not Found
```json
{
  "exists": false,
  "error": "School not found",
  "message": "No school found with subdomain: nonexistent"
}
```

### 422 Unprocessable Entity
```json
{
  "error": "Cannot delete school",
  "message": "School has associated students or teachers. Please remove them first.",
  "data": {
    "students": 150,
    "teachers": 25
  }
}
```

### 500 Internal Server Error
```json
{
  "error": "Failed to create school",
  "message": "Unable to create tenant database: Connection refused"
}
```

---

## Rate Limiting

### Current Limits
- **Public APIs:** 60 requests per minute per IP
- **Authenticated APIs:** 1000 requests per minute per user
- **SuperAdmin APIs:** Unlimited

### Rate Limit Headers
```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-RateLimit-Reset: 1642528800
```

### Rate Limit Exceeded Response
```json
{
  "error": "Too Many Requests",
  "message": "Rate limit exceeded. Please try again in 60 seconds.",
  "retry_after": 60
}
```

---

## Response Pagination Format

All paginated responses follow this structure:

```json
{
  "current_page": 1,
  "data": [...],
  "first_page_url": "https://api.compasse.net/api/v1/schools?page=1",
  "from": 1,
  "last_page": 5,
  "last_page_url": "https://api.compasse.net/api/v1/schools?page=5",
  "links": [
    {"url": null, "label": "&laquo; Previous", "active": false},
    {"url": "https://api.compasse.net/api/v1/schools?page=1", "label": "1", "active": true},
    {"url": "https://api.compasse.net/api/v1/schools?page=2", "label": "2", "active": false},
    {"url": "https://api.compasse.net/api/v1/schools?page=2", "label": "Next &raquo;", "active": false}
  ],
  "next_page_url": "https://api.compasse.net/api/v1/schools?page=2",
  "path": "https://api.compasse.net/api/v1/schools",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 75
}
```

---

## Testing

### Test Scripts Available

1. **`test-all-superadmin-apis.sh`** - Comprehensive test (33+ endpoints)
2. **`test-superadmin-complete.sh`** - Core features test (8 endpoints)
3. **`test-public-school-lookup.sh`** - Public APIs test (6 endpoints)
4. **`test-search-filtering.sh`** - Search & filtering test (9 tests)

### Run Tests
```bash
cd /path/to/samschool-backend
./test-all-superadmin-apis.sh
```

---

## Frontend Integration Examples

### JavaScript/React
```javascript
// Check if school exists
const checkSchool = async (subdomain) => {
  const response = await fetch(
    `https://api.compasse.net/api/v1/schools/by-subdomain/${subdomain}`
  );
  return await response.json();
};

// SuperAdmin: Create school
const createSchool = async (schoolData, token) => {
  const response = await fetch(
    'https://api.compasse.net/api/v1/schools',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify(schoolData)
    }
  );
  return await response.json();
};

// Search schools with filters
const searchSchools = async (params, token) => {
  const queryString = new URLSearchParams(params).toString();
  const response = await fetch(
    `https://api.compasse.net/api/v1/schools?${queryString}`,
    {
      headers: {
        'Authorization': `Bearer ${token}`
      }
    }
  );
  return await response.json();
};
```

---

## Summary

### Public APIs (No Auth)
- ✅ 5 endpoints
- ✅ School existence check
- ✅ Full school information
- ✅ Health monitoring

### SuperAdmin APIs (Auth Required)
- ✅ 30+ endpoints
- ✅ Complete school management
- ✅ School control (suspend/activate)
- ✅ Email communication
- ✅ Password management
- ✅ Tenant management
- ✅ Platform monitoring
- ✅ Statistics & analytics

### Features
- ✅ Full-text search
- ✅ Status filtering
- ✅ Pagination
- ✅ Error handling
- ✅ Rate limiting

---

## Support

For issues or questions:
- **Email:** support@compasse.net
- **Documentation:** https://docs.compasse.net
- **Status:** https://status.compasse.net

---

**Last Updated:** January 18, 2026  
**Version:** 1.0.0

