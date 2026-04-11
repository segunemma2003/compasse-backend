# Assessment, CBT & Results

> **Base URL:** `https://{subdomain}.compasse.net/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `cbt`

---

## Overview

The assessment module covers Computer-Based Testing (CBT), assignments, continuous assessment (CA), psychomotor assessment, grading systems, result generation, report cards, scoreboards, and student promotion. All endpoints are scoped to the authenticated school's tenant database.

---

## User Stories

> **As a teacher**, I want to create exams with questions and set a time limit so students take tests on their devices.

> **As a student**, I want to start a CBT exam, answer questions one at a time, and see my result immediately after submission.

> **As a teacher**, I want to create assignments, receive submissions, and grade each one.

> **As a principal**, I want to generate end-of-term results that combine CA scores and exam scores, produce report cards, and bulk-email them to parents.

> **As an admin**, I want to promote students who pass to the next class at the end of the year, and graduate SS3 students.

---

## Exam Workflow

```
Teacher creates exam (with questions array)
      │
      ▼
Exam published / made available to class
      │
      ▼
Student starts exam session    POST /assessments/cbt/start
      │
      ▼
Student submits answers one by one  POST /assessments/cbt/submit-answer
      │
      ▼
Student submits final answers  POST /assessments/cbt/submit
      │
      ▼
System auto-grades + stores result
      │
      ▼
Student views result           GET /assessments/cbt/session/{id}/results
```

---

## API Endpoints

**Base path:** `/api/v1/assessments/`

### Exams

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/exams` | List exams |
| POST | `/exams` | Create exam with questions array |
| GET | `/exams/{id}` | Get exam details |
| PUT | `/exams/{id}` | Update exam |
| DELETE | `/exams/{id}` | Delete exam |

### CBT Sessions

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/cbt/start` | Start a CBT session |
| POST | `/cbt/submit-answer` | Submit a single answer during session |
| POST | `/cbt/submit` | Submit all answers and get score |
| GET | `/cbt/{exam}/questions` | Get questions for an exam |
| GET | `/cbt/attempts/{attempt}/status` | Check session status |
| GET | `/cbt/session/{id}/results` | Get session result |

### Assignments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/assignments` | List assignments |
| POST | `/assignments` | Create assignment |
| GET | `/assignments/{id}` | Get assignment details |
| PUT | `/assignments/{id}` | Update assignment |
| DELETE | `/assignments/{id}` | Delete assignment |
| GET | `/assignments/{id}/submissions` | List submissions + statistics |
| POST | `/assignments/{id}/submit` | Student submits assignment |
| PUT | `/assignments/{id}/grade` | Teacher grades submission |

### Continuous Assessment

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/continuous-assessments` | List CA entries |
| POST | `/continuous-assessments` | Create CA |
| PUT | `/continuous-assessments/{id}` | Update CA |
| DELETE | `/continuous-assessments/{id}` | Delete CA |
| POST | `/continuous-assessments/{id}/record-scores` | Bulk record scores |
| GET | `/continuous-assessments/{id}/scores` | Get scores for CA |
| GET | `/continuous-assessments/student/{id}/scores` | All CA scores for a student |

### Psychomotor Assessment

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/psychomotor-assessments/{studentId}/{termId}/{yearId}` | Get student's psychomotor report |
| POST | `/psychomotor-assessments` | Record single assessment |
| POST | `/psychomotor-assessments/bulk` | Bulk record for entire class |
| GET | `/psychomotor-assessments/class/{classId}` | Class psychomotor report |
| DELETE | `/psychomotor-assessments/{id}` | Delete record |

### Grading System

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/grading-systems` | List grading systems |
| GET | `/grading-systems/default` | Get default grading system |
| POST | `/grading-systems` | Create grading system |
| PUT | `/grading-systems/{id}` | Update grading system |
| DELETE | `/grading-systems/{id}` | Delete grading system |
| POST | `/grading-systems/calculate-grade` | Calculate grade for a raw score |

### Results

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/results/generate` | Generate results for a term + class |
| GET | `/results/student/{id}/{termId}/{yearId}` | Get full student result sheet |
| GET | `/results/class/{classId}` | Class result summary |
| POST | `/results/{id}/comments` | Add teacher / principal comment |
| POST | `/results/{id}/approve` | Approve a result |
| POST | `/results/publish` | Publish results to students and parents |

### Scoreboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/scoreboards/class/{classId}` | Class rankings |
| GET | `/scoreboards/top-performers` | Top students school-wide |
| GET | `/scoreboards/subject/{subjectId}/toppers` | Subject top scorers |
| GET | `/scoreboards/class-comparison` | Cross-class comparison |
| POST | `/scoreboards/refresh` | Manually refresh cached scoreboard |

### Report Cards

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/report-cards/{student}/{term}/{year}` | Get report card data (JSON) |
| GET | `/report-cards/{student}/{term}/{year}/pdf` | Download PDF (binary) |
| GET | `/report-cards/{student}/{term}/{year}/print` | Printable HTML version |
| POST | `/report-cards/bulk-download` | Bulk PDF for entire class |
| POST | `/report-cards/{student}/{term}/{year}/email` | Email report card to parent |

### Promotion & Graduation

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/promotions` | List all promotions |
| POST | `/promotions/promote` | Promote a single student |
| POST | `/promotions/bulk-promote` | Promote an entire class |
| POST | `/promotions/auto-promote` | Auto-promote based on result data |
| POST | `/promotions/graduate` | Graduate SS3 / final-year students |
| GET | `/promotions/statistics` | Promotion statistics |
| DELETE | `/promotions/{id}` | Undo a promotion |

---

## Complete Request / Response Examples

### Create Exam (with questions array)

```http
POST /api/v1/assessments/exams
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Mathematics Mid-Term Exam",
  "subject_id": 4,
  "class_id": 7,
  "term_id": 2,
  "academic_year_id": 3,
  "duration_minutes": 60,
  "total_marks": 40,
  "pass_mark": 20,
  "instructions": "Answer all questions. No calculators allowed.",
  "shuffle_questions": true,
  "shuffle_options": true,
  "show_result_immediately": true,
  "status": "draft",
  "questions": [
    {
      "question_text": "What is the value of x if 2x + 4 = 10?",
      "question_type": "multiple_choice",
      "marks": 2,
      "order": 1,
      "options": [
        { "option_text": "2", "is_correct": false },
        { "option_text": "3", "is_correct": true },
        { "option_text": "4", "is_correct": false },
        { "option_text": "5", "is_correct": false }
      ]
    },
    {
      "question_text": "The square root of 144 is ___.",
      "question_type": "multiple_choice",
      "marks": 2,
      "order": 2,
      "options": [
        { "option_text": "10", "is_correct": false },
        { "option_text": "11", "is_correct": false },
        { "option_text": "12", "is_correct": true },
        { "option_text": "14", "is_correct": false }
      ]
    },
    {
      "question_text": "Pi is approximately equal to 3.14159.",
      "question_type": "true_false",
      "marks": 1,
      "order": 3,
      "options": [
        { "option_text": "True", "is_correct": true },
        { "option_text": "False", "is_correct": false }
      ]
    }
  ]
}
```

Response `201 Created`:
```json
{
  "message": "Exam created successfully",
  "data": {
    "id": 18,
    "title": "Mathematics Mid-Term Exam",
    "subject_id": 4,
    "class_id": 7,
    "term_id": 2,
    "academic_year_id": 3,
    "duration_minutes": 60,
    "total_marks": 40,
    "pass_mark": 20,
    "status": "draft",
    "questions_count": 3,
    "created_at": "2026-03-30T08:00:00.000000Z"
  }
}
```

---

### List Exams

```http
GET /api/v1/assessments/exams?class_id=7&term_id=2&status=published
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 18,
      "title": "Mathematics Mid-Term Exam",
      "subject": { "id": 4, "name": "Mathematics" },
      "class": { "id": 7, "name": "JSS 1A" },
      "duration_minutes": 60,
      "total_marks": 40,
      "status": "published",
      "questions_count": 40,
      "created_at": "2026-03-30T08:00:00.000000Z"
    }
  ],
  "current_page": 1,
  "last_page": 1,
  "per_page": 15,
  "total": 1
}
```

---

### Get Exam Details

