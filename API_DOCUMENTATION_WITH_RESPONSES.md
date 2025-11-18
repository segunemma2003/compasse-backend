# API Documentation with Response Examples

## Base URL
- **Local:** `http://localhost:8000/api/v1`
- **Production:** `https://api.compasse.net/api/v1`

## Authentication
All protected endpoints require a Bearer token in the Authorization header:
```
Authorization: Bearer {token}
```

For tenant-specific endpoints, include tenant context:
```
X-Tenant-ID: {tenant_id}
```

---

## 1. AUTHENTICATION & AUTHORIZATION

### POST /api/v1/auth/login
**Description:** User login

**Request Body:**
```json
{
  "email": "admin@school.com",
  "password": "Password@12345",
  "tenant_id": "optional-tenant-id"
}
```

**Success Response (200):**
```json
{
  "message": "Login successful",
  "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@school.com",
    "role": "school_admin",
    "school_id": 1
  }
}
```

**Error Response (401):**
```json
{
  "error": "Invalid credentials",
  "message": "The provided credentials are incorrect."
}
```

**Error Response (422):**
```json
{
  "error": "Validation failed",
  "messages": {
    "email": ["The email field is required."],
    "password": ["The password field is required."]
  }
}
```

---

### POST /api/v1/auth/register
**Description:** User registration

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "Password@12345",
  "password_confirmation": "Password@12345",
  "role": "student",
  "tenant_id": "tenant-id"
}
```

**Success Response (201):**
```json
{
  "message": "Registration successful",
  "user": {
    "id": 2,
    "name": "John Doe",
    "email": "john@example.com",
    "role": "student",
    "created_at": "2025-11-17T12:00:00.000000Z"
  },
  "token": "2|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
}
```

---

### GET /api/v1/auth/me
**Description:** Get current authenticated user

**Success Response (200):**
```json
{
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@school.com",
    "role": "school_admin",
    "school_id": 1,
    "created_at": "2025-11-17T10:00:00.000000Z"
  }
}
```

**Error Response (401):**
```json
{
  "error": "Unauthenticated",
  "message": "You must be authenticated to access this resource."
}
```

---

### POST /api/v1/auth/logout
**Description:** Logout user

**Success Response (200):**
```json
{
  "message": "Logged out successfully"
}
```

---

### POST /api/v1/auth/forgot-password
**Description:** Request password reset

**Request Body:**
```json
{
  "email": "user@example.com",
  "tenant_id": "optional-tenant-id"
}
```

**Success Response (200):**
```json
{
  "message": "Password reset token sent to email",
  "token": "reset-token-here" // Only in development
}
```

---

### POST /api/v1/auth/reset-password
**Description:** Reset password with token

**Request Body:**
```json
{
  "email": "user@example.com",
  "token": "reset-token",
  "password": "NewPassword@12345",
  "password_confirmation": "NewPassword@12345",
  "tenant_id": "optional-tenant-id"
}
```

**Success Response (200):**
```json
{
  "message": "Password reset successfully"
}
```

**Error Response (400):**
```json
{
  "error": "Invalid token",
  "message": "The password reset token is invalid or has expired."
}
```

---

## 2. TENANT MANAGEMENT (Super Admin Only)

### GET /api/v1/tenants
**Description:** List all tenants

**Success Response (200):**
```json
{
  "tenants": {
    "data": [
      {
        "id": "620636d5-bb26-4b86-b049-c0236c44126f",
        "name": "System Administration",
        "subdomain": "admin",
        "database_name": "20251117143037_sessions-test-school",
        "status": "active",
        "created_at": "2025-11-17T13:45:50.000000Z"
      }
    ],
    "current_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

---

### GET /api/v1/tenants/{id}
**Description:** Get tenant details

**Success Response (200):**
```json
{
  "tenant": {
    "id": "620636d5-bb26-4b86-b049-c0236c44126f",
    "name": "System Administration",
    "subdomain": "admin",
    "database_name": "20251117143037_sessions-test-school",
    "status": "active",
    "settings": {},
    "created_at": "2025-11-17T13:45:50.000000Z"
  }
}
```

---

### POST /api/v1/tenants
**Description:** Create new tenant

**Request Body:**
```json
{
  "name": "New School",
  "subdomain": "newschool",
  "status": "active"
}
```

**Success Response (201):**
```json
{
  "message": "Tenant created successfully",
  "tenant": {
    "id": "new-tenant-id",
    "name": "New School",
    "subdomain": "newschool",
    "status": "active"
  }
}
```

---

## 3. SCHOOL MANAGEMENT

### GET /api/v1/schools
**Description:** List all schools (tenant context required)

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Rolling Stone International School",
      "code": "RSIS001",
      "address": "123 School Street",
      "phone": "+1234567890",
      "email": "info@school.com",
      "logo": "https://example.com/logo.png",
      "status": "active",
      "created_at": "2025-11-17T14:30:00.000000Z"
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 1
}
```

---

### GET /api/v1/schools/{id}
**Description:** Get school details

**Success Response (200):**
```json
{
  "school": {
    "id": 1,
    "name": "Rolling Stone International School",
    "code": "RSIS001",
    "address": "123 School Street",
    "phone": "+1234567890",
    "email": "info@school.com",
    "website": "https://school.com",
    "logo": "https://example.com/logo.png",
    "status": "active",
    "principal_id": null,
    "vice_principal_id": null,
    "created_at": "2025-11-17T14:30:00.000000Z"
  },
  "stats": {
    "teachers": 0,
    "students": 0,
    "classes": 0
  }
}
```

---

### POST /api/v1/schools
**Description:** Create school (with logo upload support)

**Request Body (JSON):**
```json
{
  "name": "New School",
  "address": "123 School Street",
  "phone": "+1234567890",
  "email": "info@newschool.com",
  "website": "https://newschool.com",
  "logo": "https://example.com/logo.png",
  "tenant_id": "tenant-id"
}
```

**Request Body (Multipart - with logo file):**
```
name: New School
address: 123 School Street
phone: +1234567890
email: info@newschool.com
logo_file: [binary file]
```

**Success Response (201):**
```json
{
  "message": "School created successfully",
  "school": {
    "id": 2,
    "name": "New School",
    "address": "123 School Street",
    "phone": "+1234567890",
    "email": "info@newschool.com",
    "logo": "https://s3.amazonaws.com/bucket/schools/logos/filename.png",
    "status": "active",
    "created_at": "2025-11-17T15:00:00.000000Z"
  }
}
```

---

### PUT /api/v1/schools/{id}
**Description:** Update school (with logo upload support)

**Request Body:**
```json
{
  "name": "Updated School Name",
  "logo_file": "[binary file]" // Optional
}
```

**Success Response (200):**
```json
{
  "message": "School updated successfully",
  "school": {
    "id": 1,
    "name": "Updated School Name",
    "logo": "https://s3.amazonaws.com/bucket/schools/logos/new-filename.png",
    "updated_at": "2025-11-17T16:00:00.000000Z"
  }
}
```

---

### GET /api/v1/schools/{id}/stats
**Description:** Get school statistics

**Success Response (200):**
```json
{
  "stats": {
    "teachers": 25,
    "students": 500,
    "classes": 20,
    "subjects": 15,
    "departments": 5,
    "academic_years": 3,
    "terms": 9
  }
}
```

---

### GET /api/v1/schools/{id}/dashboard
**Description:** Get school dashboard data

**Success Response (200):**
```json
{
  "dashboard": {
    "overview": {
      "total_students": 500,
      "total_teachers": 25,
      "total_classes": 20,
      "attendance_rate": 95.5
    },
    "recent_activities": [],
    "upcoming_events": []
  }
}
```

---

## 4. USER MANAGEMENT

### GET /api/v1/users
**Description:** List users

**Query Parameters:**
- `role`: Filter by role (school_admin, teacher, student, parent)
- `status`: Filter by status (active, inactive, suspended)
- `search`: Search by name or email
- `per_page`: Items per page (default: 15)

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Admin User",
      "email": "admin@school.com",
      "role": "school_admin",
      "status": "active",
      "created_at": "2025-11-17T10:00:00.000000Z"
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 1
}
```

---

### POST /api/v1/users/{id}/activate
**Description:** Activate user

**Success Response (200):**
```json
{
  "message": "User activated successfully",
  "user": {
    "id": 1,
    "status": "active"
  }
}
```

---

### POST /api/v1/users/{id}/suspend
**Description:** Suspend user

**Success Response (200):**
```json
{
  "message": "User suspended successfully",
  "user": {
    "id": 1,
    "status": "suspended"
  }
}
```

---

## 5. STUDENT MANAGEMENT

### GET /api/v1/students
**Description:** List students

**Query Parameters:**
- `class_id`: Filter by class
- `arm_id`: Filter by arm
- `search`: Search by name or admission number
- `per_page`: Items per page

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "admission_number": "STU001",
      "first_name": "John",
      "last_name": "Doe",
      "email": "john.doe@school.com",
      "class_id": 1,
      "status": "active",
      "created_at": "2025-11-17T10:00:00.000000Z"
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 1
}
```

---

## 6. QUIZ SYSTEM

### GET /api/v1/quizzes
**Description:** List quizzes

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Math Quiz 1",
      "description": "Basic mathematics quiz",
      "duration_minutes": 30,
      "total_marks": 100,
      "status": "active",
      "created_at": "2025-11-17T10:00:00.000000Z"
    }
  ]
}
```

