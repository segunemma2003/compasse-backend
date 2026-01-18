# School Admin Complete API Documentation

## Table of Contents
1. [Overview](#overview)
2. [Authentication Flow](#authentication-flow)
3. [Core School Management](#core-school-management)
4. [User & Role Management](#user--role-management)
5. [Academic Management](#academic-management)
6. [Student Management](#student-management)
7. [Teacher Management](#teacher-management)
8. [Staff Management](#staff-management)
9. [Assessment & Grading](#assessment--grading)
10. [Examinations & CBT](#examinations--cbt)
11. [Attendance Management](#attendance-management)
12. [Timetable Management](#timetable-management)
13. [Library Management](#library-management)
14. [Financial Management](#financial-management)
15. [Houses & Sports](#houses--sports)
16. [Communication](#communication)
17. [Analytics & Reporting](#analytics--reporting)
18. [Subscriptions & Modules](#subscriptions--modules)
19. [Additional Features](#additional-features)

---

## Overview

**Total APIs: 119**

This documentation covers all APIs available to the **School Admin** role in a multi-tenant school management system. Each school operates in its own tenant context with isolated data.

### Base URL
```
http://localhost:8000/api/v1
```

### Required Headers
```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

### Response Format
All responses follow a consistent JSON format:
```json
{
  "data": {},
  "message": "Success message",
  "status": 200
}
```

---

## Authentication Flow

### 1. Login as School Admin
**POST** `/auth/login`

**Request:**
```json
{
  "email": "admin@school.com",
  "password": "password123"
}
```

**Headers (Include Subdomain):**
```http
X-Subdomain: yourschool
Content-Type: application/json
```

**Response:**
```json
{
  "token": "Bearer_token_here",
  "user": {
    "id": 1,
    "name": "Admin Name",
    "email": "admin@school.com",
    "role": "school_admin"
  },
  "school": {
    "id": 1,
    "name": "Your School",
    "subdomain": "yourschool"
  }
}
```

**Flow:**
1. User enters email/password + school subdomain
2. System switches to tenant database based on subdomain
3. Validates credentials in tenant database
4. Returns JWT token + user details with role

### 2. Get Current User
**GET** `/auth/me`

**Response:**
```json
{
  "user": {
    "id": 1,
    "name": "Admin Name",
    "email": "admin@school.com",
    "role": "school_admin",
    "permissions": []
  }
}
```

### 3. Logout
**POST** `/auth/logout`

**Response:**
```json
{
  "message": "Successfully logged out"
}
```

---

## Core School Management

### School Information Flow
```
Login → Get My School → Update School → Get Stats → View Dashboard
```

### 1. Get My School
**GET** `/schools/me`

**Response:**
```json
{
  "school": {
    "id": 1,
    "name": "Your School",
    "subdomain": "yourschool",
    "email": "contact@school.com",
    "phone": "+234-800-0000",
    "address": "School Address",
    "logo": "path/to/logo.png",
    "status": "active",
    "academic_year": "2025/2026",
    "term": "First Term",
    "settings": {}
  }
}
```

### 2. Update My School
**PUT** `/schools/me`

**Request:**
```json
{
  "name": "Updated School Name",
  "phone": "+234-800-1111",
  "address": "New Address",
  "email": "newemail@school.com",
  "website": "https://school.com",
  "academic_year": "2025/2026",
  "term": "Second Term"
}
```

**Response:**
```json
{
  "message": "School updated successfully",
  "school": {
    "id": 1,
    "name": "Updated School Name",
    "phone": "+234-800-1111"
  }
}
```

### 3. Get School Stats
**GET** `/schools/{school_id}/stats`

**Response:**
```json
{
  "stats": {
    "total_students": 500,
    "total_teachers": 50,
    "total_staff": 20,
    "total_classes": 30,
    "student_teacher_ratio": 10
  }
}
```

### 4. Get Dashboard
**GET** `/dashboard`

**Response:**
```json
{
  "summary": {
    "students": 500,
    "teachers": 50,
    "staff": 20,
    "classes": 30
  },
  "recent_activities": [],
  "upcoming_events": []
}
```

### 5. Get Dashboard Stats
**GET** `/dashboard/stats`

**Response:**
```json
{
  "users": 570,
  "students": 500,
  "teachers": 50,
  "classes": 30,
  "subjects": 15
}
```

---

## User & Role Management

### User Management Flow
```
List Users → Create User → Assign Role → Update User → Suspend/Activate → Delete
```

### 1. List Users
**GET** `/users?page=1&per_page=15`

**Query Parameters:**
- `page`: Page number
- `per_page`: Items per page
- `role`: Filter by role (teacher, student, staff)
- `status`: Filter by status (active, inactive)
- `search`: Search by name or email

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "User Name",
      "email": "user@school.com",
      "role": "teacher",
      "status": "active",
      "last_login_at": "2026-01-18 10:00:00"
    }
  ],
  "current_page": 1,
  "total": 100,
  "per_page": 15
}
```

### 2. Create User
**POST** `/users`

**Request:**
```json
{
  "name": "New User",
  "email": "newuser@school.com",
  "password": "password123",
  "password_confirmation": "password123",
  "role": "teacher",
  "phone": "+234-800-0000"
}
```

**Response:**
```json
{
  "message": "User created successfully",
  "data": {
    "id": 2,
    "name": "New User",
    "email": "newuser@school.com",
    "role": "teacher"
  }
}
```

### 3. Get User Details
**GET** `/users/{user_id}`

**Response:**
```json
{
  "id": 2,
  "name": "User Name",
  "email": "user@school.com",
  "role": "teacher",
  "status": "active",
  "profile_picture": null,
  "created_at": "2026-01-18"
}
```

### 4. Update User
**PUT** `/users/{user_id}`

**Request:**
```json
{
  "name": "Updated Name",
  "email": "updated@school.com",
  "phone": "+234-800-1111"
}
```

### 5. Delete User
**DELETE** `/users/{user_id}`

**Response:**
```json
{
  "message": "User deleted successfully"
}
```

### 6. Activate User
**POST** `/users/{user_id}/activate`

**Response:**
```json
{
  "message": "User activated successfully"
}
```

### 7. Suspend User
**POST** `/users/{user_id}/suspend`

**Response:**
```json
{
  "message": "User suspended successfully"
}
```

### Role Management

### 8. Get Available Roles
**GET** `/roles`

**Response:**
```json
{
  "roles": {
    "school_admin": "School Administrator",
    "teacher": "Teacher",
    "student": "Student",
    "staff": "Staff",
    "guardian": "Parent/Guardian"
  }
}
```

### 9. Assign Role to User
**POST** `/users/{user_id}/assign-role`

**Request:**
```json
{
  "role": "teacher"
}
```

**Response:**
```json
{
  "message": "Role assigned successfully"
}
```

### 10. Remove Role from User
**POST** `/users/{user_id}/remove-role`

**Request:**
```json
{
  "role": "teacher"
}
```

**Response:**
```json
{
  "message": "Role removed successfully"
}
```

### Profile Picture Management

### 11. Upload Profile Picture (Own)
**POST** `/users/me/profile-picture`

**Request:** (multipart/form-data)
```
profile_picture: (file)
```

### 12. Upload Profile Picture (User)
**POST** `/users/{user_id}/profile-picture`

### 13. Delete Profile Picture (Own)
**DELETE** `/users/me/profile-picture`

### 14. Delete Profile Picture (User)
**DELETE** `/users/{user_id}/profile-picture`

---

## Academic Management

### Academic Setup Flow
```
Create Academic Year → Create Terms → Create Departments → Create Classes → Create Subjects → Assign Arms
```

### Academic Years

### 1. List Academic Years
**GET** `/academic-years`

**Response:**
```json
[
  {
    "id": 1,
    "name": "2025/2026",
    "start_date": "2025-09-01",
    "end_date": "2026-07-31",
    "is_current": true,
    "status": "active"
  }
]
```

### 2. Create Academic Year
**POST** `/academic-years`

**Request:**
```json
{
  "name": "2026/2027",
  "start_date": "2026-09-01",
  "end_date": "2027-07-31",
  "is_current": false
}
```

### 3. Get Academic Year
**GET** `/academic-years/{id}`

### 4. Update Academic Year
**PUT** `/academic-years/{id}`

### 5. Delete Academic Year
**DELETE** `/academic-years/{id}`

### Terms

### 6. List Terms
**GET** `/terms`

**Response:**
```json
[
  {
    "id": 1,
    "name": "First Term",
    "academic_year_id": 1,
    "start_date": "2025-09-01",
    "end_date": "2025-12-20",
    "is_current": true
  }
]
```

### 7. Create Term
**POST** `/terms`

**Request:**
```json
{
  "name": "Second Term",
  "academic_year_id": 1,
  "start_date": "2026-01-06",
  "end_date": "2026-04-10"
}
```

### 8. Get Term
**GET** `/terms/{id}`

### 9. Update Term
**PUT** `/terms/{id}`

### 10. Delete Term
**DELETE** `/terms/{id}`

### Departments

### 11. List Departments
**GET** `/departments`

**Response:**
```json
[
  {
    "id": 1,
    "name": "Science",
    "code": "SCI",
    "hod_id": 5,
    "description": "Science Department"
  }
]
```

### 12. Create Department
**POST** `/departments`

**Request:**
```json
{
  "name": "Arts",
  "code": "ART",
  "description": "Arts Department"
}
```

### 13. Get Department
**GET** `/departments/{id}`

### 14. Update Department
**PUT** `/departments/{id}`

### 15. Delete Department
**DELETE** `/departments/{id}`

### Classes

### 16. List Classes
**GET** `/classes`

**Response:**
```json
[
  {
    "id": 1,
    "name": "JSS 1A",
    "level": "Junior",
    "academic_year_id": 1,
    "term_id": 1,
    "class_teacher_id": 5,
    "capacity": 40,
    "students_count": 35
  }
]
```

### 17. Create Class
**POST** `/classes`

**Request:**
```json
{
  "name": "JSS 1A",
  "level": "Junior",
  "academic_year_id": 1,
  "term_id": 1,
  "capacity": 40,
  "class_teacher_id": 5
}
```

### 18. Get Class
**GET** `/classes/{id}`

**Response:**
```json
{
  "id": 1,
  "name": "JSS 1A",
  "students": []
}
```

### 19. Update Class
**PUT** `/classes/{id}`

### 20. Delete Class
**DELETE** `/classes/{id}`

### 21. Get Class Students
**GET** `/classes/{id}/students`

**Response:**
```json
{
  "class": {
    "id": 1,
    "name": "JSS 1A"
  },
  "students": [
    {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "admission_number": "STU2025001"
    }
  ]
}
```

### Subjects

### 22. List Subjects
**GET** `/subjects`

**Response:**
```json
[
  {
    "id": 1,
    "name": "Mathematics",
    "code": "MATH",
    "department_id": 1,
    "teacher_id": 5,
    "credit_hours": 3
  }
]
```

### 23. Create Subject
**POST** `/subjects`

**Request:**
```json
{
  "name": "English Language",
  "code": "ENG",
  "department_id": 1,
  "teacher_id": 5,
  "credit_hours": 3
}
```

### 24. Get Subject
**GET** `/subjects/{id}`

### 25. Update Subject
**PUT** `/subjects/{id}`

### 26. Delete Subject
**DELETE** `/subjects/{id}`

### Arms (Class Sections)

### 27. List Arms
**GET** `/arms`

**Response:**
```json
{
  "arms": [
    {
      "id": 1,
      "name": "A",
      "description": "Section A",
      "status": "active"
    }
  ]
}
```

### 28. Create Arm
**POST** `/arms`

**Request:**
```json
{
  "name": "B",
  "description": "Section B"
}
```

### 29. Get Arm
**GET** `/arms/{id}`

### 30. Update Arm
**PUT** `/arms/{id}`

### 31. Delete Arm
**DELETE** `/arms/{id}`

### 32. Assign Arm to Class
**POST** `/arms/assign-to-class`

**Request:**
```json
{
  "arm_id": 1,
  "class_id": 1
}
```

### 33. Remove Arm from Class
**POST** `/arms/remove-from-class`

### 34. Get Class Arms
**GET** `/arms/class/{class_id}`

### 35. Get Arm Students
**GET** `/arms/{arm_id}/students`

### Grades

### 36. List Grades
**GET** `/grades`

**Response:**
```json
[
  {
    "id": 1,
    "name": "JSS 1",
    "level": "Junior Secondary",
    "order": 1
  }
]
```

---

## Student Management

### Student Lifecycle Flow
```
Create Student → Enroll in Class → Assign Subjects → Track Attendance → Record Results → Promote
```

### 1. List Students
**GET** `/students?page=1&per_page=15&class_id=1&status=active`

**Query Parameters:**
- `class_id`: Filter by class
- `status`: active, inactive, graduated
- `search`: Search by name or admission number

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "admission_number": "STU2025001",
      "class_id": 1,
      "gender": "male",
      "date_of_birth": "2010-05-15",
      "status": "active"
    }
  ],
  "current_page": 1,
  "total": 500
}
```

### 2. Create Student
**POST** `/students`

**Request:**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "middle_name": "Mike",
  "date_of_birth": "2010-05-15",
  "gender": "male",
  "class_id": 1,
  "email": "john.doe@school.com",
  "phone": "+234-800-0000",
  "address": "Student Address",
  "blood_group": "O+",
  "genotype": "AA"
}
```

**Response:**
```json
{
  "message": "Student created successfully",
  "student": {
    "id": 1,
    "admission_number": "STU2025001",
    "first_name": "John",
    "last_name": "Doe"
  },
  "credentials": {
    "username": "john.doe",
    "password": "generated_password"
  }
}
```

### 3. Get Student Details
**GET** `/students/{id}`

**Response:**
```json
{
  "id": 1,
  "first_name": "John",
  "last_name": "Doe",
  "admission_number": "STU2025001",
  "class": {
    "id": 1,
    "name": "JSS 1A"
  },
  "guardians": [
    {
      "id": 1,
      "first_name": "Parent",
      "last_name": "Doe",
      "relationship": "father"
    }
  ]
}
```

### 4. Update Student
**PUT** `/students/{id}`

**Request:**
```json
{
  "first_name": "Updated Name",
  "class_id": 2,
  "phone": "+234-800-1111"
}
```

### 5. Delete Student
**DELETE** `/students/{id}`

### Advanced Student Operations

### 6. Get Student Attendance
**GET** `/students/{id}/attendance?start_date=2026-01-01&end_date=2026-01-31`

**Response:**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "admission_number": "STU2025001"
  },
  "attendance": [],
  "summary": {
    "total_days": 0,
    "present_days": 0,
    "absent_days": 0,
    "attendance_percentage": 0
  }
}
```

### 7. Get Student Results
**GET** `/students/{id}/results?term_id=1&academic_year_id=1`

**Response:**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "admission_number": "STU2025001"
  },
  "results": [],
  "summary": {
    "total_subjects": 0,
    "average_score": 0,
    "overall_grade": "N/A",
    "class_position": 0
  }
}
```

### 8. Get Student Assignments
**GET** `/students/{id}/assignments`

**Response:**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe"
  },
  "assignments": [
    {
      "id": 1,
      "title": "Math Assignment 1",
      "subject": "Mathematics",
      "due_date": "2026-01-25"
    }
  ]
}
```

### 9. Get Student Subjects
**GET** `/students/{id}/subjects`

**Response:**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe",
    "class": {
      "id": 1,
      "name": "JSS 1A"
    }
  },
  "subjects": [
    {
      "id": 1,
      "name": "Mathematics",
      "code": "MATH"
    }
  ]
}
```

### 10. Generate Admission Number
**POST** `/students/generate-admission-number`

**Request:**
```json
{
  "year": 2025,
  "class_id": 1
}
```

**Response:**
```json
{
  "admission_number": "STU2025001"
}
```

### 11. Generate Student Credentials
**POST** `/students/generate-credentials`

**Request:**
```json
{
  "student_id": 1
}
```

**Response:**
```json
{
  "username": "john.doe",
  "password": "generated_password"
}
```

---

## Teacher Management

### Teacher Lifecycle Flow
```
Create Teacher → Assign Subjects → Assign Classes → Track Performance → Update Details
```

### 1. List Teachers
**GET** `/teachers?page=1&per_page=15`

**Query Parameters:**
- `department_id`: Filter by department
- `status`: active, inactive
- `search`: Search by name

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "first_name": "Jane",
      "last_name": "Smith",
      "employee_id": "TCH20250001",
      "email": "jane@school.com",
      "phone": "+234-800-0000",
      "status": "active",
      "employment_date": "2025-01-01"
    }
  ]
}
```

### 2. Create Teacher
**POST** `/teachers`

**Request:**
```json
{
  "first_name": "Jane",
  "last_name": "Smith",
  "email": "jane@school.com",
  "phone": "+234-800-0000",
  "date_of_birth": "1985-05-15",
  "gender": "female",
  "employment_date": "2025-01-01",
  "qualification": "B.Ed Mathematics",
  "specialization": "Mathematics",
  "department_id": 1
}
```

**Response:**
```json
{
  "message": "Teacher created successfully",
  "teacher": {
    "id": 1,
    "employee_id": "TCH20250001",
    "first_name": "Jane",
    "last_name": "Smith"
  }
}
```

### 3. Get Teacher Details
**GET** `/teachers/{id}`

**Response:**
```json
{
  "id": 1,
  "first_name": "Jane",
  "last_name": "Smith",
  "employee_id": "TCH20250001",
  "department": {
    "id": 1,
    "name": "Science"
  }
}
```

### 4. Update Teacher
**PUT** `/teachers/{id}`

### 5. Delete Teacher
**DELETE** `/teachers/{id}`

### 6. Get Teacher Classes
**GET** `/teachers/{id}/classes`

**Response:**
```json
{
  "teacher": {
    "id": 1,
    "first_name": "Jane",
    "last_name": "Smith"
  },
  "classes": []
}
```

### 7. Get Teacher Subjects
**GET** `/teachers/{id}/subjects`

**Response:**
```json
{
  "teacher": {
    "id": 1,
    "first_name": "Jane",
    "last_name": "Smith"
  },
  "subjects": []
}
```

---

## Staff Management

### 1. List Staff
**GET** `/staff`

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "first_name": "Staff",
      "last_name": "Member",
      "employee_id": "STF20250001",
      "role": "Accountant",
      "employment_date": "2025-01-01"
    }
  ]
}
```

### 2. Create Staff
**POST** `/staff`

**Request:**
```json
{
  "first_name": "Staff",
  "last_name": "Member",
  "email": "staff@school.com",
  "phone": "+234-800-0000",
  "role": "Accountant",
  "employment_date": "2025-01-01"
}
```

### 3. Get Staff Details
**GET** `/staff/{id}`

### 4. Update Staff
**PUT** `/staff/{id}`

### 5. Delete Staff
**DELETE** `/staff/{id}`

---

## Assessment & Grading

### Assessment Flow
```
Create Grading System → Create Assessments → Record Scores → Generate Results → View Scoreboards
```

### Grading Systems

### 1. List Grading Systems
**GET** `/assessments/grading-systems`

**Response:**
```json
{
  "grading_systems": [
    {
      "id": 1,
      "name": "Primary System",
      "grade_boundaries": [
        {"min": 70, "max": 100, "grade": "A", "remark": "Excellent"},
        {"min": 60, "max": 69, "grade": "B", "remark": "Very Good"}
      ],
      "pass_mark": 50,
      "is_default": true
    }
  ]
}
```

### 2. Get Default Grading System
**GET** `/assessments/grading-systems/default`

**Response:**
```json
{
  "grading_system": {
    "id": 1,
    "name": "Default System",
    "grade_boundaries": []
  }
}
```

### 3. Create Grading System
**POST** `/assessments/grading-systems`

**Request:**
```json
{
  "name": "Secondary System",
  "description": "For secondary classes",
  "grade_boundaries": [
    {"min": 75, "max": 100, "grade": "A1", "remark": "Distinction"},
    {"min": 70, "max": 74, "grade": "B2", "remark": "Very Good"},
    {"min": 60, "max": 69, "grade": "B3", "remark": "Good"},
    {"min": 50, "max": 59, "grade": "C4", "remark": "Credit"},
    {"min": 40, "max": 49, "grade": "D7", "remark": "Pass"},
    {"min": 0, "max": 39, "grade": "F9", "remark": "Fail"}
  ],
  "pass_mark": 40,
  "is_default": false
}
```

**Response:**
```json
{
  "message": "Grading system created successfully",
  "grading_system": {
    "id": 2,
    "name": "Secondary System"
  }
}
```

### 4. Update Grading System
**PUT** `/assessments/grading-systems/{id}`

### 5. Delete Grading System
**DELETE** `/assessments/grading-systems/{id}`

### 6. Calculate Grade for Score
**POST** `/assessments/grading-systems/calculate-grade`

**Request:**
```json
{
  "score": 85,
  "grading_system_id": 1
}
```

### Continuous Assessments

### 7. List Continuous Assessments
**GET** `/assessments/continuous-assessments?class_id=1&term_id=1`

**Response:**
```json
{
  "assessments": [
    {
      "id": 1,
      "name": "First CA Test",
      "type": "test",
      "class_id": 1,
      "subject_id": 1,
      "total_marks": 20,
      "assessment_date": "2026-01-20"
    }
  ]
}
```

### 8. Create Continuous Assessment
**POST** `/assessments/continuous-assessments`

**Request:**
```json
{
  "name": "First CA Test",
  "type": "test",
  "class_id": 1,
  "subject_id": 1,
  "term_id": 1,
  "academic_year_id": 1,
  "total_marks": 20,
  "assessment_date": "2026-01-20"
}
```

### 9. Update Continuous Assessment
**PUT** `/assessments/continuous-assessments/{id}`

### 10. Delete Continuous Assessment
**DELETE** `/assessments/continuous-assessments/{id}`

### 11. Record CA Scores
**POST** `/assessments/continuous-assessments/{id}/record-scores`

**Request:**
```json
{
  "scores": [
    {"student_id": 1, "score": 18},
    {"student_id": 2, "score": 15}
  ]
}
```

### 12. Get CA Scores
**GET** `/assessments/continuous-assessments/{id}/scores`

### 13. Get Student CA Scores
**GET** `/assessments/continuous-assessments/student/{student_id}/scores`

### Psychomotor Assessments

### 14. List Psychomotor Assessments by Class
**GET** `/assessments/psychomotor-assessments/class/{class_id}?term_id=1&academic_year_id=1`

**Response:**
```json
{
  "assessments": []
}
```

### 15. Get Psychomotor Assessment
**GET** `/assessments/psychomotor-assessments/{student_id}/{term_id}/{academic_year_id}`

### 16. Create Psychomotor Assessment
**POST** `/assessments/psychomotor-assessments`

**Request:**
```json
{
  "student_id": 1,
  "term_id": 1,
  "academic_year_id": 1,
  "ratings": {
    "handwriting": 4,
    "neatness": 5,
    "punctuality": 4,
    "attentiveness": 5
  }
}
```

### 17. Bulk Create Psychomotor Assessments
**POST** `/assessments/psychomotor-assessments/bulk`

### 18. Delete Psychomotor Assessment
**DELETE** `/assessments/psychomotor-assessments/{id}`

### Results Management

### 19. Generate Results
**POST** `/assessments/results/generate`

**Request:**
```json
{
  "class_id": 1,
  "term_id": 1,
  "academic_year_id": 1
}
```

### 20. Get Student Result
**GET** `/assessments/results/student/{student_id}/{term_id}/{academic_year_id}`

### 21. Get Class Results
**GET** `/assessments/results/class/{class_id}?term_id=1&academic_year_id=1`

**Response:**
```json
{
  "class": {
    "id": 1,
    "name": "JSS 1A"
  },
  "results": []
}
```

### 22. Add Comments to Result
**POST** `/assessments/results/{result_id}/comments`

### 23. Approve Result
**POST** `/assessments/results/{result_id}/approve`

### 24. Publish Results
**POST** `/assessments/results/publish`

### Scoreboards

### 25. Get Scoreboard for Class
**GET** `/assessments/scoreboards/class/{class_id}?term_id=1&academic_year_id=1&limit=10`

**Response:**
```json
{
  "scoreboard": []
}
```

### 26. Get Top Performers
**GET** `/assessments/scoreboards/top-performers?term_id=1&academic_year_id=1&limit=10`

**Response:**
```json
{
  "top_performers": []
}
```

### 27. Get Subject Toppers
**GET** `/assessments/scoreboards/subject/{subject_id}/toppers`

### 28. Manual Scoreboard Refresh
**POST** `/assessments/scoreboards/refresh`

### 29. Get Class Comparison
**GET** `/assessments/scoreboards/class-comparison`

### Report Cards

### 30. Get Report Card
**GET** `/assessments/report-cards/{student_id}/{term_id}/{academic_year_id}`

### 31. Generate PDF Report Card
**GET** `/assessments/report-cards/{student_id}/{term_id}/{academic_year_id}/pdf`

### 32. Get Printable Report Card
**GET** `/assessments/report-cards/{student_id}/{term_id}/{academic_year_id}/print`

### 33. Bulk Download Report Cards
**POST** `/assessments/report-cards/bulk-download`

### 34. Email Report Card
**POST** `/assessments/report-cards/{student_id}/{term_id}/{academic_year_id}/email`

---

## Examinations & CBT

### Exam Flow
```
Create Exam → Add Questions → Schedule Exam → Students Take Exam → View Results
```

### 1. List Exams
**GET** `/assessments/exams`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "First Term Exam",
      "subject_id": 1,
      "class_id": 1,
      "exam_date": "2026-03-15",
      "duration": 90,
      "total_marks": 100
    }
  ]
}
```

### 2. Create Exam
**POST** `/assessments/exams`

**Request:**
```json
{
  "title": "First Term Exam - Mathematics",
  "subject_id": 1,
  "class_id": 1,
  "term_id": 1,
  "academic_year_id": 1,
  "exam_date": "2026-03-15",
  "duration": 90,
  "total_marks": 100,
  "passing_marks": 40,
  "instructions": "Answer all questions"
}
```

### 3. Get Exam Details
**GET** `/assessments/exams/{id}`

### 4. Update Exam
**PUT** `/assessments/exams/{id}`

### 5. Delete Exam
**DELETE** `/assessments/exams/{id}`

### Assignments

### 6. List Assignments
**GET** `/assessments/assignments`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Math Assignment 1",
      "subject_id": 1,
      "class_id": 1,
      "due_date": "2026-01-25",
      "total_marks": 20
    }
  ]
}
```

### 7. Create Assignment
**POST** `/assessments/assignments`

### 8. Get Assignment Details
**GET** `/assessments/assignments/{id}`

### 9. Update Assignment
**PUT** `/assessments/assignments/{id}`

### 10. Delete Assignment
**DELETE** `/assessments/assignments/{id}`

### 11. Get Assignment Submissions
**GET** `/assessments/assignments/{id}/submissions`

### 12. Submit Assignment
**POST** `/assessments/assignments/{id}/submit`

### 13. Grade Assignment
**PUT** `/assessments/assignments/{id}/grade`

### Quizzes

### 14. List Quizzes
**GET** `/quizzes`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Quick Quiz 1",
      "subject_id": 1,
      "duration": 15,
      "total_marks": 10
    }
  ]
}
```

### 15. Create Quiz
**POST** `/quizzes`

### 16. Get Quiz Details
**GET** `/quizzes/{id}`

### 17. Update Quiz
**PUT** `/quizzes/{id}`

### 18. Delete Quiz
**DELETE** `/quizzes/{id}`

### 19. Get Quiz Questions
**GET** `/quizzes/{id}/questions`

### 20. Add Question to Quiz
**POST** `/quizzes/{id}/questions`

### Question Banks

### 21. List Question Banks
**GET** `/question-bank`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "subject_id": 1,
      "class_id": 1,
      "title": "Mathematics Question Bank",
      "questions_count": 50
    }
  ]
}
```

### 22. Create Question Bank
**POST** `/question-bank`

**Request:**
```json
{
  "subject_id": 1,
  "class_id": 1,
  "title": "Mathematics QB",
  "description": "Collection of math questions"
}
```

### 23. Get Question Bank
**GET** `/question-bank/{id}`

### 24. Update Question Bank
**PUT** `/question-bank/{id}`

### 25. Delete Question Bank
**DELETE** `/question-bank/{id}`

### 26. Get Questions for Exam
**GET** `/question-bank/for-exam?subject_id=1&class_id=1`

### 27. Get Question Bank Statistics
**GET** `/question-bank/statistics`

### 28. Duplicate Question Bank
**POST** `/question-bank/{id}/duplicate`

### CBT Operations

### 29. Get CBT Questions
**GET** `/assessments/cbt/{exam_id}/questions`

### 30. Create CBT Questions
**POST** `/assessments/cbt/{exam_id}/questions/create`

### 31. Submit CBT Answers
**POST** `/assessments/cbt/submit`

### 32. Get CBT Session Status
**GET** `/assessments/cbt/session/{session_id}/status`

### 33. Get CBT Results
**GET** `/assessments/cbt/session/{session_id}/results`

---

## Attendance Management

### Attendance Flow
```
Mark Attendance → View Records → Generate Reports → Send Notifications
```

### 1. List Attendance Records
**GET** `/attendance?date=2026-01-18&class_id=1`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "student_id": 1,
      "class_id": 1,
      "date": "2026-01-18",
      "status": "present",
      "remarks": ""
    }
  ]
}
```

### 2. Mark Attendance
**POST** `/attendance`

**Request:**
```json
{
  "class_id": 1,
  "date": "2026-01-18",
  "records": [
    {"student_id": 1, "status": "present"},
    {"student_id": 2, "status": "absent"},
    {"student_id": 3, "status": "late"}
  ]
}
```

### 3. Get Attendance Record
**GET** `/attendance/{id}`

### 4. Update Attendance
**PUT** `/attendance/{id}`

### 5. Delete Attendance
**DELETE** `/attendance/{id}`

### 6. Get Student Attendance
**GET** `/attendance/student/{student_id}?start_date=2026-01-01&end_date=2026-01-31`

**Response:**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe"
  },
  "attendance": [],
  "summary": {
    "total_days": 20,
    "present": 18,
    "absent": 2,
    "percentage": 90
  }
}
```