```http
GET /api/v1/assessments/exams/18
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": {
    "id": 18,
    "title": "Mathematics Mid-Term Exam",
    "subject": { "id": 4, "name": "Mathematics" },
    "class": { "id": 7, "name": "JSS 1A" },
    "term": { "id": 2, "name": "Second Term" },
    "academic_year": { "id": 3, "name": "2025/2026" },
    "duration_minutes": 60,
    "total_marks": 40,
    "pass_mark": 20,
    "status": "published",
    "shuffle_questions": true,
    "shuffle_options": true,
    "show_result_immediately": true,
    "instructions": "Answer all questions. No calculators allowed.",
    "questions": [
      {
        "id": 101,
        "question_text": "What is the value of x if 2x + 4 = 10?",
        "question_type": "multiple_choice",
        "marks": 2,
        "order": 1,
        "options": [
          { "id": 1, "option_text": "2", "is_correct": false },
          { "id": 2, "option_text": "3", "is_correct": true },
          { "id": 3, "option_text": "4", "is_correct": false },
          { "id": 4, "option_text": "5", "is_correct": false }
        ]
      }
    ],
    "created_at": "2026-03-30T08:00:00.000000Z"
  }
}
```

---

### CBT Session Flow

#### Step 1 — Start Session

```http
POST /api/v1/assessments/cbt/start
Authorization: Bearer {token}
Content-Type: application/json

{
  "exam_id": 18
}
```

Response `201 Created`:
```json
{
  "message": "Exam session started",
  "data": {
    "session_id": 55,
    "exam_id": 18,
    "student_id": 210,
    "started_at": "2026-03-30T09:00:00.000000Z",
    "expires_at": "2026-03-30T10:00:00.000000Z",
    "duration_minutes": 60,
    "total_questions": 40,
    "questions": [
      {
        "id": 101,
        "question_text": "What is the value of x if 2x + 4 = 10?",
        "question_type": "multiple_choice",
        "marks": 2,
        "order": 1,
        "options": [
          { "id": 1, "option_text": "2" },
          { "id": 2, "option_text": "3" },
          { "id": 3, "option_text": "4" },
          { "id": 4, "option_text": "5" }
        ]
      }
    ]
  }
}
```

> Note: Correct answer flags (`is_correct`) are stripped from the student-facing question list.

---

#### Step 2 — Submit Single Answer (per question)

```http
POST /api/v1/assessments/cbt/submit-answer
Authorization: Bearer {token}
Content-Type: application/json

{
  "session_id": 55,
  "question_id": 101,
  "selected_option_id": 2
}
```

Response `200 OK`:
```json
{
  "message": "Answer saved",
  "data": {
    "session_id": 55,
    "question_id": 101,
    "selected_option_id": 2,
    "saved_at": "2026-03-30T09:03:45.000000Z"
  }
}
```

---

#### Step 3 — Submit All and Get Score

```http
POST /api/v1/assessments/cbt/submit
Authorization: Bearer {token}
Content-Type: application/json

{
  "session_id": 55
}
```

Response `200 OK`:
```json
{
  "message": "Exam submitted successfully",
  "data": {
    "session_id": 55,
    "exam_title": "Mathematics Mid-Term Exam",
    "student": { "id": 210, "name": "Tolu Adeyemi" },
    "score": 32,
    "total_marks": 40,
    "percentage": 80.0,
    "passed": true,
    "pass_mark": 20,
    "grade": "A",
    "remark": "Excellent",
    "time_taken_minutes": 45,
    "submitted_at": "2026-03-30T09:45:00.000000Z",
    "correct_answers": 16,
    "wrong_answers": 4,
    "unanswered": 0
  }
}
```

---

#### Get Session Result

```http
GET /api/v1/assessments/cbt/session/55/results
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": {
    "session_id": 55,
    "exam": { "id": 18, "title": "Mathematics Mid-Term Exam" },
    "student": { "id": 210, "name": "Tolu Adeyemi" },
    "score": 32,
    "total_marks": 40,
    "percentage": 80.0,
    "passed": true,
    "grade": "A",
    "remark": "Excellent",
    "submitted_at": "2026-03-30T09:45:00.000000Z",
    "answers": [
      {
        "question_id": 101,
        "question_text": "What is the value of x if 2x + 4 = 10?",
        "selected_option": "3",
        "correct_option": "3",
        "is_correct": true,
        "marks_earned": 2
      }
    ]
  }
}
```

---

### Assignment CRUD

#### Create Assignment

```http
POST /api/v1/assessments/assignments
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Essay: Causes of World War II",
  "description": "Write a 500-word essay on the causes of World War II.",
  "subject_id": 6,
  "class_id": 9,
  "term_id": 2,
  "academic_year_id": 3,
  "due_date": "2026-04-10",
  "total_marks": 20,
  "attachment_url": null
}
```

