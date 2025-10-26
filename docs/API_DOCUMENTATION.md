# School Management System API Documentation

## Overview

This is a comprehensive multi-tenant school management system API that supports millions of users with sub-3-second response times. The system is built with Laravel and supports multiple schools with isolated databases.

## Base URL

```
https://api.samschool.com/v1
```

## Authentication

All API requests require authentication using Bearer tokens.

```bash
Authorization: Bearer {your-token}
```

## Multi-Tenancy

The system supports multi-tenancy through:

-   **Subdomain-based**: `{school}.samschool.com`
-   **Header-based**: `X-Tenant-ID: {tenant-id}`
-   **Parameter-based**: `?school_id={school-id}`

## Core Endpoints

### Authentication

#### Login

```http
POST /auth/login
```

**Request Body:**

```json
{
    "email": "user@example.com",
    "password": "password",
    "tenant_id": 1,
    "school_id": 1
}
```

**Response:**

```json
{
    "message": "Login successful",
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "user@example.com",
        "role": "teacher",
        "tenant": {
            "id": 1,
            "name": "ABC School"
        }
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer"
}
```

#### Register

```http
POST /auth/register
```

**Request Body:**

```json
{
    "name": "John Doe",
    "email": "user@example.com",
    "password": "password",
    "password_confirmation": "password",
    "role": "teacher",
    "tenant_id": 1,
    "school_id": 1,
    "employee_id": "EMP001",
    "first_name": "John",
    "last_name": "Doe",
    "employment_date": "2024-01-01"
}
```

### School Management

#### Get School Information

```http
GET /schools/{school}
```

**Response:**

```json
{
    "school": {
        "id": 1,
        "name": "ABC School",
        "address": "123 Main St",
        "phone": "+1234567890",
        "email": "info@abcschool.com",
        "website": "https://abcschool.com",
        "logo": "https://s3.amazonaws.com/bucket/logo.jpg",
        "principal": {
            "id": 1,
            "name": "Dr. Jane Smith"
        },
        "stats": {
            "teachers": 50,
            "students": 500,
            "classes": 20,
            "subjects": 15
        }
    }
}
```

#### Get School Dashboard

```http
GET /schools/{school}/dashboard
```

### Subscription Management

#### Get Available Plans

```http
GET /subscriptions/plans
```

**Response:**

```json
{
    "plans": [
        {
            "id": 1,
            "name": "Basic Plan",
            "description": "Essential features for growing schools",
            "type": "basic",
            "price": 29.99,
            "currency": "USD",
            "billing_cycle": "monthly",
            "trial_days": 14,
            "modules": [
                "student_management",
                "teacher_management",
                "academic_management"
            ],
            "limits": {
                "students": 200,
                "teachers": 25,
                "storage_gb": 10
            }
        }
    ]
}
```

#### Get Subscription Status

```http
GET /subscriptions/status
```

#### Create Subscription

```http
POST /subscriptions/create
```

**Request Body:**

```json
{
    "plan_id": 1,
    "payment_method": "card",
    "auto_renew": true
}
```

### Student Management

#### Get All Students

```http
GET /students
```

**Query Parameters:**

-   `class_id`: Filter by class
-   `arm_id`: Filter by arm
-   `status`: Filter by status
-   `search`: Search by name or admission number
-   `per_page`: Number of results per page

**Response:**

```json
{
    "students": {
        "data": [
            {
                "id": 1,
                "admission_number": "ADM001",
                "first_name": "John",
                "last_name": "Doe",
                "email": "john.doe@student.com",
                "class": {
                    "id": 1,
                    "name": "Grade 10"
                },
                "arm": {
                    "id": 1,
                    "name": "A"
                },
                "status": "active"
            }
        ],
        "current_page": 1,
        "per_page": 15,
        "total": 100
    }
}
```

#### Create Student

```http
POST /students
```

**Request Body:**

```json
{
    "user_id": 1,
    "admission_number": "ADM001",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@student.com",
    "date_of_birth": "2005-01-01",
    "gender": "male",
    "parent_name": "Jane Doe",
    "parent_phone": "+1234567890",
    "parent_email": "jane.doe@parent.com",
    "admission_date": "2024-01-01",
    "class_id": 1,
    "arm_id": 1
}
```

#### Get Student Details

```http
GET /students/{student}
```

#### Get Student Attendance

```http
GET /students/{student}/attendance
```

#### Get Student Results

```http
GET /students/{student}/results
```

### Teacher Management

#### Get All Teachers

```http
GET /teachers
```

#### Create Teacher

```http
POST /teachers
```

**Request Body:**

```json
{
    "user_id": 1,
    "employee_id": "EMP001",
    "first_name": "Jane",
    "last_name": "Smith",
    "title": "Dr.",
    "email": "jane.smith@teacher.com",
    "qualification": "PhD in Mathematics",
    "specialization": "Advanced Mathematics",
    "employment_date": "2024-01-01",
    "department_id": 1
}
```

### CBT (Computer-Based Testing)

#### Start CBT Exam

```http
POST /assessments/cbt/start
```

**Request Body:**

```json
{
    "exam_id": 1,
    "student_id": 1
}
```

**Response:**

```json
{
    "message": "CBT exam started successfully",
    "attempt": {
        "id": 1,
        "exam_id": 1,
        "student_id": 1,
        "started_at": "2024-01-01T10:00:00Z",
        "status": "in_progress"
    },
    "exam": {
        "id": 1,
        "name": "Mathematics Test",
        "duration_minutes": 60,
        "total_marks": 100
    },
    "questions": [
        {
            "id": 1,
            "question_text": "What is 2 + 2?",
            "question_type": "multiple_choice",
            "marks": 5,
            "options": ["3", "4", "5", "6"]
        }
    ],
    "time_remaining": 60
}
```

