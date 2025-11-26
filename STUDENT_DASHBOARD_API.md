# Student Dashboard API Documentation

Complete API reference for Student Dashboard functionality.

---

## Table of Contents

1. [Dashboard Overview](#dashboard-overview)
2. [My Profile](#my-profile)
3. [My Classes & Subjects](#my-classes--subjects)
4. [Assignments](#assignments)
5. [Exams & CBT](#exams--cbt)
6. [My Grades & Results](#my-grades--results)
7. [My Attendance](#my-attendance)
8. [My Timetable](#my-timetable)
9. [My Guardians](#my-guardians)
10. [Communication](#communication)
11. [Profile Management](#profile-management)

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

### Get Student Dashboard

**Endpoint:** `GET /api/v1/dashboard/student`

**Response (200):**
```json
{
  "user": {
    "id": 10,
    "name": "John Doe",
    "email": "john.doe1@westwoodschool.com",
    "role": "student",
    "profile_picture": "https://..."
  },
  "student": {
    "id": 1,
    "admission_number": "ADM001",
    "first_name": "John",
    "last_name": "Doe",
    "class": {
      "id": 1,
      "name": "JSS 1A"
    },
    "arm": {
      "id": 1,
      "name": "A"
    }
  },
  "stats": {
    "my_class": {
      "id": 1,
      "name": "JSS 1A",
      "class_teacher": "Mr. Johnson"
    },
    "my_subjects": 8,
    "pending_assignments": 3,
    "upcoming_exams": 2,
    "recent_grades": [
      {
        "subject": "Mathematics",
        "score": 85,
        "grade": "A"
      }
    ],
    "attendance_rate": 95.5
  },
  "role": "student"
}
```

---

## My Profile

### Get My Profile

**Endpoint:** `GET /api/v1/students/me`

**Response (200):**
```json
{
  "id": 1,
  "admission_number": "ADM001",
  "first_name": "John",
  "last_name": "Doe",
  "middle_name": "Smith",
  "email": "john.doe1@westwoodschool.com",
  "phone": "+234801234567",
  "date_of_birth": "2010-05-15",
  "gender": "male",
  "address": "123 Main St",
  "blood_group": "O+",
  "class": {
    "id": 1,
    "name": "JSS 1A",
    "class_teacher": {...}
  },
  "arm": {
    "id": 1,
    "name": "A"
  },
  "guardians": [
    {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "phone": "+234809876543",
      "relationship": "Mother",
      "is_primary": true
    }
  ],
  "performance_summary": {
    "average_score": 78.5,
    "rank": 5,
    "total_students": 30,
    "attendance_rate": 95.5
  }
}
```

### Update My Profile

**Endpoint:** `PUT /api/v1/students/me`

**Request Body:**
```json
{
  "phone": "+234801234567",
  "address": "New address",
  "emergency_contact": "+234809876543"
}
```

**Note:** Students can only update limited fields (phone, address, emergency contact).

---

## My Classes & Subjects

### Get My Class

**Endpoint:** `GET /api/v1/classes/my-class`

**Response (200):**
```json
{
  "id": 1,
  "name": "JSS 1A",
  "description": "Junior Secondary School 1A",
  "capacity": 30,
  "students_count": 28,
  "class_teacher": {
    "id": 1,
    "name": "Mr. Johnson",
    "email": "johnson@westwoodschool.com",
    "phone": "+234801234567"
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "code": "MATH101",
      "teacher": {...}
    }
  ]
}
```

### Get My Subjects

**Endpoint:** `GET /api/v1/subjects/my-subjects`

**Response (200):**
```json
[
  {
    "id": 1,
    "name": "Mathematics",
    "code": "MATH101",
    "description": "General Mathematics",
    "teacher": {
      "name": "Mr. Johnson",
      "email": "johnson@westwoodschool.com",
      "phone": "+234801234567"
    },
    "my_performance": {
      "average_score": 82.5,
      "total_assignments": 5,
      "completed_assignments": 5,
      "total_exams": 2,
      "exam_average": 85
    }
  }
]
```

---

## Assignments

### Get My Assignments

**Endpoint:** `GET /api/v1/assessments/assignments/my-assignments`

**Query Parameters:**
- `status` - Filter by status (pending, submitted, graded, late)
- `subject_id` - Filter by subject
- `per_page` - Items per page (default: 15)

**Response (200):**
```json
{
  "assignments": [
    {
      "id": 1,
      "title": "Algebra Homework",
      "description": "Solve problems 1-10",
      "subject": {
        "id": 1,
        "name": "Mathematics"
      },
      "teacher": {
        "name": "Mr. Johnson"
      },
      "due_date": "2025-12-10 23:59:59",
      "total_marks": 20,
      "status": "pending",
      "submission": null,
      "attachments": [
        {
          "name": "questions.pdf",
          "url": "https://..."
        }
      ]
    },
    {
      "id": 2,
      "title": "Essay on Climate Change",
      "subject": {
        "id": 2,
        "name": "English"
      },
      "due_date": "2025-12-05 23:59:59",
      "total_marks": 30,
      "status": "graded",
      "submission": {
        "submitted_at": "2025-12-04 15:30:00",
        "marks": 25,
        "grade": "B+",
        "feedback": "Well written! Great analysis."
      }
    }
  ],
  "summary": {
    "total": 10,
    "pending": 3,
    "submitted": 5,
    "graded": 2,
    "late": 0
  }
}
```

### Get Assignment Details

**Endpoint:** `GET /api/v1/assessments/assignments/{id}`

### Submit Assignment

**Endpoint:** `POST /api/v1/assessments/assignments/{id}/submit`

**Request Body:**
```json
{
  "content": "My answer here...",
  "attachments": [
    {"name": "homework.pdf", "url": "https://..."}
  ]
}
```

**Response (201):**
```json
{
  "message": "Assignment submitted successfully",
  "submission": {
    "id": 1,
    "assignment_id": 1,
    "student_id": 10,
    "submitted_at": "2025-12-09 15:30:00",
    "status": "submitted",
    "content": "My answer...",
    "attachments": [...]
  }
}
```

---

## Exams & CBT

### Get My Exams

**Endpoint:** `GET /api/v1/assessments/exams/my-exams`

**Query Parameters:**
- `status` - Filter by status (upcoming, ongoing, completed)
- `subject_id` - Filter by subject
- `per_page` - Items per page

**Response (200):**
```json
{
  "exams": [
    {
      "id": 1,
      "title": "Mathematics Mid-Term",
      "exam_code": "EXAM2025001",
      "subject": {
        "id": 1,
        "name": "Mathematics"
      },
      "exam_type": "mid_term",
      "start_date": "2025-12-01 09:00:00",
      "end_date": "2025-12-01 11:00:00",
      "duration_minutes": 120,
      "total_marks": 100,
      "is_cbt": true,
      "status": "upcoming",
      "my_result": null
    },
    {
      "id": 2,
      "title": "English Language Test",
      "subject": {
        "id": 2,
        "name": "English"
      },
      "start_date": "2025-11-20 09:00:00",
      "status": "completed",
      "my_result": {
        "marks": 85,
        "grade": "A",
        "position": 2,
        "total_students": 30
      }
    }
  ]
}
```

### Start CBT Exam

**Endpoint:** `POST /api/v1/assessments/cbt/{exam_id}/start`

**Response (201):**
```json
{
  "message": "Exam session started",
  "session": {
    "id": "sess_12345",
    "exam": {
      "id": 1,
      "title": "Mathematics Mid-Term",
      "duration_minutes": 120,
      "total_marks": 100
    },
    "started_at": "2025-12-01 09:00:00",
    "ends_at": "2025-12-01 11:00:00",
    "time_remaining": 7200
  }
}
```

### Get CBT Questions

**Endpoint:** `GET /api/v1/assessments/cbt/{exam_id}/questions`

**Response (200):**
```json
{
  "session_id": "sess_12345",
  "exam": {
    "id": 1,
    "title": "Mathematics Mid-Term",
    "total_marks": 100
  },
  "questions": [
    {
      "id": 1,
      "question": "What is 2 + 2?",
      "question_type": "multiple_choice",
      "options": [
        {"key": "A", "value": "3"},
        {"key": "B", "value": "4"},
        {"key": "C", "value": "5"},
        {"key": "D", "value": "6"}
      ],
      "marks": 2
    },
    {
      "id": 2,
      "question": "The earth is flat",
      "question_type": "true_false",
      "options": [
        {"key": "A", "value": "True"},
        {"key": "B", "value": "False"}
      ],
      "marks": 1
    }
  ],
  "total_questions": 50,
  "time_remaining": 7140
}
```

### Submit CBT Answers

**Endpoint:** `POST /api/v1/assessments/cbt/submit`

**Request Body:**
```json
{
  "session_id": "sess_12345",
  "exam_id": 1,
  "answers": [
    {
      "question_id": 1,
      "answer": ["B"]
    },
    {
      "question_id": 2,
      "answer": ["false"]
    }
  ]
}
```

**Response (200):**
```json
{
  "message": "Exam submitted successfully",
  "result": {
    "exam": {
      "id": 1,
      "title": "Mathematics Mid-Term"
    },
    "total_questions": 50,
    "answered": 48,
    "unanswered": 2,
    "score": 85,
    "total_marks": 100,
    "percentage": 85,
    "grade": "A",
    "status": "graded",
    "position": 2,
    "total_students": 30
  }
}
```

### View Exam Result

**Endpoint:** `GET /api/v1/assessments/exams/{id}/my-result`

---

## My Grades & Results

### Get All My Grades

**Endpoint:** `GET /api/v1/grades/student/me`

**Query Parameters:**
- `subject_id` - Filter by subject
- `term_id` - Filter by term
- `academic_year_id` - Filter by academic year
- `exam_type` - Filter by exam type

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
      "remarks": "Excellent performance"
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
  }
}
```

### Get Subject Performance

**Endpoint:** `GET /api/v1/grades/student/me/subject/{subject_id}`

**Response (200):**
```json
{
  "subject": {
    "id": 1,
    "name": "Mathematics"
  },
  "performance": [
    {
      "exam": "CA1",
      "score": 18,
      "total": 20,
      "percentage": 90,
      "grade": "A"
    },
    {
      "exam": "CA2",
      "score": 17,
      "total": 20,
      "percentage": 85,
      "grade": "A"
    },
    {
      "exam": "Mid-Term",
      "score": 85,
      "total": 100,
      "percentage": 85,
      "grade": "A"
    }
  ],
  "average": 86.67,
  "trend": "improving",
  "class_rank": 2,
  "highest_score": 92,
  "lowest_score": 85
}
```

---

## My Attendance

### Get My Attendance

**Endpoint:** `GET /api/v1/attendance/student/me`

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
      "check_in_time": "08:30:00"
    },
    {
      "date": "2025-11-23",
      "day": "Sunday",
      "status": "absent",
      "notes": "Sick"
    }
  ],
  "summary": {
    "total_days": 20,
    "present": 18,
    "absent": 1,
    "late": 1,
    "attendance_rate": 95,
    "punctuality_rate": 90
  }
}
```

---

## My Timetable

### Get My Timetable

**Endpoint:** `GET /api/v1/timetable/student/me`

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "class": "JSS 1A"
  },
  "timetable": [
    {
      "day": "Monday",
      "periods": [
        {
          "period": 1,
          "time": "08:00-09:00",
          "subject": "Mathematics",
          "teacher": "Mr. Johnson",
          "room": "Room 101"
        },
        {
          "period": 2,
          "time": "09:00-10:00",
          "subject": "English",
          "teacher": "Mrs. Smith",
          "room": "Room 102"
        },
        {
          "period": 3,
          "time": "10:00-11:00",
          "subject": "Break",
          "teacher": null,
          "room": null
        }
      ]
    }
  ]
}
```

---

## My Guardians

### Get My Guardians

**Endpoint:** `GET /api/v1/students/me/guardians`

**Response (200):**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe"
  },
  "guardians": [
    {
      "id": 1,
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "jane@example.com",
      "phone": "+234809876543",
      "address": "123 Main St",
      "occupation": "Engineer",
      "relationship": "Mother",
      "is_primary": true
    },
    {
      "id": 2,
      "first_name": "James",
      "last_name": "Doe",
      "email": "james@example.com",
      "phone": "+234807654321",
      "relationship": "Father",
      "is_primary": false
    }
  ]
}
```

---

## Communication

### Get My Announcements

**Endpoint:** `GET /api/v1/announcements/my-announcements`

**Response (200):**
```json
{
  "announcements": [
    {
      "id": 1,
      "title": "Important Notice",
      "content": "Class will start 30 minutes early tomorrow",
      "priority": "high",
      "created_by": "Mr. Johnson",
      "created_at": "2025-11-26 10:00:00",
      "expires_at": "2025-11-27 23:59:59"
    }
  ]
}
```

### Get My Messages

**Endpoint:** `GET /api/v1/messages/my-messages`

---

## Profile Management

### Upload Profile Picture

**Endpoint:** `POST /api/v1/users/me/profile-picture`

**Request Body:**
```json
{
  "profile_picture": "https://s3.amazonaws.com/.../photo.jpg"
}
```

**Steps:**
1. Get presigned URL: `GET /api/v1/uploads/presigned-urls?type=profile_picture&entity_type=student&entity_id={my_id}`
2. Upload file to S3 using presigned URL
3. Update profile with S3 URL

### Delete Profile Picture

**Endpoint:** `DELETE /api/v1/users/me/profile-picture`

---

## Summary

### Student Can:
✅ View personalized dashboard with stats  
✅ View and update profile (limited fields)  
✅ View class, subjects, and classmates  
✅ View and submit assignments  
✅ Take CBT exams online  
✅ View exam results and grades  
✅ Track attendance history  
✅ View class timetable  
✅ View guardian information  
✅ Receive announcements and messages  
✅ Upload and manage profile picture  

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