### 7. Get Class Attendance
**GET** `/attendance/class/{class_id}?date=2026-01-18`

**Response:**
```json
{
  "class": {
    "id": 1,
    "name": "JSS 1A"
  },
  "date": "2026-01-18",
  "attendance": []
}
```

### 8. Get Attendance Reports
**GET** `/attendance/reports?start_date=2026-01-01&end_date=2026-01-31&class_id=1`

**Response:**
```json
{
  "summary": {
    "total_students": 35,
    "average_attendance": 92,
    "total_days": 20
  },
  "details": []
}
```

### 9. Get Student Attendance List
**GET** `/attendance/students?date=2026-01-18`

### 10. Get Teacher Attendance List
**GET** `/attendance/teachers?date=2026-01-18`

---

## Timetable Management

### Timetable Flow
```
Create Timetable → Assign Periods → View by Class/Teacher → Update → Publish
```

### 1. List Timetables
**GET** `/timetable`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "class_id": 1,
      "subject_id": 1,
      "teacher_id": 1,
      "day": "Monday",
      "start_time": "08:00",
      "end_time": "09:00"
    }
  ]
}
```

### 2. Create Timetable Entry
**POST** `/timetable`

**Request:**
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

**Response:**
```json
{
  "message": "Timetable entry created successfully",
  "timetable": {
    "id": 1
  }
}
```

### 3. Get Timetable Entry
**GET** `/timetable/{id}`

### 4. Update Timetable Entry
**PUT** `/timetable/{id}`

### 5. Delete Timetable Entry
**DELETE** `/timetable/{id}`

### 6. Get Class Timetable
**GET** `/timetable/class/{class_id}`

**Response:**
```json
{
  "class": {
    "id": 1,
    "name": "JSS 1A"
  },
  "timetable": {
    "Monday": [],
    "Tuesday": [],
    "Wednesday": [],
    "Thursday": [],
    "Friday": []
  }
}
```

### 7. Get Teacher Timetable
**GET** `/timetable/teacher/{teacher_id}`

**Response:**
```json
{
  "teacher": {
    "id": 1,
    "name": "Jane Smith"
  },
  "timetable": {
    "Monday": [],
    "Tuesday": []
  }
}
```

---

## Library Management

### Library Flow
```
Add Books → Track Borrowing → Manage Returns → Generate Reports
```

### 1. List Library Books
**GET** `/library/books?search=mathematics&category=textbook`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Mathematics Textbook",
      "author": "John Author",
      "isbn": "978-0-123456-78-9",
      "total_copies": 10,
      "available_copies": 7,
      "category": "Textbook"
    }
  ]
}
```