---

### POST /api/v1/quizzes
**Description:** Create quiz

**Request Body:**
```json
{
  "name": "Math Quiz 1",
  "description": "Basic mathematics quiz",
  "duration_minutes": 30,
  "total_marks": 100,
  "start_date": "2025-11-20 09:00:00",
  "end_date": "2025-11-20 10:00:00"
}
```

**Success Response (201):**
```json
{
  "message": "Quiz created successfully",
  "quiz": {
    "id": 1,
    "name": "Math Quiz 1",
    "description": "Basic mathematics quiz",
    "duration_minutes": 30,
    "total_marks": 100,
    "status": "draft",
    "created_at": "2025-11-17T10:00:00.000000Z"
  }
}
```

---

## 7. GRADES SYSTEM

### GET /api/v1/grades
**Description:** List grades

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "student_id": 1,
      "subject_id": 1,
      "class_id": 1,
      "term": "First Term",
      "academic_year": "2025/2026",
      "ca_score": 30,
      "exam_score": 70,
      "total_score": 100,
      "grade": "A",
      "created_at": "2025-11-17T10:00:00.000000Z"
    }
  ]
}
```

---

## 8. TIMETABLE

### GET /api/v1/timetable
**Description:** Get timetable

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "class_id": 1,
      "subject_id": 1,
      "teacher_id": 1,
      "day": "Monday",
      "start_time": "08:00:00",
      "end_time": "09:00:00",
      "room": "Room 101"
    }
  ]
}
```

