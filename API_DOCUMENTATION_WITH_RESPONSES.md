# API Documentation with Response Examples

## Base URL

-   **Local:** `http://localhost:8000/api/v1`
-   **Production:** `https://api.compasse.net/api/v1`

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

-   `role`: Filter by role (school_admin, teacher, student, parent)
-   `status`: Filter by status (active, inactive, suspended)
-   `search`: Search by name or email
-   `per_page`: Items per page (default: 15)

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

-   `class_id`: Filter by class
-   `arm_id`: Filter by arm
-   `search`: Search by name or admission number
-   `per_page`: Items per page

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

-   `page`: Page number (default: 1)
-   `per_page`: Items per page (default: 15)

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

---

## COMPLETE API ENDPOINT REFERENCE

This section provides a comprehensive list of all available API endpoints organized by category. For detailed request/response examples, refer to the sections above.

### Health Check

-   `GET /api/health` - Health check endpoint

### Authentication & Authorization

-   `POST /api/v1/auth/login` - User login
-   `POST /api/v1/auth/register` - User registration
-   `POST /api/v1/auth/logout` - Logout user
-   `POST /api/v1/auth/refresh` - Refresh access token
-   `POST /api/v1/auth/refresh-token` - Refresh access token (alias)
-   `GET /api/v1/auth/me` - Get current authenticated user
-   `POST /api/v1/auth/forgot-password` - Request password reset
-   `POST /api/v1/auth/reset-password` - Reset password with token

### Tenant Management (Super Admin Only)

-   `GET /api/v1/tenants` - List all tenants
-   `GET /api/v1/tenants/{id}` - Get tenant details
-   `POST /api/v1/tenants` - Create new tenant
-   `PUT /api/v1/tenants/{id}` - Update tenant
-   `DELETE /api/v1/tenants/{id}` - Delete tenant
-   `GET /api/v1/tenants/{tenant}/stats` - Get tenant statistics

### School Management

-   `GET /api/v1/schools` - List schools
-   `GET /api/v1/schools/{id}` - Get school details
-   `GET /api/v1/schools/subdomain/{subdomain}` - Get school by subdomain (public)
-   `POST /api/v1/schools` - Create school
-   `PUT /api/v1/schools/{id}` - Update school
-   `DELETE /api/v1/schools/{id}` - Delete school
-   `GET /api/v1/schools/{id}/stats` - Get school statistics
-   `GET /api/v1/schools/{id}/dashboard` - Get school dashboard
-   `GET /api/v1/schools/{id}/organogram` - Get school organogram

### User Management

-   `GET /api/v1/users` - List users
-   `GET /api/v1/users/{id}` - Get user details
-   `POST /api/v1/users` - Create user
-   `PUT /api/v1/users/{id}` - Update user
-   `DELETE /api/v1/users/{id}` - Delete user
-   `POST /api/v1/users/{id}/activate` - Activate user
-   `POST /api/v1/users/{id}/suspend` - Suspend user

### Academic Management

-   `GET /api/v1/academic-years` - List academic years
-   `GET /api/v1/academic-years/{id}` - Get academic year
-   `POST /api/v1/academic-years` - Create academic year
-   `PUT /api/v1/academic-years/{id}` - Update academic year
-   `DELETE /api/v1/academic-years/{id}` - Delete academic year
-   `GET /api/v1/terms` - List terms
-   `GET /api/v1/terms/{id}` - Get term
-   `POST /api/v1/terms` - Create term
-   `PUT /api/v1/terms/{id}` - Update term
-   `DELETE /api/v1/terms/{id}` - Delete term
-   `GET /api/v1/departments` - List departments
-   `GET /api/v1/departments/{id}` - Get department
-   `POST /api/v1/departments` - Create department
-   `PUT /api/v1/departments/{id}` - Update department
-   `DELETE /api/v1/departments/{id}` - Delete department
-   `GET /api/v1/classes` - List classes
-   `GET /api/v1/classes/{id}` - Get class
-   `POST /api/v1/classes` - Create class
-   `PUT /api/v1/classes/{id}` - Update class
-   `DELETE /api/v1/classes/{id}` - Delete class
-   `GET /api/v1/subjects` - List subjects
-   `GET /api/v1/subjects/{id}` - Get subject
-   `POST /api/v1/subjects` - Create subject
-   `PUT /api/v1/subjects/{id}` - Update subject
-   `DELETE /api/v1/subjects/{id}` - Delete subject