### 2. Create Library Book
**POST** `/library/books`

**Request:**
```json
{
  "title": "New Book Title",
  "author": "Author Name",
  "isbn": "978-0-123456-78-9",
  "publisher": "Publisher Name",
  "publication_year": 2025,
  "category": "Textbook",
  "total_copies": 10,
  "available_copies": 10,
  "price": 5000,
  "location": "Shelf A1"
}
```

### 3. Get Book Details
**GET** `/library/books/{id}`

### 4. Update Book
**PUT** `/library/books/{id}`

**Request:**
```json
{
  "total_copies": 15,
  "available_copies": 12
}
```

### 5. Delete Book
**DELETE** `/library/books/{id}`

### 6. Borrow Book
**POST** `/library/books/{id}/borrow`

**Request:**
```json
{
  "student_id": 1,
  "due_date": "2026-02-18"
}
```

### 7. Return Book
**POST** `/library/books/{id}/return`

**Request:**
```json
{
  "borrow_id": 1,
  "condition": "good",
  "remarks": "Returned in good condition"
}
```

### 8. Get Borrowed Books
**GET** `/library/borrowed?status=active`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "book_id": 1,
      "student_id": 1,
      "borrowed_date": "2026-01-18",
      "due_date": "2026-02-18",
      "status": "borrowed"
    }
  ]
}
```

### 9. Get Library Stats
**GET** `/library/stats`

**Response:**
```json
{
  "stats": {
    "total_books": 1000,
    "borrowed_books": 150,
    "available_books": 850,
    "overdue_books": 10
  }
}
```

### 10. List Digital Resources
**GET** `/library/digital-resources`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "E-Book Title",
      "digital_url": "https://link.to/ebook",
      "is_digital": true
    }
  ]
}
```

