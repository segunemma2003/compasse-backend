# Academic Management

> **Base URL:** `https://{subdomain}.compasse.net/api/v1/`
> **Auth:** `Authorization: Bearer {token}` required on all protected endpoints
> **Module gate:** `academic_management` (students: `student_management`, teachers: `teacher_management`)

---

## Overview

The academic module is the structural backbone of the system. It manages the academic calendar, class organisation, subjects, departments, and student/teacher assignment.

---

## User Stories

> **As a school admin**, I want to set up academic years and terms so all activity (attendance, exams, fees) is properly dated.

> **As an admin**, I want to create classes, arms (streams), and subjects so teachers and students can be assigned correctly.

> **As a principal**, I want to view the organogram of teachers and classes in my school.

> **As a teacher**, I want to see which classes and subjects I'm responsible for.

---

## Data Hierarchy

```
School
  └── AcademicYear  (e.g. "2025/2026")
        └── Term    (e.g. "First Term", "Second Term")

School
  └── Department    (e.g. "Science", "Arts")
        └── Subject (e.g. "Chemistry", "Literature")

School
  └── Class         (e.g. "JSS 1", "SS 2")
        └── Arm     (e.g. "A", "B", "Gold", "Silver")
              └── Students
```

---

## API Endpoints

**Base path:** `/api/v1/` (all require `academic_management` module unless noted)

### Academic Years

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/academic-years` | List academic years |
| POST | `/academic-years` | Create academic year |
| GET | `/academic-years/{id}` | Get details + terms |
| PUT | `/academic-years/{id}` | Update |
| DELETE | `/academic-years/{id}` | Delete (blocked if has terms) |

### Terms

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/terms` | List terms (filter: `academic_year_id`, `status`) |
| POST | `/terms` | Create term |
| GET | `/terms/{id}` | Get term |
| PUT | `/terms/{id}` | Update term |
| DELETE | `/terms/{id}` | Delete term |

### Departments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/departments` | List departments |
| POST | `/departments` | Create department |
| GET | `/departments/{id}` | Get department + subjects |
| PUT | `/departments/{id}` | Update |
| DELETE | `/departments/{id}` | Delete (blocked if has subjects) |

### Classes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/classes` | List classes |
| POST | `/classes` | Create class |
| GET | `/classes/{id}` | Get class + arm details |
| PUT | `/classes/{id}` | Update |
| DELETE | `/classes/{id}` | Delete (blocked if has students) |
| GET | `/classes/{id}/students` | Students in this class (all arms) |

### Arms (Streams)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/arms` | List all arms |
| POST | `/arms` | Create arm |
| GET | `/arms/{id}` | Get arm |
| PUT | `/arms/{id}` | Update arm |
| DELETE | `/arms/{id}` | Delete arm (blocked if has students) |
| POST | `/arms/assign-to-class` | Assign arm to a class |
| POST | `/arms/remove-from-class` | Remove arm from class |
| GET | `/arms/class/{classId}` | Arms belonging to a class |
| GET | `/arms/{armId}/students` | Students in a specific arm |