### Student Management

-   `GET /api/v1/students` - List students
-   `GET /api/v1/students/{id}` - Get student details
-   `POST /api/v1/students` - Create student
-   `PUT /api/v1/students/{id}` - Update student
-   `DELETE /api/v1/students/{id}` - Delete student
-   `GET /api/v1/students/{id}/attendance` - Get student attendance
-   `GET /api/v1/students/{id}/results` - Get student results
-   `GET /api/v1/students/{id}/assignments` - Get student assignments
-   `GET /api/v1/students/{id}/subjects` - Get student subjects
-   `POST /api/v1/students/generate-admission-number` - Generate admission number
-   `POST /api/v1/students/generate-credentials` - Generate student credentials

### Teacher Management

-   `GET /api/v1/teachers` - List teachers
-   `GET /api/v1/teachers/{id}` - Get teacher details
-   `POST /api/v1/teachers` - Create teacher
-   `PUT /api/v1/teachers/{id}` - Update teacher
-   `DELETE /api/v1/teachers/{id}` - Delete teacher
-   `GET /api/v1/teachers/{id}/classes` - Get teacher classes
-   `GET /api/v1/teachers/{id}/subjects` - Get teacher subjects
-   `GET /api/v1/teachers/{id}/students` - Get teacher students

### Guardian/Parent Management

-   `GET /api/v1/guardians` - List guardians
-   `GET /api/v1/guardians/{id}` - Get guardian details
-   `POST /api/v1/guardians` - Create guardian
-   `PUT /api/v1/guardians/{id}` - Update guardian
-   `DELETE /api/v1/guardians/{id}` - Delete guardian
-   `POST /api/v1/guardians/{id}/assign-student` - Assign student to guardian
-   `DELETE /api/v1/guardians/{id}/remove-student` - Remove student from guardian
-   `GET /api/v1/guardians/{id}/students` - Get guardian's students
-   `GET /api/v1/guardians/{id}/notifications` - Get guardian notifications
-   `GET /api/v1/guardians/{id}/messages` - Get guardian messages
-   `GET /api/v1/guardians/{id}/payments` - Get guardian payments

### Assessment Module (Exams, Assignments, Results, CBT)

-   `GET /api/v1/assessments/exams` - List exams
-   `GET /api/v1/assessments/exams/{id}` - Get exam details
-   `POST /api/v1/assessments/exams` - Create exam
-   `PUT /api/v1/assessments/exams/{id}` - Update exam
-   `DELETE /api/v1/assessments/exams/{id}` - Delete exam
-   `GET /api/v1/assessments/assignments` - List assignments
-   `GET /api/v1/assessments/assignments/{id}` - Get assignment details
-   `POST /api/v1/assessments/assignments` - Create assignment
-   `PUT /api/v1/assessments/assignments/{id}` - Update assignment
-   `DELETE /api/v1/assessments/assignments/{id}` - Delete assignment
-   `GET /api/v1/assessments/assignments/{id}/submissions` - Get assignment submissions
-   `POST /api/v1/assessments/assignments/{id}/submit` - Submit assignment
-   `PUT /api/v1/assessments/assignments/{id}/grade` - Grade assignment
-   `GET /api/v1/assessments/results` - List results
-   `GET /api/v1/assessments/results/{id}` - Get result details
-   `POST /api/v1/assessments/results` - Create result
-   `PUT /api/v1/assessments/results/{id}` - Update result
-   `DELETE /api/v1/assessments/results/{id}` - Delete result
-   `GET /api/v1/assessments/cbt/{exam}/questions` - Get CBT questions
-   `POST /api/v1/assessments/cbt/submit` - Submit CBT answers
-   `GET /api/v1/assessments/cbt/session/{sessionId}/status` - Get CBT session status
-   `GET /api/v1/assessments/cbt/session/{sessionId}/results` - Get CBT results
-   `POST /api/v1/assessments/cbt/{exam}/questions/create` - Create CBT questions
-   `GET /api/v1/assessments/cbt/attempts/{attempt}/status` - Get CBT attempt status