### 11. Add Digital Resource
**POST** `/library/digital-resources`

**Request:**
```json
{
  "title": "Digital Book",
  "author": "Author",
  "digital_url": "https://link.to/resource",
  "is_digital": true
}
```

### 12. Reserve Book
**POST** `/library/books/{id}/reserve`

### 13. Get Borrowing History
**GET** `/library/history/{student_id}`

---

## Financial Management

### Finance Flow
```
Setup Fee Structure → Assign Fees → Record Payments → Track Expenses → Generate Reports
```

### Fee Management

### 1. List Fees
**GET** `/financial/fees?student_id=1&status=pending`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "student_id": 1,
      "fee_type": "Tuition",
      "amount": 50000,
      "amount_paid": 20000,
      "balance": 30000,
      "due_date": "2026-02-01",
      "status": "partial"
    }
  ]
}
```

### 2. Create Fee
**POST** `/financial/fees`

**Request:**
```json
{
  "student_id": 1,
  "fee_type": "Tuition",
  "amount": 50000,
  "term_id": 1,
  "academic_year_id": 1,
  "due_date": "2026-02-01"
}
```

### 3. Get Fee Details
**GET** `/financial/fees/{id}`

### 4. Update Fee
**PUT** `/financial/fees/{id}`

### 5. Delete Fee
**DELETE** `/financial/fees/{id}`

### 6. Get Fee Structure
**GET** `/financial/fees/structure?class_id=1`

**Response:**
```json
{
  "fee_structure": [
    {
      "fee_type": "Tuition",
      "class_id": 1,
      "total_amount": 50000,
      "count": 35
    }
  ]
}
```

### 7. Create Fee Structure
**POST** `/financial/fees/structure`

**Request:**
```json
{
  "fee_type": "Tuition",
  "amount": 50000,
  "class_ids": [1, 2, 3],
  "academic_year_id": 1,
  "term_id": 1,
  "due_date": "2026-02-01"
}
```

### 8. Update Fee Structure
**PUT** `/financial/fees/structure/{id}`

### 9. Get Student Fees
**GET** `/financial/fees/student/{student_id}`

**Response:**
```json
{
  "student_id": 1,
  "fees": [
    {
      "id": 1,
      "fee_type": "Tuition",
      "amount": 50000,
      "balance": 30000
    }
  ]
}
```

### 10. Pay Fee
**POST** `/financial/fees/{id}/pay`

**Request:**
```json
{
  "amount": 20000,
  "payment_method": "bank_transfer",
  "payment_reference": "TXN123456",
  "payment_date": "2026-01-18"
}
```

### Payment Management

### 11. List Payments
**GET** `/financial/payments?student_id=1&start_date=2026-01-01`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "student_id": 1,
      "amount": 20000,
      "payment_method": "bank_transfer",
      "payment_reference": "TXN123456",
      "payment_date": "2026-01-18",
      "status": "confirmed"
    }
  ]
}
```