### Subjects

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/subjects` | List subjects (filter: `department_id`, `class_id`) |
| POST | `/subjects` | Create subject |
| GET | `/subjects/{id}` | Get subject |
| PUT | `/subjects/{id}` | Update subject |
| DELETE | `/subjects/{id}` | Delete subject |

---

## Request / Response Examples

### Academic Years

#### Create Academic Year

**Request**
```http
POST /api/v1/academic-years HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "2025/2026",
  "start_date": "2025-09-01",
  "end_date": "2026-07-31",
  "is_current": true
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Academic year created successfully",
  "academic_year": {
    "id": 3,
    "name": "2025/2026",
    "start_date": "2025-09-01",
    "end_date": "2026-07-31",
    "is_current": true,
    "terms_count": 0,
    "created_at": "2026-03-30T08:00:00Z"
  }
}
```

#### List Academic Years

**Request**
```http
GET /api/v1/academic-years HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 3,
      "name": "2025/2026",
      "start_date": "2025-09-01",
      "end_date": "2026-07-31",
      "is_current": true,
      "terms_count": 2
    },
    {
      "id": 2,
      "name": "2024/2025",
      "start_date": "2024-09-01",
      "end_date": "2025-07-31",
      "is_current": false,
      "terms_count": 3
    }
  ],
  "meta": { "total": 2 }
}
```

---

### Terms

#### Create a Term

**Request**
```http
POST /api/v1/terms HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "academic_year_id": 3,
  "name": "Second Term",
  "start_date": "2026-01-06",
  "end_date": "2026-04-10",
  "is_current": true
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Term created successfully",
  "term": {
    "id": 7,
    "academic_year_id": 3,
    "academic_year": { "id": 3, "name": "2025/2026" },
    "name": "Second Term",
    "start_date": "2026-01-06",
    "end_date": "2026-04-10",
    "is_current": true,
    "created_at": "2026-03-30T08:15:00Z"
  }
}
```

---

### Departments

#### Create a Department

**Request**
```http
POST /api/v1/departments HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "Science",
  "description": "Biology, Chemistry, Physics and Mathematics department",
  "head_of_department_id": 12
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Department created successfully",
  "department": {
    "id": 2,
    "name": "Science",
    "description": "Biology, Chemistry, Physics and Mathematics department",
    "head_of_department": {
      "id": 12,
      "name": "Mr. Emeka Okonkwo"
    },
    "subjects_count": 0,
    "created_at": "2026-03-30T08:20:00Z"
  }
}
```

#### Get Department with Subjects

**Request**
```http
GET /api/v1/departments/2 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": {
    "id": 2,
    "name": "Science",
    "description": "Biology, Chemistry, Physics and Mathematics department",
    "head_of_department": { "id": 12, "name": "Mr. Emeka Okonkwo" },
    "subjects": [
      { "id": 5, "name": "Biology", "code": "BIO" },
      { "id": 6, "name": "Chemistry", "code": "CHE" },
      { "id": 7, "name": "Physics", "code": "PHY" }
    ]
  }
}
```

---

### Classes

#### Create a Class

**Request**
```http
POST /api/v1/classes HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "JSS 2",
  "level": "junior",
  "description": "Junior Secondary School Year 2"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Class created successfully",
  "class": {
    "id": 4,
    "name": "JSS 2",
    "level": "junior",
    "description": "Junior Secondary School Year 2",
    "arms_count": 0,
    "students_count": 0,
    "created_at": "2026-03-30T08:30:00Z"
  }
}
```

#### Get Class with Arms

**Request**
```http
GET /api/v1/classes/4 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": {
    "id": 4,
    "name": "JSS 2",
    "level": "junior",
    "arms": [
      { "id": 7, "name": "A", "students_count": 38 },
      { "id": 8, "name": "B", "students_count": 35 }
    ],
    "students_count": 73
  }
}
```

---

### Arms

#### Create an Arm

**Request**
```http
POST /api/v1/arms HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "A",
  "class_id": 4
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Arm created successfully",
  "arm": {
    "id": 7,
    "name": "A",
    "class_id": 4,
    "class": { "id": 4, "name": "JSS 2" },
    "students_count": 0,
    "created_at": "2026-03-30T08:35:00Z"
  }
}
```

#### Assign Arm to Class

**Request**
```http
POST /api/v1/arms/assign-to-class HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "arm_id": 7,
  "class_id": 4
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Arm assigned to class successfully",
  "arm": { "id": 7, "name": "A" },
  "class": { "id": 4, "name": "JSS 2" }
}
```

#### Remove Arm from Class

**Request**
```http
POST /api/v1/arms/remove-from-class HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "arm_id": 7,
  "class_id": 4
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Arm removed from class successfully"
}
```

#### List Arms for a Class

**Request**
```http
GET /api/v1/arms/class/4 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "class": { "id": 4, "name": "JSS 2" },
  "arms": [
    { "id": 7, "name": "A", "students_count": 38 },
    { "id": 8, "name": "B", "students_count": 35 }
  ]
}
```

---

### Subjects

#### Create a Subject

**Request**
```http
POST /api/v1/subjects HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "name": "Chemistry",
  "code": "CHE",
  "department_id": 2,
  "class_id": 4,
  "description": "General Chemistry for JSS 2"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Subject created successfully",
  "subject": {
    "id": 6,
    "name": "Chemistry",
    "code": "CHE",
    "department": { "id": 2, "name": "Science" },
    "class": { "id": 4, "name": "JSS 2" },
    "description": "General Chemistry for JSS 2",
    "created_at": "2026-03-30T08:45:00Z"
  }
}
```

---

## Student Management

**Required module:** `student_management`

### Student Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/students` | List students (filter: `class_id`, `arm_id`, `status`, `search`) |
| POST | `/students` | Enroll student (admission number auto-generated) |
| GET | `/students/{id}` | Get student profile |
| PUT | `/students/{id}` | Update student |
| DELETE | `/students/{id}` | Deactivate student |
| GET | `/students/{id}/attendance` | Student attendance records |
| GET | `/students/{id}/results` | Student exam results |
| GET | `/students/{id}/assignments` | Student assignments |
| GET | `/students/{id}/subjects` | Student subjects |
| POST | `/students/generate-admission-number` | Preview admission number |
| POST | `/students/generate-credentials` | Preview auto-generated email/username |