### Quiz System

-   `GET /api/v1/quizzes` - List quizzes
-   `GET /api/v1/quizzes/{id}` - Get quiz details
-   `POST /api/v1/quizzes` - Create quiz
-   `PUT /api/v1/quizzes/{id}` - Update quiz
-   `DELETE /api/v1/quizzes/{id}` - Delete quiz
-   `GET /api/v1/quizzes/{id}/questions` - Get quiz questions
-   `POST /api/v1/quizzes/{id}/questions` - Add question to quiz
-   `GET /api/v1/quizzes/{id}/attempts` - Get quiz attempts
-   `POST /api/v1/quizzes/{id}/attempt` - Start quiz attempt
-   `POST /api/v1/quizzes/{id}/submit` - Submit quiz answers
-   `GET /api/v1/quizzes/{id}/results` - Get quiz results

### Grades System

-   `GET /api/v1/grades` - List grades
-   `GET /api/v1/grades/{id}` - Get grade details
-   `POST /api/v1/grades` - Create/record grade
-   `PUT /api/v1/grades/{id}` - Update grade
-   `DELETE /api/v1/grades/{id}` - Delete grade
-   `GET /api/v1/grades/student/{student_id}` - Get student grades
-   `GET /api/v1/grades/class/{class_id}` - Get class grades

### Timetable Management

-   `GET /api/v1/timetable` - Get timetable
-   `GET /api/v1/timetable/{id}` - Get timetable entry
-   `POST /api/v1/timetable` - Create timetable entry
-   `PUT /api/v1/timetable/{id}` - Update timetable entry
-   `DELETE /api/v1/timetable/{id}` - Delete timetable entry
-   `GET /api/v1/timetable/class/{class_id}` - Get class timetable
-   `GET /api/v1/timetable/teacher/{teacher_id}` - Get teacher timetable

### Announcements

-   `GET /api/v1/announcements` - List announcements
-   `GET /api/v1/announcements/{id}` - Get announcement details
-   `POST /api/v1/announcements` - Create announcement
-   `PUT /api/v1/announcements/{id}` - Update announcement
-   `DELETE /api/v1/announcements/{id}` - Delete announcement
-   `POST /api/v1/announcements/{id}/publish` - Publish announcement

### Library Management

-   `GET /api/v1/library/books` - List books
-   `GET /api/v1/library/books/{id}` - Get book details
-   `POST /api/v1/library/books` - Add book
-   `PUT /api/v1/library/books/{id}` - Update book
-   `DELETE /api/v1/library/books/{id}` - Delete book
-   `GET /api/v1/library/borrowed` - List borrowed books
-   `POST /api/v1/library/borrow` - Borrow book
-   `POST /api/v1/library/return` - Return book
-   `GET /api/v1/library/digital-resources` - List digital resources
-   `POST /api/v1/library/digital-resources` - Add digital resource
-   `GET /api/v1/library/members` - List library members
-   `GET /api/v1/library/stats` - Get library statistics

### Houses System

-   `GET /api/v1/houses` - List houses
-   `GET /api/v1/houses/{id}` - Get house details
-   `POST /api/v1/houses` - Create house
-   `PUT /api/v1/houses/{id}` - Update house
-   `DELETE /api/v1/houses/{id}` - Delete house
-   `GET /api/v1/houses/{id}/members` - Get house members
-   `POST /api/v1/houses/{id}/points` - Add house points
-   `GET /api/v1/houses/{id}/points` - Get house points history
-   `GET /api/v1/houses/competitions` - List house competitions

### Sports Management