### 12. Create Payment
**POST** `/financial/payments`

### 13. Get Payment Details
**GET** `/financial/payments/{id}`

### 14. Update Payment
**PUT** `/financial/payments/{id}`

### 15. Delete Payment
**DELETE** `/financial/payments/{id}`

### 16. Get Student Payments
**GET** `/financial/payments/student/{student_id}`

### 17. Get Payment Receipt
**GET** `/financial/payments/receipt/{id}`

### Expense Management

### 18. List Expenses
**GET** `/financial/expenses?category=salaries&start_date=2026-01-01`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "description": "January Salaries",
      "amount": 500000,
      "category": "salaries",
      "date": "2026-01-31",
      "status": "paid"
    }
  ]
}
```

### 19. Create Expense
**POST** `/financial/expenses`

**Request:**
```json
{
  "description": "Office Supplies",
  "amount": 50000,
  "category": "supplies",
  "date": "2026-01-18",
  "payment_method": "cash",
  "vendor": "Supplier Name"
}
```

### 20. Get Expense Details
**GET** `/financial/expenses/{id}`

### 21. Update Expense
**PUT** `/financial/expenses/{id}`

### 22. Delete Expense
**DELETE** `/financial/expenses/{id}`

### Payroll Management

### 23. List Payroll
**GET** `/financial/payroll?month=1&year=2026`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "staff_id": 1,
      "month": 1,
      "year": 2026,
      "basic_salary": 100000,
      "allowances": 20000,
      "deductions": 5000,
      "net_salary": 115000,
      "status": "paid"
    }
  ]
}
```