### Student Enrollment

#### Full Enrollment Request (with Guardian)

**Request**
```http
POST /api/v1/students HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "first_name": "Chukwuemeka",
  "last_name": "Obi",
  "middle_name": "Divine",
  "date_of_birth": "2012-07-14",
  "gender": "male",
  "class_id": 4,
  "arm_id": 7,
  "academic_year_id": 3,
  "term_id": 7,
  "religion": "Christianity",
  "state_of_origin": "Anambra",
  "nationality": "Nigerian",
  "address": "15 Adeola Odeku Street, Victoria Island, Lagos",
  "phone": "08011223344",
  "blood_group": "A+",
  "genotype": "AA",
  "profile_photo": null,
  "status": "active",
  "guardians": [
    {
      "first_name": "Obinna",
      "last_name": "Obi",
      "email": "obinna.obi@example.com",
      "phone": "08099887766",
      "relationship": "Father",
      "occupation": "Engineer",
      "address": "15 Adeola Odeku Street, Victoria Island, Lagos",
      "is_primary": true
    },
    {
      "first_name": "Ngozi",
      "last_name": "Obi",
      "email": "ngozi.obi@example.com",
      "phone": "08033445566",
      "relationship": "Mother",
      "occupation": "Teacher",
      "address": "15 Adeola Odeku Street, Victoria Island, Lagos",
      "is_primary": false
    }
  ]
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Student enrolled successfully",
  "student": {
    "id": 103,
    "admission_number": "GFA/2026/0103",
    "first_name": "Chukwuemeka",
    "last_name": "Obi",
    "middle_name": "Divine",
    "full_name": "Chukwuemeka Divine Obi",
    "date_of_birth": "2012-07-14",
    "gender": "male",
    "class": { "id": 4, "name": "JSS 2" },
    "arm": { "id": 7, "name": "A" },
    "academic_year": { "id": 3, "name": "2025/2026" },
    "term": { "id": 7, "name": "Second Term" },
    "status": "active",
    "guardians": [
      {
        "id": 55,
        "full_name": "Obinna Obi",
        "relationship": "Father",
        "phone": "08099887766",
        "is_primary": true
      },
      {
        "id": 56,
        "full_name": "Ngozi Obi",
        "relationship": "Mother",
        "phone": "08033445566",
        "is_primary": false
      }
    ]
  },
  "login_credentials": {
    "email": "chukwuemeka.obi@greenfield.edu.ng",
    "username": "chukwuemeka.obi",
    "password": "GFA@2026103",
    "note": "Student must change password on first login"
  }
}
```

