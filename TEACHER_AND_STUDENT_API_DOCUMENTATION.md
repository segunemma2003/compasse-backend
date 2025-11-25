# Teacher & Student API Documentation

## Table of Contents

1. [Overview](#overview)
2. [Teacher APIs](#teacher-apis)
3. [Student APIs](#student-apis)
4. [Authentication](#authentication)

---

## Overview

This documentation covers all API endpoints and functionality for:
- **Teachers** - Comprehensive teaching and assessment tools
- **Students** - Learning, assignments, exams, and performance tracking

---

# Teacher APIs

## Dashboard

### Get Teacher Dashboard

**Endpoint:** `GET /api/v1/dashboard/teacher`

**Headers:**
```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
```

**Response (200):**
```json
{
  "user": {
    "id": 5,
    "name": "Mr. Johnson",
    "email": "johnson@westwoodschool.com",
    "role": "teacher"
  },
  "teacher": {
    "id": 1,
    "employee_id": "TCH001",
    "department_id": 2,
    "subjects": [...]
  },
  "stats": {
    "my_classes": 3,
    "my_subjects": 2,
    "my_students": 90,
    "pending_assignments": 5,
    "upcoming_exams": 2
  },
  "role": "teacher"
}
```

---

## 1. Exam Management

### Create Exam

**Endpoint:** `POST /api/v1/assessments/exams`

**Request Body:**
```json
{
  "title": "Mathematics Mid-Term Exam",
  "subject_id": 1,
  "class_id": 1,
  "exam_type": "mid_term",
  "start_date": "2025-12-01 09:00:00",
  "end_date": "2025-12-01 11:00:00",
  "duration_minutes": 120,
  "total_marks": 100,
  "passing_marks": 40,
  "instructions": "Answer all questions",
  "is_cbt": true,
  "cbt_settings": {
    "shuffle_questions": true,
    "shuffle_options": true,
    "show_results_immediately": false,
    "allow_review": true
  }
}
```

**Response (201):**
```json
{
  "message": "Exam created successfully",
  "exam": {
    "id": 1,
    "title": "Mathematics Mid-Term Exam",
    "exam_code": "EXAM2025001",
    "subject": {...},
    "class": {...},
    "created_by": 5,
    "status": "scheduled"
  }
}
```

### List My Exams

**Endpoint:** `GET /api/v1/assessments/exams?teacher_id={my_id}`

**Query Parameters:**
- `subject_id` - Filter by subject
- `class_id` - Filter by class
- `status` - Filter by status (scheduled, ongoing, completed)
- `exam_type` - Filter by type

### Get Exam Details

**Endpoint:** `GET /api/v1/assessments/exams/{id}`

### Update Exam

**Endpoint:** `PUT /api/v1/assessments/exams/{id}`

### Delete Exam

**Endpoint:** `DELETE /api/v1/assessments/exams/{id}`

---

## 2. Question Bank Management

### Add Question to Question Bank

**Endpoint:** `POST /api/v1/question-bank`

**Request Body:**
```json
{
  "subject_id": 1,
  "class_id": 1,
  "term_id": 1,
  "academic_year_id": 1,
  "question_type": "multiple_choice",
  "question": "What is 2 + 2?",
  "options": [
    {"key": "A", "value": "3"},
    {"key": "B", "value": "4"},
    {"key": "C", "value": "5"},
    {"key": "D", "value": "6"}
  ],
  "correct_answer": ["B"],
  "explanation": "2 + 2 equals 4",
  "difficulty": "easy",
  "marks": 2,
  "tags": ["arithmetic", "addition"],
  "topic": "Basic Arithmetic"
}
```

**Response (201):**
```json
{
  "message": "Question created successfully",
  "question": {
    "id": 1,
    "question": "What is 2 + 2?",
    "question_type": "multiple_choice",
    "difficulty": "easy",
    "marks": 2,
    "usage_count": 0,
    "created_by": 5
  }
}
```

### Bulk Add Questions

**Endpoint:** `POST /api/v1/bulk/questions/create`

**Request Body:**
```json
{
  "questions": [
    {
      "subject_id": 1,
      "class_id": 1,
      "term_id": 1,
      "academic_year_id": 1,
      "question_type": "multiple_choice",
      "question": "Question 1",
      "options": [...],
      "correct_answer": ["A"]
    },
    // ... up to 10,000 questions
  ]
}
```

### Get Questions for Exam

**Endpoint:** `GET /api/v1/question-bank/for-exam`

**Query Parameters:**
```
?subject_id=1&class_id=1&term_id=1&academic_year_id=1&difficulty=medium&count=30
```

**Response (200):**
```json
{
  "total_available": 150,
  "returned": 30,
  "questions": [...]
}
```

### List My Questions

**Endpoint:** `GET /api/v1/question-bank?created_by={my_id}`

### Update Question

**Endpoint:** `PUT /api/v1/question-bank/{id}`

### Delete Question

**Endpoint:** `DELETE /api/v1/question-bank/{id}`

### Duplicate Question

**Endpoint:** `POST /api/v1/question-bank/{id}/duplicate`

---

## 3. Grading & Scoring

### Record Student Results

**Endpoint:** `POST /api/v1/assessments/results`

**Request Body:**
```json
{
  "student_id": 10,
  "exam_id": 1,
  "subject_id": 1,
  "marks_obtained": 85,
  "total_marks": 100,
  "grade": "A",
  "remarks": "Excellent performance"
}
```

### Bulk Update Results

**Endpoint:** `POST /api/v1/bulk/results/update`

**Request Body:**
```json
{
  "results": [
    {
      "student_id": 10,
      "exam_id": 1,
      "subject_id": 1,
      "marks_obtained": 85,
      "total_marks": 100,
      "grade": "A"
    },
    // ... up to 1000 results
  ]
}
```

**Response (200):**
```json
{
  "success": true,
  "summary": {
    "total": 30,
    "created": 30,
    "failed": 0
  }
}
```

### View Student Results

**Endpoint:** `GET /api/v1/assessments/results?exam_id={exam_id}`

### Get Class Performance

**Endpoint:** `GET /api/v1/grades/class/{class_id}`

**Response (200):**
```json
{
  "class": {...},
  "statistics": {
    "total_students": 30,
    "average_score": 75.5,
    "highest_score": 95,
    "lowest_score": 45,
    "pass_rate": 85
  },
  "grades": [
    {
      "student": {...},
      "marks": 85,
      "grade": "A",
      "position": 2
    }
  ]
}
```

---

## 4. Assignment Management

### Create Assignment

**Endpoint:** `POST /api/v1/assessments/assignments`

**Request Body:**
```json
{
  "title": "Algebra Homework",
  "description": "Solve problems 1-10",
  "subject_id": 1,
  "class_id": 1,
  "due_date": "2025-12-10 23:59:59",
  "total_marks": 20,
  "instructions": "Show all working",
  "attachments": [
    {"name": "questions.pdf", "url": "https://..."}
  ]
}
```

### List My Assignments

**Endpoint:** `GET /api/v1/assessments/assignments?teacher_id={my_id}`

### Get Assignment Submissions

**Endpoint:** `GET /api/v1/assessments/assignments/{id}/submissions`

**Response (200):**
```json
{
  "assignment": {...},
  "submissions": [
    {
      "id": 1,
      "student": {...},
      "submitted_at": "2025-12-09 15:30:00",
      "status": "submitted",
      "attachments": [...],
      "marks": null,
      "feedback": null
    }
  ],
  "statistics": {
    "total_students": 30,
    "submitted": 25,
    "pending": 5,
    "late": 2,
    "graded": 10
  }
}
```

### Grade Assignment Submission

**Endpoint:** `POST /api/v1/assessments/assignments/{assignment_id}/submissions/{submission_id}/grade`

**Request Body:**
```json
{
  "marks": 18,
  "feedback": "Well done! Minor errors in question 5.",
  "status": "graded"
}
```

---

## 5. Attendance Management

### Mark Class Attendance

**Endpoint:** `POST /api/v1/attendance/mark`

**Request Body:**
```json
{
  "class_id": 1,
  "date": "2025-11-24",
  "attendance": [
    {
      "student_id": 10,
      "status": "present",
      "check_in_time": "08:00:00"
    },
    {
      "student_id": 11,
      "status": "absent",
      "notes": "Sick"
    },
    {
      "student_id": 12,
      "status": "late",
      "check_in_time": "08:30:00"
    }
  ]
}
```

### Bulk Mark Attendance

**Endpoint:** `POST /api/v1/bulk/attendance/mark`

```json
{
  "attendance_records": [
    {
      "attendanceable_id": 10,
      "attendanceable_type": "student",
      "date": "2025-11-24",
      "status": "present",
      "check_in_time": "08:00:00"
    }
    // ... up to 1000 records
  ]
}
```

### Get Class Attendance

**Endpoint:** `GET /api/v1/attendance/class/{class_id}?date=2025-11-24`

### Get Student Attendance

**Endpoint:** `GET /api/v1/attendance/student/{student_id}`

### Attendance Reports

**Endpoint:** `GET /api/v1/attendance/reports?class_id=1&from=2025-11-01&to=2025-11-30`

---

## 6. My Classes & Students

### Get My Classes

**Endpoint:** `GET /api/v1/classes?class_teacher_id={my_id}`

**Response (200):**
```json
[
  {
    "id": 1,
    "name": "JSS 1A",
    "students_count": 30,
    "class_teacher": {...},
    "subjects": [...]
  }
]
```

### Get My Students

**Endpoint:** `GET /api/v1/students?class_id={my_class_id}`

### Get Student Profile

**Endpoint:** `GET /api/v1/students/{id}`

**Response (200):**
```json
{
  "id": 10,
  "first_name": "John",
  "last_name": "Doe",
  "admission_number": "ADM001",
  "class": {...},
  "guardians": [...],
  "performance": {
    "average_score": 78.5,
    "attendance_rate": 95,
    "rank": 5
  }
}
```

---

## 7. My Subjects

### Get My Subjects

**Endpoint:** `GET /api/v1/subjects?teacher_id={my_id}`

**Response (200):**
```json
[
  {
    "id": 1,
    "name": "Mathematics",
    "code": "MATH101",
    "classes": [
      {"id": 1, "name": "JSS 1A"},
      {"id": 2, "name": "JSS 1B"}
    ],
    "students_count": 60
  }
]
```

---

## 8. Timetable

### Get My Timetable

**Endpoint:** `GET /api/v1/timetable/teacher/{my_id}`

**Response (200):**
```json
{
  "teacher": {...},
  "timetable": [
    {
      "day": "Monday",
      "periods": [
        {
          "period": 1,
          "time": "08:00-09:00",
          "subject": "Mathematics",
          "class": "JSS 1A",
          "room": "Room 101"
        }
      ]
    }
  ]
}
```

---

## 9. Communication

### Send Announcement

**Endpoint:** `POST /api/v1/announcements`

**Request Body:**
```json
{
  "title": "Important Notice",
  "content": "Class will start 30 minutes early tomorrow",
  "target_audience": "students",
  "class_id": 1,
  "priority": "high",
  "expires_at": "2025-12-10 23:59:59"
}
```

### Send Message to Students

**Endpoint:** `POST /api/v1/messages/send`

**Request Body:**
```json
{
  "recipients": [10, 11, 12],
  "recipient_type": "students",
  "subject": "Assignment Reminder",
  "message": "Please submit your homework by Friday",
  "priority": "normal"
}
```

---

# Student APIs

## Dashboard

### Get Student Dashboard

**Endpoint:** `GET /api/v1/dashboard/student`

**Response (200):**
```json
{
  "user": {
    "id": 10,
    "name": "John Doe",
    "email": "john.doe1@westwoodschool.com",
    "role": "student"
  },
  "student": {
    "id": 1,
    "admission_number": "ADM001",
    "class": {...},
    "guardians": [...]
  },
  "stats": {
    "my_class": {...},
    "my_subjects": 8,
    "pending_assignments": 3,
    "upcoming_exams": 2,
    "recent_grades": [...],
    "attendance_rate": 95.5
  },
  "role": "student"
}
```

---

## 1. My Profile

### Get My Profile

**Endpoint:** `GET /api/v1/students/me`

**Response (200):**
```json
{
  "id": 1,
  "admission_number": "ADM001",
  "first_name": "John",
  "last_name": "Doe",
  "email": "john.doe1@westwoodschool.com",
  "class": {...},
  "arm": {...},
  "guardians": [...],
  "performance_summary": {
    "average_score": 78.5,
    "rank": 5,
    "total_students": 30
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

---

## 2. My Classes & Subjects

### Get My Class

**Endpoint:** `GET /api/v1/classes/my-class`

**Response (200):**
```json
{
  "id": 1,
  "name": "JSS 1A",
  "class_teacher": {...},
  "students_count": 30,
  "subjects": [...]
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
    "teacher": {
      "name": "Mr. Johnson",
      "email": "johnson@westwoodschool.com"
    }
  }
]
```

---

## 3. Assignments

### Get My Assignments

**Endpoint:** `GET /api/v1/assessments/assignments/my-assignments`

**Query Parameters:**
- `status` - Filter by status (pending, submitted, graded, late)
- `subject_id` - Filter by subject

**Response (200):**
```json
{
  "assignments": [
    {
      "id": 1,
      "title": "Algebra Homework",
      "subject": {...},
      "due_date": "2025-12-10 23:59:59",
      "total_marks": 20,
      "status": "pending",
      "submission": null
    },
    {
      "id": 2,
      "title": "Essay on Climate Change",
      "subject": {...},
      "due_date": "2025-12-05 23:59:59",
      "total_marks": 30,
      "status": "graded",
      "submission": {
        "submitted_at": "2025-12-04 15:30:00",
        "marks": 25,
        "feedback": "Well written!"
      }
    }
  ],
  "summary": {
    "total": 10,
    "pending": 3,
    "submitted": 5,
    "graded": 2
  }
}
```

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
    "submitted_at": "2025-12-09 15:30:00",
    "status": "submitted"
  }
}
```

### View Assignment Details

**Endpoint:** `GET /api/v1/assessments/assignments/{id}`

---

## 4. Exams & CBT

### Get My Exams

**Endpoint:** `GET /api/v1/assessments/exams/my-exams`

**Query Parameters:**
- `status` - Filter by status (upcoming, ongoing, completed)
- `subject_id` - Filter by subject

**Response (200):**
```json
{
  "exams": [
    {
      "id": 1,
      "title": "Mathematics Mid-Term",
      "subject": {...},
      "start_date": "2025-12-01 09:00:00",
      "end_date": "2025-12-01 11:00:00",
      "duration_minutes": 120,
      "total_marks": 100,
      "is_cbt": true,
      "status": "upcoming",
      "my_result": null
    }
  ]
}
```

### Start CBT Exam

**Endpoint:** `POST /api/v1/assessments/cbt/{exam}/start`

**Response (201):**
```json
{
  "message": "Exam session started",
  "session": {
    "id": "sess_12345",
    "exam": {...},
    "started_at": "2025-12-01 09:00:00",
    "ends_at": "2025-12-01 11:00:00",
    "time_remaining": 7200
  }
}
```

### Get CBT Questions

**Endpoint:** `GET /api/v1/assessments/cbt/{exam}/questions`

**Response (200):**
```json
{
  "session_id": "sess_12345",
  "questions": [
    {
      "id": 1,
      "question": "What is 2 + 2?",
      "question_type": "multiple_choice",
      "options": [
        {"key": "A", "value": "3"},
        {"key": "B", "value": "4"},
        {"key": "C", "value": "5"}
      ],
      "marks": 2
    }
  ],
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
      "answer": ["C"]
    }
  ]
}
```

**Response (200):**
```json
{
  "message": "Exam submitted successfully",
  "result": {
    "total_questions": 50,
    "answered": 48,
    "unanswered": 2,
    "score": 85,
    "percentage": 85,
    "grade": "A",
    "status": "graded"
  }
}
```

### View Exam Results

**Endpoint:** `GET /api/v1/assessments/exams/{id}/my-result`

---

## 5. My Grades & Results

### Get My Grades

**Endpoint:** `GET /api/v1/grades/student/me`

**Query Parameters:**
- `subject_id` - Filter by subject
- `term_id` - Filter by term
- `academic_year_id` - Filter by academic year

**Response (200):**
```json
{
  "student": {...},
  "grades": [
    {
      "subject": "Mathematics",
      "exam_type": "Mid-Term",
      "marks": 85,
      "total_marks": 100,
      "percentage": 85,
      "grade": "A",
      "position": 2,
      "class_average": 72
    }
  ],
  "summary": {
    "overall_average": 78.5,
    "overall_grade": "B+",
    "overall_position": 5,
    "total_students": 30
  }
}
```

### Get Subject Performance

**Endpoint:** `GET /api/v1/grades/student/me/subject/{subject_id}`

**Response (200):**
```json
{
  "subject": {...},
  "performance": [
    {
      "exam": "Mid-Term",
      "score": 85,
      "grade": "A"
    },
    {
      "exam": "End-Term",
      "score": 78,
      "grade": "B+"
    }
  ],
  "average": 81.5,
  "trend": "improving"
}
```

---

## 6. My Attendance

### Get My Attendance

**Endpoint:** `GET /api/v1/attendance/student/me`

**Query Parameters:**
- `from` - Start date (YYYY-MM-DD)
- `to` - End date (YYYY-MM-DD)

**Response (200):**
```json
{
  "attendance": [
    {
      "date": "2025-11-24",
      "status": "present",
      "check_in_time": "08:00:00"
    },
    {
      "date": "2025-11-23",
      "status": "present",
      "check_in_time": "07:55:00"
    },
    {
      "date": "2025-11-22",
      "status": "late",
      "check_in_time": "08:30:00"
    }
  ],
  "summary": {
    "total_days": 20,
    "present": 18,
    "absent": 1,
    "late": 1,
    "attendance_rate": 95
  }
}
```

---

## 7. My Timetable

### Get My Timetable

**Endpoint:** `GET /api/v1/timetable/student/me`

**Response (200):**
```json
{
  "student": {...},
  "class": {...},
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
        }
      ]
    }
  ]
}
```

---

## 8. My Guardians/Parents

### Get My Guardians

**Endpoint:** `GET /api/v1/students/me/guardians`

**Response (200):**
```json
{
  "guardians": [
    {
      "id": 1,
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "jane.doe@example.com",
      "phone": "+234809876543",
      "relationship": "Mother",
      "is_primary": true
    }
  ]
}
```

---

## 9. Announcements & Messages

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
      "created_at": "2025-11-24 10:00:00"
    }
  ]
}
```

### Get My Messages

**Endpoint:** `GET /api/v1/messages/my-messages`

---

## Authentication

### All endpoints require these headers:

```http
Authorization: Bearer {your_access_token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

### Getting Access Token

**Login as Teacher:**
```bash
POST /api/v1/auth/login
{
  "email": "teacher@school.com",
  "password": "password",
  "role": "teacher"
}
```

**Login as Student:**
```bash
POST /api/v1/auth/login
{
  "email": "student@school.com",
  "password": "Password@123",
  "role": "student"
}
```

**Response:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1Qi...",
  "token_type": "Bearer",
  "user": {...},
  "role": "teacher"
}
```

---

## Summary

### Teacher Can:
✅ Create & manage exams (traditional & CBT)  
✅ Add questions to question bank  
✅ Grade & score students  
✅ Create & grade assignments  
✅ Mark attendance  
✅ View student performance  
✅ Send messages & announcements  
✅ View timetable  

### Student Can:
✅ View dashboard & performance  
✅ Take CBT exams  
✅ Submit assignments  
✅ View grades & results  
✅ Check attendance  
✅ View timetable  
✅ Communicate with teachers  
✅ Access learning materials  

---

**Last Updated:** November 24, 2025  
**API Version:** 1.0.0

