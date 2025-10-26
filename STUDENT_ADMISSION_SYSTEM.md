# Student Admission Number & Credential Generation System

## Overview

The SamSchool Management System automatically generates unique admission numbers, email addresses, and usernames for all registered students using the school's domain and naming conventions.

## Features

-   **Unique Admission Numbers**: Auto-generated based on school abbreviation, year, and class
-   **Email Generation**: Uses school domain (schoolname.samschool.com)
-   **Username Generation**: Clean, unique usernames for login
-   **Automatic User Account Creation**: Creates corresponding user accounts
-   **Bulk Registration Support**: Handles multiple students at once

---

## Admission Number Format

### Format: `SCHOOL_ABBR + YEAR + CLASS_ABBR + SEQUENCE`

**Examples:**

-   `ABC2025SS001` - ABC High School, 2025, Senior Secondary 1, Student #1
-   `XYZ2025JS001` - XYZ Academy, 2025, Junior Secondary 1, Student #1
-   `DEF2025SS002` - DEF School, 2025, Senior Secondary 1, Student #2

### Components:

-   **School Abbreviation**: First 3 letters of school name (ABC, XYZ, DEF)
-   **Year**: Current year (2025)
-   **Class Abbreviation**: First 2 letters of class name (SS, JS, PS)
-   **Sequence**: 3-digit sequential number (001, 002, 003...)

---

## Email Generation

### Format: `firstname.lastname@schoolname.samschool.com`

**Examples:**

-   `john.doe@abchighschool.samschool.com`
-   `jane.smith@xyzacademy.samschool.com`
-   `mike.johnson@defschool.samschool.com`

### Features:

-   **Domain**: School name converted to domain format + `.samschool.com`
-   **Uniqueness**: Auto-adds numbers if email exists (john.doe1@school.com)
-   **Clean Format**: Removes special characters, converts to lowercase

---

## Username Generation

### Format: `firstname.lastname`

**Examples:**

-   `john.doe`
-   `jane.smith`
-   `mike.johnson`

### Features:

-   **Clean Format**: Removes special characters, converts to lowercase
-   **Uniqueness**: Auto-adds numbers if username exists (john.doe1)
-   **Consistent**: Matches email format for easy recognition

---

## API Endpoints

### 1. Create Student with Auto-Generation

**POST** `/api/v1/students`

**Request Body:**

```json
{
    "first_name": "John",
    "last_name": "Doe",
    "middle_name": "Michael",
    "school_id": 1,
    "class_id": 1,
    "arm_id": 1,
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "phone": "+1234567890",
    "address": "123 Main St, City",
    "blood_group": "O+",
    "parent_name": "Jane Doe",
    "parent_phone": "+1234567890",
    "parent_email": "jane.doe@example.com",
    "emergency_contact": "+1234567890"
}
```

**Response (201 Created):**

```json
{
    "message": "Student created successfully",
    "student": {
        "id": 1,
        "admission_number": "ABC2025SS001",
        "first_name": "John",
        "last_name": "Doe",
        "middle_name": "Michael",
        "email": "john.doe@abchighschool.samschool.com",
        "username": "john.doe",
        "school": {
            "id": 1,
            "name": "ABC High School",
            "domain": "abchighschool.samschool.com"
        },
        "class": {
            "id": 1,
            "name": "SS1"
        },
        "arm": {
            "id": 1,
            "name": "A"
        },
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john.doe@abchighschool.samschool.com",
            "username": "john.doe",
            "role": "student",
            "status": "active"
        },
        "status": "active",
        "admission_date": "2025-01-21T10:00:00Z"
    }
}
```

### 2. Generate Admission Number

**POST** `/api/v1/students/generate-admission-number`

**Request Body:**

```json
{
    "school_id": 1,
    "class_id": 1
}
```

**Response (200 OK):**

```json
{
    "admission_number": "ABC2025SS001"
}
```

### 3. Generate Email and Username

**POST** `/api/v1/students/generate-credentials`

**Request Body:**

```json
{
    "first_name": "John",
    "last_name": "Doe",
    "school_id": 1
}
```

**Response (200 OK):**

```json
{
    "email": "john.doe@abchighschool.samschool.com",
    "username": "john.doe"
}
```