#### Submit Answer

```http
POST /assessments/cbt/submit-answer
```

**Request Body:**

```json
{
    "attempt_id": 1,
    "question_id": 1,
    "answer_data": ["4"],
    "time_taken": 30
}
```

#### Submit Complete Exam

```http
POST /assessments/cbt/submit
```

**Request Body:**

```json
{
    "attempt_id": 1
}
```

### File Upload

#### Get Presigned URLs

```http
GET /uploads/presigned-urls
```

**Query Parameters:**

-   `type`: File type (profile_picture, document, image, video, audio)
-   `entity_type`: Entity type (school, student, teacher, guardian, exam, question, assignment, result)
-   `entity_id`: Entity ID

**Response:**

```json
{
    "upload_urls": {
        "profile_picture": {
            "url": "https://s3.amazonaws.com/bucket/presigned-url",
            "fields": {},
            "key": "tenants/1/students/1/profile_pictures/unique-key.jpg",
            "bucket": "samschool-files"
        }
    }
}
```

#### Upload File Directly

```http
POST /uploads/upload
```

**Request Body:**

```
Content-Type: multipart/form-data

file: [binary file data]
path: "tenants/1/students/1/profile_pictures"
entity_type: "student"
entity_id: 1
```

### Guardian Management

#### Get All Guardians

```http
GET /guardians
```

#### Create Guardian

```http
POST /guardians
```

**Request Body:**

```json
{
    "user_id": 1,
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane.doe@parent.com",
    "phone": "+1234567890",
    "occupation": "Engineer",
    "relationship_to_student": "Mother"
}
```

#### Assign Student to Guardian

```http
POST /guardians/{guardian}/assign-student
```

**Request Body:**

```json
{
    "student_id": 1,
    "relationship": "Mother",
    "is_primary": true,
    "emergency_contact": false
}
```

### Communication

#### Send SMS

```http
POST /communication/sms/send
```

**Request Body:**

```json
{
    "recipients": ["+1234567890", "+0987654321"],
    "message": "Your child's exam results are ready.",
    "type": "notification"
}
```

#### Send Email

```http
POST /communication/email/send
```

**Request Body:**

```json
{
    "recipients": ["parent@example.com"],
    "subject": "Exam Results Available",
    "content": "Your child's exam results are now available.",
    "type": "notification"
}
```

### Financial Management

#### Get All Fees

```http
GET /financial/fees
```

#### Create Fee

```http
POST /financial/fees
```

**Request Body:**

```json
{
    "student_id": 1,
    "class_id": 1,
    "fee_type": "tuition",
    "amount": 500.0,
    "due_date": "2024-02-01",
    "description": "Monthly tuition fee"
}
```

#### Make Payment

```http
POST /financial/payments
```

**Request Body:**

```json
{
    "student_id": 1,
    "guardian_id": 1,
    "fee_id": 1,
    "amount": 500.0,
    "payment_method": "card",
    "payment_reference": "TXN123456"
}
```

## Error Responses

All error responses follow this format:

```json
{
    "error": "Error type",
    "message": "Detailed error message",
    "code": "ERROR_CODE"
}
```

### Common Error Codes

-   `VALIDATION_ERROR`: Request validation failed
-   `UNAUTHORIZED`: Authentication required
-   `FORBIDDEN`: Insufficient permissions
-   `NOT_FOUND`: Resource not found
-   `MODULE_ACCESS_DENIED`: Module not available in current subscription
-   `RATE_LIMITED`: Too many requests

## Rate Limiting

-   **General API**: 1000 requests per hour
-   **File Upload**: 100 requests per hour
-   **SMS/Email**: 100 requests per hour

## Response Times

-   **Standard API**: < 500ms
-   **Complex Queries**: < 2s
-   **File Operations**: < 3s
-   **Bulk Operations**: < 5s

## Caching

The API uses intelligent caching to ensure fast response times:

-   **Student Data**: 5 minutes
-   **Teacher Data**: 5 minutes
-   **School Data**: 10 minutes
-   **Statistics**: 1 hour
-   **Subscription Data**: 1 hour

## Webhooks

The system supports webhooks for real-time notifications:

### Events

-   `student.enrolled`
-   `student.graduated`
-   `exam.completed`
-   `payment.received`
-   `attendance.marked`

### Webhook Payload

```json
{
    "event": "student.enrolled",
    "timestamp": "2024-01-01T10:00:00Z",
    "data": {
        "student_id": 1,
        "student_name": "John Doe",
        "class": "Grade 10A"
    }
}
```

## SDKs and Libraries

### JavaScript/Node.js

```bash
npm install samschool-api-client
```

```javascript
import { SamSchoolAPI } from "samschool-api-client";

const api = new SamSchoolAPI({
    baseUrl: "https://api.samschool.com/v1",
    token: "your-token",
});

const students = await api.students.list();
```

### PHP

```bash
composer require samschool/api-client
```

```php
use SamSchool\ApiClient\SamSchoolAPI;

$api = new SamSchoolAPI([
    'base_url' => 'https://api.samschool.com/v1',
    'token' => 'your-token'
]);

$students = $api->students()->list();
```

## Support

For API support and questions:

-   **Email**: api-support@samschool.com
-   **Documentation**: https://docs.samschool.com
-   **Status Page**: https://status.samschool.com
