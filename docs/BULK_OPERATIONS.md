# Bulk Operations Documentation

## Overview

The SamSchool Management System provides comprehensive bulk operations for efficient data management. This includes bulk registration of students and teachers, bulk creation of classes with arms, bulk exam and assignment creation, and much more.

## Key Features

### 🏫 Class-Arm Structure

-   **Classes**: Main academic levels (e.g., SS1, SS2, JSS1)
-   **Arms**: Subdivisions within classes (e.g., SS1A, SS1B, SS1C)
-   **Class Teachers**: Each arm has a designated class teacher
-   **Student Assignment**: Students are assigned to specific arms within classes

### 📊 Bulk Operations Available

1. **Student Management**

    - Bulk student registration
    - Bulk guardian assignment
    - Bulk student import from CSV

2. **Teacher Management**

    - Bulk teacher registration
    - Bulk class and subject assignment
    - Bulk teacher import from CSV

3. **Academic Management**

    - Bulk class creation with arms
    - Bulk subject creation
    - Bulk exam creation
    - Bulk assignment creation

4. **Administrative Operations**
    - Bulk attendance marking
    - Bulk result updates
    - Bulk notification sending
    - Bulk fee creation

## API Endpoints

### Base URL

```
/api/v1/bulk/
```

### Authentication

All bulk operations require authentication with tenant context:

```http
Authorization: Bearer {token}
X-Tenant-ID: {tenant_id}
Content-Type: application/json
```

## Detailed API Documentation

### 1. Bulk Student Registration

**Endpoint:** `POST /api/v1/bulk/students/register`

**Description:** Register multiple students with proper arm assignment and guardian linking.

**Request Body:**

```json
{
    "students": [
        {
            "first_name": "John",
            "last_name": "Doe",
            "email": "john.doe@student.com",
            "phone": "+1234567890",
            "admission_number": "SS1A001",
            "class_id": 1,
            "arm_id": 1,
            "date_of_birth": "2008-05-15",
            "gender": "male",
            "address": "123 Main Street, City"
        }
    ],
    "guardians": [
        {
            "student_index": 0,
            "first_name": "Robert",
            "last_name": "Doe",
            "email": "robert.doe@parent.com",
            "phone": "+1234567893",
            "relationship": "father",
            "is_primary": true
        }
    ]
}
```

**Response:**

```json
{
    "success": true,
    "message": "Bulk student registration completed",
    "data": {
        "successful": [
            {
                "index": 0,
                "student_id": 1,
                "user_id": 1,
                "admission_number": "SS1A001",
                "name": "John Doe"
            }
        ],
        "failed": [],
        "total": 1,
        "operation_id": "uuid-here"
    }
}
```

### 2. Bulk Teacher Registration

**Endpoint:** `POST /api/v1/bulk/teachers/register`

**Description:** Register multiple teachers with class and subject assignments.

**Request Body:**

```json
{
    "teachers": [
        {
            "first_name": "Dr. Sarah",
            "last_name": "Wilson",
            "email": "sarah.wilson@teacher.com",
            "phone": "+1234567895",
            "employee_id": "TCH001",
            "department_id": 1,
            "qualification": "PhD in Mathematics",
            "experience_years": 10,
            "hire_date": "2024-01-01",
            "date_of_birth": "1985-06-15",
            "gender": "female",
            "address": "321 Teacher Lane, City"
        }
    ],
    "subjects": [[1, 2]],
    "classes": [[1, 2]]
}
```

### 3. Bulk Class Creation with Arms

**Endpoint:** `POST /api/v1/bulk/classes/create`

**Description:** Create multiple classes with arms and assign class teachers.

**Request Body:**

```json
{
    "classes": [
        {
            "name": "Senior Secondary 1 (SS1)",
            "description": "Senior Secondary 1 Class",
            "academic_year_id": 1,
            "term_id": 1,
            "arms": [
                {
                    "name": "SS1A",
                    "description": "Science Class A",
                    "capacity": 40,
                    "class_teacher_id": 1
                },
                {
                    "name": "SS1B",
                    "description": "Science Class B",
                    "capacity": 40,
                    "class_teacher_id": 2
                }
            ],
            "subjects": [1, 2, 3, 4, 5]
        }
    ]
}
```

### 4. Bulk Exam Creation

**Endpoint:** `POST /api/v1/bulk/exams/create`

**Description:** Create multiple exams for different classes and arms.

**Request Body:**

```json
{
    "exams": [
        {
            "name": "SS1A Mathematics Test",
            "description": "First term mathematics test for SS1A",
            "subject_id": 1,
            "class_id": 1,
            "teacher_id": 1,
            "type": "cbt",
            "duration_minutes": 60,
            "total_marks": 100,
            "passing_marks": 40,
            "start_date": "2024-02-15 09:00:00",
            "end_date": "2024-02-15 10:00:00",
            "is_cbt": true,
            "cbt_settings": {
                "shuffle_questions": true,
                "shuffle_options": true,
                "show_answers": false,
                "allow_review": true
            },
            "question_settings": {
                "multiple_choice": 20,
                "true_false": 10,
                "short_answer": 5
            }
        }
    ]
}
```