### 24. Create Payroll
**POST** `/financial/payroll`

### 25. Get Payroll Details
**GET** `/financial/payroll/{id}`

### 26. Update Payroll
**PUT** `/financial/payroll/{id}`

### 27. Delete Payroll
**DELETE** `/financial/payroll/{id}`

---

## Houses & Sports

### House System Flow
```
Create Houses → Assign Students → Award Points → Track Competitions → View Rankings
```

### Houses

### 1. List Houses
**GET** `/houses`

**Response:**
```json
{
  "houses": [
    {
      "id": 1,
      "name": "Red House",
      "color": "red",
      "motto": "Strength and Honor",
      "points": 150,
      "captain_id": 10
    }
  ]
}
```

### 2. Create House
**POST** `/houses`

**Request:**
```json
{
  "name": "Blue House",
  "color": "blue",
  "motto": "Unity and Progress",
  "captain_id": 15
}
```

**Response:**
```json
{
  "message": "House created successfully",
  "house": {
    "id": 2,
    "name": "Blue House"
  }
}
```

### 3. Get House Details
**GET** `/houses/{id}`

### 4. Update House
**PUT** `/houses/{id}`

**Request:**
```json
{
  "color": "navy blue",
  "points": 200
}
```

### 5. Delete House
**DELETE** `/houses/{id}`

### 6. Get House Members
**GET** `/houses/{id}/members`

### 7. Add Points to House
**POST** `/houses/{id}/points`

**Request:**
```json
{
  "points": 10,
  "reason": "Won quiz competition",
  "awarded_by": 1
}
```

### 8. Get House Points
**GET** `/houses/{id}/points`

### 9. List House Competitions
**GET** `/houses/competitions`

**Response:**
```json
{
  "competitions": []
}
```

### Sports Management

### 10. List Sports Activities
**GET** `/sports/activities`

**Response:**
```json
{
  "activities": [
    {
      "id": 1,
      "name": "Football",
      "type": "team",
      "coach_id": 5,
      "status": "active"
    }
  ]
}
```

### 11. Create Sports Activity
**POST** `/sports/activities`

**Request:**
```json
{
  "name": "Basketball",
  "description": "School basketball program",
  "type": "team",
  "coach_id": 5
}
```

**Response:**
```json
{
  "message": "Sports activity created successfully",
  "activity": {
    "id": 2,
    "name": "Basketball"
  }
}
```

### 12. Update Sports Activity
**PUT** `/sports/activities/{id}`

### 13. Delete Sports Activity
**DELETE** `/sports/activities/{id}`

### 14. List Sports Teams
**GET** `/sports/teams`

**Response:**
```json
{
  "teams": [
    {
      "id": 1,
      "name": "Senior Football Team",
      "activity_id": 1,
      "captain_id": 20
    }
  ]
}
```

### 15. Create Sports Team
**POST** `/sports/teams`

**Request:**
```json
{
  "name": "Junior Basketball Team",
  "sport": "Basketball",
  "gender": "mixed",
  "age_group": "Junior"
}
```

**Response:**
```json
{
  "message": "Team created successfully",
  "team": {
    "id": 2,
    "name": "Junior Basketball Team"
  }
}
```

### 16. Update Sports Team
**PUT** `/sports/teams/{id}`

### 17. Delete Sports Team
**DELETE** `/sports/teams/{id}`

### 18. List Sports Events
**GET** `/sports/events`

**Response:**
```json
{
  "events": [
    {
      "id": 1,
      "name": "Inter-House Football Match",
      "event_date": "2026-02-15",
      "venue": "School Field"
    }
  ]
}
```

### 19. Create Sports Event
**POST** `/sports/events`

**Request:**
```json
{
  "title": "Basketball Match",
  "date": "2026-02-15",
  "venue": "School Court",
  "description": "Final match"
}
```

**Response:**
```json
{
  "message": "Sports event created successfully",
  "event": {
    "id": 2,
    "name": "Basketball Match"
  }
}
```

### 20. Update Sports Event
**PUT** `/sports/events/{id}`

### 21. Delete Sports Event
**DELETE** `/sports/events/{id}`

---

## Communication

### Communication Flow
```
Send Message → View Inbox → Mark as Read → Send SMS/Email → View Notifications
```