Response `201 Created`:
```json
{
  "message": "Assignment created successfully",
  "data": {
    "id": 33,
    "title": "Essay: Causes of World War II",
    "subject": { "id": 6, "name": "History" },
    "class": { "id": 9, "name": "SS 2A" },
    "due_date": "2026-04-10",
    "total_marks": 20,
    "submissions_count": 0,
    "created_at": "2026-03-30T10:00:00.000000Z"
  }
}
```

#### List Assignments

```http
GET /api/v1/assessments/assignments?class_id=9&subject_id=6&term_id=2
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": [
    {
      "id": 33,
      "title": "Essay: Causes of World War II",
      "subject": { "id": 6, "name": "History" },
      "class": { "id": 9, "name": "SS 2A" },
      "due_date": "2026-04-10",
      "total_marks": 20,
      "submissions_count": 18,
      "graded_count": 5,
      "created_at": "2026-03-30T10:00:00.000000Z"
    }
  ],
  "current_page": 1,
  "last_page": 1,
  "per_page": 15,
  "total": 1
}
```

#### Student Submits Assignment

```http
POST /api/v1/assessments/assignments/33/submit
Authorization: Bearer {token}
Content-Type: application/json

{
  "student_id": 210,
  "submission_text": "World War II was caused by multiple interrelated factors including the rise of fascism, the failure of appeasement...",
  "attachment_url": "https://storage.compasse.net/submissions/essay-33-210.pdf"
}
```

Response `201 Created`:
```json
{
  "message": "Assignment submitted successfully",
  "data": {
    "id": 88,
    "assignment_id": 33,
    "student": { "id": 210, "name": "Tolu Adeyemi" },
    "submitted_at": "2026-04-09T14:22:00.000000Z",
    "status": "submitted",
    "is_late": false
  }
}
```

#### Teacher Grades Submission

```http
PUT /api/v1/assessments/assignments/33/grade
Authorization: Bearer {token}
Content-Type: application/json

{
  "submission_id": 88,
  "score": 17,
  "feedback": "Good structure and solid analysis. Work on your conclusion."
}
```

Response `200 OK`:
```json
{
  "message": "Submission graded successfully",
  "data": {
    "submission_id": 88,
    "student": { "id": 210, "name": "Tolu Adeyemi" },
    "score": 17,
    "total_marks": 20,
    "percentage": 85.0,
    "grade": "A",
    "feedback": "Good structure and solid analysis. Work on your conclusion.",
    "graded_by": { "id": 3, "name": "Mrs. Funmilayo Adesanya" },
    "graded_at": "2026-04-11T09:00:00.000000Z"
  }
}
```

#### List Submissions for an Assignment

```http
GET /api/v1/assessments/assignments/33/submissions
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "assignment": { "id": 33, "title": "Essay: Causes of World War II", "total_marks": 20 },
  "statistics": {
    "total_students": 30,
    "submitted": 18,
    "not_submitted": 12,
    "graded": 5,
    "average_score": 14.2
  },
  "submissions": [
    {
      "id": 88,
      "student": { "id": 210, "name": "Tolu Adeyemi" },
      "submitted_at": "2026-04-09T14:22:00.000000Z",
      "score": 17,
      "status": "graded",
      "is_late": false
    }
  ]
}
```

---

### Continuous Assessment (CA)

#### Create CA

```http
POST /api/v1/assessments/continuous-assessments
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "First CA — Mathematics",
  "subject_id": 4,
  "class_id": 7,
  "term_id": 2,
  "academic_year_id": 3,
  "total_marks": 20,
  "ca_type": "test",
  "date": "2026-03-15"
}
```

Response `201 Created`:
```json
{
  "message": "Continuous assessment created",
  "data": {
    "id": 12,
    "title": "First CA — Mathematics",
    "subject": { "id": 4, "name": "Mathematics" },
    "class": { "id": 7, "name": "JSS 1A" },
    "total_marks": 20,
    "ca_type": "test",
    "date": "2026-03-15",
    "scores_recorded": 0
  }
}
```

#### Bulk Record Scores

```http
POST /api/v1/assessments/continuous-assessments/12/record-scores
Authorization: Bearer {token}
Content-Type: application/json

{
  "scores": [
    { "student_id": 210, "score": 18 },
    { "student_id": 211, "score": 15 },
    { "student_id": 212, "score": 12 },
    { "student_id": 213, "score": 20 },
    { "student_id": 214, "score": 9 }
  ]
}
```

