# Teacher Dashboard API Documentation

Complete API reference for Teacher Dashboard functionality.

---

## Table of Contents

1. [Dashboard Overview](#dashboard-overview)
2. [My Classes & Students](#my-classes--students)
3. [Exams & CBT](#exams--cbt)
4. [Question Bank](#question-bank)
5. [Grading & Results](#grading--results)
6. [Assignments](#assignments)
7. [Attendance](#attendance)
8. [Timetable](#timetable)
9. [Communication](#communication)
10. [Profile Management](#profile-management)

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

### Get Teacher Dashboard

**Endpoint:** `GET /api/v1/dashboard/teacher`

**Response (200):**
```json
{
  "user": {
    "id": 5,
    "name": "Mr. Johnson",
    "email": "johnson@westwoodschool.com",
    "role": "teacher",
    "profile_picture": "https://..."
  },
  "teacher": {
    "id": 1,
    "employee_id": "TCH001",
    "department_id": 2,
    "qualification": "B.Sc. Mathematics",
    "specialization": "Mathematics"
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

## My Classes & Students

### Get My Classes

**Endpoint:** `GET /api/v1/classes?class_teacher_id={my_teacher_id}`

**Response (200):**
```json
[
  {
    "id": 1,
    "name": "JSS 1A",
    "capacity": 30,
    "students_count": 28,
    "class_teacher": {
      "id": 1,
      "name": "Mr. Johnson"
    },
    "arms": [...]
  }
]
```

### Get My Students

**Endpoint:** `GET /api/v1/students?class_id={my_class_id}`

### Get Student Details

**Endpoint:** `GET /api/v1/students/{id}`

**Response (200):**
```json
{
  "id": 10,
  "admission_number": "ADM001",
  "first_name": "John",
  "last_name": "Doe",
  "class": {
    "id": 1,
    "name": "JSS 1A"
  },
  "performance": {
    "average_score": 78.5,
    "attendance_rate": 95,
    "rank": 5
  },
  "guardians": [...]
}
```

### Get My Subjects

**Endpoint:** `GET /api/v1/subjects?teacher_id={my_teacher_id}`

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

## Exams & CBT

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

## Question Bank

### Add Question

**Endpoint:** `POST /api/v1/question-bank`

**Request Body (Multiple Choice):**
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

**Request Body (True/False):**
```json
{
  "question_type": "true_false",
  "question": "The earth is flat",
  "correct_answer": ["false"],
  "explanation": "The earth is spherical"
}
```

**Request Body (Short Answer):**
```json
{
  "question_type": "short_answer",
  "question": "What is the capital of Nigeria?",
  "correct_answer": ["Abuja"],
  "marks": 3
}
```

**Request Body (Essay):**
```json
{
  "question_type": "essay",
  "question": "Discuss the causes of World War II",
  "marks": 20,
  "rubric": "Introduction (5 marks), Body (10 marks), Conclusion (5 marks)"
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
    }
    // ... up to 10,000 questions
  ]
}
```

**Performance:** Optimized for bulk inserts, can handle 10,000+ questions.

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

**Query Parameters:**
- `subject_id` - Filter by subject
- `class_id` - Filter by class
- `difficulty` - Filter by difficulty (easy, medium, hard)
- `question_type` - Filter by type
- `tags` - Filter by tags
- `per_page` - Items per page

### Update Question

**Endpoint:** `PUT /api/v1/question-bank/{id}`

### Delete Question

**Endpoint:** `DELETE /api/v1/question-bank/{id}`

### Duplicate Question

**Endpoint:** `POST /api/v1/question-bank/{id}/duplicate`

**Response (201):**
```json
{
  "message": "Question duplicated successfully",
  "question": {...}
}
```

---

## Grading & Results

### Record Student Result

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
    }
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

### View Results

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

## Assignments

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

### Grade Assignment

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

## Attendance

### Mark Class Attendance

**Endpoint:** `POST /api/v1/attendance/mark`

**Request Body:**
```json
{
  "class_id": 1,
  "date": "2025-11-26",
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
      "date": "2025-11-26",
      "status": "present",
      "check_in_time": "08:00:00"
    }
    // ... up to 1000 records
  ]
}
```

### Get Class Attendance

**Endpoint:** `GET /api/v1/attendance/class/{class_id}?date=2025-11-26`

### Get Student Attendance

**Endpoint:** `GET /api/v1/attendance/student/{student_id}`

### Attendance Reports

**Endpoint:** `GET /api/v1/attendance/reports?class_id=1&from=2025-11-01&to=2025-11-30`

---

## Timetable

### Get My Timetable

**Endpoint:** `GET /api/v1/timetable/teacher/{my_teacher_id}`

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
        },
        {
          "period": 2,
          "time": "09:00-10:00",
          "subject": "Mathematics",
          "class": "JSS 1B",
          "room": "Room 101"
        }
      ]
    }
  ]
}
```

---

## Communication

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

## Profile Management

### Update My Profile

**Endpoint:** `PUT /api/v1/teachers/me`

**Request Body:**
```json
{
  "phone": "+234801234567",
  "address": "New address",
  "qualification": "M.Sc. Mathematics",
  "specialization": "Advanced Mathematics"
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

**Steps:**
1. Get presigned URL: `GET /api/v1/uploads/presigned-urls?type=profile_picture&entity_type=teacher&entity_id={my_id}`
2. Upload file to S3 using presigned URL
3. Update profile with S3 URL

### Delete Profile Picture

**Endpoint:** `DELETE /api/v1/users/me/profile-picture`

---

## Summary

### Teacher Can:
✅ View personalized dashboard with stats  
✅ Create and manage exams (traditional & CBT)  
✅ Add questions to question bank (single & bulk up to 10,000)  
✅ Grade and record student results (single & bulk)  
✅ Create and grade assignments  
✅ Mark attendance (single & bulk)  
✅ View student performance and analytics  
✅ Send messages and announcements  
✅ View teaching timetable  
✅ Manage profile and upload profile picture  

---

**Last Updated:** November 26, 2025  
**API Version:** 1.0.0