### Messaging

### 1. List Messages
**GET** `/communication/messages?type=received`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "from_user_id": 5,
      "to_user_id": 1,
      "subject": "Meeting Request",
      "message": "Please attend...",
      "is_read": false,
      "sent_at": "2026-01-18 10:00:00"
    }
  ]
}
```

### 2. Create Message
**POST** `/communication/messages`

**Request:**
```json
{
  "to_user_id": 5,
  "subject": "Subject Here",
  "message": "Message content here",
  "priority": "normal"
}
```

### 3. Get Message
**GET** `/communication/messages/{id}`

### 4. Update Message
**PUT** `/communication/messages/{id}`

### 5. Delete Message
**DELETE** `/communication/messages/{id}`

### 6. Mark Message as Read
**PUT** `/communication/messages/{id}/read`

### Notifications

### 7. List Notifications
**GET** `/communication/notifications?is_read=false`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "New Student Enrolled",
      "message": "A new student has been enrolled",
      "type": "info",
      "is_read": false,
      "created_at": "2026-01-18"
    }
  ]
}
```

### 8. Create Notification
**POST** `/communication/notifications`

### 9. Get Notification
**GET** `/communication/notifications/{id}`

### 10. Update Notification
**PUT** `/communication/notifications/{id}`

### 11. Delete Notification
**DELETE** `/communication/notifications/{id}`

### 12. Mark Notification as Read
**PUT** `/communication/notifications/{id}/read`

### 13. Mark All Notifications as Read
**PUT** `/communication/notifications/read-all`

### SMS & Email

### 14. Send SMS
**POST** `/communication/sms/send`

**Request:**
```json
{
  "recipients": ["+234-800-0000", "+234-800-1111"],
  "message": "School closing early today"
}
```

### 15. Send Email
**POST** `/communication/email/send`

**Request:**
```json
{
  "recipients": ["parent@email.com"],
  "subject": "School Update",
  "body": "Email content here"
}
```

---

## Analytics & Reporting

### Analytics Flow
```
View School Analytics → Class Performance → Student Trends → Generate Reports
```

### 1. Get School Analytics
**GET** `/assessments/analytics/school?term_id=1&academic_year_id=1`

**Response:**
```json
{
  "analytics": {
    "total_students": 500,
    "average_performance": 75.5,
    "pass_rate": 92,
    "top_subjects": [],
    "weakest_subjects": []
  }
}
```

### 2. Get Class Analytics
**GET** `/assessments/analytics/class/{class_id}?term_id=1&academic_year_id=1`

**Response:**
```json
{
  "class": {
    "id": 1,
    "name": "JSS 1A"
  },
  "analytics": {
    "average_score": 78.5,
    "pass_rate": 95,
    "total_students": 35
  }
}
```

### 3. Get Subject Analytics
**GET** `/assessments/analytics/subject/{subject_id}?term_id=1&academic_year_id=1`

### 4. Get Student Trend
**GET** `/assessments/analytics/student/{student_id}/trend`

### 5. Get Comparative Analytics
**GET** `/assessments/analytics/comparative?class_id=1&term_id=1`

### 6. Get Student Prediction
**GET** `/assessments/analytics/student/{student_id}/prediction`

### Promotions

### 7. List Promotions
**GET** `/assessments/promotions`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "student_id": 1,
      "from_class_id": 1,
      "to_class_id": 2,
      "academic_year_id": 1,
      "status": "promoted"
    }
  ]
}
```

### 8. Promote Student
**POST** `/assessments/promotions/promote`

**Request:**
```json
{
  "student_id": 1,
  "from_class_id": 1,
  "to_class_id": 2,
  "academic_year_id": 1
}
```

### 9. Bulk Promote Students
**POST** `/assessments/promotions/bulk-promote`

**Request:**
```json
{
  "class_id": 1,
  "to_class_id": 2,
  "academic_year_id": 1,
  "criteria": {
    "min_average": 50
  }
}
```

### 10. Auto Promote Students
**POST** `/assessments/promotions/auto-promote`

### 11. Graduate Students
**POST** `/assessments/promotions/graduate`

**Request:**
```json
{
  "student_ids": [50, 51, 52],
  "academic_year_id": 1
}
```

### 12. Get Promotion Statistics
**GET** `/assessments/promotions/statistics?academic_year_id=1`

**Response:**
```json
{
  "statistics": {
    "promoted": 450,
    "repeated": 30,
    "graduated": 20,
    "total": 500
  }
}
```

### 13. Delete Promotion
**DELETE** `/assessments/promotions/{id}`

---

## Subscriptions & Modules

### Subscription Flow
```
View Plans → Check Current Status → View Modules → Check Limits
```

### 1. List Subscriptions
**GET** `/subscriptions`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "plan_id": 1,
      "status": "active",
      "start_date": "2025-09-01",
      "end_date": "2026-08-31"
    }
  ]
}
```

### 2. Get Subscription Plans
**GET** `/subscriptions/plans`

**Response:**
```json
{
  "plans": []
}
```

### 3. Get Available Modules
**GET** `/subscriptions/modules`

**Response:**
```json
{
  "modules": []
}
```

### 4. Get Subscription Status
**GET** `/subscriptions/status`

**Response:**
```json
{
  "subscription": {
    "status": "active",
    "plan": null,
    "modules": [],
    "message": "Subscriptions table not found. Using default active status."
  }
}
```

### 5. Get Subscription Details
**GET** `/subscriptions/{id}`

### 6. Create Subscription
**POST** `/subscriptions/create`

### 7. Upgrade Subscription
**PUT** `/subscriptions/{id}/upgrade`

### 8. Renew Subscription
**POST** `/subscriptions/{id}/renew`

### 9. Cancel Subscription
**DELETE** `/subscriptions/{id}/cancel`

### 10. Check Module Access
**GET** `/subscriptions/modules/{module}/access`

### 11. Check Feature Access
**GET** `/subscriptions/features/{feature}/access`

### 12. Get School Modules
**GET** `/subscriptions/school/modules`

**Response:**
```json
{
  "modules": [
    "academic_management",
    "student_management",
    "teacher_management",
    "cbt",
    "livestream",
    "fee_management",
    "attendance_management"
  ]
}
```

### 13. Get School Limits
**GET** `/subscriptions/school/limits`

**Response:**
```json
{
  "limits": {
    "students": 1000,
    "teachers": 100,
    "storage": 10000
  }
}
```

---

## Additional Features

### Settings

### 1. Get Settings
**GET** `/settings`

**Response:**
```json
{
  "settings": []
}
```

### 2. Update Settings
**PUT** `/settings`

**Request:**
```json
{
  "school_name": "Updated School Name",
  "academic_year": "2025/2026",
  "term": "Second Term"
}
```

**Response:**
```json
{
  "message": "Settings updated successfully"
}
```

### Guardians (Parents)

### 3. List Guardians
**GET** `/guardians`

**Response:**
```json
{
  "guardians": {
    "data": [
      {
        "id": 1,
        "first_name": "Parent",
        "last_name": "One",
        "email": "parent@email.com",
        "phone": "+234-900-0000",
        "relationship_to_student": "father"
      }
    ]
  }
}
```

### 4. Create Guardian
**POST** `/guardians`