Response `200 OK`:
```json
{
  "message": "Scores recorded successfully",
  "data": {
    "ca_id": 12,
    "scores_recorded": 5,
    "average_score": 14.8,
    "highest_score": 20,
    "lowest_score": 9
  }
}
```

#### Get Scores for a CA

```http
GET /api/v1/assessments/continuous-assessments/12/scores
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "ca": {
    "id": 12,
    "title": "First CA — Mathematics",
    "total_marks": 20
  },
  "scores": [
    { "student": { "id": 210, "name": "Tolu Adeyemi" }, "score": 18, "percentage": 90.0 },
    { "student": { "id": 211, "name": "Kemi Okafor" }, "score": 15, "percentage": 75.0 }
  ],
  "statistics": {
    "average": 14.8,
    "highest": 20,
    "lowest": 9,
    "pass_rate": 80.0
  }
}
```

---

### Psychomotor Assessment

#### Record Single Psychomotor Assessment

```http
POST /api/v1/assessments/psychomotor-assessments
Authorization: Bearer {token}
Content-Type: application/json

{
  "student_id": 210,
  "term_id": 2,
  "academic_year_id": 3,
  "skill": "Handwriting",
  "rating": 4,
  "remark": "Neat and well-formed letters",
  "teacher_id": 3
}
```

Response `201 Created`:
```json
{
  "message": "Psychomotor assessment recorded",
  "data": {
    "id": 45,
    "student": { "id": 210, "name": "Tolu Adeyemi" },
    "skill": "Handwriting",
    "rating": 4,
    "remark": "Neat and well-formed letters",
    "term": { "id": 2, "name": "Second Term" },
    "created_at": "2026-03-30T10:00:00.000000Z"
  }
}
```

#### Bulk Record for Entire Class

```http
POST /api/v1/assessments/psychomotor-assessments/bulk
Authorization: Bearer {token}
Content-Type: application/json

{
  "class_id": 7,
  "term_id": 2,
  "academic_year_id": 3,
  "skill": "Drawing",
  "assessments": [
    { "student_id": 210, "rating": 5, "remark": "Exceptional artistic talent" },
    { "student_id": 211, "rating": 3, "remark": "Average performance" },
    { "student_id": 212, "rating": 4, "remark": "Good creativity" }
  ]
}
```

Response `200 OK`:
```json
{
  "message": "Bulk psychomotor assessments recorded",
  "data": {
    "class_id": 7,
    "skill": "Drawing",
    "recorded_count": 3,
    "average_rating": 4.0
  }
}
```

---

### Grading System

#### Create Grading System

```http
POST /api/v1/assessments/grading-systems
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Standard Nigerian WAEC Scale",
  "is_default": true,
  "grade_ranges": [
    { "min_score": 75, "max_score": 100, "grade": "A1", "remark": "Excellent", "points": 5 },
    { "min_score": 70, "max_score": 74, "grade": "B2", "remark": "Very Good", "points": 4 },
    { "min_score": 65, "max_score": 69, "grade": "B3", "remark": "Good", "points": 3 },
    { "min_score": 60, "max_score": 64, "grade": "C4", "remark": "Credit", "points": 3 },
    { "min_score": 55, "max_score": 59, "grade": "C5", "remark": "Credit", "points": 2 },
    { "min_score": 50, "max_score": 54, "grade": "C6", "remark": "Credit", "points": 2 },
    { "min_score": 45, "max_score": 49, "grade": "D7", "remark": "Pass", "points": 1 },
    { "min_score": 40, "max_score": 44, "grade": "E8", "remark": "Pass", "points": 1 },
    { "min_score": 0, "max_score": 39, "grade": "F9", "remark": "Fail", "points": 0 }
  ]
}
```

Response `201 Created`:
```json
{
  "message": "Grading system created successfully",
  "data": {
    "id": 2,
    "name": "Standard Nigerian WAEC Scale",
    "is_default": true,
    "grade_ranges_count": 9,
    "created_at": "2026-03-30T10:00:00.000000Z"
  }
}
```

#### Calculate Grade for a Score

```http
POST /api/v1/assessments/grading-systems/calculate-grade
Authorization: Bearer {token}
Content-Type: application/json

{
  "score": 72,
  "grading_system_id": 2
}
```

