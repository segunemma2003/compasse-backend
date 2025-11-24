# Exam & CBT (Computer-Based Testing) API Documentation

Complete guide for Exam Management and Computer-Based Testing (CBT) systems.

---

## 📖 Table of Contents

1. [Overview](#overview)
2. [Exam Management APIs](#exam-management-apis)
3. [CBT (Computer-Based Testing) APIs](#cbt-computer-based-testing-apis)
4. [Results & Grading](#results--grading)
5. [Bulk Operations](#bulk-operations)
6. [Request/Response Examples](#requestresponse-examples)

---

## Overview

The Exam & CBT system provides comprehensive exam management, including:

- **Traditional Exams** - Paper-based or offline exams
- **Computer-Based Testing (CBT)** - Online exams with auto-grading
- **Results Management** - Recording and generating results
- **Analytics** - Performance tracking and insights

---

## Exam Management APIs

### Base URL
```
https://api.compasse.net/api/v1/assessments/exams
```

### Authentication
All endpoints require:
```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
```

---

### 1. **List All Exams**

**Endpoint:** `GET /api/v1/assessments/exams`

**Query Parameters:**
- `subject_id` (optional) - Filter by subject
- `class_id` (optional) - Filter by class
- `teacher_id` (optional) - Filter by teacher
- `term_id` (optional) - Filter by term
- `academic_year_id` (optional) - Filter by academic year
- `exam_type` (optional) - Filter by type: `mid_term`, `end_term`, `ca1`, `ca2`, `mock`, `final`
- `status` (optional) - Filter by status: `scheduled`, `ongoing`, `completed`, `cancelled`
- `search` (optional) - Search in title/description
- `per_page` (optional) - Items per page (default: 15)

**Example Request:**
```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/exams?class_id=5&term_id=1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

**Response (200):**
```json
{
    "exams": {
        "data": [
            {
                "id": 1,
                "school_id": 1,
                "title": "Mathematics Mid-Term Exam",
                "description": "Covering algebra and geometry",
                "subject_id": 2,
                "class_id": 5,
                "teacher_id": 10,
                "term_id": 1,
                "academic_year_id": 1,
                "start_date": "2025-12-01T09:00:00Z",
                "end_date": "2025-12-01T11:00:00Z",
                "duration_minutes": 120,
                "total_marks": 100,
                "passing_marks": 50,
                "exam_type": "mid_term",
                "is_cbt": false,
                "status": "scheduled",
                "instructions": "Answer all questions...",
                "created_at": "2025-11-23T10:00:00Z",
                "subject": {
                    "id": 2,
                    "name": "Mathematics",
                    "code": "MATH101"
                },
                "class": {
                    "id": 5,
                    "name": "Grade 10A"
                },
                "teacher": {
                    "id": 10,
                    "name": "Mr. John Smith"
                }
            }
        ],
        "current_page": 1,
        "per_page": 15,
        "total": 1
    }
}
```

---

### 2. **Get Single Exam**

**Endpoint:** `GET /api/v1/assessments/exams/{exam_id}`

**Response (200):**
```json
{
    "exam": {
        "id": 1,
        "school_id": 1,
        "title": "Mathematics Mid-Term Exam",
        "description": "Covering algebra and geometry",
        "subject_id": 2,
        "class_id": 5,
        "teacher_id": 10,
        "term_id": 1,
        "academic_year_id": 1,
        "start_date": "2025-12-01T09:00:00Z",
        "end_date": "2025-12-01T11:00:00Z",
        "duration_minutes": 120,
        "total_marks": 100,
        "passing_marks": 50,
        "exam_type": "mid_term",
        "is_cbt": false,
        "status": "scheduled",
        "instructions": "Answer all questions carefully",
        "venue": "Hall A",
        "created_at": "2025-11-23T10:00:00Z",
        "updated_at": "2025-11-23T10:00:00Z",
        "subject": { ... },
        "class": { ... },
        "teacher": { ... },
        "term": { ... },
        "questions_count": 50,
        "students_count": 30,
        "completed_count": 0
    }
}
```

---

### 3. **Create Exam**

**Endpoint:** `POST /api/v1/assessments/exams`

**Request Body:**
```json
{
    "title": "Mathematics Mid-Term Exam",
    "description": "Covering algebra, geometry, and trigonometry",
    "subject_id": 2,
    "class_id": 5,
    "teacher_id": 10,
    "term_id": 1,
    "academic_year_id": 1,
    "start_date": "2025-12-01 09:00:00",
    "end_date": "2025-12-01 11:00:00",
    "duration_minutes": 120,
    "total_marks": 100,
    "passing_marks": 50,
    "exam_type": "mid_term",
    "is_cbt": false,
    "status": "scheduled",
    "instructions": "Answer all questions. No calculators allowed.",
    "venue": "Hall A"
}
```

**Validation Rules:**
- `title` (required, string, max 255)
- `description` (optional, string)
- `subject_id` (required, exists in subjects table)
- `class_id` (required, exists in classes table)
- `teacher_id` (optional, exists in teachers table)
- `term_id` (required, exists in terms table)
- `academic_year_id` (required, exists in academic_years table)
- `start_date` (required, datetime)
- `end_date` (required, datetime, after start_date)
- `duration_minutes` (optional, integer)
- `total_marks` (required, integer, min 1)
- `passing_marks` (required, integer, min 0)
- `exam_type` (required, enum: mid_term/end_term/ca1/ca2/mock/final)
- `is_cbt` (optional, boolean, default: false)
- `status` (optional, enum: scheduled/ongoing/completed/cancelled)
- `instructions` (optional, text)
- `venue` (optional, string)

**Note:** No `school_id` needed! Auto-derived from X-Subdomain.

**Response (201):**
```json
{
    "message": "Exam created successfully",
    "exam": {
        "id": 2,
        "title": "Mathematics Mid-Term Exam",
        ...
    }
}
```

---

### 4. **Update Exam**

**Endpoint:** `PUT /api/v1/assessments/exams/{exam_id}`

**Request Body:** (all fields optional)
```json
{
    "title": "Updated Exam Title",
    "start_date": "2025-12-02 09:00:00",
    "status": "ongoing"
}
```

**Response (200):**
```json
{
    "message": "Exam updated successfully",
    "exam": { ... }
}
```

---

### 5. **Delete Exam**

**Endpoint:** `DELETE /api/v1/assessments/exams/{exam_id}`

**Response (200):**
```json
{
    "message": "Exam deleted successfully"
}
```

---

### 6. **Publish Exam**

**Endpoint:** `POST /api/v1/assessments/exams/{exam_id}/publish`

**Description:** Makes exam visible to students and changes status.

**Response (200):**
```json
{
    "message": "Exam published successfully",
    "exam": {
        "id": 1,
        "status": "ongoing",
        ...
    }
}
```

---

### 7. **Get Exam Questions**

**Endpoint:** `GET /api/v1/assessments/exams/{exam_id}/questions`

**Response (200):**
```json
{
    "questions": [
        {
            "id": 1,
            "exam_id": 1,
            "question_text": "What is 2 + 2?",
            "question_type": "multiple_choice",
            "marks": 2,
            "options": ["2", "3", "4", "5"],
            "correct_answer": "4",
            "order": 1
        }
    ]
}
```

---

### 8. **Get Exam Attempts**

**Endpoint:** `GET /api/v1/assessments/exams/{exam_id}/attempts`

**Description:** Get all student attempts for this exam.

**Response (200):**
```json
{
    "attempts": [
        {
            "id": 1,
            "exam_id": 1,
            "student_id": 50,
            "started_at": "2025-12-01T09:05:00Z",
            "submitted_at": "2025-12-01T11:00:00Z",
            "score": 85,
            "total_marks": 100,
            "percentage": 85.0,
            "status": "completed",
            "student": {
                "id": 50,
                "name": "John Doe",
                "admission_number": "2025001"
            }
        }
    ]
}
```

---

## CBT (Computer-Based Testing) APIs

### Overview
CBT allows students to take exams online with automatic grading and real-time feedback.

**Base URL:** `/api/v1/assessments/cbt`

---

### 1. **Get CBT Questions**

**Endpoint:** `GET /api/v1/assessments/cbt/{exam_id}/questions`

**Description:** Get questions for a CBT exam (for students taking the exam).

**Response (200):**
```json
{
    "exam": {
        "id": 1,
        "title": "Mathematics CBT",
        "duration_minutes": 60,
        "total_marks": 50,
        "instructions": "Answer all questions..."
    },
    "questions": [
        {
            "id": 1,
            "question_text": "What is the square root of 16?",
            "question_type": "multiple_choice",
            "marks": 2,
            "options": ["2", "3", "4", "5"],
            "order": 1
        },
        {
            "id": 2,
            "question_text": "Solve for x: 2x + 5 = 15",
            "question_type": "short_answer",
            "marks": 3,
            "order": 2
        }
    ]
}
```

**Note:** Correct answers are NOT returned in the response (for security).

---

### 2. **Start CBT Session**

**Endpoint:** `POST /api/v1/cbt/start`

**Request Body:**
```json
{
    "exam_id": 1
}
```

**Response (200):**
```json
{
    "message": "CBT session started successfully",
    "session": {
        "id": 10,
        "exam_id": 1,
        "student_id": 50,
        "started_at": "2025-12-01T09:05:00Z",
        "expires_at": "2025-12-01T10:05:00Z",
        "status": "in_progress"
    }
}
```

---

### 3. **Submit CBT Answer**

**Endpoint:** `POST /api/v1/cbt/submit-answer`

**Request Body:**
```json
{
    "session_id": 10,
    "question_id": 1,
    "answer": "4"
}
```

**Response (200):**
```json
{
    "message": "Answer submitted successfully",
    "is_correct": true,
    "marks_awarded": 2
}
```

---

### 4. **Submit Complete CBT Exam**

**Endpoint:** `POST /api/v1/assessments/cbt/submit`

**Request Body:**
```json
{
    "exam_id": 1,
    "answers": [
        {
            "question_id": 1,
            "answer": "4"
        },
        {
            "question_id": 2,
            "answer": "5"
        }
    ]
}
```

**Response (200):**
```json
{
    "message": "Exam submitted successfully",
    "result": {
        "exam_id": 1,
        "student_id": 50,
        "score": 45,
        "total_marks": 50,
        "percentage": 90.0,
        "grade": "A",
        "correct_answers": 18,
        "wrong_answers": 2,
        "submitted_at": "2025-12-01T10:00:00Z"
    }
}
```

---

### 5. **Get CBT Session Status**

**Endpoint:** `GET /api/v1/assessments/cbt/session/{session_id}/status`

**Response (200):**
```json
{
    "session": {
        "id": 10,
        "exam_id": 1,
        "student_id": 50,
        "started_at": "2025-12-01T09:05:00Z",
        "expires_at": "2025-12-01T10:05:00Z",
        "status": "in_progress",
        "time_remaining_seconds": 3420,
        "questions_answered": 15,
        "questions_total": 20
    }
}
```

---

### 6. **Get CBT Results**

**Endpoint:** `GET /api/v1/assessments/cbt/session/{session_id}/results`

**Description:** Get detailed results after submitting CBT exam.

**Response (200):**
```json
{
    "result": {
        "session_id": 10,
        "exam_id": 1,
        "student_id": 50,
        "score": 45,
        "total_marks": 50,
        "percentage": 90.0,
        "grade": "A",
        "submitted_at": "2025-12-01T10:00:00Z",
        "detailed_answers": [
            {
                "question_id": 1,
                "question_text": "What is the square root of 16?",
                "student_answer": "4",
                "correct_answer": "4",
                "is_correct": true,
                "marks": 2
            },
            {
                "question_id": 2,
                "question_text": "Solve for x: 2x + 5 = 15",
                "student_answer": "5",
                "correct_answer": "5",
                "is_correct": true,
                "marks": 3
            }
        ],
        "statistics": {
            "total_questions": 20,
            "correct_answers": 18,
            "wrong_answers": 2,
            "unanswered": 0,
            "time_taken_minutes": 55
        }
    }
}
```

---

### 7. **Create CBT Questions (Admin/Teacher)**

**Endpoint:** `POST /api/v1/assessments/cbt/{exam_id}/questions/create`

**Request Body:**
```json
{
    "questions": [
        {
            "question_text": "What is 2 + 2?",
            "question_type": "multiple_choice",
            "marks": 2,
            "options": ["2", "3", "4", "5"],
            "correct_answer": "4",
            "explanation": "Basic arithmetic",
            "order": 1
        },
        {
            "question_text": "Define photosynthesis",
            "question_type": "essay",
            "marks": 5,
            "order": 2
        }
    ]
}
```

**Question Types:**
- `multiple_choice` - Multiple choice (with options)
- `true_false` - True or False
- `short_answer` - Short text answer
- `essay` - Long text answer
- `fill_in_blank` - Fill in the blanks

**Response (201):**
```json
{
    "message": "20 questions created successfully",
    "questions": [ ... ]
}
```

---

## Results & Grading

### 1. **List Results**

**Endpoint:** `GET /api/v1/assessments/results`

**Query Parameters:**
- `student_id` (optional)
- `exam_id` (optional)
- `class_id` (optional)
- `subject_id` (optional)
- `term_id` (optional)
- `academic_year_id` (optional)
- `per_page` (optional, default: 15)

**Response (200):**
```json
{
    "results": {
        "data": [
            {
                "id": 1,
                "student_id": 50,
                "exam_id": 1,
                "subject_id": 2,
                "score": 85,
                "total_marks": 100,
                "percentage": 85.0,
                "grade": "A",
                "remarks": "Excellent performance",
                "created_at": "2025-12-01T12:00:00Z",
                "student": {
                    "id": 50,
                    "name": "John Doe",
                    "admission_number": "2025001"
                },
                "exam": {
                    "id": 1,
                    "title": "Mathematics Mid-Term"
                }
            }
        ],
        "current_page": 1,
        "per_page": 15,
        "total": 30
    }
}
```

---

### 2. **Create Result**

**Endpoint:** `POST /api/v1/assessments/results`

**Request Body:**
```json
{
    "student_id": 50,
    "exam_id": 1,
    "subject_id": 2,
    "score": 85,
    "total_marks": 100,
    "grade": "A",
    "remarks": "Excellent performance"
}
```

**Response (201):**
```json
{
    "message": "Result created successfully",
    "result": {
        "id": 1,
        "student_id": 50,
        "exam_id": 1,
        "score": 85,
        "percentage": 85.0,
        "grade": "A",
        ...
    }
}
```

---

### 3. **Update Result**

**Endpoint:** `PUT /api/v1/assessments/results/{result_id}`

**Request Body:**
```json
{
    "score": 90,
    "grade": "A+",
    "remarks": "Outstanding!"
}
```

**Response (200):**
```json
{
    "message": "Result updated successfully",
    "result": { ... }
}
```

---

### 4. **Delete Result**

**Endpoint:** `DELETE /api/v1/assessments/results/{result_id}`

**Response (200):**
```json
{
    "message": "Result deleted successfully"
}
```

---

### 5. **Generate Results (Bulk)**

**Endpoint:** `POST /api/v1/results/mid-term/generate`

**Description:** Generate results for all students in a class.

**Request Body:**
```json
{
    "class_id": 5,
    "term_id": 1,
    "academic_year_id": 1
}
```

**Response (200):**
```json
{
    "message": "Results generated successfully",
    "total_generated": 30,
    "students_processed": 30
}
```

---

### 6. **Publish Results**

**Endpoint:** `POST /api/v1/results/publish`

**Description:** Make results visible to students and parents.

**Request Body:**
```json
{
    "exam_id": 1,
    "class_id": 5
}
```

**Response (200):**
```json
{
    "message": "Results published successfully",
    "total_published": 30
}
```

---

## Bulk Operations

### **Bulk Create Exams**

**Endpoint:** `POST /api/v1/bulk/exams/create`

**Request Body:**
```json
{
    "exams": [
        {
            "title": "Math Mid-Term",
            "subject_id": 2,
            "class_id": 5,
            ...
        },
        {
            "title": "English Mid-Term",
            "subject_id": 3,
            "class_id": 5,
            ...
        }
    ]
}
```

**Response (201):**
```json
{
    "message": "Exams created successfully",
    "total_created": 2,
    "exams": [ ... ]
}
```

---

## Request/Response Examples

### Example 1: Create Traditional Exam

```bash
curl -X POST "https://api.compasse.net/api/v1/assessments/exams" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Mathematics Mid-Term Exam",
    "subject_id": 2,
    "class_id": 5,
    "term_id": 1,
    "academic_year_id": 1,
    "start_date": "2025-12-01 09:00:00",
    "end_date": "2025-12-01 11:00:00",
    "total_marks": 100,
    "passing_marks": 50,
    "exam_type": "mid_term",
    "is_cbt": false
  }'
```

### Example 2: Create CBT Exam

```bash
curl -X POST "https://api.compasse.net/api/v1/assessments/exams" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain": westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Science CBT Assessment",
    "subject_id": 4,
    "class_id": 6,
    "term_id": 1,
    "academic_year_id": 1,
    "start_date": "2025-12-05 10:00:00",
    "end_date": "2025-12-05 11:00:00",
    "duration_minutes": 60,
    "total_marks": 50,
    "passing_marks": 25,
    "exam_type": "ca1",
    "is_cbt": true,
    "instructions": "Answer all questions. You cannot go back once you move forward."
  }'
```

### Example 3: Get All Exams for a Class

```bash
curl -X GET "https://api.compasse.net/api/v1/assessments/exams?class_id=5&status=scheduled" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

### Example 4: Student Takes CBT Exam

```bash
# 1. Start exam session
curl -X POST "https://api.compasse.net/api/v1/cbt/start" \
  -H "Authorization: Bearer STUDENT_TOKEN" \
  -H "X-Subdomain: westwood" \
  -d '{"exam_id": 1}'

# 2. Get questions
curl -X GET "https://api.compasse.net/api/v1/assessments/cbt/1/questions" \
  -H "Authorization: Bearer STUDENT_TOKEN" \
  -H "X-Subdomain: westwood"

# 3. Submit answers
curl -X POST "https://api.compasse.net/api/v1/assessments/cbt/submit" \
  -H "Authorization: Bearer STUDENT_TOKEN" \
  -H "X-Subdomain: westwood" \
  -d '{
    "exam_id": 1,
    "answers": [
      {"question_id": 1, "answer": "4"},
      {"question_id": 2, "answer": "5"}
    ]
  }'

# 4. Get results
curl -X GET "https://api.compasse.net/api/v1/assessments/cbt/session/10/results" \
  -H "Authorization: Bearer STUDENT_TOKEN" \
  -H "X-Subdomain: westwood"
```

---

## Best Practices

### 1. **Exam Creation**
- Set clear start and end dates
- Include detailed instructions
- Specify passing marks
- Use appropriate exam types
- Add venue information for traditional exams

### 2. **CBT Exams**
- Set reasonable duration_minutes
- Include clear instructions
- Test questions before publishing
- Enable time limits and auto-submit
- Provide immediate feedback when possible

### 3. **Results Management**
- Record results promptly
- Include constructive remarks
- Publish results only when ready
- Generate reports for analysis
- Notify parents/guardians

### 4. **Security**
- Never expose correct answers before exam completion
- Track exam attempts and prevent cheating
- Use session timeouts for CBT exams
- Monitor unusual activity
- Secure question banks

---

## Permissions

| Role | Create Exam | Update Exam | Delete Exam | View All Exams | Take Exam | Grade Exam |
|------|-------------|-------------|-------------|----------------|-----------|------------|
| Super Admin | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| School Admin | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| Teacher | ✅ | ✅ (own) | ✅ (own) | ✅ (assigned) | ❌ | ✅ (assigned) |
| Student | ❌ | ❌ | ❌ | ✅ (assigned) | ✅ | ❌ |
| Parent/Guardian | ❌ | ❌ | ❌ | ✅ (child's) | ❌ | ❌ |

---

## Related Documentation

- **Complete Admin APIs:** `COMPLETE_ADMIN_API_DOCUMENTATION.md`
- **Results & Grading:** Section above
- **API Simplification:** `API_SIMPLIFICATION_SUMMARY.md`

---

**Last Updated:** November 24, 2025  
**API Version:** 1.0  
**Module:** Exam & CBT Management  
**Status:** ✅ Ready for Production