-   `GET /api/v1/sports/activities` - List sports activities
-   `POST /api/v1/sports/activities` - Create sports activity
-   `PUT /api/v1/sports/activities/{id}` - Update sports activity
-   `DELETE /api/v1/sports/activities/{id}` - Delete sports activity
-   `GET /api/v1/sports/teams` - List sports teams
-   `POST /api/v1/sports/teams` - Create team
-   `GET /api/v1/sports/events` - List sports events
-   `POST /api/v1/sports/events` - Create sports event

### Staff Management

-   `GET /api/v1/staff` - List staff
-   `GET /api/v1/staff/{id}` - Get staff details
-   `POST /api/v1/staff` - Create staff
-   `PUT /api/v1/staff/{id}` - Update staff
-   `DELETE /api/v1/staff/{id}` - Delete staff

### Achievements

-   `GET /api/v1/achievements` - List achievements
-   `GET /api/v1/achievements/{id}` - Get achievement details
-   `POST /api/v1/achievements` - Create achievement
-   `PUT /api/v1/achievements/{id}` - Update achievement
-   `DELETE /api/v1/achievements/{id}` - Delete achievement
-   `GET /api/v1/achievements/student/{student_id}` - Get student achievements

### Settings

-   `GET /api/v1/settings` - Get settings
-   `PUT /api/v1/settings` - Update settings
-   `GET /api/v1/settings/school` - Get school settings
-   `PUT /api/v1/settings/school` - Update school settings

### Dashboards

-   `GET /api/v1/dashboard/admin` - Admin dashboard
-   `GET /api/v1/dashboard/teacher` - Teacher dashboard
-   `GET /api/v1/dashboard/student` - Student dashboard
-   `GET /api/v1/dashboard/parent` - Parent dashboard
-   `GET /api/v1/dashboard/super-admin` - Super admin dashboard

### Attendance Management

-   `GET /api/v1/attendance` - List attendance records
-   `GET /api/v1/attendance/{id}` - Get attendance record
-   `POST /api/v1/attendance/mark` - Mark attendance
-   `PUT /api/v1/attendance/{id}` - Update attendance
-   `DELETE /api/v1/attendance/{id}` - Delete attendance
-   `GET /api/v1/attendance/class/{class_id}` - Get class attendance
-   `GET /api/v1/attendance/student/{student_id}` - Get student attendance
-   `GET /api/v1/attendance/students` - List student attendance
-   `GET /api/v1/attendance/teachers` - List teacher attendance
-   `GET /api/v1/attendance/reports` - Get attendance reports

### Financial Management (Fees, Payments, Expenses, Payroll)

-   `GET /api/v1/financial/fees` - List fees
-   `GET /api/v1/financial/fees/{id}` - Get fee details
-   `POST /api/v1/financial/fees` - Create fee
-   `PUT /api/v1/financial/fees/{id}` - Update fee
-   `DELETE /api/v1/financial/fees/{id}` - Delete fee
-   `POST /api/v1/financial/fees/{id}/pay` - Pay fee
-   `GET /api/v1/financial/fees/student/{student_id}` - Get student fees
-   `GET /api/v1/financial/fees/structure` - Get fee structure
-   `POST /api/v1/financial/fees/structure` - Create fee structure
-   `PUT /api/v1/financial/fees/structure/{id}` - Update fee structure
-   `GET /api/v1/financial/payments` - List payments
-   `GET /api/v1/financial/payments/{id}` - Get payment details
-   `POST /api/v1/financial/payments` - Create payment
-   `PUT /api/v1/financial/payments/{id}` - Update payment
-   `DELETE /api/v1/financial/payments/{id}` - Delete payment
-   `GET /api/v1/financial/payments/student/{student_id}` - Get student payments
-   `GET /api/v1/financial/payments/receipt/{id}` - Get payment receipt
-   `GET /api/v1/financial/expenses` - List expenses
-   `GET /api/v1/financial/expenses/{id}` - Get expense details
-   `POST /api/v1/financial/expenses` - Create expense
-   `PUT /api/v1/financial/expenses/{id}` - Update expense
-   `DELETE /api/v1/financial/expenses/{id}` - Delete expense
-   `GET /api/v1/financial/payroll` - List payroll records
-   `GET /api/v1/financial/payroll/{id}` - Get payroll details
-   `POST /api/v1/financial/payroll` - Create payroll record
-   `PUT /api/v1/financial/payroll/{id}` - Update payroll record
-   `DELETE /api/v1/financial/payroll/{id}` - Delete payroll record