#### What Happens During Enrollment

1. All fields are validated.
2. Admission number is auto-generated in school-specific format (e.g. `GFA/2026/0103`).
3. School email is auto-generated (`firstname.lastname@schooldomain.com`).
4. A User account is created with a default password.
5. Guardian accounts are created if provided (up to 2), each with their own User login.
6. Guardians are attached to the student with `relationship` and `is_primary` flag.
7. Response includes `login_credentials` for handover to the parent.

#### List Students

**Request**
```http
GET /api/v1/students?class_id=4&arm_id=7 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "data": [
    {
      "id": 103,
      "admission_number": "GFA/2026/0103",
      "full_name": "Chukwuemeka Divine Obi",
      "date_of_birth": "2012-07-14",
      "gender": "male",
      "class": { "id": 4, "name": "JSS 2" },
      "arm": { "id": 7, "name": "A" },
      "status": "active"
    }
  ],
  "meta": {
    "total": 38,
    "per_page": 15,
    "current_page": 1,
    "last_page": 3
  }
}
```

#### Get Student Attendance

**Request**
```http
GET /api/v1/students/103/attendance?term_id=7 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "student": { "id": 103, "full_name": "Chukwuemeka Divine Obi" },
  "term": { "id": 7, "name": "Second Term" },
  "summary": {
    "total_school_days": 52,
    "days_present": 48,
    "days_absent": 4,
    "attendance_percentage": 92.3
  },
  "records": [
    {
      "date": "2026-03-30",
      "status": "present",
      "remark": null
    },
    {
      "date": "2026-03-29",
      "status": "absent",
      "remark": "Sick"
    }
  ]
}
```

#### Get Student Results

**Request**
```http
GET /api/v1/students/103/results?term_id=7 HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "student": { "id": 103, "full_name": "Chukwuemeka Divine Obi" },
  "term": { "id": 7, "name": "Second Term" },
  "results": [
    {
      "subject": { "id": 6, "name": "Chemistry", "code": "CHE" },
      "ca_score": 28,
      "exam_score": 64,
      "total_score": 92,
      "grade": "A",
      "remark": "Excellent"
    },
    {
      "subject": { "id": 7, "name": "Physics", "code": "PHY" },
      "ca_score": 22,
      "exam_score": 58,
      "total_score": 80,
      "grade": "B",
      "remark": "Very Good"
    }
  ],
  "summary": {
    "total_subjects": 8,
    "average_score": 74.5,
    "class_position": 3,
    "class_size": 38
  }
}
```

#### Get Student Subjects

**Request**
```http
GET /api/v1/students/103/subjects HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "student": { "id": 103, "full_name": "Chukwuemeka Divine Obi" },
  "subjects": [
    { "id": 6, "name": "Chemistry", "code": "CHE", "teacher": { "name": "Mr. Emeka Okonkwo" } },
    { "id": 7, "name": "Physics", "code": "PHY", "teacher": { "name": "Mrs. Funke Adesanya" } }
  ]
}
```

---

## Guardian Management

**Required module:** `student_management`

### Guardian Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/guardians` | List guardians (filter: `student_id`, `search`) |
| POST | `/guardians` | Create guardian |
| GET | `/guardians/{id}` | Get guardian + linked students |
| PUT | `/guardians/{id}` | Update guardian |
| DELETE | `/guardians/{id}` | Delete guardian |
| POST | `/guardians/{id}/assign-student` | Assign student to guardian |
| POST | `/guardians/{id}/remove-student` | Remove student from guardian |

#### Create Guardian (Standalone)

**Request**
```http
POST /api/v1/guardians HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "first_name": "Chidi",
  "last_name": "Okafor",
  "email": "chidi.okafor@example.com",
  "phone": "08055667788",
  "relationship": "Uncle",
  "occupation": "Businessman",
  "address": "22 Broad Street, Lagos Island"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Guardian created successfully",
  "guardian": {
    "id": 57,
    "first_name": "Chidi",
    "last_name": "Okafor",
    "full_name": "Chidi Okafor",
    "email": "chidi.okafor@example.com",
    "phone": "08055667788",
    "relationship": "Uncle",
    "occupation": "Businessman",
    "address": "22 Broad Street, Lagos Island",
    "students": []
  }
}
```