### 4. Get Student Details

**GET** `/api/v1/students/{id}`

**Response (200 OK):**

```json
{
    "id": 1,
    "admission_number": "ABC2025SS001",
    "first_name": "John",
    "last_name": "Doe",
    "middle_name": "Michael",
    "email": "john.doe@abchighschool.samschool.com",
    "username": "john.doe",
    "phone": "+1234567890",
    "address": "123 Main St, City",
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "blood_group": "O+",
    "school": {
        "id": 1,
        "name": "ABC High School",
        "domain": "abchighschool.samschool.com"
    },
    "class": {
        "id": 1,
        "name": "SS1"
    },
    "arm": {
        "id": 1,
        "name": "A"
    },
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john.doe@abchighschool.samschool.com",
        "username": "john.doe",
        "role": "student"
    },
    "status": "active",
    "admission_date": "2025-01-21T10:00:00Z",
    "created_at": "2025-01-21T10:00:00Z",
    "updated_at": "2025-01-21T10:00:00Z"
}
```

---

## Bulk Student Registration

### Bulk Registration Endpoint

**POST** `/api/v1/bulk/students/register`

**Request Body:**

```json
{
    "students": [
        {
            "first_name": "John",
            "last_name": "Doe",
            "school_id": 1,
            "class_id": 1,
            "arm_id": 1,
            "date_of_birth": "2010-05-15",
            "gender": "male"
        },
        {
            "first_name": "Jane",
            "last_name": "Smith",
            "school_id": 1,
            "class_id": 1,
            "arm_id": 2,
            "date_of_birth": "2010-06-20",
            "gender": "female"
        }
    ]
}
```

**Response (200 OK):**

```json
{
    "message": "Bulk registration completed",
    "results": {
        "successful": 2,
        "failed": 0,
        "students": [
            {
                "name": "John Doe",
                "admission_number": "ABC2025SS001",
                "email": "john.doe@abchighschool.samschool.com",
                "username": "john.doe",
                "status": "created"
            },
            {
                "name": "Jane Smith",
                "admission_number": "ABC2025SS002",
                "email": "jane.smith@abchighschool.samschool.com",
                "username": "jane.smith",
                "status": "created"
            }
        ]
    }
}
```

---

## School Domain Configuration

### Domain Generation Rules

1. **School Name**: "ABC High School"
2. **Clean Name**: Remove special characters → "ABCHighSchool"
3. **Lowercase**: Convert to lowercase → "abchighschool"
4. **Add Suffix**: Add `.samschool.com` → `abchighschool.samschool.com`

### Examples:

| School Name              | Generated Domain                       |
| ------------------------ | -------------------------------------- |
| ABC High School          | `abchighschool.samschool.com`          |
| XYZ Academy              | `xyzacademy.samschool.com`             |
| DEF International School | `definternationalschool.samschool.com` |
| St. Mary's College       | `stmaryscollege.samschool.com`         |

---

## User Account Creation

### Automatic User Account Features

When a student is created, the system automatically:

1. **Creates User Account**: Links student to user account
2. **Sets Default Password**: `password123` (can be changed)
3. **Assigns Role**: `student` role
4. **Sets Status**: `active` status
5. **Links to Tenant**: Uses school's tenant

### User Account Structure

```json
{
    "id": 1,
    "tenant_id": 1,
    "name": "John Doe",
    "email": "john.doe@abchighschool.samschool.com",
    "username": "john.doe",
    "password": "hashed_password",
    "role": "student",
    "status": "active",
    "created_at": "2025-01-21T10:00:00Z"
}
```

---

## Error Handling

### Common Errors

#### 1. School Not Found

```json
{
    "error": "School not found",
    "message": "The specified school does not exist"
}
```

#### 2. Duplicate Email

```json
{
    "error": "Email already exists",
    "message": "The email john.doe@abchighschool.samschool.com is already taken"
}
```

#### 3. Duplicate Username

```json
{
    "error": "Username already exists",
    "message": "The username john.doe is already taken"
}
```

#### 4. Validation Error

```json
{
    "error": "Validation failed",
    "messages": {
        "first_name": ["The first name field is required"],
        "last_name": ["The last name field is required"],
        "school_id": ["The school id field is required"]
    }
}
```