### Communication Module (Messages, Notifications, SMS, Email)

-   `GET /api/v1/communication/messages` - List messages
-   `GET /api/v1/communication/messages/{id}` - Get message details
-   `POST /api/v1/communication/messages` - Send message
-   `PUT /api/v1/communication/messages/{id}` - Update message
-   `DELETE /api/v1/communication/messages/{id}` - Delete message
-   `PUT /api/v1/communication/messages/{id}/read` - Mark message as read
-   `GET /api/v1/communication/notifications` - List notifications
-   `GET /api/v1/communication/notifications/{id}` - Get notification details
-   `POST /api/v1/communication/notifications` - Create notification
-   `PUT /api/v1/communication/notifications/{id}` - Update notification
-   `DELETE /api/v1/communication/notifications/{id}` - Delete notification
-   `PUT /api/v1/communication/notifications/{id}/read` - Mark notification as read
-   `PUT /api/v1/communication/notifications/read-all` - Mark all notifications as read
-   `POST /api/v1/communication/sms/send` - Send SMS
-   `POST /api/v1/communication/email/send` - Send email

### Livestream Module

-   `GET /api/v1/livestreams/livestreams` - List livestreams
-   `GET /api/v1/livestreams/livestreams/{id}` - Get livestream details
-   `POST /api/v1/livestreams/livestreams` - Create livestream
-   `PUT /api/v1/livestreams/livestreams/{id}` - Update livestream
-   `DELETE /api/v1/livestreams/livestreams/{id}` - Delete livestream
-   `POST /api/v1/livestreams/{id}/join` - Join livestream
-   `POST /api/v1/livestreams/{id}/leave` - Leave livestream
-   `GET /api/v1/livestreams/{id}/attendance` - Get livestream attendance
-   `POST /api/v1/livestreams/{id}/start` - Start livestream
-   `POST /api/v1/livestreams/{id}/end` - End livestream

### Transport Management

-   `GET /api/v1/transport/routes` - List transport routes
-   `GET /api/v1/transport/routes/{id}` - Get route details
-   `POST /api/v1/transport/routes` - Create route
-   `PUT /api/v1/transport/routes/{id}` - Update route
-   `DELETE /api/v1/transport/routes/{id}` - Delete route
-   `GET /api/v1/transport/vehicles` - List vehicles
-   `GET /api/v1/transport/vehicles/{id}` - Get vehicle details
-   `POST /api/v1/transport/vehicles` - Add vehicle
-   `PUT /api/v1/transport/vehicles/{id}` - Update vehicle
-   `DELETE /api/v1/transport/vehicles/{id}` - Delete vehicle
-   `GET /api/v1/transport/drivers` - List drivers
-   `GET /api/v1/transport/drivers/{id}` - Get driver details
-   `POST /api/v1/transport/drivers` - Create driver
-   `PUT /api/v1/transport/drivers/{id}` - Update driver
-   `DELETE /api/v1/transport/drivers/{id}` - Delete driver
-   `GET /api/v1/transport/students` - List students using transport
-   `POST /api/v1/transport/assign` - Assign student to route
-   `GET /api/v1/transport/pickup/secure` - Get secure pickup information

### Hostel Management

