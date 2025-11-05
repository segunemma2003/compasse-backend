# SamSchool Management System - Complete API Documentation

## Overview

This document provides comprehensive API documentation for the SamSchool Management System - a multi-tenant, multi-database microservices architecture for managing multiple schools.

### Base URL

```
Production: https://api.samschool.com
Development: http://localhost:8078
```

### Authentication

Most endpoints require authentication using Bearer tokens. Include the token in the Authorization header:

```
Authorization: Bearer {your_token_here}
```

### Response Format

All API responses are in JSON format. Successful responses typically return:

-   `200 OK` - Successful GET, PUT, PATCH requests
-   `201 Created` - Successful POST requests
-   `204 No Content` - Successful DELETE requests

Error responses include:

-   `400 Bad Request` - Invalid request format
-   `401 Unauthorized` - Missing or invalid authentication
-   `403 Forbidden` - Insufficient permissions
-   `404 Not Found` - Resource not found
-   `422 Unprocessable Entity` - Validation errors
-   `500 Internal Server Error` - Server error

---

## Table of Contents

1. [Health Check](#health-check)
2. [Public APIs](#public-apis)
3. [Authentication APIs](#authentication-apis)
4. [Multi-tenant APIs](#multi-tenant-apis)
5. [School Management APIs](#school-management-apis)
6. [Subscription Management APIs](#subscription-management-apis)
7. [File Upload APIs](#file-upload-apis)
8. [Student Management APIs](#student-management-apis)
9. [Teacher Management APIs](#teacher-management-apis)
10. [Academic Management APIs](#academic-management-apis)
11. [Assessment APIs](#assessment-apis)
12. [Guardian Management APIs](#guardian-management-apis)
13. [Livestream APIs](#livestream-apis)
14. [Communication APIs](#communication-apis)
15. [Financial Management APIs](#financial-management-apis)
16. [Administrative APIs](#administrative-apis)
17. [Bulk Operations APIs](#bulk-operations-apis)
18. [Reports APIs](#reports-apis)

---

## Health Check

### Get System Health

**GET** `/api/health`

Check if the API server is running and healthy.

**Response (200 OK):**

```json
{
    "status": "ok",
    "timestamp": "2025-01-26T10:00:00Z",
    "version": "1.0.0"
}
```

---

## Public APIs

### Get School by Subdomain

**GET** `/api/v1/schools/subdomain/{subdomain}`

Retrieve school information by subdomain. This is a public endpoint that doesn't require authentication.

**Path Parameters:**

-   `subdomain` (required): The subdomain of the school (e.g., "tester", "abchighschool")

**Response (200 OK):**

```json
{
    "success": true,
    "subdomain": "tester",
    "tenant": {
        "id": 1,
        "name": "Tester School District",
        "subdomain": "tester",
        "domain": "tester.samschool.com",
        "status": "active"
    },
    "school": {
        "id": 1,
        "name": "Tester High School",
        "address": "123 Main St",
        "phone": "+1234567890",
        "email": "info@tester.com",
        "website": "https://tester.samschool.com",
        "logo": "https://s3.amazonaws.com/...",
        "status": "active",
        "academic_year": "2024/2025",
        "term": "First Term",
        "settings": {},
        "created_at": "2025-01-26T10:00:00Z",
        "updated_at": "2025-01-26T10:00:00Z"
    },
    "stats": {
        "students": 150,
        "teachers": 25,
        "classes": 12
    }
}
```

**Response (404 Not Found):**

```json
{
    "error": "School not found",
    "message": "No active school found with subdomain: invalid-subdomain"
}
```

---

## Authentication APIs

### Register User

**POST** `/api/v1/auth/register`

Register a new user in the system.

**Request Body:**

```json
{
    "name": "John Doe",
    "email": "john.doe@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "phone": "+1234567890",
    "role": "student|teacher|parent|guardian|admin|staff|hod|year_tutor|class_teacher|subject_teacher|principal|vice_principal|accountant|librarian|driver|security|cleaner|caterer|nurse|super_admin|school_admin",
    "tenant_id": 1,
    "school_id": 1
}
```

**Response (201 Created):**

```json
{
    "message": "Registration successful",
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john.doe@example.com",
        "role": "student",
        "status": "active",
        "tenant": {
            "id": 1,
            "name": "Test School District",
            "domain": "test-school.samschool.com"
        }
    },
    "token": "1|abc123...",
    "token_type": "Bearer"
}
```

### Login User

**POST** `/api/v1/auth/login`

Authenticate user and get access token.

**Request Body:**

```json
{
    "email": "john.doe@example.com",
    "password": "password123"
}
```

**Response (200 OK):**

```json
{
    "message": "Login successful",
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john.doe@example.com",
        "role": "student",
        "tenant": {
            "id": 1,
            "name": "Test School District"
        }
    },
    "token": "1|abc123...",
    "token_type": "Bearer"
}
```

### Get Current User

**GET** `/api/v1/auth/me`

Get current authenticated user information.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "id": 1,
    "name": "John Doe",
    "email": "john.doe@example.com",
    "role": "student",
    "tenant": {
        "id": 1,
        "name": "Test School District"
    }
}
```

### Logout User

**POST** `/api/v1/auth/logout`

Logout current user and revoke token.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "message": "Logout successful"
}
```

---

## School Management APIs

### Get School Details

**GET** `/api/v1/schools/{id}`

Get detailed information about a school.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "id": 1,
    "name": "Tester High School",
    "address": "123 Main St",
    "phone": "+1234567890",
    "email": "info@tester.com",
    "website": "https://tester.samschool.com",
    "logo": "https://s3.amazonaws.com/...",
    "status": "active",
    "academic_year": "2024/2025",
    "term": "First Term",
    "settings": {},
    "created_at": "2025-01-26T10:00:00Z"
}
```

### Update School

**PUT/PATCH** `/api/v1/schools/{id}`

Update school information. Requires school admin or higher permissions.

**Headers:**

```
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "name": "Updated School Name",
    "address": "456 New St",
    "phone": "+9876543210",
    "email": "info@updated.com",
    "website": "https://updated.samschool.com"
}
```

**Response (200 OK):**

```json
{
    "message": "School updated successfully",
    "school": {
        "id": 1,
        "name": "Updated School Name",
        "address": "456 New St",
        "phone": "+9876543210",
        "email": "info@updated.com"
    }
}
```

### Get School Statistics

**GET** `/api/v1/schools/{id}/stats`

Get comprehensive statistics for a school.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "stats": {
        "students": {
            "total": 150,
            "active": 145,
            "inactive": 5
        },
        "teachers": {
            "total": 25,
            "active": 24,
            "inactive": 1
        },
        "classes": 12,
        "subjects": 45,
        "departments": 6,
        "revenue": {
            "current_month": 500000,
            "total": 5000000
        }
    }
}
```

### Get School Dashboard

**GET** `/api/v1/schools/{id}/dashboard`

Get dashboard data for a school including recent activities, upcoming events, and key metrics.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "school": {
        "id": 1,
        "name": "Tester High School"
    },
    "stats": {
        "total_students": 150,
        "total_teachers": 25,
        "total_classes": 12,
        "attendance_rate": 95.5
    },
    "recent_activities": [
        {
            "id": 1,
            "type": "student_registration",
            "description": "New student registered: John Doe",
            "timestamp": "2025-01-26T08:00:00Z",
            "user": "Admin User"
        }
    ],
    "upcoming_events": [
        {
            "id": 1,
            "title": "Parent-Teacher Meeting",
            "date": "2025-02-01T10:00:00Z",
            "type": "meeting"
        }
    ]
}
```

### Get School Organogram

**GET** `/api/v1/schools/{id}/organogram`

Get the organizational structure of the school including roles and hierarchy.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "organogram": {
        "principal": {
            "id": 1,
            "name": "Dr. Jane Smith",
            "role": "principal"
        },
        "vice_principal": {
            "id": 2,
            "name": "Mr. John Doe",
            "role": "vice_principal"
        },
        "departments": [
            {
                "id": 1,
                "name": "Science Department",
                "hod": {
                    "id": 3,
                    "name": "Dr. Sarah Johnson"
                },
                "teachers": [...]
            }
        ],
        "year_tutors": [...],
        "class_teachers": [...]
    }
}
```

---

## User Management APIs

### Get All Users

**GET** `/api/v1/users`

Get paginated list of users.

**Headers:**

```
Authorization: Bearer {token}
```

**Query Parameters:**

-   `page` (optional): Page number (default: 1)
-   `per_page` (optional): Items per page (default: 15)
-   `role` (optional): Filter by role
-   `status` (optional): Filter by status (active, inactive, suspended)
-   `search` (optional): Search by name or email

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "name": "John Doe",
            "email": "john.doe@example.com",
            "role": "student",
            "status": "active",
            "created_at": "2025-01-21T10:00:00Z"
        }
    ],
    "links": {
        "first": "http://localhost:8000/api/v1/users?page=1",
        "last": "http://localhost:8000/api/v1/users?page=10",
        "prev": null,
        "next": "http://localhost:8000/api/v1/users?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 10,
        "per_page": 15,
        "to": 15,
        "total": 150
    }
}
```

### Get User by ID

**GET** `/api/v1/users/{id}`

Get specific user by ID.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "id": 1,
    "name": "John Doe",
    "email": "john.doe@example.com",
    "role": "student",
    "status": "active",
    "phone": "+1234567890",
    "profile_picture": "https://example.com/avatar.jpg",
    "last_login_at": "2025-01-21T10:00:00Z",
    "created_at": "2025-01-21T10:00:00Z",
    "updated_at": "2025-01-21T10:00:00Z"
}
```

### Update User

**PUT** `/api/v1/users/{id}`

Update user information.

**Headers:**

```
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "name": "John Smith",
    "phone": "+1234567890",
    "status": "active"
}
```

**Response (200 OK):**

```json
{
    "message": "User updated successfully",
    "user": {
        "id": 1,
        "name": "John Smith",
        "email": "john.doe@example.com",
        "phone": "+1234567890",
        "status": "active"
    }
}
```

### Delete User

**DELETE** `/api/v1/users/{id}`

Delete user (soft delete).

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "message": "User deleted successfully"
}
```

---

## Student Management APIs

### Get All Students

**GET** `/api/v1/students`

Get paginated list of students.

**Headers:**

```
Authorization: Bearer {token}
```

**Query Parameters:**

-   `page` (optional): Page number
-   `per_page` (optional): Items per page
-   `class_id` (optional): Filter by class
-   `arm_id` (optional): Filter by arm
-   `search` (optional): Search by name or admission number

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "admission_number": "SS2025001",
            "name": "John Doe",
            "email": "john.doe@schoolname.com",
            "username": "john.doe",
            "class": {
                "id": 1,
                "name": "SS1"
            },
            "arm": {
                "id": 1,
                "name": "A"
            },
            "status": "active"
        }
    ],
    "links": {...},
    "meta": {...}
}
```

### Get Student by ID

**GET** `/api/v1/students/{id}`

Get specific student by ID.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "id": 1,
    "admission_number": "SS2025001",
    "name": "John Doe",
    "email": "john.doe@schoolname.com",
    "username": "john.doe",
    "class": {
        "id": 1,
        "name": "SS1"
    },
    "arm": {
        "id": 1,
        "name": "A"
    },
    "guardian": {
        "id": 1,
        "name": "Jane Doe",
        "email": "jane.doe@example.com",
        "phone": "+1234567890"
    },
    "status": "active",
    "created_at": "2025-01-21T10:00:00Z"
}
```

### Create Student

**POST** `/api/v1/students`

Create new student with auto-generated admission number, email, and username. The system automatically:

-   Generates a unique admission number (format: `{SCHOOL_ABBR}{YEAR}{CLASS_ABBR}{SEQUENCE}`)
-   Generates email using school domain (format: `{firstname}.{lastname}@{schooldomain}.samschool.com`)
-   Generates username (format: `{firstname}.{lastname}`)
-   Creates a user account for the student

**Headers:**

```
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "school_id": 1,
    "first_name": "John",
    "last_name": "Doe",
    "middle_name": "Michael",
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "class_id": 1,
    "arm_id": 1,
    "parent_name": "Jane Doe",
    "parent_phone": "+1234567890",
    "parent_email": "jane.doe@example.com",
    "address": "123 Main St",
    "phone": "+1234567890",
    "email": "john.doe@abchighschool.samschool.com",
    "username": "john.doe"
}
```

**Note:** `email` and `username` are optional. If not provided, they will be auto-generated.

**Response (201 Created):**

```json
{
    "message": "Student registered successfully with auto-generated credentials",
    "student": {
        "id": 1,
        "admission_number": "ABC2025SS1001",
        "first_name": "John",
        "last_name": "Doe",
        "middle_name": "Michael",
        "email": "john.doe@abchighschool.samschool.com",
        "username": "john.doe",
        "class": {
            "id": 1,
            "name": "SS1"
        },
        "arm": {
            "id": 1,
            "name": "A"
        },
        "user": {
            "id": 10,
            "email": "john.doe@abchighschool.samschool.com",
            "role": "student"
        },
        "status": "active",
        "admission_date": "2025-01-26T10:00:00Z"
    }
}
```

### Generate Admission Number

**POST** `/api/v1/students/generate-admission-number`

Generate a unique admission number for a student without creating the student record.

**Headers:**

```
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "school_id": 1,
    "class_id": 1
}
```

**Response (200 OK):**

```json
{
    "admission_number": "ABC2025SS1001",
    "format": "SCHOOL_ABBR + YEAR + CLASS_ABBR + SEQUENCE",
    "explanation": "ABC (school abbreviation) + 2025 (year) + SS1 (class) + 001 (sequence)"
}
```

### Generate Student Credentials

**POST** `/api/v1/students/generate-credentials`

Generate email and username for a student based on their name and school domain.

**Headers:**

```
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "first_name": "John",
    "last_name": "Doe",
    "school_id": 1
}
```

**Response (200 OK):**

```json
{
    "email": "john.doe@abchighschool.samschool.com",
    "username": "john.doe",
    "explanation": {
        "email": "Generated using format: {firstname}.{lastname}@{schooldomain}.samschool.com",
        "username": "Generated using format: {firstname}.{lastname}"
    }
}
```

### Update Student

**PUT** `/api/v1/students/{id}`

Update student information.

**Headers:**

```
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "name": "John Smith",
    "class_id": 2,
    "arm_id": 2,
    "address": "456 Oak St"
}
```

**Response (200 OK):**

```json
{
    "message": "Student updated successfully",
    "student": {
        "id": 1,
        "admission_number": "SS2025001",
        "name": "John Smith",
        "email": "john.smith@schoolname.com",
        "username": "john.smith"
    }
}
```

### Get Student Attendance

**GET** `/api/v1/students/{id}/attendance`

Get student attendance records.

**Headers:**

```
Authorization: Bearer {token}
```

**Query Parameters:**

-   `start_date` (optional): Start date (YYYY-MM-DD)
-   `end_date` (optional): End date (YYYY-MM-DD)
-   `month` (optional): Month (1-12)
-   `year` (optional): Year

**Response (200 OK):**

```json
{
    "student": {
        "id": 1,
        "name": "John Doe",
        "admission_number": "SS2025001"
    },
    "attendance": [
        {
            "date": "2025-01-21",
            "status": "present",
            "time_in": "08:00:00",
            "time_out": "15:00:00"
        }
    ],
    "summary": {
        "total_days": 20,
        "present_days": 18,
        "absent_days": 2,
        "attendance_percentage": 90.0
    }
}
```

### Get Student Results

**GET** `/api/v1/students/{id}/results`

Get student academic results.

**Headers:**

```
Authorization: Bearer {token}
```

**Query Parameters:**

-   `term_id` (optional): Filter by term
-   `session_id` (optional): Filter by session
-   `subject_id` (optional): Filter by subject

**Response (200 OK):**

```json
{
    "student": {
        "id": 1,
        "name": "John Doe",
        "admission_number": "SS2025001"
    },
    "results": [
        {
            "subject": {
                "id": 1,
                "name": "Mathematics"
            },
            "ca_score": 25,
            "exam_score": 65,
            "total_score": 90,
            "grade": "A",
            "position": 1
        }
    ],
    "summary": {
        "total_subjects": 5,
        "average_score": 85.5,
        "overall_grade": "A",
        "class_position": 3
    }
}
```

---

## Teacher Management APIs

### Get All Teachers

**GET** `/api/v1/teachers`

Get paginated list of teachers.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "name": "Dr. Smith",
            "email": "dr.smith@schoolname.com",
            "username": "dr.smith",
            "subjects": [
                {
                    "id": 1,
                    "name": "Mathematics"
                }
            ],
            "classes": [
                {
                    "id": 1,
                    "name": "SS1A"
                }
            ],
            "status": "active"
        }
    ],
    "links": {...},
    "meta": {...}
}
```

### Get Teacher by ID

**GET** `/api/v1/teachers/{id}`

Get specific teacher by ID.

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "id": 1,
    "name": "Dr. Smith",
    "email": "dr.smith@schoolname.com",
    "username": "dr.smith",
    "subjects": [
        {
            "id": 1,
            "name": "Mathematics",
            "code": "MATH"
        }
    ],
    "classes": [
        {
            "id": 1,
            "name": "SS1A"
        }
    ],
    "qualification": "Ph.D. Mathematics",
    "experience_years": 10,
    "status": "active"
}
```

### Create Teacher

**POST** `/api/v1/teachers`

Create new teacher.

**Headers:**

```
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "name": "Dr. Smith",
    "subjects": [1, 2],
    "classes": [1, 2],
    "qualification": "Ph.D. Mathematics",
    "experience_years": 10,
    "phone": "+1234567890"
}
```

**Response (201 Created):**

```json
{
    "message": "Teacher created successfully",
    "teacher": {
        "id": 1,
        "name": "Dr. Smith",
        "email": "dr.smith@schoolname.com",
        "username": "dr.smith",
        "subjects": [...],
        "classes": [...]
    }
}
```

---

## Academic Management APIs

### Classes Management

#### Get All Classes

**GET** `/api/v1/classes`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "name": "SS1",
            "level": "Senior Secondary 1",
            "arms": [
                {
                    "id": 1,
                    "name": "A",
                    "class_teacher": {
                        "id": 1,
                        "name": "Dr. Smith"
                    }
                }
            ],
            "student_count": 30
        }
    ]
}
```

#### Create Class

**POST** `/api/v1/classes`

**Request Body:**

```json
{
    "name": "SS2",
    "level": "Senior Secondary 2",
    "arms": ["A", "B", "C"]
}
```

### Subjects Management

#### Get All Subjects

**GET** `/api/v1/subjects`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "name": "Mathematics",
            "code": "MATH",
            "description": "Core Mathematics",
            "teachers": [
                {
                    "id": 1,
                    "name": "Dr. Smith"
                }
            ]
        }
    ]
}
```

#### Create Subject

**POST** `/api/v1/subjects`

**Request Body:**

```json
{
    "name": "Physics",
    "code": "PHY",
    "description": "Core Physics",
    "teacher_ids": [1, 2]
}
```

---

## Assessment APIs

### Exams Management

#### Get All Exams

**GET** `/api/v1/exams`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "title": "First Term Examination",
            "type": "exam",
            "subject": {
                "id": 1,
                "name": "Mathematics"
            },
            "class": {
                "id": 1,
                "name": "SS1A"
            },
            "start_date": "2025-03-15",
            "end_date": "2025-03-20",
            "duration": 120,
            "total_marks": 100,
            "status": "scheduled"
        }
    ]
}
```