---

## Best Practices

### 1. Student Registration

-   Always provide `first_name`, `last_name`, and `school_id`
-   Include `class_id` for proper admission number generation
-   Set `date_of_birth` for age calculations
-   Provide parent/guardian information

### 2. Bulk Registration

-   Use CSV import for large numbers of students
-   Validate data before bulk import
-   Handle errors gracefully
-   Provide progress feedback

### 3. Email Management

-   Students can change their email after registration
-   Username changes require admin approval
-   Admission numbers are permanent and cannot be changed

### 4. Security

-   Default passwords should be changed on first login
-   Implement password policies
-   Use secure authentication methods
-   Monitor login attempts

---

## Integration Examples

### 1. Frontend Integration

```javascript
// Create student with auto-generation
const createStudent = async (studentData) => {
    const response = await fetch("/api/v1/students", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(studentData),
    });

    const result = await response.json();

    if (response.ok) {
        console.log("Student created:", result.student);
        console.log("Admission Number:", result.student.admission_number);
        console.log("Email:", result.student.email);
        console.log("Username:", result.student.username);
    }
};
```

### 2. Bulk Import Integration

```javascript
// Bulk student registration
const bulkRegisterStudents = async (students) => {
    const response = await fetch("/api/v1/bulk/students/register", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ students }),
    });

    const result = await response.json();

    if (response.ok) {
        console.log("Bulk registration completed");
        console.log("Successful:", result.results.successful);
        console.log("Failed:", result.results.failed);
    }
};
```

### 3. Credential Generation

```javascript
// Generate credentials before registration
const generateCredentials = async (firstName, lastName, schoolId) => {
    const response = await fetch("/api/v1/students/generate-credentials", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
            first_name: firstName,
            last_name: lastName,
            school_id: schoolId,
        }),
    });

    const result = await response.json();

    if (response.ok) {
        console.log("Generated Email:", result.email);
        console.log("Generated Username:", result.username);
    }
};
```

---

## Database Schema

### Students Table

```sql
CREATE TABLE students (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    school_id BIGINT NOT NULL,
    user_id BIGINT NULL,
    admission_number VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    middle_name VARCHAR(255) NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20) NULL,
    address TEXT NULL,
    date_of_birth DATE NULL,
    gender ENUM('male', 'female', 'other') NULL,
    blood_group VARCHAR(10) NULL,
    parent_name VARCHAR(255) NULL,
    parent_phone VARCHAR(20) NULL,
    parent_email VARCHAR(255) NULL,
    emergency_contact VARCHAR(20) NULL,
    admission_date DATE NOT NULL,
    class_id BIGINT NULL,
    arm_id BIGINT NULL,
    status ENUM('active', 'inactive', 'suspended', 'graduated') DEFAULT 'active',
    profile_picture VARCHAR(500) NULL,
    medical_info JSON NULL,
    transport_info JSON NULL,
    hostel_info JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (school_id) REFERENCES schools(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (arm_id) REFERENCES arms(id),

    INDEX idx_admission_number (admission_number),
    INDEX idx_email (email),
    INDEX idx_username (username),
    INDEX idx_school_class (school_id, class_id),
    INDEX idx_status (status)
);
```

---

## Testing

### Test Student Creation

```bash
# Test student creation
curl -X POST http://localhost:8000/api/v1/students \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "school_id": 1,
    "class_id": 1,
    "date_of_birth": "2010-05-15",
    "gender": "male"
  }'
```

### Test Credential Generation

```bash
# Test credential generation
curl -X POST http://localhost:8000/api/v1/students/generate-credentials \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "school_id": 1
  }'
```

---

## Conclusion

The Student Admission Number & Credential Generation System provides:

-   ✅ **Unique Admission Numbers** for easy student identification
-   ✅ **Automatic Email Generation** using school domains
-   ✅ **Clean Username Creation** for login purposes
-   ✅ **Bulk Registration Support** for efficient onboarding
-   ✅ **Automatic User Account Creation** for seamless access
-   ✅ **Comprehensive Error Handling** for robust operation
-   ✅ **Flexible Configuration** for different school setups

This system ensures every student gets a unique identity within the school ecosystem while maintaining consistency and professionalism across all communications.