-   `GET /api/v1/hostel/rooms` - List hostel rooms
-   `GET /api/v1/hostel/rooms/{id}` - Get room details
-   `POST /api/v1/hostel/rooms` - Create room
-   `PUT /api/v1/hostel/rooms/{id}` - Update room
-   `DELETE /api/v1/hostel/rooms/{id}` - Delete room
-   `GET /api/v1/hostel/allocations` - List hostel allocations
-   `GET /api/v1/hostel/allocations/{id}` - Get allocation details
-   `POST /api/v1/hostel/allocations` - Create allocation
-   `PUT /api/v1/hostel/allocations/{id}` - Update allocation
-   `DELETE /api/v1/hostel/allocations/{id}` - Delete allocation
-   `GET /api/v1/hostel/maintenance` - List maintenance records
-   `GET /api/v1/hostel/maintenance/{id}` - Get maintenance details
-   `POST /api/v1/hostel/maintenance` - Create maintenance record
-   `PUT /api/v1/hostel/maintenance/{id}` - Update maintenance record
-   `DELETE /api/v1/hostel/maintenance/{id}` - Delete maintenance record

### Health Management

-   `GET /api/v1/health/records` - List health records
-   `GET /api/v1/health/records/{id}` - Get health record details
-   `POST /api/v1/health/records` - Create health record
-   `PUT /api/v1/health/records/{id}` - Update health record
-   `DELETE /api/v1/health/records/{id}` - Delete health record
-   `GET /api/v1/health/appointments` - List health appointments
-   `GET /api/v1/health/appointments/{id}` - Get appointment details
-   `POST /api/v1/health/appointments` - Create appointment
-   `PUT /api/v1/health/appointments/{id}` - Update appointment
-   `DELETE /api/v1/health/appointments/{id}` - Delete appointment
-   `GET /api/v1/health/medications` - List medications
-   `GET /api/v1/health/medications/{id}` - Get medication details
-   `POST /api/v1/health/medications` - Create medication record
-   `PUT /api/v1/health/medications/{id}` - Update medication record
-   `DELETE /api/v1/health/medications/{id}` - Delete medication record

### Inventory Management

-   `GET /api/v1/inventory/items` - List inventory items
-   `GET /api/v1/inventory/items/{id}` - Get item details
-   `POST /api/v1/inventory/items` - Add inventory item
-   `PUT /api/v1/inventory/items/{id}` - Update inventory item
-   `DELETE /api/v1/inventory/items/{id}` - Delete inventory item
-   `GET /api/v1/inventory/categories` - List categories
-   `GET /api/v1/inventory/categories/{id}` - Get category details
-   `POST /api/v1/inventory/categories` - Create category
-   `PUT /api/v1/inventory/categories/{id}` - Update category
-   `DELETE /api/v1/inventory/categories/{id}` - Delete category
-   `GET /api/v1/inventory/transactions` - List transactions
-   `GET /api/v1/inventory/transactions/{id}` - Get transaction details
-   `POST /api/v1/inventory/transactions` - Create transaction
-   `PUT /api/v1/inventory/transactions/{id}` - Update transaction
-   `DELETE /api/v1/inventory/transactions/{id}` - Delete transaction
-   `POST /api/v1/inventory/checkout` - Checkout item
-   `POST /api/v1/inventory/return` - Return item

### Event Management

-   `GET /api/v1/events/events` - List events
-   `GET /api/v1/events/events/{id}` - Get event details
-   `POST /api/v1/events/events` - Create event
-   `PUT /api/v1/events/events/{id}` - Update event
-   `DELETE /api/v1/events/events/{id}` - Delete event
-   `GET /api/v1/events/upcoming` - Get upcoming events
-   `GET /api/v1/events/calendars` - List calendars
-   `GET /api/v1/events/calendars/{id}` - Get calendar details
-   `POST /api/v1/events/calendars` - Create calendar
-   `PUT /api/v1/events/calendars/{id}` - Update calendar
-   `DELETE /api/v1/events/calendars/{id}` - Delete calendar

### Reports

-   `GET /api/v1/reports/academic` - Get academic report
-   `GET /api/v1/reports/financial` - Get financial report
-   `GET /api/v1/reports/attendance` - Get attendance report
-   `GET /api/v1/reports/performance` - Get performance report
-   `GET /api/v1/reports/{type}/export` - Export report (PDF/Excel/CSV)