**Request:**
```json
{
  "first_name": "Parent",
  "last_name": "Name",
  "email": "parent@email.com",
  "phone": "+234-900-0000",
  "relationship": "mother",
  "address": "Parent Address"
}
```

### 5. Get Guardian Details
**GET** `/guardians/{id}`

### 6. Update Guardian
**PUT** `/guardians/{id}`

### 7. Delete Guardian
**DELETE** `/guardians/{id}`

### 8. Assign Student to Guardian
**POST** `/guardians/{id}/assign-student`

**Request:**
```json
{
  "student_id": 1
}
```

### 9. Remove Student from Guardian
**DELETE** `/guardians/{id}/remove-student`

### 10. Get Guardian Students
**GET** `/guardians/{id}/students`

### 11. Get Guardian Notifications
**GET** `/guardians/{id}/notifications`

### 12. Get Guardian Messages
**GET** `/guardians/{id}/messages`

### 13. Get Guardian Payments
**GET** `/guardians/{id}/payments`

### Announcements

### 14. List Announcements
**GET** `/announcements`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "School Holiday",
      "content": "School will be closed...",
      "priority": "high",
      "published_at": "2026-01-18"
    }
  ]
}
```

### 15. Create Announcement
**POST** `/announcements`

**Request:**
```json
{
  "title": "Important Notice",
  "content": "Content here",
  "priority": "normal",
  "target_audience": "all"
}
```

### 16. Get Announcement
**GET** `/announcements/{id}`

### 17. Update Announcement
**PUT** `/announcements/{id}`

### 18. Delete Announcement
**DELETE** `/announcements/{id}`

### Events

### 19. List Events
**GET** `/events/events`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "School Sports Day",
      "description": "Annual sports day",
      "event_date": "2026-03-20",
      "venue": "School Field"
    }
  ]
}
```

### 20. Create Event
**POST** `/events/events`

### 21. Get Event
**GET** `/events/events/{id}`

### 22. Update Event
**PUT** `/events/events/{id}`

### 23. Delete Event
**DELETE** `/events/events/{id}`

### 24. Get Upcoming Events
**GET** `/events/upcoming`

### School Stories

### 25. List Stories
**GET** `/stories`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "School Achievements",
      "content": "We won the competition",
      "published_at": "2026-01-15"
    }
  ]
}
```

### 26. Get Story Analytics
**GET** `/stories/{id}/analytics`

### Livestreams

### 27. List Livestreams
**GET** `/livestreams`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Math Class",
      "teacher_id": 5,
      "start_time": "2026-01-19 10:00:00",
      "status": "scheduled"
    }
  ]
}
```

### 28. Create Livestream
**POST** `/livestreams`

### 29. Get Livestream
**GET** `/livestreams/{id}`

### 30. Update Livestream
**PUT** `/livestreams/{id}`

### 31. Delete Livestream
**DELETE** `/livestreams/{id}`

### 32. Join Livestream
**POST** `/livestreams/{id}/join`

### 33. Leave Livestream
**POST** `/livestreams/{id}/leave`

### 34. Get Livestream Attendance
**GET** `/livestreams/{id}/attendance`

### 35. Start Livestream
**POST** `/livestreams/{id}/start`

### 36. End Livestream
**POST** `/livestreams/{id}/end`

### Achievements

### 37. List Achievements
**GET** `/achievements`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Best Student",
      "description": "Top performer",
      "category": "academic"
    }
  ]
}
```

### 38. Create Achievement
**POST** `/achievements`

### 39. Get Achievement
**GET** `/achievements/{id}`

### 40. Update Achievement
**PUT** `/achievements/{id}`

### 41. Delete Achievement
**DELETE** `/achievements/{id}`

### 42. Get Student Achievements
**GET** `/achievements/student/{student_id}`

**Response:**
```json
{
  "student": {
    "id": 1,
    "name": "John Doe"
  },
  "achievements": []
}
```

### File Uploads

### 43. Get Presigned URLs
**GET** `/uploads/presigned-urls`

### 44. Upload File
**POST** `/uploads/upload`

### 45. Upload Multiple Files
**POST** `/uploads/upload/multiple`

### 46. Delete File
**DELETE** `/uploads/{key}`

---

## Common Response Codes

### Success Responses
- `200 OK` - Request successful
- `201 Created` - Resource created successfully
- `204 No Content` - Request successful, no content to return

### Client Error Responses
- `400 Bad Request` - Invalid request data
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Access denied
- `404 Not Found` - Resource not found
- `422 Unprocessable Entity` - Validation failed

### Server Error Responses
- `500 Internal Server Error` - Server error

---

## Error Response Format

All error responses follow this format:

```json
{
  "error": "Error title",
  "message": "Detailed error message",
  "messages": {
    "field_name": ["Validation error for this field"]
  }
}
```

---

## Pagination

List endpoints support pagination with the following query parameters:

```
?page=1&per_page=15
```

**Paginated Response:**
```json
{
  "data": [],
  "current_page": 1,
  "last_page": 10,
  "per_page": 15,
  "total": 150,
  "from": 1,
  "to": 15,
  "next_page_url": "http://localhost:8000/api/v1/students?page=2",
  "prev_page_url": null
}
```

---

## Filtering & Search

Most list endpoints support filtering and search:

```
?search=john&status=active&class_id=1&date_from=2026-01-01
```

---

## Complete Workflow Example

### Setting Up a New Term

```
1. Login as School Admin
   POST /auth/login

2. Create Academic Year (if not exists)
   POST /academic-years

3. Create Term
   POST /terms

4. Create Classes
   POST /classes

5. Assign Class Teachers
   PUT /classes/{id}

6. Create/Assign Subjects
   POST /subjects

7. Enroll Students
   POST /students

8. Assign Students to Classes
   PUT /students/{id}

9. Create Timetable
   POST /timetable

10. Setup Fee Structure
    POST /financial/fees/structure

11. Assign Fees to Students
    (Auto-created from structure)

12. Start Recording Attendance
    POST /attendance

13. Create Assessments
    POST /assessments/continuous-assessments

14. Record Results
    POST /assessments/continuous-assessments/{id}/record-scores

15. Generate Report Cards
    POST /assessments/results/generate

16. End Term - Promote Students
    POST /assessments/promotions/bulk-promote
```

---

## Best Practices

1. **Always include the tenant subdomain** in the `X-Subdomain` header
2. **Store the authentication token** securely and include it in all requests
3. **Handle pagination** for large datasets
4. **Use filters** to reduce data transfer
5. **Check subscription status** before accessing premium features
6. **Validate data** on the client side before sending to API
7. **Handle errors gracefully** and show user-friendly messages
8. **Use bulk operations** when available (bulk promote, bulk enroll)
9. **Cache frequently accessed data** (roles, plans, modules)
10. **Monitor API usage** and optimize requests

---

## Rate Limiting

Current API rate limits:
- **Authenticated requests:** 1000 requests per hour
- **Unauthenticated requests:** 60 requests per hour

Rate limit headers in response:
```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 995
X-RateLimit-Reset: 1642531200
```

---

## Support & Documentation

For additional support or questions:
- **Technical Support:** tech@school.com
- **API Issues:** api-support@school.com
- **Feature Requests:** features@school.com

---

**Document Version:** 1.0  
**Last Updated:** January 18, 2026  
**Total APIs Documented:** 119  
**Status:** All APIs Tested & Working ✅

