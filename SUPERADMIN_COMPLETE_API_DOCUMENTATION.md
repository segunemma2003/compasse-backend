# SuperAdmin Complete API Documentation

## Table of Contents
1. [Overview](#overview)
2. [Authentication](#authentication)
3. [School Management](#school-management)
4. [School Control Actions](#school-control-actions)
5. [Tenant Management](#tenant-management)
6. [Dashboard & Analytics](#dashboard--analytics)
7. [Search & Filtering](#search--filtering)
8. [Best Practices](#best-practices)

---

## Overview

**Total APIs: 21**
**All APIs Tested: ✅ PASSING**

This documentation covers all APIs available to the **SuperAdmin** role. SuperAdmin operates on the **central database only** and manages all schools/tenants from a single control panel.

### Key Principles

1. **No Tenant Context**: SuperAdmin APIs operate on the central database and DO NOT require the `X-Subdomain` header
2. **No Tenancy Initialization**: SuperAdmin methods never initialize tenancy or switch to tenant databases
3. **Central Management**: All operations manage schools from the central database
4. **Full Control**: SuperAdmin has full access to all schools and tenant operations

### Base URL
```
http://localhost:8000/api/v1
```

### Required Headers
```http
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Note:** SuperAdmin APIs do NOT use the `X-Subdomain` header.

---

## Authentication

### 1. SuperAdmin Login
**POST** `/auth/login`

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
    "status": "active",
    "last_login_at": "2026-01-18T22:12:44.000000Z"
  },
  "token": "272|AB9OVLs69Mezqc7HcrbOnDVlia4hmcX474hTQY1oa01a09b0",
  "token_type": "Bearer"
}
```

**Notes:**
- SuperAdmin user has `role: "super_admin"`
- `tenant_id` is always `null` for SuperAdmin
- Use the returned `token` for all subsequent requests

---

## School Management

### 2. List All Schools
**GET** `/schools?page=1&per_page=10`

**Query Parameters:**
- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 10)
- `status`: Filter by status (active, suspended, inactive)
- `search`: Search by name, email, subdomain

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 23,
      "tenant_id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
      "name": "Test School",
      "code": "TEST_SCHOOL",
      "address": "123 Test Street",
      "phone": "+1234567890",
      "email": "info@testschool.com",
      "website": null,
      "logo": null,
      "status": "active",
      "created_at": "2025-11-23T19:48:53.000000Z",
      "updated_at": "2025-11-23T19:48:54.000000Z",
      "tenant": {
        "id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
        "name": "Test School School",
        "subdomain": "testschool",
        "database_name": "20251123194840_test-school",
        "status": "active",
        "created_at": "2025-11-23T19:48:40.000000Z"
      }
    }
  ],
  "first_page_url": "http://localhost:8000/api/v1/schools?page=1",
  "last_page": 2,
  "per_page": 10,
  "total": 12
}
```

### 3. Create School
**POST** `/schools`

**Request:**
```json
{
  "name": "New School Name",
  "subdomain": "newschool",
  "email": "admin@newschool.com",
  "phone": "+2348001234567",
  "address": "School Address",
  "admin_name": "School Admin",
  "admin_email": "admin@newschool.com",
  "admin_password": "password123"
}
```

**Response:**
```json
{
  "message": "School created successfully",
  "school": {
    "id": 89,
    "tenant_id": "uuid-here",
    "name": "New School Name",
    "code": "NEW_SCHOOL_NAME",
    "subdomain": "newschool",
    "status": "active",
    "created_at": "2026-01-18T22:00:00.000000Z"
  },
  "tenant": {
    "id": "uuid-here",
    "subdomain": "newschool",
    "database_name": "20260118220000_new-school"
  },
  "admin": {
    "name": "School Admin",
    "email": "admin@newschool.com",
    "password": "password123"
  }
}
```

**Process:**
1. Creates tenant in central database
2. Creates tenant database
3. Runs tenant migrations
4. Creates school record in tenant database
5. Creates admin user in tenant database
6. Seeds default data (academic years, terms, modules)

### 4. Get School Details
**GET** `/admin/schools/{school_id}`

**Response:**
```json
{
  "id": 23,
  "tenant_id": "uuid-here",
  "name": "School Name",
  "code": "SCHOOL_CODE",
  "email": "school@email.com",
  "phone": "+2348001234567",
  "address": "School Address",
  "status": "active",
  "created_at": "2025-11-23T19:48:53.000000Z",
  "updated_at": "2025-11-23T19:48:54.000000Z",
  "tenant": {
    "id": "uuid-here",
    "subdomain": "schoolsubdomain",
    "database_name": "tenant_database_name",
    "status": "active"
  }
}
```

### 5. Update School
**PUT** `/admin/schools/{school_id}`

**Request:**
```json
{
  "name": "Updated School Name",
  "phone": "+2349000000000",
  "email": "newemail@school.com",
  "status": "active"
}
```

**Response:**
```json
{
  "message": "School updated successfully",
  "school": {
    "id": 23,
    "name": "Updated School Name",
    "phone": "+2349000000000",
    "status": "active"
  }
}
```

### 6. Get School Stats
**GET** `/admin/schools/{school_id}/stats`

**Response:**
```json
{
  "stats": {
    "school_name": "School Name",
    "status": "active",
    "subdomain": "schoolsubdomain",
    "created_at": "2025-11-23T19:48:53.000000Z",
    "updated_at": "2026-01-18T10:00:00.000000Z",
    "tenant_status": "active",
    "database_name": "20251123194840_school-name"
  }
}
```

**Note:** Returns information from central database only.

### 7. Get School Dashboard
**GET** `/admin/schools/{school_id}/dashboard`

**Response:**
```json
{
  "school": {
    "id": 23,
    "name": "School Name",
    "code": "SCHOOL_CODE",
    "status": "active",
    "email": "school@email.com",
    "phone": "+2348001234567",
    "address": "School Address",
    "created_at": "2025-11-23T19:48:53.000000Z",
    "updated_at": "2026-01-18T10:00:00.000000Z"
  },
  "tenant": {
    "id": "uuid-here",
    "subdomain": "schoolsubdomain",
    "database_name": "20251123194840_school-name",
    "status": "active",
    "created_at": "2025-11-23T19:48:40.000000Z"
  }
}
```

### 8. Delete School
**DELETE** `/schools/{school_id}?force=true&delete_database=true`

**Query Parameters:**
- `force`: Set to `true` to force deletion (required)
- `delete_database`: Set to `true` to also drop tenant database (optional)

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

**Warning:** This is a destructive operation. It will:
1. Delete school record from central database
2. Delete tenant record from central database
3. Drop tenant database (if `delete_database=true`)

---

## School Control Actions

### 9. Suspend School
**POST** `/admin/schools/{school_id}/suspend`

**Request:**
```json
{
  "reason": "Non-payment of subscription"
}
```

**Response:**
```json
{
  "message": "School suspended successfully",
  "school": {
    "id": 23,
    "status": "suspended"
  }
}
```

**Effect:**
- Updates school status to "suspended" in central database
- Updates tenant status to "suspended"
- School users will not be able to access the system

### 10. Activate School
**POST** `/admin/schools/{school_id}/activate`

**Response:**
```json
{
  "message": "School activated successfully",
  "school": {
    "id": 23,
    "status": "active"
  }
}
```

**Effect:**
- Updates school status to "active"
- Updates tenant status to "active"
- School users can access the system again

### 11. Send Email to School
**POST** `/admin/schools/{school_id}/send-email`

**Request:**
```json
{
  "subject": "Important Announcement",
  "message": "This is an important message from SuperAdmin",
  "recipients": ["admin", "all"]
}
```

**Response:**
```json
{
  "message": "Email sent successfully",
  "sent_to": ["admin@school.com"],
  "failed": []
}
```

**Recipients Options:**
- `"admin"`: Send to school admin only
- `"all"`: Send to all users in the school
- `["email@example.com"]`: Send to specific email addresses

### 12. Get School Users Count
**GET** `/admin/schools/{school_id}/users-count`

**Response:**
```json
{
  "users_count": 150,
  "breakdown": {
    "total": 150,
    "admins": 2,
    "teachers": 20,
    "students": 120,
    "parents": 8,
    "active": 145,
    "inactive": 5
  }
}
```

**Note:** This switches to tenant database to count users, then switches back.

### 13. Get School Activity Logs
**GET** `/admin/schools/{school_id}/activity-logs`

**Response:**
```json
{
  "school_id": 23,
  "logs": [
    {
      "action": "school_created",
      "timestamp": "2025-11-23T19:48:53.000000Z",
      "details": "School was created"
    },
    {
      "action": "school_updated",
      "timestamp": "2026-01-18T10:00:00.000000Z",
      "details": "School was last updated"
    }
  ],
  "total": 2
}
```

---

## Tenant Management

### 14. List All Tenants
**GET** `/tenants`

**Response:**
```json
{
  "data": [
    {
      "id": "uuid-here",
      "name": "School Name Tenant",
      "subdomain": "schoolsubdomain",
      "database_name": "20251123194840_school-name",
      "status": "active",
      "created_at": "2025-11-23T19:48:40.000000Z",
      "updated_at": "2025-11-23T19:48:40.000000Z"
    }
  ]
}
```

### 15. Verify Tenant
**GET** `/tenants/verify?subdomain={subdomain}`

**Response:**
```json
{
  "tenant": {
    "id": "uuid-here",
    "subdomain": "schoolsubdomain",
    "status": "active"
  },
  "exists": true
}
```

---

## Dashboard & Analytics

### 16. Get SuperAdmin Analytics
**GET** `/super-admin/analytics`

**Response:**
```json
{
  "total_schools": 12,
  "active_schools": 10,
  "suspended_schools": 2,
  "total_users": 1500,
  "total_students": 1200,
  "total_teachers": 250,
  "revenue": {
    "this_month": 500000,
    "last_month": 480000,
    "growth": 4.17
  },
  "recent_signups": [
    {
      "school": "New School",
      "date": "2026-01-18"
    }
  ]
}
```

### 17. Get Database Status
**GET** `/super-admin/database`

**Response:**
```json
{
  "status": "healthy",
  "connections": {
    "main": "samschool_central",
    "tenants": 12
  }
}
```

### 18. Get Security Info
**GET** `/super-admin/security`

**Response:**
```json
{
  "security_logs": [],
  "active_sessions": 0,
  "failed_login_attempts": 0
}
```

### 19. Get Dashboard (Alternative)
**GET** `/dashboard/super-admin`

**Response:**
```json
{
  "total_schools": 12,
  "active_schools": 10,
  "suspended_schools": 2,
  "recent_activities": []
}
```

---

## Search & Filtering

### 20. Get School by Subdomain
**GET** `/schools/by-subdomain/{subdomain}`

**Response:**
```json
{
  "id": 23,
  "name": "School Name",
  "subdomain": "schoolsubdomain",
  "status": "active",
  "tenant": {
    "id": "uuid-here",
    "subdomain": "schoolsubdomain"
  }
}
```

### 21. Get School by Subdomain (Alternative)
**GET** `/schools/subdomain/{subdomain}`

**Response:**
```json
{
  "id": 23,
  "name": "School Name",
  "subdomain": "schoolsubdomain",
  "status": "active"
}
```

---

## Complete Workflow Example

### Setting Up a New School

```bash
# 1. Login as SuperAdmin
POST /auth/login
{
  "email": "superadmin@compasse.net",
  "password": "Nigeria@60"
}

# 2. Create New School
POST /schools
{
  "name": "Excellence Academy",
  "subdomain": "excellence",
  "email": "admin@excellence.edu",
  "phone": "+2348001234567",
  "address": "123 Education Street, Lagos",
  "admin_name": "John Admin",
  "admin_email": "john@excellence.edu",
  "admin_password": "SecurePass123"
}

# 3. Verify School Creation
GET /admin/schools/{school_id}

# 4. Check School Stats
GET /admin/schools/{school_id}/stats

# 5. Get Dashboard Overview
GET /admin/schools/{school_id}/dashboard

# 6. Verify Tenant
GET /tenants/verify?subdomain=excellence

# 7. Check User Count
GET /admin/schools/{school_id}/users-count

# 8. View Activity Logs
GET /admin/schools/{school_id}/activity-logs

# If needed - Suspend School
POST /admin/schools/{school_id}/suspend
{
  "reason": "Payment overdue"
}

# Reactivate School
POST /admin/schools/{school_id}/activate

# Send Email to School
POST /admin/schools/{school_id}/send-email
{
  "subject": "Welcome to the Platform",
  "message": "Your school has been successfully set up",
  "recipients": ["admin"]
}
```

---

## Best Practices

### 1. Authentication
- **Always use SuperAdmin credentials** for these APIs
- Store the token securely
- Token expires after 24 hours (or as configured)

### 2. School Management
- **Always verify tenant creation** after creating a school
- Use `force=true` and `delete_database=true` carefully when deleting
- Check school status before performing operations

### 3. Data Isolation
- SuperAdmin operates on **central database only**
- Never use `X-Subdomain` header for SuperAdmin APIs
- Each school has **completely isolated data** in their tenant database

### 4. Error Handling
- Check HTTP status codes
- Handle 404 for non-existent schools
- Handle 403 for unauthorized access
- Handle 500 for server errors

### 5. Performance
- Use pagination for list endpoints
- Cache frequently accessed data
- Use search/filter parameters to reduce data transfer

### 6. Security
- **Never share SuperAdmin credentials**
- Log all SuperAdmin actions
- Review activity logs regularly
- Monitor suspended schools

### 7. Tenant Management
- Always verify subdomain uniqueness before creating schools
- Check database names don't conflict
- Monitor disk space for tenant databases

---

## API Response Codes

### Success Responses
- `200 OK` - Request successful
- `201 Created` - Resource created successfully
- `204 No Content` - Deletion successful

### Client Error Responses
- `400 Bad Request` - Invalid request data
- `401 Unauthorized` - Not authenticated
- `403 Forbidden` - Not SuperAdmin or access denied
- `404 Not Found` - Resource not found
- `422 Unprocessable Entity` - Validation failed

### Server Error Responses
- `500 Internal Server Error` - Server error

---

## Error Response Format

```json
{
  "error": "Error title",
  "message": "Detailed error message",
  "messages": {
    "field_name": ["Validation error for this field"]
  }
}
```

---

## Testing Summary

| Category | APIs | Status |
|----------|------|--------|
| Authentication | 1 | ✅ PASSING |
| School Management | 7 | ✅ PASSING |
| School Control | 5 | ✅ PASSING |
| Tenant Management | 2 | ✅ PASSING |
| Dashboard & Analytics | 4 | ✅ PASSING |
| Search & Filtering | 2 | ✅ PASSING |
| **Total** | **21** | **✅ ALL PASSING** |

---

## Important Notes

1. **No Tenant Context**: SuperAdmin never works with tenant context
2. **Central Database Only**: All data comes from the central database
3. **No X-Subdomain Header**: Never use this header for SuperAdmin APIs
4. **Full Control**: SuperAdmin has unrestricted access to all schools
5. **Careful with Deletions**: School deletion is permanent and irreversible
6. **Monitor Activity**: Always track SuperAdmin actions for security
7. **Test in Staging**: Test all operations in staging before production

---

## Support & Documentation

For additional support or questions:
- **Technical Support:** tech@samschool.com
- **Security Issues:** security@samschool.com
- **Feature Requests:** features@samschool.com

---

**Document Version:** 1.0  
**Last Updated:** January 18, 2026  
**Total APIs Documented:** 21  
**Test Status:** ✅ All APIs Tested & Working  
**Maintainer:** SuperAdmin Team