Response `200 OK`:
```json
{
  "score": 72,
  "grade": "B2",
  "remark": "Very Good",
  "points": 4,
  "grading_system": "Standard Nigerian WAEC Scale"
}
```

---

### Result Generation

#### Generate Results (Term + Class)

```http
POST /api/v1/assessments/results/generate
Authorization: Bearer {token}
Content-Type: application/json

{
  "term_id": 2,
  "academic_year_id": 3,
  "class_id": 7
}
```

Response `200 OK`:
```json
{
  "message": "Results generated successfully",
  "data": {
    "class": { "id": 7, "name": "JSS 1A" },
    "term": { "id": 2, "name": "Second Term" },
    "academic_year": { "id": 3, "name": "2025/2026" },
    "students_processed": 30,
    "subjects_included": 9,
    "generated_at": "2026-03-30T11:00:00.000000Z"
  }
}
```

#### Get Student Result Sheet (full JSON)

```http
GET /api/v1/assessments/results/student/210/2/3
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "data": {
    "student": {
      "id": 210,
      "name": "Tolu Adeyemi",
      "admission_number": "GF/2024/001",
      "class": "JSS 1A",
      "photo_url": "https://storage.compasse.net/photos/210.jpg"
    },
    "term": { "id": 2, "name": "Second Term" },
    "academic_year": { "id": 3, "name": "2025/2026" },
    "school": { "name": "Greenfield Academy", "logo_url": "..." },
    "subjects": [
      {
        "subject": { "id": 4, "name": "Mathematics" },
        "ca_score": 36,
        "exam_score": 64,
        "total_score": 100,
        "grade": "A1",
        "remark": "Excellent",
        "teacher": "Mr. Biodun Okonkwo",
        "teacher_comment": "Outstanding performance. Keep it up!"
      },
      {
        "subject": { "id": 6, "name": "History" },
        "ca_score": 28,
        "exam_score": 55,
        "total_score": 83,
        "grade": "B2",
        "remark": "Very Good",
        "teacher": "Mrs. Funmilayo Adesanya",
        "teacher_comment": "Good work. Pay attention to dates."
      }
    ],
    "summary": {
      "total_subjects": 9,
      "total_score_obtained": 720,
      "total_score_obtainable": 900,
      "average": 80.0,
      "overall_grade": "A",
      "class_position": 3,
      "class_size": 30,
      "times_present": 52,
      "times_absent": 6,
      "next_term_begins": "2026-05-06"
    },
    "psychomotor": [
      { "skill": "Handwriting", "rating": 4, "remark": "Neat" },
      { "skill": "Drawing", "rating": 5, "remark": "Excellent" }
    ],
    "teacher_comment": "Tolu is a dedicated student. Encourage her to participate more in class.",
    "principal_comment": "A very promising student. We look forward to her continued growth.",
    "status": "approved",
    "approved_by": { "id": 1, "name": "Mr. Chukwuemeka Obi" },
    "approved_at": "2026-03-28T14:00:00.000000Z"
  }
}
```

#### Add Teacher / Principal Comment

```http
POST /api/v1/assessments/results/77/comments
Authorization: Bearer {token}
Content-Type: application/json

{
  "teacher_comment": "Tolu is a dedicated student with great potential.",
  "principal_comment": "A very promising student. We expect continued excellence."
}
```

Response `200 OK`:
```json
{
  "message": "Comments added successfully",
  "data": {
    "result_id": 77,
    "teacher_comment": "Tolu is a dedicated student with great potential.",
    "principal_comment": "A very promising student. We expect continued excellence."
  }
}
```

#### Approve Result

```http
POST /api/v1/assessments/results/77/approve
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "message": "Result approved successfully",
  "data": {
    "result_id": 77,
    "status": "approved",
    "approved_by": { "id": 1, "name": "Mr. Chukwuemeka Obi" },
    "approved_at": "2026-03-30T14:00:00.000000Z"
  }
}
```

#### Publish Results

```http
POST /api/v1/assessments/results/publish
Authorization: Bearer {token}
Content-Type: application/json

{
  "term_id": 2,
  "academic_year_id": 3,
  "class_id": 7,
  "notify_parents": true,
  "notification_channel": "sms"
}
```

Response `200 OK`:
```json
{
  "message": "Results published successfully",
  "data": {
    "published_count": 30,
    "notifications_queued": 28,
    "published_at": "2026-03-30T15:00:00.000000Z"
  }
}
```