#### Assign Student to Guardian

**Request**
```http
POST /api/v1/guardians/57/assign-student HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 103,
  "relationship": "Uncle",
  "is_primary": false
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Student assigned to guardian successfully",
  "guardian": { "id": 57, "full_name": "Chidi Okafor" },
  "student": { "id": 103, "full_name": "Chukwuemeka Divine Obi" },
  "relationship": "Uncle",
  "is_primary": false
}
```

#### Remove Student from Guardian

**Request**
```http
POST /api/v1/guardians/57/remove-student HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "student_id": 103
}
```

**Response** `HTTP 200 OK`
```json
{
  "message": "Student removed from guardian successfully"
}
```

---

## Teacher Management

**Required module:** `teacher_management`

### Teacher Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/teachers` | List teachers (filter: `department_id`, `status`, `search`) |
| POST | `/teachers` | Create teacher (with user account) |
| GET | `/teachers/{id}` | Get teacher profile |
| PUT | `/teachers/{id}` | Update teacher |
| DELETE | `/teachers/{id}` | Delete teacher |
| GET | `/teachers/{id}/classes` | Classes assigned to this teacher |
| GET | `/teachers/{id}/subjects` | Subjects taught by this teacher |
| GET | `/teachers/{id}/students` | All students across the teacher's classes |

#### Create Teacher

**Request**
```http
POST /api/v1/teachers HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json

{
  "first_name": "Emeka",
  "last_name": "Okonkwo",
  "email": "emeka.okonkwo@greenfield.edu.ng",
  "phone": "08077889900",
  "department_id": 2,
  "qualification": "B.Sc Chemistry, PGDE",
  "date_of_joining": "2020-09-01",
  "status": "active"
}
```

**Response** `HTTP 201 Created`
```json
{
  "message": "Teacher created successfully",
  "teacher": {
    "id": 12,
    "staff_id": "GFA/TCH/0012",
    "full_name": "Emeka Okonkwo",
    "email": "emeka.okonkwo@greenfield.edu.ng",
    "phone": "08077889900",
    "department": { "id": 2, "name": "Science" },
    "qualification": "B.Sc Chemistry, PGDE",
    "date_of_joining": "2020-09-01",
    "status": "active"
  },
  "login_credentials": {
    "email": "emeka.okonkwo@greenfield.edu.ng",
    "password": "GFA@TCH0012",
    "note": "Teacher must change password on first login"
  }
}
```

#### Get Teacher's Classes

**Request**
```http
GET /api/v1/teachers/12/classes HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "teacher": { "id": 12, "full_name": "Emeka Okonkwo" },
  "classes": [
    {
      "id": 4,
      "name": "JSS 2",
      "arms": [
        { "id": 7, "name": "A", "students_count": 38 },
        { "id": 8, "name": "B", "students_count": 35 }
      ],
      "role": "Class Teacher"
    },
    {
      "id": 5,
      "name": "JSS 3",
      "arms": [
        { "id": 9, "name": "A", "students_count": 40 }
      ],
      "role": "Subject Teacher"
    }
  ]
}
```

#### Get Teacher's Subjects

**Request**
```http
GET /api/v1/teachers/12/subjects HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "teacher": { "id": 12, "full_name": "Emeka Okonkwo" },
  "subjects": [
    { "id": 6, "name": "Chemistry", "code": "CHE", "class": { "name": "JSS 2" } },
    { "id": 6, "name": "Chemistry", "code": "CHE", "class": { "name": "JSS 3" } }
  ]
}
```

#### Get Teacher's Students

**Request**
```http
GET /api/v1/teachers/12/students HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "teacher": { "id": 12, "full_name": "Emeka Okonkwo" },
  "students": [
    {
      "id": 103,
      "full_name": "Chukwuemeka Divine Obi",
      "admission_number": "GFA/2026/0103",
      "class": "JSS 2",
      "arm": "A"
    }
  ],
  "meta": { "total": 113 }
}
```