---

## 9. ANNOUNCEMENTS

### GET /api/v1/announcements
**Description:** List announcements

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "title": "School Holiday",
      "content": "School will be closed on Friday",
      "type": "general",
      "status": "published",
      "published_at": "2025-11-17T10:00:00.000000Z",
      "created_at": "2025-11-17T09:00:00.000000Z"
    }
  ]
}
```

---

### POST /api/v1/announcements/{id}/publish
**Description:** Publish announcement

**Success Response (200):**
```json
{
  "message": "Announcement published successfully",
  "announcement": {
    "id": 1,
    "status": "published",
    "published_at": "2025-11-17T10:00:00.000000Z"
  }
}
```

---

## 10. LIBRARY

### GET /api/v1/library/books
**Description:** List books

**Success Response (200):**
```json
{
  "books": [
    {
      "id": 1,
      "title": "Introduction to Mathematics",
      "author": "John Smith",
      "isbn": "978-1234567890",
      "category": "Mathematics",
      "available_copies": 5,
      "total_copies": 10,
      "status": "available"
    }
  ]
}
```

---

## 11. DASHBOARDS

### GET /api/v1/dashboard/admin
**Description:** Admin dashboard

**Success Response (200):**
```json
{
  "dashboard": {
    "overview": {
      "total_students": 500,
      "total_teachers": 25,
      "total_classes": 20,
      "attendance_rate": 95.5
    },
    "recent_activities": [],
    "upcoming_events": []
  }
}
```

---

### GET /api/v1/dashboard/teacher
**Description:** Teacher dashboard

**Success Response (200):**
```json
{
  "dashboard": {
    "my_classes": 3,
    "my_students": 90,
    "pending_assignments": 5,
    "upcoming_classes": []
  }
}
```

---

## 12. SUPER ADMIN ANALYTICS

### GET /api/v1/super-admin/analytics
**Description:** Super admin analytics

**Success Response (200):**
```json
{
  "analytics": {
    "total_tenants": 10,
    "active_tenants": 8,
    "total_schools": 25,
    "total_users": 5000,
    "system_health": "healthy"
  }
}
```

---

### GET /api/v1/super-admin/database
**Description:** Database status

**Success Response (200):**
```json
{
  "status": "healthy",
  "connections": {
    "main": "compasse_main",
    "tenants": 10
  }
}
```

---

## ERROR RESPONSES

### 400 Bad Request
```json
{
  "error": "Bad Request",
  "message": "Invalid request parameters."
}
```

### 401 Unauthorized
```json
{
  "error": "Unauthenticated",
  "message": "You must be authenticated to access this resource."
}
```

### 403 Forbidden
```json
{
  "error": "Forbidden",
  "message": "Insufficient permissions. Required role: super_admin"
}
```

### 404 Not Found
```json
{
  "error": "Not Found",
  "message": "The requested resource was not found."
}
```

### 422 Validation Error
```json
{
  "error": "Validation failed",
  "messages": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

### 500 Internal Server Error
```json
{
  "error": "Internal Server Error",
  "message": "An unexpected error occurred. Please try again later."
}
```

---

## PAGINATION

Most list endpoints support pagination:

**Query Parameters:**
- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 15)

**Response Format:**
```json
{
  "data": [...],
  "current_page": 1,
  "per_page": 15,
  "total": 100,
  "last_page": 7,
  "from": 1,
  "to": 15
}
```

---

## FILTERING

Many endpoints support filtering via query parameters:

**Example:**
```
GET /api/v1/students?class_id=1&status=active&search=john
```

---

## SORTING

Some endpoints support sorting:

**Example:**
```
GET /api/v1/students?sort=name&order=asc
```

