# Parent/Guardian Dashboard API Documentation

Complete API reference for Parent/Guardian Dashboard functionality.

---

## Table of Contents

1. [Dashboard Overview](#dashboard-overview)
2. [My Children](#my-children)
3. [Child Performance](#child-performance)
4. [Attendance Monitoring](#attendance-monitoring)
5. [Assignments & Homework](#assignments--homework)
6. [Exam Results](#exam-results)
7. [Fee Management](#fee-management)
8. [Communication](#communication)
9. [Profile Management](#profile-management)

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

### Get Parent Dashboard

**Endpoint:** `GET /api/v1/dashboard/parent`

**Response (200):**
```json
{
  "user": {
    "id": 15,
    "name": "Mrs. Jane Doe",
    "email": "jane@example.com",
    "role": "guardian",
    "profile_picture": "https://..."
  },
  "guardian": {
    "id": 1,
    "first_name": "Jane",
    "last_name": "Doe",
    "phone": "+234809876543",
    "occupation": "Engineer",
    "relationship": "Mother"
  },
  "children": [
    {
      "id": 1,
      "name": "John Doe",
      "admission_number": "ADM001",
      "class": "JSS 1A",
      "profile_picture": "https://...",
      "stats": {
        "average_score": 78.5,
        "attendance_rate": 95.5,
        "rank": 5,
        "pending_assignments": 3
      }
    },
    {
      "id": 2,
      "name": "Mary Doe",
      "admission_number": "ADM002",
      "class": "JSS 3B",
      "profile_picture": "https://...",
      "stats": {
        "average_score": 85.2,
        "attendance_rate": 98.0,
        "rank": 2,
        "pending_assignments": 1
      }
    }
  ],
  "stats": {
    "total_children": 2,
    "upcoming_events": 3,
    "pending_fees": 50000,
    "unread_messages": 2
  },
  "role": "parent"
}
```

---

## My Children

### Get All My Children

**Endpoint:** `GET /api/v1/guardians/me/children`

**Response (200):**
```json
{
  "guardian": {
    "id": 1,
    "name": "Jane Doe"
  },
  "children": [
    {
      "id": 1,
      "admission_number": "ADM001",
      "first_name": "John",
      "last_name": "Doe",
      "email": "john.doe1@westwoodschool.com",
      "class": {
        "id": 1,
        "name": "JSS 1A",
        "class_teacher": "Mr. Johnson"
      },
      "arm": {
        "id": 1,
        "name": "A"
      },
      "performance_summary": {
        "average_score": 78.5,
        "rank": 5,
        "total_students": 30,
        "attendance_rate": 95.5
      }
    }
  ]
}
```

### Get Child Details

**Endpoint:** `GET /api/v1/students/{child_id}`

**Response (200):**
```json
{
  "id": 1,
  "admission_number": "ADM001",
  "first_name": "John",
  "last_name": "Doe",
  "email": "john.doe1@westwoodschool.com",
  "phone": "+234801234567",
  "class": {...},
  "arm": {...},
  "guardians": [...],
  "performance_summary": {
    "average_score": 78.5,
    "rank": 5,
    "attendance_rate": 95.5
  }
}
```

---

## Child Performance

### Get Child's Grades

**Endpoint:** `GET /api/v1/grades/student/{child_id}`

**Query Parameters:**
- `subject_id` - Filter by subject
- `term_id` - Filter by term
- `academic_year_id` - Filter by academic year

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "admission_number": "ADM001",
    "class": "JSS 1A"
  },
  "grades": [
    {
      "subject": "Mathematics",
      "exam_type": "Mid-Term",
      "marks": 85,
      "total_marks": 100,
      "percentage": 85,
      "grade": "A",
      "position": 2,
      "class_average": 72,
      "teacher_remarks": "Excellent performance"
    },
    {
      "subject": "English",
      "exam_type": "Mid-Term",
      "marks": 78,
      "total_marks": 100,
      "percentage": 78,
      "grade": "B+",
      "position": 5,
      "class_average": 70
    }
  ],
  "summary": {
    "overall_average": 78.5,
    "overall_grade": "B+",
    "overall_position": 5,
    "total_students": 30,
    "total_subjects": 8
  },
  "term": {
    "id": 1,
    "name": "First Term",
    "academic_year": "2025/2026"
  }
}
```

### Get Subject Performance

**Endpoint:** `GET /api/v1/grades/student/{child_id}/subject/{subject_id}`

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe"
  },
  "subject": {
    "id": 1,
    "name": "Mathematics",
    "teacher": "Mr. Johnson"
  },
  "performance": [
    {
      "exam": "CA1",
      "date": "2025-10-15",
      "score": 18,
      "total": 20,
      "percentage": 90,
      "grade": "A"
    },
    {
      "exam": "CA2",
      "date": "2025-11-10",
      "score": 17,
      "total": 20,
      "percentage": 85,
      "grade": "A"
    },
    {
      "exam": "Mid-Term",
      "date": "2025-12-01",
      "score": 85,
      "total": 100,
      "percentage": 85,
      "grade": "A"
    }
  ],
  "statistics": {
    "average": 86.67,
    "trend": "improving",
    "class_rank": 2,
    "highest_score": 92,
    "lowest_score": 85
  }
}
```

---

## Attendance Monitoring

### Get Child's Attendance

**Endpoint:** `GET /api/v1/attendance/student/{child_id}`

**Query Parameters:**
- `from` - Start date (YYYY-MM-DD)
- `to` - End date (YYYY-MM-DD)
- `per_page` - Items per page

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "admission_number": "ADM001",
    "class": "JSS 1A"
  },
  "attendance": [
    {
      "date": "2025-11-26",
      "day": "Wednesday",
      "status": "present",
      "check_in_time": "08:00:00"
    },
    {
      "date": "2025-11-25",
      "day": "Tuesday",
      "status": "present",
      "check_in_time": "07:55:00"
    },
    {
      "date": "2025-11-24",
      "day": "Monday",
      "status": "late",
      "check_in_time": "08:30:00",
      "notes": "Traffic delay"
    },
    {
      "date": "2025-11-23",
      "day": "Sunday",
      "status": "absent",
      "notes": "Sick",
      "excused": true
    }
  ],
  "summary": {
    "total_days": 20,
    "present": 18,
    "absent": 1,
    "late": 1,
    "excused_absences": 1,
    "attendance_rate": 95,
    "punctuality_rate": 90
  },
  "period": {
    "from": "2025-11-01",
    "to": "2025-11-26"
  }
}
```

---

## Assignments & Homework

### Get Child's Assignments

**Endpoint:** `GET /api/v1/assessments/assignments/student/{child_id}`

**Query Parameters:**
- `status` - Filter by status (pending, submitted, graded, late)
- `subject_id` - Filter by subject
- `per_page` - Items per page

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "class": "JSS 1A"
  },
  "assignments": [
    {
      "id": 1,
      "title": "Algebra Homework",
      "description": "Solve problems 1-10",
      "subject": "Mathematics",
      "teacher": "Mr. Johnson",
      "due_date": "2025-12-10 23:59:59",
      "total_marks": 20,
      "status": "pending",
      "submission": null,
      "days_remaining": 14
    },
    {
      "id": 2,
      "title": "Essay on Climate Change",
      "subject": "English",
      "teacher": "Mrs. Smith",
      "due_date": "2025-12-05 23:59:59",
      "total_marks": 30,
      "status": "graded",
      "submission": {
        "submitted_at": "2025-12-04 15:30:00",
        "marks": 25,
        "grade": "B+",
        "feedback": "Well written! Great analysis.",
        "on_time": true
      }
    }
  ],
  "summary": {
    "total": 10,
    "pending": 3,
    "submitted": 5,
    "graded": 2,
    "late": 0,
    "average_score": 85
  }
}
```

### Get Assignment Details

**Endpoint:** `GET /api/v1/assessments/assignments/{assignment_id}/student/{child_id}`

---

## Exam Results

### Get Child's Exams

**Endpoint:** `GET /api/v1/assessments/exams/student/{child_id}`

**Query Parameters:**
- `status` - Filter by status (upcoming, completed)
- `subject_id` - Filter by subject
- `term_id` - Filter by term

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "class": "JSS 1A"
  },
  "exams": [
    {
      "id": 1,
      "title": "Mathematics Mid-Term",
      "subject": "Mathematics",
      "exam_type": "mid_term",
      "date": "2025-12-01",
      "start_time": "09:00:00",
      "end_time": "11:00:00",
      "total_marks": 100,
      "is_cbt": true,
      "status": "upcoming"
    },
    {
      "id": 2,
      "title": "English Language Test",
      "subject": "English",
      "date": "2025-11-20",
      "total_marks": 100,
      "status": "completed",
      "result": {
        "marks": 85,
        "percentage": 85,
        "grade": "A",
        "position": 2,
        "total_students": 30,
        "class_average": 72,
        "teacher_remarks": "Excellent work"
      }
    }
  ]
}
```

### Get Exam Result

**Endpoint:** `GET /api/v1/assessments/exams/{exam_id}/student/{child_id}`

---

## Fee Management

### Get Child's Fee Status

**Endpoint:** `GET /api/v1/financial/fees/student/{child_id}`

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "admission_number": "ADM001",
    "class": "JSS 1A"
  },
  "fees": [
    {
      "id": 1,
      "name": "Tuition Fee",
      "amount": 150000,
      "paid": 150000,
      "balance": 0,
      "status": "paid",
      "due_date": "2025-10-01",
      "paid_date": "2025-09-25"
    },
    {
      "id": 2,
      "name": "Second Term Fee",
      "amount": 150000,
      "paid": 100000,
      "balance": 50000,
      "status": "partial",
      "due_date": "2026-01-15"
    },
    {
      "id": 3,
      "name": "Extra-Curricular Activities",
      "amount": 25000,
      "paid": 0,
      "balance": 25000,
      "status": "unpaid",
      "due_date": "2025-12-15"
    }
  ],
  "summary": {
    "total_fees": 325000,
    "total_paid": 250000,
    "total_balance": 75000,
    "next_due_date": "2025-12-15"
  }
}
```

### Get Payment History

**Endpoint:** `GET /api/v1/financial/payments/student/{child_id}`

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe"
  },
  "payments": [
    {
      "id": 1,
      "amount": 150000,
      "payment_method": "bank_transfer",
      "reference": "PAY2025001",
      "paid_date": "2025-09-25",
      "fee": "Tuition Fee",
      "receipt_url": "https://..."
    },
    {
      "id": 2,
      "amount": 100000,
      "payment_method": "card",
      "reference": "PAY2025045",
      "paid_date": "2025-11-10",
      "fee": "Second Term Fee (Partial)",
      "receipt_url": "https://..."
    }
  ],
  "total_paid": 250000
}
```

### Make Payment

**Endpoint:** `POST /api/v1/financial/fees/{fee_id}/pay`

**Request Body:**
```json
{
  "student_id": 1,
  "amount": 50000,
  "payment_method": "card",
  "card_details": {
    "number": "4111111111111111",
    "exp_month": "12",
    "exp_year": "2026",
    "cvv": "123"
  }
}
```

---

## Communication

### Get My Messages

**Endpoint:** `GET /api/v1/messages/my-messages`

**Response (200):**
```json
{
  "messages": [
    {
      "id": 1,
      "from": "Mr. Johnson",
      "role": "teacher",
      "subject": "Assignment Reminder",
      "message": "Please remind John to submit the homework",
      "sent_at": "2025-11-25 10:00:00",
      "read": false,
      "regarding_student": "John Doe"
    }
  ],
  "unread_count": 1
}
```

### Send Message to Teacher

**Endpoint:** `POST /api/v1/messages/send`

**Request Body:**
```json
{
  "recipient_id": 5,
  "recipient_type": "teacher",
  "subject": "Request for Meeting",
  "message": "I would like to discuss John's progress",
  "regarding_student_id": 1
}
```

### Get Announcements

**Endpoint:** `GET /api/v1/announcements/for-parents`

**Response (200):**
```json
{
  "announcements": [
    {
      "id": 1,
      "title": "Parent-Teacher Meeting",
      "content": "All parents are invited to the meeting on Dec 15",
      "priority": "high",
      "created_at": "2025-11-20 09:00:00",
      "expires_at": "2025-12-15 23:59:59"
    }
  ]
}
```

---

## Profile Management

### Get My Profile

**Endpoint:** `GET /api/v1/guardians/me`

**Response (200):**
```json
{
  "id": 1,
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "phone": "+234809876543",
  "address": "123 Main St",
  "occupation": "Engineer",
  "relationship": "Mother",
  "is_primary": true,
  "children": [...]
}
```

### Update My Profile

**Endpoint:** `PUT /api/v1/guardians/me`

**Request Body:**
```json
{
  "phone": "+234809876543",
  "address": "New address",
  "occupation": "Senior Engineer"
}
```

### Upload Profile Picture

**Endpoint:** `POST /api/v1/users/me/profile-picture`

**Request Body:**
```json
{
  "profile_picture": "https://s3.amazonaws.com/.../photo.jpg"
}
```

### Delete Profile Picture

**Endpoint:** `DELETE /api/v1/users/me/profile-picture`

---

## Summary

### Parent/Guardian Can:
✅ View personalized dashboard with all children's stats  
✅ Monitor all children's academic performance  
✅ Track attendance and punctuality  
✅ View assignments and homework status  
✅ Access exam results and report cards  
✅ View and pay school fees  
✅ Check payment history  
✅ Communicate with teachers and school  
✅ Receive important announcements  
✅ Update profile and upload profile picture  

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