#### Create Exam

**POST** `/api/v1/exams`

**Request Body:**

```json
{
    "title": "First Term Examination",
    "type": "exam",
    "subject_id": 1,
    "class_id": 1,
    "start_date": "2025-03-15",
    "end_date": "2025-03-20",
    "duration": 120,
    "total_marks": 100
}
```

### CBT (Computer-Based Testing)

#### Get CBT Sessions

**GET** `/api/v1/cbt/sessions`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "session_id": "CBT2025001",
            "exam": {
                "id": 1,
                "title": "Mathematics CBT"
            },
            "student": {
                "id": 1,
                "name": "John Doe",
                "admission_number": "SS2025001"
            },
            "start_time": "2025-01-21T10:00:00Z",
            "end_time": "2025-01-21T12:00:00Z",
            "status": "completed",
            "score": 85,
            "total_questions": 50,
            "answered_questions": 50
        }
    ]
}
```

#### Start CBT Session

**POST** `/api/v1/cbt/sessions`

**Request Body:**

```json
{
    "exam_id": 1,
    "student_id": 1
}
```

**Response (201 Created):**

```json
{
    "message": "CBT session started",
    "session": {
        "id": 1,
        "session_id": "CBT2025001",
        "exam": {...},
        "questions": [
            {
                "id": 1,
                "question": "What is 2 + 2?",
                "type": "multiple_choice",
                "options": [
                    {"id": 1, "text": "3"},
                    {"id": 2, "text": "4"},
                    {"id": 3, "text": "5"},
                    {"id": 4, "text": "6"}
                ],
                "marks": 2
            }
        ],
        "start_time": "2025-01-21T10:00:00Z",
        "duration": 120
    }
}
```

#### Submit CBT Answers

**POST** `/api/v1/cbt/sessions/{session_id}/submit`

**Request Body:**

```json
{
    "answers": [
        {
            "question_id": 1,
            "answer": "4",
            "time_spent": 30
        }
    ]
}
```

**Response (200 OK):**

```json
{
    "message": "CBT submitted successfully",
    "result": {
        "session_id": "CBT2025001",
        "score": 85,
        "total_marks": 100,
        "correct_answers": 42,
        "wrong_answers": 8,
        "completion_time": "01:45:30",
        "grade": "A"
    }
}
```

---

## Financial Management APIs

### Fees Management

#### Get All Fees

**GET** `/api/v1/fees`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "student": {
                "id": 1,
                "name": "John Doe",
                "admission_number": "SS2025001"
            },
            "fee_type": "tuition",
            "amount": 50000,
            "due_date": "2025-02-15",
            "status": "pending",
            "created_at": "2025-01-21T10:00:00Z"
        }
    ]
}
```