---

### Scoreboard

#### Class Rankings

```http
GET /api/v1/assessments/scoreboards/class/7?term_id=2&academic_year_id=3
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "class": { "id": 7, "name": "JSS 1A" },
  "term": { "id": 2, "name": "Second Term" },
  "rankings": [
    {
      "position": 1,
      "student": { "id": 215, "name": "Amara Eze", "admission_number": "GF/2024/006" },
      "total_score": 845,
      "average": 93.9,
      "overall_grade": "A1"
    },
    {
      "position": 2,
      "student": { "id": 218, "name": "Emeka Nwosu" },
      "total_score": 830,
      "average": 92.2,
      "overall_grade": "A1"
    },
    {
      "position": 3,
      "student": { "id": 210, "name": "Tolu Adeyemi" },
      "total_score": 720,
      "average": 80.0,
      "overall_grade": "A"
    }
  ],
  "total_students": 30
}
```

#### Top Performers School-Wide

```http
GET /api/v1/assessments/scoreboards/top-performers?term_id=2&academic_year_id=3&limit=10
Authorization: Bearer {token}
```

Response `200 OK`:
```json
{
  "term": { "id": 2, "name": "Second Term" },
  "top_performers": [
    {
      "position": 1,
      "student": { "id": 215, "name": "Amara Eze", "class": "JSS 1A" },
      "average": 93.9,
      "overall_grade": "A1"
    }
  ],
  "total_in_school": 450
}
```

---

### Report Card

#### Get Report Card Data (JSON)

```http
GET /api/v1/assessments/report-cards/210/2/3
Authorization: Bearer {token}
```

Response: Same full JSON structure as the result sheet above, formatted for display in a card layout.

#### Download PDF

```http
GET /api/v1/assessments/report-cards/210/2/3/pdf
Authorization: Bearer {token}
```

Response: Binary PDF file.
```
Content-Type: application/pdf
Content-Disposition: attachment; filename="report-card-Tolu-Adeyemi-Term2-2025-2026.pdf"
```

#### Email Report Card to Parent

```http
POST /api/v1/assessments/report-cards/210/2/3/email
Authorization: Bearer {token}
Content-Type: application/json

{
  "email": "parent@example.com",
  "message": "Please find attached Tolu's second term report card."
}
```

Response `200 OK`:
```json
{
  "message": "Report card emailed successfully",
  "recipient": "parent@example.com",
  "status": "queued"
}
```

#### Bulk Download for Class

```http
POST /api/v1/assessments/report-cards/bulk-download
Authorization: Bearer {token}
Content-Type: application/json

{
  "class_id": 7,
  "term_id": 2,
  "academic_year_id": 3
}
```

Response `200 OK`:
```json
{
  "message": "Bulk PDF generation queued",
  "download_url": "https://storage.compasse.net/bulk-reports/class-7-term-2-2025-2026.zip",
  "expires_at": "2026-03-31T15:00:00.000000Z"
}
```

---

### Promotion

#### Promote Single Student

```http
POST /api/v1/assessments/promotions/promote
Authorization: Bearer {token}
Content-Type: application/json

{
  "student_id": 210,
  "from_class_id": 7,
  "to_class_id": 8,
  "academic_year_id": 3,
  "reason": "End of year promotion"
}
```

Response `200 OK`:
```json
{
  "message": "Student promoted successfully",
  "data": {
    "id": 101,
    "student": { "id": 210, "name": "Tolu Adeyemi" },
    "from_class": { "id": 7, "name": "JSS 1A" },
    "to_class": { "id": 8, "name": "JSS 2A" },
    "promoted_at": "2026-07-01T09:00:00.000000Z",
    "promoted_by": { "id": 1, "name": "Mr. Chukwuemeka Obi" }
  }
}
```

#### Bulk Promote Class

```http
POST /api/v1/assessments/promotions/bulk-promote
Authorization: Bearer {token}
Content-Type: application/json

{
  "from_class_id": 7,
  "to_class_id": 8,
  "academic_year_id": 3,
  "student_ids": [210, 211, 212, 213, 214]
}
```

Response `200 OK`:
```json
{
  "message": "Bulk promotion completed",
  "data": {
    "promoted_count": 5,
    "failed_count": 0,
    "from_class": "JSS 1A",
    "to_class": "JSS 2A"
  }
}
```

#### Auto-Promote Based on Results

