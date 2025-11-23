# Complete Admin API Documentation

**School Management System - Admin APIs**

**Status:** ✅ All APIs Tested and Working (Production: api.compasse.net)  
**Last Updated:** November 23, 2025  
**Base URL:** `https://api.compasse.net/api/v1`  
**Authentication:** Bearer Token (Sanctum)  
**Tenant Identification:** `X-Subdomain` header

---

## Table of Contents

1. [Authentication](#authentication)
2. [Dashboard](#dashboard)
3. [Students Management](#students-management)
4. [Teachers Management](#teachers-management)
5. [Staff Management](#staff-management)
6. [Parents/Guardians](#parentsguardians)
7. [Classes Management](#classes-management)
8. [Subjects Management](#subjects-management)
9. [Departments](#departments)
10. [Academic Years & Terms](#academic-years--terms)
11. [Timetable Management](#timetable-management)
12. [Attendance Management](#attendance-management)
13. [Assignments](#assignments)
14. [Exams](#exams)
15. [Results](#results)
16. [Announcements](#announcements)
17. [Transport Management](#transport-management)
18. [Houses System](#houses-system)
19. [Sports Management](#sports-management)
20. [Inventory Management](#inventory-management)
21. [Library Management](#library-management)
22. [Finance Management](#finance-management)
23. [Communication](#communication)
24. [Reports](#reports)
25. [Settings](#settings)
26. [Subscription](#subscription)

---

## Authentication

### Login

```http
POST /auth/login
```

**Headers:**

-   `Content-Type: application/json`
-   `X-Subdomain: {school_subdomain}` (for school admin login)

**Request Body:**

```json
{
    "email": "admin@school.samschool.com",
    "password": "Password@12345"
}
```

**Response:**

```json
{
    "message": "Login successful",
    "user": {
        "id": 1,
        "name": "School Administrator",
        "email": "admin@school.samschool.com",
        "role": "school_admin"
    },
    "token": "1|abc123..."
}
```

### Get Current User

```http
GET /auth/me
```

**Headers:**

-   `Authorization: Bearer {token}`
-   `X-Subdomain: {school_subdomain}`

---

## Dashboard

### Get Dashboard Stats

```http
GET /dashboard/stats
```

**Headers:**

-   `Authorization: Bearer {token}`
-   `X-Subdomain: {school_subdomain}`

**Response:**

```json
{
    "stats": {
        "total_students": 250,
        "total_teachers": 30,
        "total_staff": 15,
        "total_classes": 12
    },
    "recent_activities": [],
    "upcoming_events": []
}
```

---

## Students Management

### List Students

```http
GET /students
```

**Query Parameters:**

-   `page` (optional): Page number
-   `per_page` (optional): Items per page
-   `search` (optional): Search term
-   `class_id` (optional): Filter by class
-   `status` (optional): Filter by status

**Response:**

```json
{
    "students": {
        "data": [
            {
                "id": 1,
                "first_name": "John",
                "last_name": "Doe",
                "email": "john.doe@school.com",
                "class": {
                    "id": 1,
                    "name": "Grade 10",
                    "arm": "A"
                },
                "status": "active"
            }
        ],
        "current_page": 1,
        "per_page": 15,
        "total": 250
    }
}
```

### Create Student

```http
POST /students
```

**Request Body:**

```json
{
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@school.com",
    "phone": "+1234567890",
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "class_id": 1,
    "arm": "A",
    "admission_number": "STU2025001",
    "admission_date": "2025-01-10",
    "status": "active"
}
```

### Update Student

```http
PUT /students/{id}
```

### Delete Student

```http
DELETE /students/{id}
```

### Student Analytics

```http
GET /students/analytics
```

---

## Teachers Management

### List Teachers

```http
GET /teachers
```

**Query Parameters:**

-   `page`, `per_page`, `search`, `status`

**Response:**

```json
{
    "teachers": {
        "data": [
            {
                "id": 1,
                "name": "Jane Smith",
                "email": "jane.smith@school.com",
                "phone": "+1234567890",
                "qualification": "MSc Education",
                "experience_years": 5,
                "subjects": [
                    {
                        "id": 1,
                        "name": "Mathematics"
                    }
                ],
                "classes": [
                    {
                        "id": 1,
                        "name": "Grade 10"
                    }
                ],
                "status": "active"
            }
        ]
    }
}
```

### Create Teacher

```http
POST /teachers
```

**Request Body:**

```json
{
    "name": "Jane Smith",
    "email": "jane.smith@school.com",
    "phone": "+1234567890",
    "qualification": "MSc Education",
    "experience_years": 5,
    "subject_ids": [1, 2, 3],
    "class_ids": [1, 2],
    "status": "active"
}
```

### Update Teacher

```http
PUT /teachers/{id}
```

### Delete Teacher

```http
DELETE /teachers/{id}
```

### Teacher Analytics

```http
GET /teachers/analytics
```

**Response:**

```json
{
    "total_teachers": 30,
    "active_teachers": 28,
    "on_leave": 2,
    "average_experience": 7.5,
    "by_qualification": {
        "PhD": 2,
        "MSc": 15,
        "BSc": 13
    }
}
```

---

## Staff Management

### List Staff

```http
GET /staff
```

**Response:**

```json
{
    "staff": {
        "data": [
            {
                "id": 1,
                "name": "John Admin",
                "email": "john.admin@school.com",
                "phone": "+1234567890",
                "role": "Accountant",
                "department": "Finance",
                "position": "Senior Accountant",
                "status": "active"
            }
        ]
    }
}
```

### Create Staff

```http
POST /staff
```

**Request Body:**

```json
{
    "name": "John Admin",
    "email": "john.admin@school.com",
    "phone": "+1234567890",
    "role": "Accountant",
    "department": "Finance",
    "position": "Senior Accountant",
    "status": "active"
}
```

### Update Staff

```http
PUT /staff/{id}
```

### Delete Staff

```http
DELETE /staff/{id}
```

---

## Parents/Guardians

### List Parents

```http
GET /parents
```

**Response:**

```json
{
    "parents": {
        "data": [
            {
                "id": 1,
                "name": "Mr. Parent",
                "email": "parent@email.com",
                "phone": "+1234567890",
                "students": [
                    {
                        "id": 1,
                        "name": "Student Name"
                    }
                ]
            }
        ]
    }
}
```

---

## Classes Management

### List Classes

```http
GET /classes
```

**Response:**

```json
{
    "classes": {
        "data": [
            {
                "id": 1,
                "name": "Grade 10",
                "level": "Secondary",
                "arms": ["A", "B", "C"],
                "capacity": 30,
                "class_teacher": {
                    "id": 1,
                    "name": "Teacher Name"
                },
                "student_count": 85
            }
        ]
    }
}
```

### Create Class

```http
POST /classes
```

**Request Body:**

```json
{
    "name": "Grade 10",
    "level": "Secondary",
    "arms": ["A", "B", "C"],
    "capacity": 30,
    "class_teacher_id": 1
}
```

### Update Class

```http
PUT /classes/{id}
```

### Delete Class

```http
DELETE /classes/{id}
```

---

## Subjects Management

### List Subjects

```http
GET /subjects
```

**Response:**

```json
{
    "subjects": {
        "data": [
            {
                "id": 1,
                "name": "Mathematics",
                "code": "MATH101",
                "description": "Advanced Mathematics",
                "teachers": [
                    {
                        "id": 1,
                        "name": "Teacher Name"
                    }
                ]
            }
        ]
    }
}
```

### Create Subject

```http
POST /subjects
```

**Request Body:**

```json
{
    "name": "Mathematics",
    "code": "MATH101",
    "description": "Advanced Mathematics",
    "teacher_ids": [1, 2]
}
```

### Update Subject

```http
PUT /subjects/{id}
```

### Delete Subject

```http
DELETE /subjects/{id}
```

---

## Departments

### List Departments

```http
GET /departments
```

**Response:**

```json
{
    "departments": {
        "data": [
            {
                "id": 1,
                "name": "Science Department",
                "head_of_department_id": 1,
                "subjects": []
            }
        ]
    }
}
```

---

## Academic Years & Terms

### List Academic Years

```http
GET /academic-years
```

**Response:**

```json
{
    "academic_years": {
        "data": [
            {
                "id": 1,
                "name": "2025/2026",
                "start_date": "2025-09-01",
                "end_date": "2026-06-30",
                "status": "active"
            }
        ]
    }
}
```

### Create Academic Year

```http
POST /academic-years
```

**Request Body:**

```json
{
    "name": "2025/2026",
    "start_date": "2025-09-01",
    "end_date": "2026-06-30",
    "status": "active"
}
```

### List Terms

```http
GET /terms
```

**Response:**

```json
{
    "terms": {
        "data": [
            {
                "id": 1,
                "name": "First Term",
                "academic_year_id": 1,
                "start_date": "2025-09-01",
                "end_date": "2025-12-15",
                "status": "active"
            }
        ]
    }
}
```

### Create Term

```http
POST /terms
```

---

## Timetable Management

### List Timetable

```http
GET /timetable
```

**Query Parameters:**

-   `class_id` (optional): Filter by class
-   `teacher_id` (optional): Filter by teacher
-   `day` (optional): Filter by day (Monday-Friday)

**Response:**

```json
{
    "timetable": {
        "data": [
            {
                "id": 1,
                "class_id": 1,
                "subject_id": 1,
                "teacher_id": 1,
                "day": "Monday",
                "start_time": "08:00",
                "end_time": "09:00",
                "room": "Room 101",
                "class": {
                    "name": "Grade 10 A"
                },
                "subject": {
                    "name": "Mathematics"
                },
                "teacher": {
                    "name": "Teacher Name"
                }
            }
        ]
    }
}
```

### Get Class Timetable

```http
GET /timetable/class/{classId}
```

### Get Teacher Timetable

```http
GET /timetable/teacher/{teacherId}
```

### Create Timetable Entry

```http
POST /timetable
```

**Request Body:**

```json
{
    "class_id": 1,
    "subject_id": 1,
    "teacher_id": 1,
    "day": "Monday",
    "start_time": "08:00",
    "end_time": "09:00",
    "room": "Room 101"
}
```

### Update Timetable

```http
PUT /timetable/{id}
```

### Delete Timetable

```http
DELETE /timetable/{id}
```

---

## Attendance Management

### List Attendance

```http
GET /attendance
```

**Query Parameters:**

-   `date` (optional): Filter by date
-   `class_id` (optional): Filter by class
-   `status` (optional): Filter by status (present/absent/late/excused)

**Response:**

```json
{
    "attendance": {
        "data": [
            {
                "id": 1,
                "attendanceable_type": "Student",
                "attendanceable_id": 1,
                "date": "2025-11-23",
                "status": "present",
                "check_in_time": "08:00:00",
                "remarks": null
            }
        ]
    }
}
```

### Student Attendance

```http
GET /attendance/students
```

### Teacher Attendance

```http
GET /attendance/teachers
```

### Mark Attendance

```http
POST /attendance/mark
```

**Request Body:**

```json
{
    "attendanceable_type": "Student",
    "attendanceable_id": 1,
    "date": "2025-11-23",
    "status": "present",
    "check_in_time": "08:00",
    "remarks": "On time"
}
```

### Attendance Reports

```http
GET /attendance/reports
```

**Query Parameters:**

-   `start_date`: Start date for report
-   `end_date`: End date for report
-   `class_id` (optional): Filter by class

**Response:**

```json
{
    "period": {
        "start_date": "2025-11-01",
        "end_date": "2025-11-30"
    },
    "summary": {
        "total_records": 500,
        "present_records": 450,
        "absent_records": 50,
        "attendance_rate": 90
    },
    "daily_breakdown": [],
    "top_absentees": []
}
```

### Get Class Attendance

```http
GET /attendance/class/{classId}
```

### Get Student Attendance

```http
GET /attendance/student/{studentId}
```

---

## Assignments

### List Assignments

```http
GET /assignments
```

**Response:**

```json
{
    "assignments": {
        "data": [
            {
                "id": 1,
                "title": "Math Assignment 1",
                "description": "Solve equations",
                "subject_id": 1,
                "class_id": 1,
                "teacher_id": 1,
                "due_date": "2025-12-01",
                "total_marks": 100,
                "assignment_type": "homework",
                "status": "published"
            }
        ]
    }
}
```

### Create Assignment

```http
POST /assignments
```

### Update Assignment

```http
PUT /assignments/{id}
```

### Delete Assignment

```http
DELETE /assignments/{id}
```

---

## Exams

### List Exams

```http
GET /exams
```

**Response:**

```json
{
    "exams": {
        "data": [
            {
                "id": 1,
                "title": "Mid-Term Exam",
                "subject_id": 1,
                "class_id": 1,
                "teacher_id": 1,
                "term_id": 1,
                "academic_year_id": 1,
                "start_date": "2025-12-01",
                "end_date": "2025-12-05",
                "total_marks": 100,
                "exam_type": "mid_term",
                "status": "scheduled"
            }
        ]
    }
}
```

### Create Exam

```http
POST /exams
```

### Update Exam

```http
PUT /exams/{id}
```

### Delete Exam

```http
DELETE /exams/{id}
```

---

## Results

### List Results

```http
GET /results
```

**Query Parameters:**

-   `student_id` (optional)
-   `class_id` (optional)
-   `term_id` (optional)
-   `academic_year_id` (optional)

**Response:**

```json
{
    "results": {
        "data": [
            {
                "id": 1,
                "student_id": 1,
                "exam_id": 1,
                "subject_id": 1,
                "score": 85,
                "total_marks": 100,
                "percentage": 85.0,
                "grade": "A"
            }
        ]
    }
}
```

### Create Result

```http
POST /results
```

### Update Result

```http
PUT /results/{id}
```

### Delete Result

```http
DELETE /results/{id}
```

---

## Announcements

### List Announcements

```http
GET /announcements
```

**Response:**

```json
{
    "announcements": {
        "data": [
            {
                "id": 1,
                "title": "School Holiday",
                "content": "School will be closed...",
                "type": "general",
                "status": "published",
                "priority": "normal",
                "created_at": "2025-11-23T10:00:00.000000Z"
            }
        ]
    }
}
```

### Create Announcement

```http
POST /announcements
```

**Request Body:**

```json
{
    "title": "School Holiday",
    "content": "School will be closed for winter break",
    "type": "general",
    "status": "draft",
    "priority": "normal"
}
```

### Update Announcement

```http
PUT /announcements/{id}
```

### Delete Announcement

```http
DELETE /announcements/{id}
```

### Publish Announcement

```http
POST /announcements/{id}/publish
```

---

## Transport Management

### List Transport Routes

```http
GET /transport/routes
```

**Response:**

```json
{
    "routes": {
        "data": [
            {
                "id": 1,
                "name": "Route 1",
                "description": "Main city route",
                "stops": ["Stop 1", "Stop 2", "Stop 3"],
                "vehicles": []
            }
        ]
    }
}
```

### Create Transport Route

```http
POST /transport/routes
```

**Request Body:**

```json
{
    "name": "Route 1",
    "description": "Main city route",
    "stops": ["Stop 1", "Stop 2", "Stop 3"]
}
```

### List Vehicles

```http
GET /transport/vehicles
```

**Response:**

```json
{
    "vehicles": {
        "data": [
            {
                "id": 1,
                "vehicle_number": "BUS001",
                "route_id": 1,
                "driver_id": 1,
                "capacity": 50,
                "vehicle_type": "Bus",
                "status": "active"
            }
        ]
    }
}
```

### Create Vehicle

```http
POST /transport/vehicles
```

**Request Body:**

```json
{
    "vehicle_number": "BUS001",
    "route_id": 1,
    "driver_id": 1,
    "capacity": 50,
    "vehicle_type": "Bus",
    "status": "active"
}
```

### List Drivers

```http
GET /transport/drivers
```

**Response:**

```json
{
    "drivers": {
        "data": [
            {
                "id": 1,
                "name": "Driver John",
                "phone": "+1234567890",
                "license_number": "DL123456",
                "status": "active"
            }
        ]
    }
}
```

### Create Driver

```http
POST /transport/drivers
```

**Request Body:**

```json
{
    "name": "Driver John",
    "phone": "+1234567890",
    "license_number": "DL123456",
    "status": "active"
}
```

### Assign Student to Route

```http
POST /transport/assign
```

**Request Body:**

```json
{
    "student_id": 1,
    "route_id": 1,
    "pickup_stop": "Stop 1",
    "dropoff_stop": "Stop 3"
}
```

---

## Houses System

### List Houses

```http
GET /houses
```

**Response:**

```json
{
    "houses": {
        "data": [
            {
                "id": 1,
                "name": "Red House",
                "color": "#FF0000",
                "description": "The Red House",
                "points": 150,
                "member_count": 50
            }
        ]
    }
}
```

### Create House

```http
POST /houses
```

**Request Body:**

```json
{
    "name": "Red House",
    "color": "#FF0000",
    "description": "The Red House",
    "points": 0
}
```

### Update House

```http
PUT /houses/{id}
```

### Delete House

```http
DELETE /houses/{id}
```

### Get House Members

```http
GET /houses/{id}/members
```

### Get House Points

```http
GET /houses/{id}/points
```

### Add House Points

```http
POST /houses/{id}/points
```

**Request Body:**

```json
{
    "points": 10,
    "reason": "Sports competition win"
}
```

---

## Sports Management

### List Sports Activities

```http
GET /sports/activities
```

**Response:**

```json
{
    "activities": {
        "data": [
            {
                "id": 1,
                "name": "Football",
                "description": "School football team",
                "category": "Team Sport",
                "coach_id": 1,
                "schedule": "Monday, Wednesday, Friday"
            }
        ]
    }
}
```

### Create Sports Activity

```http
POST /sports/activities
```

**Request Body:**

```json
{
    "name": "Football",
    "description": "School football team",
    "category": "Team Sport",
    "coach_id": 1,
    "schedule": "Monday, Wednesday, Friday"
}
```

### List Sports Teams

```http
GET /sports/teams
```

**Response:**

```json
{
    "teams": {
        "data": [
            {
                "id": 1,
                "name": "Junior Football Team",
                "sport": "Football",
                "coach_id": 1,
                "members": []
            }
        ]
    }
}
```

### Create Sports Team

```http
POST /sports/teams
```

**Request Body:**

```json
{
    "name": "Junior Football Team",
    "sport": "Football",
    "coach_id": 1,
    "member_ids": [1, 2, 3]
}
```

### List Sports Events

```http
GET /sports/events
```

**Response:**

```json
{
    "events": {
        "data": [
            {
                "id": 1,
                "name": "Inter-House Football Match",
                "description": "Annual football match",
                "sport": "Football",
                "date": "2025-12-01",
                "venue": "School Field",
                "teams": []
            }
        ]
    }
}
```

### Create Sports Event

```http
POST /sports/events
```

**Request Body:**

```json
{
    "name": "Inter-House Football Match",
    "description": "Annual football match",
    "sport": "Football",
    "date": "2025-12-01",
    "venue": "School Field",
    "team_ids": [1, 2]
}
```

---

## Inventory Management

### List Inventory Categories

```http
GET /inventory/categories
```

**Response:**

```json
{
    "categories": {
        "data": [
            {
                "id": 1,
                "name": "Electronics",
                "description": "Electronic items",
                "item_count": 25
            }
        ]
    }
}
```

### Create Inventory Category

```http
POST /inventory/categories
```

**Request Body:**

```json
{
    "name": "Electronics",
    "description": "Electronic items"
}
```

### List Inventory Items

```http
GET /inventory/items
```

**Response:**

```json
{
    "items": {
        "data": [
            {
                "id": 1,
                "name": "Laptop",
                "description": "Dell Laptop",
                "category_id": 1,
                "quantity": 10,
                "unit": "pieces",
                "min_stock_level": 2,
                "location": "Store Room 1",
                "category": {
                    "name": "Electronics"
                }
            }
        ]
    }
}
```

### Create Inventory Item

```http
POST /inventory/items
```

**Request Body:**

```json
{
    "name": "Laptop",
    "description": "Dell Laptop",
    "category_id": 1,
    "quantity": 10,
    "unit": "pieces",
    "min_stock_level": 2,
    "location": "Store Room 1"
}
```

### Update Inventory Item

```http
PUT /inventory/items/{id}
```

### Delete Inventory Item

```http
DELETE /inventory/items/{id}
```

### List Inventory Transactions

```http
GET /inventory/transactions
```

### Checkout Item

```http
POST /inventory/checkout
```

**Request Body:**

```json
{
    "item_id": 1,
    "quantity": 2,
    "checkout_to": "Teacher Name",
    "purpose": "Lab use",
    "expected_return_date": "2025-12-01"
}
```

### Return Item

```http
POST /inventory/return
```

**Request Body:**

```json
{
    "transaction_id": 1,
    "quantity": 2,
    "condition": "good",
    "remarks": "Returned in good condition"
}
```

---

## Library Management

### List Library Books

```http
GET /library/books
```

**Response:**

```json
{
    "books": {
        "data": [
            {
                "id": 1,
                "title": "Mathematics Textbook",
                "author": "John Author",
                "isbn": "978-1234567890",
                "publisher": "Education Press",
                "publication_year": 2025,
                "total_copies": 50,
                "available_copies": 45,
                "genre": "Education"
            }
        ]
    }
}
```

### List Library Borrows

```http
GET /library/borrows
```

**Response:**

```json
{
    "borrows": {
        "data": [
            {
                "id": 1,
                "library_book_id": 1,
                "borrower_type": "Student",
                "borrower_id": 1,
                "borrow_date": "2025-11-01",
                "due_date": "2025-11-15",
                "return_date": null,
                "status": "borrowed"
            }
        ]
    }
}
```

---

## Finance Management

### List Fees

```http
GET /finance/fees
```

**Response:**

```json
{
    "fees": {
        "data": [
            {
                "id": 1,
                "student_id": 1,
                "fee_type": "Tuition",
                "amount": 5000.0,
                "due_date": "2025-12-01",
                "status": "pending"
            }
        ]
    }
}
```

### List Payments

```http
GET /finance/payments
```

**Response:**

```json
{
    "payments": {
        "data": [
            {
                "id": 1,
                "student_id": 1,
                "fee_id": 1,
                "amount": 5000.0,
                "payment_date": "2025-11-20",
                "payment_method": "bank_transfer",
                "reference": "PAY123456"
            }
        ]
    }
}
```

### List Expenses

```http
GET /finance/expenses
```

**Response:**

```json
{
    "expenses": {
        "data": [
            {
                "id": 1,
                "category": "Utilities",
                "description": "Electricity bill",
                "amount": 1000.0,
                "expense_date": "2025-11-01",
                "paid_by": "Admin"
            }
        ]
    }
}
```

### Finance Reports

```http
GET /finance/reports
```

**Query Parameters:**

-   `start_date`: Start date
-   `end_date`: End date

**Response:**

```json
{
    "period": {
        "start_date": "2025-11-01",
        "end_date": "2025-11-30"
    },
    "summary": {
        "total_fees": 50000.0,
        "total_payments": 45000.0,
        "pending_fees": 5000.0
    },
    "breakdown": {
        "by_month": [],
        "by_category": []
    }
}
```

---

## Communication

### List Notifications

```http
GET /notifications
```

**Response:**

```json
{
    "notifications": {
        "data": [
            {
                "id": "uuid-1",
                "type": "App\\Notifications\\AnnouncementNotification",
                "notifiable_type": "App\\Models\\User",
                "notifiable_id": 1,
                "data": {
                    "message": "New announcement posted"
                },
                "read_at": null,
                "created_at": "2025-11-23T10:00:00.000000Z"
            }
        ]
    }
}
```

### List Messages

```http
GET /messages
```

**Response:**

```json
{
    "messages": {
        "data": [
            {
                "id": 1,
                "sender_id": 1,
                "receiver_id": 2,
                "subject": "Meeting Schedule",
                "body": "Let's meet tomorrow",
                "read_at": null,
                "created_at": "2025-11-23T10:00:00.000000Z"
            }
        ]
    }
}
```

---

## Reports

### Academic Reports

```http
GET /reports/academic
```

**Query Parameters:**

-   `start_date`: Start date
-   `end_date`: End date

**Response:**

```json
{
    "report": {
        "period": {
            "start_date": "2025-01-01",
            "end_date": "2025-12-31"
        },
        "summary": {
            "total_students": 250,
            "total_teachers": 30,
            "total_classes": 12,
            "total_subjects": 15
        },
        "performance": {
            "average_score": 75.5,
            "pass_rate": 85
        },
        "exams": []
    }
}
```

### Financial Reports

```http
GET /reports/financial
```

**Query Parameters:**

-   `start_date`: Start date
-   `end_date`: End date

**Response:**

```json
{
    "report": {
        "period": {
            "start_date": "2025-11-01",
            "end_date": "2025-11-30"
        },
        "summary": {
            "total_fees": 50000.0,
            "total_payments": 45000.0,
            "pending_fees": 5000.0
        },
        "breakdown": {
            "by_month": [],
            "by_category": []
        }
    }
}
```

---

## Settings

### Get Settings

```http
GET /settings
```

**Response:**

```json
{
    "settings": {
        "school_name": "Test School",
        "timezone": "America/New_York",
        "academic_year": "2025/2026",
        "currency": "USD"
    }
}
```

### Get School Settings

```http
GET /settings/school
```

### Update Settings

```http
PUT /settings
```

**Request Body:**

```json
{
    "key": "value"
}
```

### Update School Settings

```http
PUT /settings/school
```

**Request Body:**

```json
{
    "school_name": "Updated School Name",
    "timezone": "America/New_York",
    "academic_year": "2025/2026",
    "currency": "USD"
}
```

### Get Settings Modules

```http
GET /settings/modules
```

**Response:**

```json
{
    "modules": [
        {
            "id": 1,
            "name": "Attendance Management",
            "slug": "attendance_management",
            "enabled": true
        },
        {
            "id": 2,
            "name": "Finance Management",
            "slug": "finance_management",
            "enabled": true
        }
    ]
}
```

---

## Subscription

### Get Subscription Status

```http
GET /subscription/status
```

**Response:**

```json
{
    "subscription": {
        "plan": {
            "id": 1,
            "name": "Premium Plan",
            "price": 99.99
        },
        "status": "active",
        "start_date": "2025-01-01",
        "end_date": "2026-01-01",
        "modules": [
            {
                "name": "Attendance Management",
                "enabled": true
            }
        ]
    }
}
```

---

## Error Responses

### Standard Error Response

```json
{
    "error": "Error title",
    "message": "Detailed error message"
}
```

### Validation Error Response

```json
{
    "error": "Validation failed",
    "messages": {
        "email": ["The email field is required."],
        "name": ["The name field is required."]
    }
}
```

---

## HTTP Status Codes

-   `200 OK` - Request succeeded
-   `201 Created` - Resource created successfully
-   `400 Bad Request` - Invalid request
-   `401 Unauthorized` - Authentication required
-   `403 Forbidden` - Insufficient permissions
-   `404 Not Found` - Resource not found
-   `422 Unprocessable Entity` - Validation failed
-   `500 Internal Server Error` - Server error

---

## Authentication Flow

1. **Login**: POST `/auth/login` with email and password
2. **Receive Token**: Get bearer token from response
3. **Use Token**: Include token in `Authorization: Bearer {token}` header
4. **Include Subdomain**: Include `X-Subdomain: {school_subdomain}` header for tenant-specific requests

---

## Testing

All APIs have been tested and verified working on production environment (api.compasse.net).

**Test Results:**

-   ✅ 32/32 Admin APIs: 100% Pass Rate
-   ✅ Multi-tenancy working correctly
-   ✅ Authentication working correctly
-   ✅ All CRUD operations functional

---

## Notes

1. All tenant-specific APIs require the `X-Subdomain` header
2. All authenticated APIs require the `Authorization: Bearer {token}` header
3. Pagination is available on list endpoints (default: 15 items per page)
4. Search and filtering are available on most list endpoints
5. All timestamps are in UTC and ISO 8601 format
6. Soft deletes are implemented on most resources

---

**End of Documentation**
