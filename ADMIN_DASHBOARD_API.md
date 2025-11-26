# Admin Dashboard API Documentation

Complete API reference for School Admin Dashboard functionality.

---

## Table of Contents

1. [Dashboard Overview](#dashboard-overview)
2. [School Management](#school-management)
3. [User Management](#user-management)
4. [Academic Setup](#academic-setup)
5. [Student Management](#student-management)
6. [Teacher Management](#teacher-management)
7. [Staff & Guardian Management](#staff--guardian-management)
8. [Settings](#settings)
9. [Subscriptions & Modules](#subscriptions--modules)

---

## Authentication

All endpoints require:

```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

---

## Dashboard Overview

### Get Admin Dashboard

**Endpoint:** `GET /api/v1/dashboard/admin`

**Response (200):**
```json
{
  "user": {
    "id": 1,
    "name": "John Admin",
    "email": "admin@westwoodschool.com",
    "role": "school_admin"
  },
  "school": {
    "id": 1,
    "name": "Westwood School",
    "subdomain": "westwood",
    "logo": "https://...",
    "website": "https://westwoodschool.com"
  },
  "stats": {
    "total_students": 450,
    "total_teachers": 35,
    "total_staff": 20,
    "total_classes": 15,
    "active_terms": 1,
    "pending_fees": 250000
  },
  "role": "school_admin"
}
```

---

## School Management

### Get School Details

**Endpoint:** `GET /api/v1/schools/{school_id}`

### Update School

**Endpoint:** `PUT /api/v1/schools/{school_id}`

**Request Body:**
```json
{
  "name": "Westwood International School",
  "email": "info@westwoodschool.com",
  "phone": "+234803456789",
  "address": "123 Education Street, Lagos",
  "logo": "https://s3..../logo.png",
  "website": "https://westwoodschool.com",
  "motto": "Excellence in Education",
  "description": "Premier institution..."
}
```

### Get School Statistics

**Endpoint:** `GET /api/v1/schools/{school_id}/stats`

**Response (200):**
```json
{
  "students": {
    "total": 450,
    "active": 445,
    "inactive": 5,
    "by_class": [...]
  },
  "teachers": {
    "total": 35,
    "active": 34,
    "on_leave": 1
  },
  "staff": {
    "total": 20,
    "by_role": [...]
  },
  "academic": {
    "classes": 15,
    "subjects": 12,
    "arms": 30
  },
  "financial": {
    "total_fees_due": 5000000,
    "fees_paid": 4750000,
    "pending": 250000
  }
}
```

---

## User Management

### List All Users

**Endpoint:** `GET /api/v1/users`

**Query Parameters:**
- `role` - Filter by role
- `status` - Filter by status (active, inactive, suspended)
- `search` - Search by name/email
- `per_page` - Items per page (default: 15)

### Create User

**Endpoint:** `POST /api/v1/users`

**Request Body:**
```json
{
  "name": "Jane Doe",
  "email": "jane@westwoodschool.com",
  "password": "SecurePass123",
  "password_confirmation": "SecurePass123",
  "role": "teacher",
  "phone": "+234801234567",
  "status": "active",
  "profile_picture": "https://..."
}
```

### Update User

**Endpoint:** `PUT /api/v1/users/{id}`

### Activate User

**Endpoint:** `POST /api/v1/users/{id}/activate`

### Suspend User

**Endpoint:** `POST /api/v1/users/{id}/suspend`

### Delete User

**Endpoint:** `DELETE /api/v1/users/{id}`

---

## Profile Picture Management

### Upload Profile Picture

**Endpoint:** `POST /api/v1/users/{id}/profile-picture`

**Request Body:**
```json
{
  "profile_picture": "https://s3.amazonaws.com/.../photo.jpg"
}
```

**Response (200):**
```json
{
  "message": "Profile picture updated successfully",
  "user": {
    "id": 5,
    "name": "John Doe",
    "profile_picture": "https://..."
  }
}
```

### Upload Own Profile Picture

**Endpoint:** `POST /api/v1/users/me/profile-picture`

### Delete Profile Picture

**Endpoint:** `DELETE /api/v1/users/{id}/profile-picture`

### Delete Own Profile Picture

**Endpoint:** `DELETE /api/v1/users/me/profile-picture`

---

## Academic Setup

### Academic Years

**List Academic Years:** `GET /api/v1/academic-years`

**Create Academic Year:**
```bash
POST /api/v1/academic-years
{
  "name": "2025/2026",
  "start_date": "2025-09-01",
  "end_date": "2026-07-31",
  "is_active": true
}
```

### Terms

**List Terms:** `GET /api/v1/terms`

**Create Term:**
```bash
POST /api/v1/terms
{
  "academic_year_id": 1,
  "name": "First Term",
  "start_date": "2025-09-01",
  "end_date": "2025-12-15",
  "is_active": true
}
```

### Departments

**List Departments:** `GET /api/v1/departments`

**Create Department:**
```bash
POST /api/v1/departments
{
  "name": "Science Department",
  "code": "SCI",
  "description": "All science subjects",
  "head_of_department_id": 5
}
```

### Classes

**List Classes:** `GET /api/v1/classes`

**Create Class:**
```bash
POST /api/v1/classes
{
  "name": "JSS 1",
  "description": "Junior Secondary School 1",
  "capacity": 180,
  "class_teacher_id": 5,
  "academic_year_id": 1,
  "term_id": 1
}
```

### Arms (Class Divisions)

**List All Arms:** `GET /api/v1/arms`

**Create Arm:**
```bash
POST /api/v1/arms
{
  "class_id": 1,
  "name": "A",
  "description": "JSS 1A",
  "capacity": 30,
  "class_teacher_id": 5,
  "status": "active"
}
```

**Get Arms for a Class:** `GET /api/v1/arms/class/{class_id}`

**Response (200):**
```json
{
  "class": {
    "id": 1,
    "name": "JSS 1"
  },
  "arms": [
    {
      "id": 1,
      "name": "A",
      "capacity": 30,
      "students_count": 28,
      "stats": {
        "total_students": 28,
        "capacity_utilization": 93.33
      },
      "is_full": false,
      "available_capacity": 2
    }
  ],
  "total_arms": 6,
  "total_capacity": 180,
  "total_students": 165
}
```

**Get Students in Arm:** `GET /api/v1/arms/{id}/students`

**Assign Teacher to Arm:** `POST /api/v1/arms/{id}/assign-teacher`

```json
{
  "class_teacher_id": 5
}
```

### Subjects

**List Subjects:** `GET /api/v1/subjects`

**Create Subject:**
```bash
POST /api/v1/subjects
{
  "name": "Mathematics",
  "code": "MATH101",
  "description": "General Mathematics",
  "department_id": 1,
  "teacher_id": 5
}
```

---

## Student Management

### List Students

**Endpoint:** `GET /api/v1/students`

**Query Parameters:**
- `class_id` - Filter by class
- `arm_id` - Filter by arm
- `status` - Filter by status
- `search` - Search by name/admission number
- `per_page` - Items per page (default: 15)

### Create Student

**Endpoint:** `POST /api/v1/students`

**Request Body:**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "middle_name": "Smith",
  "date_of_birth": "2010-05-15",
  "gender": "male",
  "class_id": 1,
  "arm_id": 1,
  "phone": "+234801234567",
  "address": "123 Main St",
  "blood_group": "O+",
  "guardians": [
    {
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "jane@example.com",
      "phone": "+234809876543",
      "relationship": "Mother",
      "is_primary": true
    }
  ]
}
```

**Note:** All of these are auto-generated:
- `admission_number` - Auto-generated (ADM00001)
- `email` - Auto-generated (firstname.lastname{id}@schooldomain.com)
- `username` - Auto-generated
- `password` - Auto-generated (Password@123)
- `school_id` - Auto-derived from X-Subdomain

### Bulk Create Students

**Endpoint:** `POST /api/v1/bulk/students/register`

**Request Body:**
```json
{
  "students": [
    {
      "first_name": "Student1",
      "last_name": "Test",
      "date_of_birth": "2010-01-15",
      "gender": "male",
      "class_id": 1
    }
    // ... up to 10,000 students
  ]
}
```

**Performance:** Can handle 10,000+ students without timeout using optimized bulk inserts.

### Get Student Details

**Endpoint:** `GET /api/v1/students/{id}`

### Update Student

**Endpoint:** `PUT /api/v1/students/{id}`

### Delete Student

**Endpoint:** `DELETE /api/v1/students/{id}`

---

## Teacher Management

### List Teachers

**Endpoint:** `GET /api/v1/teachers`

### Create Teacher

**Endpoint:** `POST /api/v1/teachers`

**Request Body:**
```json
{
  "first_name": "John",
  "last_name": "Smith",
  "email": "john.smith@westwoodschool.com",
  "phone": "+234801234567",
  "date_of_birth": "1985-05-15",
  "gender": "male",
  "department_id": 1,
  "qualification": "B.Sc. Mathematics",
  "specialization": "Mathematics",
  "date_of_joining": "2025-01-15"
}
```

**Note:** Auto-generated:
- `employee_id` - Auto-generated (TCH001)
- `email` - Can be auto-generated if not provided
- `password` - Auto-generated (Password@123)

### Bulk Create Teachers

**Endpoint:** `POST /api/v1/bulk/teachers/register`

### Get Teacher Details

**Endpoint:** `GET /api/v1/teachers/{id}`

### Update Teacher

**Endpoint:** `PUT /api/v1/teachers/{id}`

### Delete Teacher

**Endpoint:** `DELETE /api/v1/teachers/{id}`

---

## Staff & Guardian Management

### Staff

**List Staff:** `GET /api/v1/staff`

**Create Staff:** `POST /api/v1/staff`

```json
{
  "first_name": "Mary",
  "last_name": "Johnson",
  "email": "mary@westwoodschool.com",
  "phone": "+234801234567",
  "role": "accountant",
  "department": "Administration",
  "date_of_joining": "2025-01-15"
}
```

**Bulk Create Staff:** `POST /api/v1/bulk/staff/create`

### Guardians

**List Guardians:** `GET /api/v1/guardians`

**Create Guardian:** `POST /api/v1/guardians`

```json
{
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "phone": "+234809876543",
  "address": "123 Main St",
  "occupation": "Engineer",
  "relationship": "Mother"
}
```

**Bulk Create Guardians:** `POST /api/v1/bulk/guardians/create`

---

## Settings

### Get Settings

**Endpoint:** `GET /api/v1/settings`

### Update Settings

**Endpoint:** `PUT /api/v1/settings`

**Request Body:**
```json
{
  "academic_year_format": "YYYY/YYYY",
  "attendance_grace_period": 15,
  "late_payment_fee_percentage": 5,
  "minimum_age_requirement": 4,
  "maximum_age_requirement": 18,
  "default_class_capacity": 30,
  "grading_system": "percentage"
}
```

### Get School Settings

**Endpoint:** `GET /api/v1/settings/school`

### Update School Settings

**Endpoint:** `PUT /api/v1/settings/school`

---

## Subscriptions & Modules

### Get Subscription Status

**Endpoint:** `GET /api/v1/subscriptions/status`

**Response (200):**
```json
{
  "subscription": {
    "plan": "Premium",
    "start_date": "2025-01-01",
    "end_date": "2025-12-31",
    "status": "active",
    "days_remaining": 300
  },
  "modules": [
    {
      "name": "Academic Management",
      "status": "active"
    },
    {
      "name": "Student Management",
      "status": "active"
    },
    {
      "name": "CBT System",
      "status": "active"
    }
  ],
  "limits": {
    "max_students": 1000,
    "current_students": 450,
    "max_teachers": 100,
    "current_teachers": 35
  }
}
```

### Get Available Plans

**Endpoint:** `GET /api/v1/subscriptions/plans`

### Get Available Modules

**Endpoint:** `GET /api/v1/subscriptions/modules`

### Upgrade Subscription

**Endpoint:** `PUT /api/v1/subscriptions/{subscription_id}/upgrade`

---

## Summary

### Admin Can:
✅ View comprehensive dashboard  
✅ Manage school information  
✅ Create and manage users (all roles)  
✅ Upload/delete profile pictures  
✅ Setup academic structure (years, terms, departments)  
✅ Create and manage classes & arms  
✅ Manage students (single & bulk)  
✅ Manage teachers (single & bulk)  
✅ Manage staff & guardians  
✅ Configure school settings  
✅ Manage subscriptions  
✅ Access all modules based on subscription  

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