---

## School Structure Endpoints

### Get School Organogram

```http
GET /api/v1/schools/{id}/organogram HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

Returns the full school org chart: departments → teachers, classes → arms → students.

**Response** `HTTP 200 OK`
```json
{
  "school": { "id": 1, "name": "Greenfield Academy" },
  "departments": [
    {
      "id": 2,
      "name": "Science",
      "head": { "id": 12, "name": "Mr. Emeka Okonkwo" },
      "teachers": [
        { "id": 12, "name": "Mr. Emeka Okonkwo", "subjects": ["Chemistry"] }
      ]
    }
  ],
  "classes": [
    {
      "id": 4,
      "name": "JSS 2",
      "arms": [
        { "id": 7, "name": "A", "students_count": 38, "class_teacher": { "name": "Mr. Emeka Okonkwo" } }
      ]
    }
  ]
}
```

### Get School Stats

```http
GET /api/v1/schools/{id}/stats HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response** `HTTP 200 OK`
```json
{
  "students": 412,
  "teachers": 28,
  "classes": 9,
  "arms": 18,
  "subjects": 42,
  "departments": 4,
  "active_terms": 1,
  "current_academic_year": "2025/2026"
}
```

### Get Current School

```http
GET /api/v1/schools/me HTTP/1.1
Host: greenfield.compasse.net
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

Returns the current authenticated school admin's school profile.

---

## Business Rules

1. **Admission number** — Auto-generated per school on student creation; format is configured per school (e.g. `{prefix}/{year}/{sequence}`).
2. **Email auto-generation** — Student school email is generated from `firstname.lastname@schooldomain.com`. Collisions are resolved by appending a number.
3. **Guardian limit** — A student can have multiple guardians but only one marked `is_primary = true`.
4. **Current academic year / term** — Only one academic year and one term per school can be `is_current = true` at a time.
5. **Cascade delete protection** — Classes cannot be deleted with active students; departments cannot be deleted with subjects.
6. **Teacher module independence** — `teacher_management` is a separate module gate from `academic_management`. A school can have academic structure without teacher management enabled, but teacher sub-resources require the teacher module.

---

## Frontend Integration

### How the Frontend Handles Tenancy

All academic API calls go to `https://{school}.compasse.net/api/v1/...` with `Authorization: Bearer {token}`. Three module flags control visibility:
- `academic_management` — academic years, terms, classes, arms, subjects, departments
- `student_management` — student enrollment, profiles, sub-resources, guardians
- `teacher_management` — teacher profiles and sub-resources

The frontend checks all three on app boot and conditionally renders the relevant nav sections.

### Academic Year Selector

The academic year selector is a global dropdown in the top navigation bar. It is populated from `GET /api/v1/academic-years`. When the user changes the selected year, a React/Vue context value (or Pinia/Redux store) is updated.

**Effect on all views:** Every view that lists students, results, attendance, or fees reads the selected `academic_year_id` from context and appends it as a query parameter on all API calls. This means switching the year filter immediately changes all data displayed across the app without a full page reload.

The current academic year (`is_current: true`) is selected by default on app load.

### Class → Arm Drill-Down Navigation

The class structure is navigated in two levels:

1. **Class list** — `GET /api/v1/classes` — shown as a grid of cards (one per class). Each card shows the class name and total student count.
2. **Arm list** — On clicking a class, the frontend calls `GET /api/v1/arms/class/{classId}` and shows a list of arms with their student counts.
3. **Student list** — On clicking an arm, the frontend calls `GET /api/v1/students?class_id={id}&arm_id={id}` and renders the student table with search and filter controls.

Breadcrumb navigation is shown throughout: `Classes > JSS 2 > Arm A > Students`.

This drill-down pattern ensures the frontend never loads all students at once — it always scopes to a specific class and arm before listing students.