#### Create Fee

**POST** `/api/v1/fees`

**Request Body:**

```json
{
    "student_id": 1,
    "fee_type": "tuition",
    "amount": 50000,
    "due_date": "2025-02-15",
    "description": "First term tuition fee"
}
```

#### Pay Fee

**POST** `/api/v1/fees/{id}/pay`

**Request Body:**

```json
{
    "amount": 50000,
    "payment_method": "bank_transfer",
    "reference": "TXN123456789"
}
```

**Response (200 OK):**

```json
{
    "message": "Payment successful",
    "payment": {
        "id": 1,
        "amount": 50000,
        "method": "bank_transfer",
        "reference": "TXN123456789",
        "status": "completed",
        "paid_at": "2025-01-21T10:00:00Z"
    }
}
```

---

## Communication APIs

### Messages

#### Get Messages

**GET** `/api/v1/messages`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "sender": {
                "id": 1,
                "name": "Dr. Smith"
            },
            "recipient": {
                "id": 2,
                "name": "John Doe"
            },
            "subject": "Assignment Reminder",
            "message": "Please submit your mathematics assignment",
            "type": "assignment_reminder",
            "status": "sent",
            "created_at": "2025-01-21T10:00:00Z"
        }
    ]
}
```

#### Send Message

**POST** `/api/v1/messages`

**Request Body:**

```json
{
    "recipient_id": 2,
    "subject": "Assignment Reminder",
    "message": "Please submit your mathematics assignment",
    "type": "assignment_reminder"
}
```

### Notifications

#### Get Notifications

**GET** `/api/v1/notifications`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "title": "New Assignment",
            "message": "Mathematics assignment has been posted",
            "type": "assignment",
            "read": false,
            "created_at": "2025-01-21T10:00:00Z"
        }
    ]
}
```

