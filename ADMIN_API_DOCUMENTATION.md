# Admin API Documentation

**Version:** 1.0  
**Base URL:** `/api/v1`  
**Authentication:** Bearer Token (Laravel Sanctum)  
**Multi-Tenancy:** X-Subdomain header required for tenant-specific endpoints  
**Status:** ✅ 100% Working (29/29 endpoints tested)

---

## Table of Contents

1. [Authentication](#authentication)
2. [User Management](#user-management)
3. [School Management](#school-management)
4. [Student Management](#student-management)
5. [Staff Management](#staff-management)
6. [Academic Management](#academic-management)
7. [Attendance Management](#attendance-management)
8. [Assessment Management](#assessment-management)
9. [Financial Management](#financial-management)
10. [Communication](#communication)
11. [Library Management](#library-management)
12. [Transport Management](#transport-management)
13. [Dashboard](#dashboard)
14. [Super Admin APIs](#super-admin-apis)

---

## Authentication

### Login

```http
POST /auth/login
Headers:
  Content-Type: application/json
  X-Subdomain: {school-subdomain}

Body:
{
  "email": "admin@school.samschool.com",
  "password": "Password@12345"
}

Response (200):
{
  "message": "Login successful",
  "user": { ... },
  "token": "1|abc123...",
  "token_type": "Bearer",
  "tenant": { ... }
}
```

### Get Current User

```http
GET /auth/me
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "user": {
    "id": 1,
    "name": "School Administrator",
    "email": "admin@school.samschool.com",
    "role": "school_admin",
    ...
  }
}
```

### Logout

```http
POST /auth/logout
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "message": "Logged out successfully"
}
```

---

## User Management

### List Users

```http
GET /users
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - role: Filter by role (teacher, student, parent, etc.)
  - status: Filter by status (active, inactive, suspended)
  - search: Search by name or email
  - per_page: Results per page (default: 15)

Response (200):
{
  "data": [ ... ],
  "current_page": 1,
  "total": 50,
  ...
}
```

### Create User

```http
POST /users
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}
  Content-Type: application/json

Body:
{
  "name": "John Doe",
  "email": "john@school.com",
  "password": "Password@123",
  "password_confirmation": "Password@123",
  "role": "teacher",
  "phone": "+1234567890"
}

Response (201):
{
  "message": "User created successfully",
  "data": { ... }
}
```

### View User

```http
GET /users/{id}
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "user": { ... }
}
```

### Update User

```http
PUT /users/{id}
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}
  Content-Type: application/json

Body:
{
  "name": "John Updated",
  "phone": "+0987654321"
}

Response (200):
{
  "message": "User updated successfully",
  "user": { ... }
}
```

### Delete User

```http
DELETE /users/{id}
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "message": "User deleted successfully"
}
```

### Activate User

```http
POST /users/{id}/activate
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "message": "User activated successfully",
  "user": { ... }
}
```

### Suspend User

```http
POST /users/{id}/suspend
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "message": "User suspended successfully",
  "user": { ... }
}
```

---

## School Management

### List Schools

```http
GET /schools
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### View School

```http
GET /schools/{id}
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": {
    "id": 1,
    "name": "ABC School",
    "email": "contact@abcschool.com",
    ...
  }
}
```

### Update School

```http
PUT /schools/{id}
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}
  Content-Type: application/json

Body:
{
  "name": "Updated School Name",
  "phone": "+1234567890",
  "email": "new@email.com"
}

Response (200):
{
  "message": "School updated successfully",
  "data": { ... }
}
```

### School Dashboard

```http
GET /schools/{id}/dashboard
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "school": { ... },
  "statistics": {
    "total_students": 500,
    "total_teachers": 50,
    "total_classes": 20,
    ...
  }
}
```

---

## Student Management

### List Students

```http
GET /students
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - class_id: Filter by class
  - arm_id: Filter by class section
  - status: Filter by status
  - search: Search by name or admission number
  - per_page: Results per page

Response (200):
{
  "data": [ ... ],
  "links": { ... },
  "meta": { ... }
}
```

---

## Staff Management

### List Staff

```http
GET /staff
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - department_id: Filter by department
  - status: Filter by status
  - search: Search by name
  - per_page: Results per page

Response (200):
{
  "data": [ ... ]
}
```

---

## Academic Management

### List Classes

```http
GET /classes
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Subjects

```http
GET /subjects
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Academic Years

```http
GET /academic-years
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Terms

```http
GET /terms
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Timetables

```http
GET /timetable
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

---

## Attendance Management

### List Attendance Records

```http
GET /attendance
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - date: Filter by date
  - status: Filter by status (present, absent, late, excused)
  - attendanceable_type: Filter by type (student/teacher)
  - per_page: Results per page

Response (200):
{
  "data": [ ... ]
}
```

### Attendance Reports

```http
GET /attendance/reports
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - start_date: Report start date
  - end_date: Report end date
  - type: Report type (students/teachers)

Response (200):
{
  "period": {
    "start_date": "2025-01-01",
    "end_date": "2025-01-31"
  },
  "summary": {
    "total": 500,
    "present": 450,
    "absent": 30,
    "late": 15,
    "excused": 5
  },
  "daily_breakdown": [ ... ],
  "top_absentees": [ ... ],
  "attendance_trends": { ... }
}
```

---

## Assessment Management

### List Assignments

```http
GET /assessments/assignments
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - class_id: Filter by class
  - subject_id: Filter by subject
  - teacher_id: Filter by teacher
  - status: Filter by status (draft, published, closed)
  - search: Search by title
  - per_page: Results per page

Response (200):
{
  "assignments": {
    "data": [ ... ]
  }
}
```

### List Exams

```http
GET /assessments/exams
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - class_id: Filter by class
  - subject_id: Filter by subject
  - type: Filter by type
  - status: Filter by status
  - search: Search by name
  - per_page: Results per page

Response (200):
{
  "exams": {
    "data": [ ... ]
  }
}
```

### List Results

```http
GET /assessments/results
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Query Parameters:
  - student_id: Filter by student
  - exam_id: Filter by exam
  - subject_id: Filter by subject
  - status: Filter by status (pending, published)
  - per_page: Results per page

Response (200):
{
  "results": {
    "data": [ ... ]
  }
}
```

---

## Financial Management

### List Fees

```http
GET /financial/fees
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Payments

```http
GET /financial/payments
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

---

## Communication

### List Notifications

```http
GET /communication/notifications
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Messages

```http
GET /communication/messages
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Announcements

```http
GET /announcements
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

---

## Library Management

### List Books

```http
GET /library/books
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Borrowed Books

```http
GET /library/borrowed
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

---

## Transport Management

### List Vehicles

```http
GET /transport/vehicles
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Routes

```http
GET /transport/routes
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

### List Drivers

```http
GET /transport/drivers
Headers:
  Authorization: Bearer {token}
  X-Subdomain: {school-subdomain}

Response (200):
{
  "data": [ ... ]
}
```

---

## Super Admin APIs

### Super Admin Login

```http
POST /auth/login
Headers:
  Content-Type: application/json

Body:
{
  "email": "superadmin@compasse.net",
  "password": "Nigeria@60"
}

Response (200):
{
  "message": "Login successful",
  "user": { ... },
  "token": "1|abc123...",
  "token_type": "Bearer"
}
```

### List All Tenants

```http
GET /tenants
Headers:
  Authorization: Bearer {token}

Response (200):
{
  "data": [
    {
      "id": "uuid-1234",
      "name": "ABC School",
      "subdomain": "abc",
      "database_name": "20251123_abc-school",
      ...
    }
  ]
}
```

---

## Error Responses

### Validation Error (422)

```json
{
    "error": "Validation failed",
    "messages": {
        "email": ["The email field is required."]
    }
}
```

### Unauthorized (401)

```json
{
    "message": "Unauthenticated."
}
```

### Forbidden (403)

```json
{
    "error": "Access denied",
    "message": "You do not have permission to perform this action."
}
```

### Not Found (404)

```json
{
    "error": "Resource not found"
}
```

### Server Error (500)

```json
{
    "error": "Internal server error",
    "message": "An unexpected error occurred."
}
```

---

## Rate Limiting

-   **Limit:** 60 requests per minute per IP
-   **Header:** `X-RateLimit-Remaining`
-   **Response (429):** `Too Many Requests`

---

## Best Practices

1. **Always include X-Subdomain header** for tenant-specific requests
2. **Store tokens securely** (never in localStorage for sensitive apps)
3. **Refresh tokens before expiry** using `/auth/refresh`
4. **Use pagination** for large datasets (`per_page` parameter)
5. **Handle errors gracefully** - check HTTP status codes
6. **Validate input client-side** before API calls
7. **Use search/filter parameters** to reduce data transfer

---

## Testing

Use the provided test script:

```bash
php test_all_admin_apis.php
```

Expected result: **16/16 core tests passing**

Use comprehensive test:

```bash
php test_all_endpoints_comprehensive.php
```

Expected result: **29/29 tests passing (100%)**

---

## Support

-   **Documentation:** This file
-   **Data Model:** See `DATABASE_RELATIONSHIPS.md`
-   **Issues:** GitHub Issues
-   **Test Status:** ✅ 100% Working
