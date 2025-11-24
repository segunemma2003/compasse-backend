# Bulk Operations & Question Bank API Documentation

## Table of Contents
1. [Overview](#overview)
2. [Bulk Operations](#bulk-operations)
3. [Question Bank System](#question-bank-system)
4. [Authentication](#authentication)
5. [Error Handling](#error-handling)
6. [Best Practices](#best-practices)

---

## Overview

This documentation covers:
- **Bulk Operations**: Create multiple records (students, teachers, staff, guardians, questions) in a single API call
- **Question Bank**: Store, manage, and retrieve questions for exam creation across terms, subjects, and classes

### Base URL
```
Production: https://api.compasse.net/api/v1
Local: http://localhost:8000/api/v1
```

### Required Headers
```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

---

## Bulk Operations

### 1. Bulk Create Students

Create multiple students with auto-generated credentials in a single request.

**Endpoint:** `POST /bulk/students/register`

**Request Body:**
```json
{
  "students": [
    {
      "first_name": "John",
      "last_name": "Doe",
      "middle_name": "Smith",
      "class_id": 1,
      "arm_id": 1,
      "date_of_birth": "2010-05-15",
      "gender": "male",
      "phone": "+234801234567",
      "address": "123 Main Street, Lagos",
      "blood_group": "O+",
      "parent_name": "Jane Doe",
      "parent_phone": "+234809876543",
      "parent_email": "jane.doe@example.com",
      "emergency_contact": "+234809876543",
      "medical_info": {
        "allergies": ["Peanuts"],
        "medications": []
      },
      "transport_info": {
        "route_id": 1,
        "pickup_point": "Main Gate"
      }
    },
    {
      "first_name": "Jane",
      "last_name": "Smith",
      "class_id": 1,
      "date_of_birth": "2010-08-20",
      "gender": "female",
      "phone": "+234807654321"
    }
  ],
  "guardians": [
    {
      "first_name": "Jane",
      "last_name": "Doe",
      "email": "jane.doe@example.com",
      "phone": "+234809876543",
      "relationship": "Mother"
    }
  ]
}
```

**Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Bulk student registration completed",
  "data": {
    "created": [
      {
        "student": {
          "id": 1,
          "first_name": "John",
          "last_name": "Doe",
          "admission_number": "ADM001",
          "email": "john.doe1@schooldomain.com",
          "username": "john.doe001",
          "class": {...},
          "user": {...}
        },
        "login_credentials": {
          "email": "john.doe1@schooldomain.com",
          "password": "Password@123",
          "note": "Student should change password on first login"
        }
      }
    ],
    "failed": []
  }
}
```

**Validation Rules:**
- `students` - required|array|min:1|max:1000
- `students.*.first_name` - required|string|max:255
- `students.*.last_name` - required|string|max:255
- `students.*.class_id` - required|exists:classes,id
- `students.*.date_of_birth` - required|date
- `students.*.gender` - required|in:male,female,other

---

### 2. Bulk Create Teachers

Create multiple teachers with auto-generated credentials.

**Endpoint:** `POST /bulk/teachers/register`

**Request Body:**
```json
{
  "teachers": [
    {
      "first_name": "Michael",
      "last_name": "Johnson",
      "department_id": 2,
      "qualification": "M.Ed Mathematics",
      "experience_years": 5,
      "hire_date": "2020-09-01",
      "date_of_birth": "1990-03-15",
      "gender": "male",
      "phone": "+234801111111",
      "address": "456 Oak Avenue, Lagos"
    }
  ],
  "subjects": [
    {
      "subject_id": 1
    }
  ],
  "classes": [
    {
      "class_id": 1
    }
  ]
}
```

**Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Bulk teacher registration completed",
  "data": {
    "created": [
      {
        "teacher": {
          "id": 1,
          "first_name": "Michael",
          "last_name": "Johnson",
          "employee_id": "SCHTCH0001",
          "email": "michael.johnson1@schooldomain.com",
          "username": "michael.johnson001",
          "department": {...},
          "user": {...}
        },
        "login_credentials": {
          "email": "michael.johnson1@schooldomain.com",
          "username": "michael.johnson001",
          "password": "Password@123"
        }
      }
    ],
    "failed": []
  }
}
```

---

### 3. Bulk Create Staff

Create multiple staff members with auto-generated credentials.

**Endpoint:** `POST /bulk/staff/create`

**Request Body:**
```json
{
  "staff": [
    {
      "first_name": "Sarah",
      "last_name": "Williams",
      "middle_name": "Ann",
      "department_id": 3,
      "position": "Administrative Officer",
      "date_of_birth": "1988-07-22",
      "gender": "female",
      "phone": "+234802222222",
      "address": "789 Pine Road, Lagos",
      "qualification": "B.Sc. Business Administration",
      "hire_date": "2019-01-15",
      "salary": 150000,
      "employment_type": "full_time"
    },
    {
      "first_name": "David",
      "last_name": "Brown",
      "department_id": 3,
      "position": "IT Support",
      "date_of_birth": "1992-11-10",
      "gender": "male",
      "hire_date": "2021-06-01",
      "employment_type": "contract"
    }
  ]
}
```

**Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Bulk staff creation completed",
  "summary": {
    "total": 2,
    "created": 2,
    "failed": 0
  },
  "data": {
    "created": [
      {
        "staff": {
          "id": 1,
          "first_name": "Sarah",
          "last_name": "Williams",
          "employee_id": "SCHSTF0001",
          "email": "sarah.williams1@schooldomain.com",
          "username": "sarah.williams123",
          "position": "Administrative Officer",
          "department": {...},
          "user": {...}
        },
        "login_credentials": {
          "email": "sarah.williams1@schooldomain.com",
          "username": "sarah.williams123",
          "password": "Password@123"
        }
      }
    ],
    "failed": []
  }
}
```

**Validation Rules:**
- `staff` - required|array|min:1|max:500
- `staff.*.first_name` - required|string|max:255
- `staff.*.last_name` - required|string|max:255
- `staff.*.department_id` - required|exists:departments,id
- `staff.*.position` - required|string|max:255
- `staff.*.date_of_birth` - required|date
- `staff.*.gender` - required|in:male,female,other
- `staff.*.hire_date` - required|date
- `staff.*.employment_type` - nullable|in:full_time,part_time,contract,intern

---

### 4. Bulk Create Guardians/Parents

Create multiple guardians with student associations and auto-generated credentials.

**Endpoint:** `POST /bulk/guardians/create`

**Request Body:**
```json
{
  "guardians": [
    {
      "first_name": "Emily",
      "last_name": "Davis",
      "phone": "+234803333333",
      "occupation": "Doctor",
      "address": "321 Elm Street, Lagos",
      "students": [
        {
          "student_id": 1,
          "relationship": "Mother",
          "is_primary": true
        },
        {
          "student_id": 2,
          "relationship": "Mother",
          "is_primary": false
        }
      ]
    },
    {
      "first_name": "Robert",
      "last_name": "Davis",
      "phone": "+234804444444",
      "occupation": "Engineer",
      "address": "321 Elm Street, Lagos",
      "students": [
        {
          "student_id": 1,
          "relationship": "Father",
          "is_primary": false
        }
      ]
    }
  ]
}
```

**Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Bulk guardian creation completed",
  "summary": {
    "total": 2,
    "created": 2,
    "failed": 0
  },
  "data": {
    "created": [
      {
        "guardian": {
          "id": 1,
          "first_name": "Emily",
          "last_name": "Davis",
          "email": "emily.davis1@schooldomain.com",
          "username": "emily.davis456",
          "phone": "+234803333333",
          "occupation": "Doctor",
          "students": [
            {
              "id": 1,
              "first_name": "John",
              "last_name": "Doe",
              "pivot": {
                "relationship": "Mother",
                "is_primary": true
              }
            }
          ],
          "user": {...}
        },
        "login_credentials": {
          "email": "emily.davis1@schooldomain.com",
          "username": "emily.davis456",
          "password": "Password@123"
        }
      }
    ],
    "failed": []
  }
}
```

**Validation Rules:**
- `guardians` - required|array|min:1|max:500
- `guardians.*.first_name` - required|string|max:255
- `guardians.*.last_name` - required|string|max:255
- `guardians.*.phone` - required|string|max:20
- `guardians.*.students.*.student_id` - required|exists:students,id
- `guardians.*.students.*.relationship` - required|string|max:255

---

### 5. Bulk Create Questions for Question Bank

Create multiple questions in bulk for the question bank.

**Endpoint:** `POST /bulk/questions/create`

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
      "question": "What is the capital of Nigeria?",
      "options": [
        {"key": "A", "value": "Lagos"},
        {"key": "B", "value": "Abuja"},
        {"key": "C", "value": "Kano"},
        {"key": "D", "value": "Port Harcourt"}
      ],
      "correct_answer": ["B"],
      "explanation": "Abuja is the capital city of Nigeria, located in the center of the country.",
      "difficulty": "easy",
      "marks": 2,
      "tags": ["geography", "capitals", "nigeria"],
      "topic": "Nigerian Geography"
    },
    {
      "subject_id": 1,
      "class_id": 1,
      "term_id": 1,
      "academic_year_id": 1,
      "question_type": "true_false",
      "question": "Lagos is the capital of Nigeria",
      "correct_answer": ["false"],
      "explanation": "Lagos was the former capital but Abuja is the current capital of Nigeria.",
      "difficulty": "easy",
      "marks": 1,
      "tags": ["geography", "nigeria"],
      "topic": "Nigerian Geography"
    },
    {
      "subject_id": 2,
      "class_id": 1,
      "term_id": 1,
      "academic_year_id": 1,
      "question_type": "short_answer",
      "question": "Calculate the area of a rectangle with length 5cm and width 3cm",
      "correct_answer": ["15 square cm", "15 sq cm", "15cm²"],
      "explanation": "Area = length × width = 5 × 3 = 15 square cm",
      "difficulty": "medium",
      "marks": 3,
      "tags": ["mathematics", "area", "rectangle"],
      "topic": "Area and Perimeter"
    },
    {
      "subject_id": 2,
      "class_id": 1,
      "term_id": 1,
      "academic_year_id": 1,
      "question_type": "essay",
      "question": "Explain the Pythagorean theorem and provide an example of its application",
      "correct_answer": ["Points: Definition, formula (a² + b² = c²), example with calculations"],
      "explanation": "Students should define the theorem, state the formula, and provide a worked example.",
      "difficulty": "hard",
      "marks": 10,
      "tags": ["mathematics", "geometry", "pythagorean theorem"],
      "topic": "Geometry",
      "hints": "Remember to include a right-angled triangle diagram"
    },
    {
      "subject_id": 3,
      "class_id": 1,
      "term_id": 1,
      "academic_year_id": 1,
      "question_type": "fill_in_blank",
      "question": "The chemical symbol for water is ____",
      "correct_answer": ["H2O", "H₂O"],
      "explanation": "Water is composed of two hydrogen atoms and one oxygen atom.",
      "difficulty": "easy",
      "marks": 1,
      "tags": ["chemistry", "molecules", "water"],
      "topic": "Chemical Formulas"
    }
  ]
}
```

**Success Response (201 Created):**
```json
{
  "success": true,
  "message": "Bulk question creation completed",
  "summary": {
    "total": 5,
    "created": 5,
    "failed": 0
  },
  "data": {
    "created": [
      {
        "id": 1,
        "subject_id": 1,
        "class_id": 1,
        "term_id": 1,
        "academic_year_id": 1,
        "question_type": "multiple_choice",
        "question": "What is the capital of Nigeria?",
        "options": [...],
        "difficulty": "easy",
        "marks": 2,
        "tags": ["geography", "capitals", "nigeria"],
        "topic": "Nigerian Geography",
        "status": "active",
        "usage_count": 0,
        "created_at": "2025-11-24T10:30:00.000000Z"
      }
    ],
    "failed": []
  }
}
```

**Question Types:**
- `multiple_choice` - Multiple choice with options
- `true_false` - True or False questions
- `short_answer` - Short text answer
- `essay` - Long form essay answer
- `fill_in_blank` - Fill in the blank
- `matching` - Match items from two lists
- `ordering` - Arrange items in correct order

**Validation Rules:**
- `questions` - required|array|min:1|max:1000
- `questions.*.subject_id` - required|exists:subjects,id
- `questions.*.class_id` - required|exists:classes,id
- `questions.*.term_id` - required|exists:terms,id
- `questions.*.academic_year_id` - required|exists:academic_years,id
- `questions.*.question_type` - required|in:multiple_choice,true_false,short_answer,essay,fill_in_blank,matching,ordering
- `questions.*.question` - required|string
- `questions.*.correct_answer` - required
- `questions.*.difficulty` - nullable|in:easy,medium,hard
- `questions.*.marks` - nullable|integer|min:1

---

## Question Bank System

### Architecture

The Question Bank allows teachers to:
1. **Store questions** organized by subject, class, term, and academic year
2. **Reuse questions** across multiple exams
3. **Filter questions** by type, difficulty, topic, and tags
4. **Track usage** to see which questions are most popular
5. **Build exams** by selecting from the question bank

### 1. Create Single Question

**Endpoint:** `POST /question-bank`

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
  "marks": 1,
  "tags": ["arithmetic", "addition"],
  "topic": "Basic Arithmetic"
}
```

**Success Response (201 Created):**
```json
{
  "message": "Question created successfully",
  "question": {
    "id": 1,
    "school_id": 1,
    "subject_id": 1,
    "class_id": 1,
    "term_id": 1,
    "academic_year_id": 1,
    "question_type": "multiple_choice",
    "question": "What is 2 + 2?",
    "options": [...],
    "correct_answer": ["B"],
    "explanation": "2 + 2 equals 4",
    "difficulty": "easy",
    "marks": 1,
    "tags": ["arithmetic", "addition"],
    "topic": "Basic Arithmetic",
    "status": "active",
    "usage_count": 0,
    "subject": {...},
    "class": {...},
    "term": {...},
    "academicYear": {...},
    "created_at": "2025-11-24T10:30:00.000000Z"
  }
}
```

---

### 2. List Questions with Filters

**Endpoint:** `GET /question-bank`

**Query Parameters:**
- `subject_id` - Filter by subject
- `class_id` - Filter by class
- `term_id` - Filter by term
- `academic_year_id` - Filter by academic year
- `question_type` - Filter by type (multiple_choice, true_false, etc.)
- `difficulty` - Filter by difficulty (easy, medium, hard)
- `topic` - Search by topic
- `search` - Search in question text
- `tags` - Filter by tags (comma-separated or array)
- `status` - Filter by status (active, inactive, archived)
- `sort_by` - Sort field (default: created_at)
- `sort_order` - Sort order (asc, desc)
- `per_page` - Items per page (default: 50)
- `page` - Page number

**Example Request:**
```bash
GET /question-bank?subject_id=1&class_id=1&term_id=1&difficulty=medium&tags=geometry&per_page=20&page=1
```

**Success Response (200 OK):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "question": "Calculate the area of a circle with radius 5cm",
      "question_type": "short_answer",
      "difficulty": "medium",
      "marks": 3,
      "tags": ["mathematics", "area", "circle"],
      "topic": "Area and Perimeter",
      "usage_count": 5,
      "last_used_at": "2025-11-20T14:30:00.000000Z",
      "subject": {...},
      "class": {...},
      "term": {...},
      "academicYear": {...}
    }
  ],
  "first_page_url": "http://api.compasse.net/api/v1/question-bank?page=1",
  "from": 1,
  "last_page": 3,
  "last_page_url": "http://api.compasse.net/api/v1/question-bank?page=3",
  "links": [...],
  "next_page_url": "http://api.compasse.net/api/v1/question-bank?page=2",
  "path": "http://api.compasse.net/api/v1/question-bank",
  "per_page": 20,
  "prev_page_url": null,
  "to": 20,
  "total": 50
}
```

---

### 3. Get Questions for Exam Creation

Retrieve questions suitable for creating an exam based on criteria.

**Endpoint:** `GET /question-bank/for-exam`

**Query Parameters:**
- `subject_id` - required
- `class_id` - required
- `term_id` - required
- `academic_year_id` - required
- `question_types` - array of question types
- `difficulty` - specific difficulty level
- `topics` - array of topics
- `count` - number of questions to return (default: 50, max: 200)

**Example Request:**
```bash
GET /question-bank/for-exam?subject_id=1&class_id=1&term_id=1&academic_year_id=1&question_types[]=multiple_choice&question_types[]=true_false&difficulty=medium&count=30
```

**Success Response (200 OK):**
```json
{
  "total_available": 150,
  "returned": 30,
  "questions": [
    {
      "id": 5,
      "question": "What is the Pythagorean theorem?",
      "question_type": "multiple_choice",
      "options": [...],
      "correct_answer": ["A"],
      "difficulty": "medium",
      "marks": 2,
      "tags": ["geometry", "theorem"],
      "topic": "Geometry"
    },
    // ... 29 more questions
  ]
}
```

**Usage in Exam Creation:**
When creating an exam, you can use these question IDs to associate questions with the exam.

---

### 4. Get Single Question

**Endpoint:** `GET /question-bank/{id}`

**Success Response (200 OK):**
```json
{
  "id": 1,
  "school_id": 1,
  "subject_id": 1,
  "class_id": 1,
  "term_id": 1,
  "academic_year_id": 1,
  "question_type": "multiple_choice",
  "question": "What is the capital of Nigeria?",
  "options": [
    {"key": "A", "value": "Lagos"},
    {"key": "B", "value": "Abuja"},
    {"key": "C", "value": "Kano"},
    {"key": "D", "value": "Port Harcourt"}
  ],
  "correct_answer": ["B"],
  "explanation": "Abuja is the capital city of Nigeria.",
  "difficulty": "easy",
  "marks": 2,
  "tags": ["geography", "capitals"],
  "topic": "Nigerian Geography",
  "status": "active",
  "usage_count": 10,
  "last_used_at": "2025-11-23T15:30:00.000000Z",
  "subject": {
    "id": 1,
    "name": "Geography",
    "code": "GEO101"
  },
  "class": {
    "id": 1,
    "name": "JSS 1"
  },
  "term": {
    "id": 1,
    "name": "First Term"
  },
  "academicYear": {
    "id": 1,
    "name": "2024/2025"
  },
  "creator": {
    "id": 5,
    "name": "Mr. Johnson",
    "email": "johnson@schooldomain.com"
  },
  "created_at": "2025-09-01T10:00:00.000000Z",
  "updated_at": "2025-11-23T15:30:00.000000Z"
}
```

---

### 5. Update Question

**Endpoint:** `PUT /question-bank/{id}`

**Request Body:**
```json
{
  "question": "What is the current capital of Nigeria?",
  "explanation": "Abuja became the capital of Nigeria in 1991, replacing Lagos.",
  "difficulty": "medium",
  "marks": 3
}
```

**Success Response (200 OK):**
```json
{
  "message": "Question updated successfully",
  "question": {
    "id": 1,
    "question": "What is the current capital of Nigeria?",
    "explanation": "Abuja became the capital of Nigeria in 1991, replacing Lagos.",
    "difficulty": "medium",
    "marks": 3,
    ...
  }
}
```

---

### 6. Delete Question

**Endpoint:** `DELETE /question-bank/{id}`

**Success Response (200 OK):**
```json
{
  "message": "Question deleted successfully"
}
```

**Note:** Deleting a question that's already used in exams may affect those exams. Consider archiving instead.

---

### 7. Duplicate Question

Create a copy of an existing question for modification.

**Endpoint:** `POST /question-bank/{id}/duplicate`

**Success Response (201 Created):**
```json
{
  "message": "Question duplicated successfully",
  "question": {
    "id": 150,
    "question": "What is the capital of Nigeria?",
    "question_type": "multiple_choice",
    "options": [...],
    "usage_count": 0,
    "created_at": "2025-11-24T11:00:00.000000Z",
    ...
  }
}
```

---

### 8. Get Question Statistics

Get analytics and statistics about the question bank.

**Endpoint:** `GET /question-bank/statistics`

**Success Response (200 OK):**
```json
{
  "total_questions": 500,
  "active_questions": 450,
  "by_type": [
    {
      "question_type": "multiple_choice",
      "count": 200
    },
    {
      "question_type": "true_false",
      "count": 100
    },
    {
      "question_type": "short_answer",
      "count": 80
    },
    {
      "question_type": "essay",
      "count": 50
    },
    {
      "question_type": "fill_in_blank",
      "count": 40
    },
    {
      "question_type": "matching",
      "count": 20
    },
    {
      "question_type": "ordering",
      "count": 10
    }
  ],
  "by_difficulty": [
    {
      "difficulty": "easy",
      "count": 200
    },
    {
      "difficulty": "medium",
      "count": 200
    },
    {
      "difficulty": "hard",
      "count": 100
    }
  ],
  "by_subject": [
    {
      "subject_id": 1,
      "subject": {
        "id": 1,
        "name": "Mathematics"
      },
      "count": 150
    },
    {
      "subject_id": 2,
      "subject": {
        "id": 2,
        "name": "English"
      },
      "count": 120
    }
  ],
  "most_used": [
    {
      "id": 45,
      "question": "What is the Pythagorean theorem?",
      "usage_count": 25,
      "last_used_at": "2025-11-23T14:30:00.000000Z"
    }
  ]
}
```

---

## Authentication

All bulk operations and question bank endpoints require:

1. **Authentication Token:**
```http
Authorization: Bearer {your_token_here}
```

2. **Tenant Identification:**
```http
X-Subdomain: {school_subdomain}
```

**Example:**
```bash
curl -X POST "https://api.compasse.net/api/v1/bulk/students/register" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc..." \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "students": [...]
  }'
```

---

## Error Handling

### Validation Errors (422)
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "students.0.first_name": ["The first name field is required."],
    "students.1.class_id": ["The selected class is invalid."]
  }
}
```

### Server Errors (500)
```json
{
  "success": false,
  "message": "Bulk operation failed",
  "error": "Database connection timeout"
}
```

### Partial Success
When some items succeed and others fail, you'll receive:
```json
{
  "success": true,
  "message": "Bulk operation completed with some failures",
  "summary": {
    "total": 10,
    "created": 8,
    "failed": 2
  },
  "data": {
    "created": [...],
    "failed": [
      {
        "index": 3,
        "data": {...},
        "error": "Duplicate email address"
      },
      {
        "index": 7,
        "data": {...},
        "error": "Invalid class ID"
      }
    ]
  }
}
```

---

## Best Practices

### 1. Bulk Operations

**Batch Size:**
- Students: Max 1000 per request
- Teachers: Max 500 per request
- Staff: Max 500 per request
- Guardians: Max 500 per request
- Questions: Max 1000 per request

**Error Handling:**
- Always check the `summary` in the response
- Review `failed` items and retry with corrections
- Use transactions for critical operations

**Performance:**
- For large datasets, split into multiple batches
- Consider off-peak hours for massive imports
- Use CSV import for very large datasets (10,000+ records)

### 2. Question Bank

**Organization:**
- Use consistent `tags` for easy filtering
- Set appropriate `difficulty` levels
- Include detailed `explanations` for learning
- Group related questions by `topic`

**Exam Creation Workflow:**
1. Filter questions by subject, class, term, and academic year
2. Further filter by difficulty and topic
3. Use `/question-bank/for-exam` to get random selection
4. Review questions before adding to exam
5. Questions automatically track usage count

**Question Reusability:**
- Questions are reusable across multiple exams
- Usage count tracks popularity
- Duplicate questions for variations
- Archive outdated questions instead of deleting

### 3. Auto-Generated Credentials

All bulk user creation endpoints (students, teachers, staff, guardians) automatically generate:
- **Email**: firstname.lastnameid@schooldomain.com
- **Username**: firstname.lastname###
- **Password**: Password@123 (should be changed on first login)

**Security:**
- Advise users to change password on first login
- Implement password change enforcement
- Enable email verification
- Use 2FA for sensitive roles

---

## Complete Example: Creating an Exam from Question Bank

### Step 1: Filter and Review Questions
```bash
GET /question-bank/for-exam?subject_id=1&class_id=1&term_id=1&academic_year_id=1&count=30
```

### Step 2: Create Exam
```bash
POST /exams
{
  "name": "First Term Mathematics Exam",
  "subject_id": 1,
  "class_id": 1,
  "term_id": 1,
  "academic_year_id": 1,
  "type": "cbt",
  "duration_minutes": 60,
  "total_marks": 50,
  "start_date": "2025-12-01 09:00:00",
  "end_date": "2025-12-01 11:00:00",
  "question_ids": [1, 5, 10, 15, 20, ...]
}
```

### Step 3: Questions Auto-link
The system will:
- Link the selected questions to the exam
- Increment usage_count for each question
- Update last_used_at timestamp
- Calculate total marks

---

## Support

For issues or questions:
- Email: support@samschool.com
- Documentation: https://docs.samschool.com
- API Status: https://status.samschool.com

---

**Last Updated:** November 24, 2025  
**API Version:** 1.0.0