#### Mark Notification as Read

**PUT** `/api/v1/notifications/{id}/read`

**Response (200 OK):**

```json
{
    "message": "Notification marked as read"
}
```

---

## Administrative APIs

### Schools Management

#### Get All Schools

**GET** `/api/v1/schools`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "name": "ABC High School",
            "domain": "abc.samschool.com",
            "address": "123 School St",
            "phone": "+1234567890",
            "email": "info@abc.samschool.com",
            "principal": {
                "id": 1,
                "name": "Dr. Johnson"
            },
            "student_count": 500,
            "teacher_count": 25,
            "status": "active"
        }
    ]
}
```

#### Create School

**POST** `/api/v1/schools`

**Request Body:**

```json
{
    "name": "XYZ High School",
    "domain": "xyz.samschool.com",
    "address": "456 Education Ave",
    "phone": "+1234567890",
    "email": "info@xyz.samschool.com",
    "principal_id": 1
}
```

### Bulk Operations

#### Bulk Student Registration

**POST** `/api/v1/bulk/students`

**Request Body:**

```json
{
    "students": [
        {
            "name": "John Doe",
            "class_id": 1,
            "arm_id": 1,
            "guardian_id": 1,
            "date_of_birth": "2010-05-15",
            "gender": "male"
        }
    ]
}
```

**Response (200 OK):**

```json
{
    "message": "Bulk registration completed",
    "results": {
        "successful": 45,
        "failed": 5,
        "students": [
            {
                "name": "John Doe",
                "admission_number": "SS2025001",
                "email": "john.doe@schoolname.com",
                "username": "john.doe",
                "status": "created"
            }
        ]
    }
}
```

---

## Multi-tenant APIs

### Create Tenant (Super Admin Only)

**POST** `/api/v1/tenants`

Super admin endpoint to create a new tenant (school district) with an automatically created database and school admin user.

**Headers:**

```
Authorization: Bearer {super_admin_token}
```

**Request Body:**

```json
{
    "name": "Tester School District",
    "subdomain": "tester",
    "domain": "tester.samschool.com",
    "school": {
        "name": "Tester High School",
        "address": "123 Main St",
        "phone": "+1234567890",
        "email": "info@tester.com",
        "website": "https://tester.samschool.com",
        "admin_name": "School Administrator",
        "admin_email": "admin@tester.samschool.com",
        "admin_password": "SecurePassword123"
    },
    "settings": {
        "timezone": "Africa/Lagos",
        "currency": "NGN"
    }
}
```

**Request Parameters:**

-   `name` (required): Name of the tenant/school district
-   `subdomain` (required): Unique subdomain identifier
-   `domain` (optional): Custom domain name
-   `school` (required): School information object
    -   `name` (required): School name
    -   `address` (optional): School address
    -   `phone` (optional): School phone number
    -   `email` (optional): School email
    -   `website` (optional): School website URL
    -   `admin_name` (optional): Admin user's full name (default: "School Administrator")
    -   `admin_email` (optional): Admin user's email (default: auto-generated as `admin@{subdomain}.samschool.com`)
    -   `admin_password` (optional): Admin user's password (default: auto-generated 12-character random string)
-   `settings` (optional): Additional tenant settings

**Response (201 Created):**

```json
{
    "message": "Tenant and school created successfully",
    "tenant": {
        "id": 1,
        "name": "Tester School District",
        "subdomain": "tester",
        "domain": "tester.samschool.com",
        "database_name": "tenant_tester_20250126120000",
        "status": "active",
        "created_at": "2025-01-26T10:00:00Z"
    },
    "school": {
        "id": 1,
        "name": "Tester High School",
        "address": "123 Main St",
        "phone": "+1234567890",
        "email": "info@tester.com",
        "status": "active"
    },
    "admin_credentials": {
        "email": "admin@tester.samschool.com",
        "role": "school_admin",
        "password": "xK9mP2qR7nL4",
        "note": "Please save these credentials. The password cannot be retrieved later."
    }
}
```

**What Happens Automatically:**

1. ✅ Tenant record created in main database
2. ✅ Database created automatically (e.g., `tenant_tester_20250126120000`)
3. ✅ School record created in tenant database
4. ✅ Tenant migrations run automatically
5. ✅ School admin user created in main database
6. ✅ Admin credentials returned in response

**Response (422 Validation Error):**

```json
{
    "error": "Validation failed",
    "messages": {
        "subdomain": ["The subdomain has already been taken."]
    }
}
```

### List All Tenants (Super Admin Only)

**GET** `/api/v1/tenants`

Get paginated list of all tenants.

**Headers:**

```
Authorization: Bearer {super_admin_token}
```

**Query Parameters:**

-   `page` (optional): Page number (default: 1)
-   `per_page` (optional): Items per page (default: 15)
-   `status` (optional): Filter by status (active, inactive, suspended)
-   `search` (optional): Search by name, subdomain, or domain

**Response (200 OK):**

```json
{
    "tenants": {
        "data": [
            {
                "id": 1,
                "name": "Tester School District",
                "subdomain": "tester",
                "domain": "tester.samschool.com",
                "status": "active",
                "created_at": "2025-01-26T10:00:00Z"
            }
        ],
        "links": {...},
        "meta": {...}
    }
}
```

### Get Tenant Details (Super Admin Only)

**GET** `/api/v1/tenants/{id}`

Get specific tenant details.

**Headers:**

```
Authorization: Bearer {super_admin_token}
```

**Response (200 OK):**

```json
{
    "id": 1,
    "name": "Tester School District",
    "subdomain": "tester",
    "domain": "tester.samschool.com",
    "database_name": "tenant_tester_20250126120000",
    "status": "active",
    "settings": {},
    "schools": [...],
    "created_at": "2025-01-26T10:00:00Z"
}
```

### Get Tenant Statistics (Super Admin Only)

**GET** `/api/v1/tenants/{id}/stats`

Get comprehensive statistics for a tenant.

**Headers:**

```
Authorization: Bearer {super_admin_token}
```

**Response (200 OK):**

```json
{
    "stats": {
        "tenant": {
            "total_users": 150,
            "total_schools": 1
        },
        "schools": 1,
        "users": 150,
        "students": 120,
        "teachers": 25
    }
}
```

### Update Tenant (Super Admin Only)

**PUT/PATCH** `/api/v1/tenants/{id}`

Update tenant information.

**Headers:**

```
Authorization: Bearer {super_admin_token}
```

**Request Body:**

```json
{
    "name": "Updated School District",
    "domain": "updated.samschool.com",
    "status": "active",
    "settings": {
        "timezone": "Africa/Lagos"
    }
}
```

**Response (200 OK):**

```json
{
    "message": "Tenant updated successfully",
    "tenant": {
        "id": 1,
        "name": "Updated School District",
        "subdomain": "tester",
        "domain": "updated.samschool.com",
        "status": "active"
    }
}
```

### Delete Tenant (Super Admin Only)

**DELETE** `/api/v1/tenants/{id}`

Delete a tenant and its associated database. **Warning: This action is irreversible!**

**Headers:**

```
Authorization: Bearer {super_admin_token}
```

**Response (200 OK):**

```json
{
    "message": "Tenant deleted successfully"
}
```

---

### Tenant Management

#### Get All Tenants

**GET** `/api/v1/tenants`

**Headers:**

```
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
    "data": [
        {
            "id": 1,
            "name": "Test School District",
            "domain": "test-school.samschool.com",
            "database_name": "test_school_db",
            "status": "active",
            "schools_count": 3,
            "users_count": 150
        }
    ]
}
```

#### Create Tenant

**POST** `/api/v1/tenants`

**Request Body:**

```json
{
    "name": "New School District",
    "domain": "new-school.samschool.com",
    "database_name": "new_school_db"
}
```

---

## Error Responses

### 400 Bad Request

```json
{
    "error": "Bad Request",
    "message": "Invalid request data",
    "details": {
        "field": "email",
        "message": "The email field is required"
    }
}
```

### 401 Unauthorized

```json
{
    "error": "Unauthorized",
    "message": "Authentication required"
}
```

### 403 Forbidden

```json
{
    "error": "Forbidden",
    "message": "Insufficient permissions"
}
```

### 404 Not Found

```json
{
    "error": "Not Found",
    "message": "Resource not found"
}
```

### 422 Unprocessable Entity

```json
{
    "error": "Validation failed",
    "messages": {
        "email": ["The email has already been taken"],
        "password": ["The password must be at least 8 characters"]
    }
}
```

### 500 Internal Server Error

```json
{
    "error": "Internal Server Error",
    "message": "An unexpected error occurred"
}
```

---

## Authentication

All API endpoints (except registration and login) require authentication using Bearer tokens.

**Header Format:**

```
Authorization: Bearer {your_token_here}
```

**Token Expiration:**

-   Access tokens expire after 24 hours
-   Refresh tokens expire after 30 days
-   Use the refresh endpoint to get new tokens

---

## Rate Limiting

-   **General API**: 60 requests per minute per user
-   **Authentication**: 5 requests per minute per IP
-   **File Upload**: 10 requests per minute per user

**Rate Limit Headers:**

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1640995200
```

---

## Pagination

Most list endpoints support pagination:

**Query Parameters:**

-   `page`: Page number (default: 1)
-   `per_page`: Items per page (default: 15, max: 100)

**Response Format:**

```json
{
    "data": [...],
    "links": {
        "first": "http://api.example.com/endpoint?page=1",
        "last": "http://api.example.com/endpoint?page=10",
        "prev": null,
        "next": "http://api.example.com/endpoint?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 10,
        "per_page": 15,
        "to": 15,
        "total": 150
    }
}
```

---

## Webhooks

The system supports webhooks for real-time notifications:

**Available Events:**

-   `student.registered`
-   `student.updated`
-   `payment.completed`
-   `exam.scheduled`
-   `assignment.created`

**Webhook Payload:**

```json
{
    "event": "student.registered",
    "data": {
        "student": {
            "id": 1,
            "name": "John Doe",
            "admission_number": "SS2025001"
        }
    },
    "timestamp": "2025-01-21T10:00:00Z"
}
```