### Results Generation

-   `POST /api/v1/results/mid-term/generate` - Generate mid-term results
-   `POST /api/v1/results/end-term/generate` - Generate end-of-term results
-   `POST /api/v1/results/annual/generate` - Generate annual results
-   `GET /api/v1/results/student/{studentId}` - Get student results
-   `GET /api/v1/results/class/{classId}` - Get class results
-   `POST /api/v1/results/publish` - Publish results
-   `POST /api/v1/results/unpublish` - Unpublish results

### Bulk Operations

-   `POST /api/v1/bulk/students/register` - Bulk register students
-   `POST /api/v1/bulk/teachers/register` - Bulk register teachers
-   `POST /api/v1/bulk/classes/create` - Bulk create classes
-   `POST /api/v1/bulk/subjects/create` - Bulk create subjects
-   `POST /api/v1/bulk/exams/create` - Bulk create exams
-   `POST /api/v1/bulk/assignments/create` - Bulk create assignments
-   `POST /api/v1/bulk/fees/create` - Bulk create fees
-   `POST /api/v1/bulk/attendance/mark` - Bulk mark attendance
-   `POST /api/v1/bulk/results/update` - Bulk update results
-   `POST /api/v1/bulk/notifications/send` - Bulk send notifications
-   `POST /api/v1/bulk/import/csv` - Bulk import from CSV
-   `GET /api/v1/bulk/operations/{operationId}/status` - Get bulk operation status
-   `DELETE /api/v1/bulk/operations/{operationId}/cancel` - Cancel bulk operation

### Subscriptions & Billing

-   `GET /api/v1/subscriptions` - List subscriptions
-   `GET /api/v1/subscriptions/{id}` - Get subscription details
-   `GET /api/v1/subscriptions/plans` - List subscription plans
-   `GET /api/v1/subscriptions/modules` - List available modules
-   `GET /api/v1/subscriptions/status` - Get subscription status
-   `POST /api/v1/subscriptions/create` - Create subscription
-   `PUT /api/v1/subscriptions/{id}/upgrade` - Upgrade subscription
-   `POST /api/v1/subscriptions/{id}/renew` - Renew subscription
-   `DELETE /api/v1/subscriptions/{id}/cancel` - Cancel subscription
-   `GET /api/v1/subscriptions/modules/{module}/access` - Check module access
-   `GET /api/v1/subscriptions/features/{feature}/access` - Check feature access
-   `GET /api/v1/subscriptions/school/modules` - Get school modules
-   `GET /api/v1/subscriptions/school/limits` - Get school limits

### File Upload

-   `GET /api/v1/uploads/presigned-urls` - Get presigned URLs for upload
-   `POST /api/v1/uploads/upload` - Upload single file
-   `POST /api/v1/uploads/upload/multiple` - Upload multiple files
-   `DELETE /api/v1/uploads/{key}` - Delete file

### Super Admin Analytics

-   `GET /api/v1/super-admin/analytics` - System analytics
-   `GET /api/v1/super-admin/database` - Database status
-   `GET /api/v1/super-admin/security` - Security logs

---

## NOTES

1. **Tenant Context**: Most endpoints require tenant context. Include `X-Tenant-ID` header or `tenant_id` in request body/query parameters.

2. **Module Access**: Some endpoints require specific module subscriptions. Check subscription status before accessing module-specific endpoints.

3. **Pagination**: List endpoints support pagination with `page` and `per_page` query parameters.

4. **Filtering**: Many endpoints support filtering via query parameters (e.g., `?status=active&search=keyword`).

5. **Error Handling**: All endpoints return consistent error responses. Refer to the "ERROR RESPONSES" section above.

6. **Authentication**: All protected endpoints require a valid Bearer token in the `Authorization` header.

7. **Rate Limiting**: API requests may be rate-limited. Check response headers for rate limit information.

---

**Total Endpoints: 374+**

For detailed request/response examples for specific endpoints, refer to the detailed sections above or contact the API support team.