```http
POST /api/v1/assessments/promotions/auto-promote
Authorization: Bearer {token}
Content-Type: application/json

{
  "class_id": 7,
  "term_id": 2,
  "academic_year_id": 3,
  "pass_threshold": 40,
  "dry_run": false
}
```

Response `200 OK`:
```json
{
  "message": "Auto-promotion completed",
  "data": {
    "total_students": 30,
    "promoted": 26,
    "held_back": 4,
    "held_back_students": [
      { "id": 220, "name": "Kunle Bello", "average": 32.5 }
    ]
  }
}
```

---

## Analytics

**Base path:** `/api/v1/assessments/analytics/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/school` | School-wide performance summary |
| GET | `/class/{id}` | Class performance analytics |
| GET | `/subject/{id}` | Subject performance analytics |
| GET | `/student/{id}/trend` | Student performance over time |
| GET | `/comparative` | Cross-class / cross-term comparison |
| GET | `/student/{id}/prediction` | AI-based performance prediction |

---

## Question Bank

**Base path:** `/api/v1/question-bank/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | List question banks |
| POST | `/` | Create bank |
| GET | `/statistics` | Question statistics |
| GET | `/for-exam` | Get questions suitable for an exam |
| GET | `/{id}` | Get bank details |
| PUT | `/{id}` | Update |
| DELETE | `/{id}` | Delete |

---

## Frontend Integration

### Tenancy

The frontend sends requests to `https://{school_subdomain}.compasse.net/api/v1/assessments/...`. The subdomain is stored in `localStorage` after login and prepended to all API calls via an Axios base URL or interceptor.

### CBT Exam Player

The exam player component handles the full CBT session lifecycle:

1. **Start session** — `POST /assessments/cbt/start` with `exam_id`. Store `session_id` and `expires_at` in component state.
2. **Timer** — Calculate remaining time from `expires_at - now`. Display a countdown clock. Auto-submit when the timer reaches zero.
3. **Question navigation** — Render a numbered question palette. Answered questions are highlighted in green; unanswered in grey. Students can jump between questions freely.
4. **Per-question save** — On each option selection call `POST /assessments/cbt/submit-answer` silently in the background. Show a small "Saving..." indicator. This ensures no answers are lost on accidental refresh.
5. **Final submit** — Call `POST /assessments/cbt/submit` on explicit submit or timer expiry. Disable the submit button after the first click to prevent double submission.
6. **Result display** — If `show_result_immediately = true`, redirect to the result screen after submit and render the score, grade, and per-question breakdown from `GET /assessments/cbt/session/{id}/results`.

### Result Sheet Viewer

- Fetch the full result JSON from `GET /assessments/results/student/{id}/{termId}/{yearId}`.
- Display subject scores in a table with CA, Exam, Total, Grade, and Remark columns.
- Render the psychomotor section below the subject table.
- Show teacher and principal comments at the bottom.
- If `status !== "published"`, show a greyed-out preview banner ("Result pending approval").

### Report Card PDF Download

- Call `GET /assessments/report-cards/{student}/{term}/{year}/pdf` with `responseType: 'blob'` in Axios.
- Create a temporary `<a>` element with the blob URL and trigger a download.
- Show a loading spinner during the request.

```js
const response = await axios.get(
  `/assessments/report-cards/${studentId}/${termId}/${yearId}/pdf`,
  { responseType: 'blob' }
);
const url = window.URL.createObjectURL(new Blob([response.data]));
const link = document.createElement('a');
link.href = url;
link.setAttribute('download', `report-card-${studentId}.pdf`);
document.body.appendChild(link);
link.click();
link.remove();
```

---

## Business Rules

1. **Module gate** — All endpoints require the `cbt` module to be active on the school's subscription.
2. **Session expiry** — CBT sessions expire at `started_at + duration_minutes`. Submission after expiry is rejected with HTTP 422.
3. **Auto-grade** — Objective questions (multiple_choice, true_false) are auto-graded on submit. Subjective questions require manual teacher grading.
4. **Result generation** — `POST /results/generate` aggregates all CA scores and exam scores for the given term, class, and year. Re-running it overwrites any existing draft results.
5. **Approve before publish** — Results must be in `approved` status before `POST /results/publish` succeeds.
6. **Promotion audit** — Every promotion creates a record in the `promotions` table with `promoted_by` and timestamp for audit trail. Use `DELETE /promotions/{id}` to undo.