### 5. Bulk Attendance Marking

**Endpoint:** `POST /api/v1/bulk/attendance/mark`

**Description:** Mark attendance for multiple students and teachers.

**Request Body:**

```json
{
    "attendance_records": [
        {
            "attendanceable_id": 1,
            "attendanceable_type": "student",
            "date": "2024-01-15",
            "status": "present",
            "check_in_time": "08:00:00",
            "notes": "On time"
        },
        {
            "attendanceable_id": 1,
            "attendanceable_type": "teacher",
            "date": "2024-01-15",
            "status": "present",
            "check_in_time": "07:30:00",
            "check_out_time": "15:30:00",
            "notes": "Full day"
        }
    ]
}
```

### 6. Bulk CSV Import

**Endpoint:** `POST /api/v1/bulk/import/csv`

**Description:** Import data from CSV files with field mapping.

**Request Body (multipart/form-data):**

```
file: [CSV file]
type: students|teachers|classes|subjects|exams|assignments|fees
mapping: {
  "first_name": 0,
  "last_name": 1,
  "email": 2,
  "admission_number": 3,
  "class_id": 4,
  "arm_id": 5
}
skip_header: true
validate_data: true
```

## Class-Arm Structure Examples

### Example 1: Creating SS1 with Multiple Arms

```json
{
    "name": "Senior Secondary 1 (SS1)",
    "arms": [
        {
            "name": "SS1A",
            "description": "Science Class A",
            "capacity": 40,
            "class_teacher_id": 1
        },
        {
            "name": "SS1B",
            "description": "Science Class B",
            "capacity": 40,
            "class_teacher_id": 2
        },
        {
            "name": "SS1C",
            "description": "Arts Class",
            "capacity": 35,
            "class_teacher_id": 3
        }
    ]
}
```

### Example 2: Student Assignment to Arms

```json
{
    "students": [
        {
            "admission_number": "SS1A001",
            "class_id": 1, // SS1 class
            "arm_id": 1, // SS1A arm
            "first_name": "John",
            "last_name": "Doe"
        },
        {
            "admission_number": "SS1B001",
            "class_id": 1, // SS1 class
            "arm_id": 2, // SS1B arm
            "first_name": "Jane",
            "last_name": "Smith"
        }
    ]
}
```

## Error Handling

### Validation Errors

```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "students.0.email": ["The email field is required."],
        "students.0.arm_id": ["The arm does not belong to the specified class."]
    }
}
```

### Processing Errors

```json
{
  "success": true,
  "data": {
    "successful": [...],
    "failed": [
      {
        "index": 2,
        "data": {...},
        "error": "Email already exists"
      }
    ],
    "total": 10
  }
}
```

## Best Practices

### 1. Data Validation

-   Always validate data before bulk operations
-   Use proper field mapping for CSV imports
-   Check arm-class relationships before student assignment

### 2. Performance Optimization

-   Process bulk operations in batches (max 1000 records)
-   Use database transactions for data integrity
-   Monitor operation status for large datasets

### 3. Error Handling

-   Always check the response for failed records
-   Implement retry logic for failed operations
-   Log errors for debugging

### 4. Security

-   Validate user permissions for bulk operations
-   Sanitize input data
-   Use proper authentication and tenant isolation

## Monitoring and Status

### Check Operation Status

```http
GET /api/v1/bulk/operations/{operation_id}/status
```

### Cancel Operation

```http
DELETE /api/v1/bulk/operations/{operation_id}/cancel
```

## CSV Import Templates

### Student Import Template

```csv
first_name,last_name,email,admission_number,class_id,arm_id,date_of_birth,gender,phone,address
John,Doe,john.doe@student.com,SS1A001,1,1,2008-05-15,male,+1234567890,123 Main Street
Jane,Smith,jane.smith@student.com,SS1A002,1,1,2008-03-20,female,+1234567891,456 Oak Avenue
```

### Teacher Import Template

```csv
first_name,last_name,email,employee_id,department_id,qualification,experience_years,hire_date,date_of_birth,gender,phone,address
Dr. Sarah,Wilson,sarah.wilson@teacher.com,TCH001,1,PhD in Mathematics,10,2024-01-01,1985-06-15,female,+1234567895,321 Teacher Lane
Mr. David,Brown,david.brown@teacher.com,TCH002,2,MSc in Physics,8,2024-01-01,1987-09-22,male,+1234567896,654 Educator Street
```

## Rate Limits

-   Maximum 1000 records per bulk operation
-   Maximum 10 concurrent bulk operations per tenant
-   Rate limit: 100 requests per minute per tenant

## Support

For technical support or questions about bulk operations, please contact the development team or refer to the main API documentation.
