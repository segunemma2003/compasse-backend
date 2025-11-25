# Student Creation API Documentation

## Table of Contents

1. [Overview](#overview)
2. [API Endpoint](#api-endpoint)
3. [Authentication](#authentication)
4. [Required Fields](#required-fields)
5. [Optional Fields](#optional-fields)
6. [Auto-Generated Fields](#auto-generated-fields)
7. [Request Examples](#request-examples)
8. [Response Examples](#response-examples)
9. [Error Handling](#error-handling)
10. [Prerequisites](#prerequisites)
11. [Important Notes](#important-notes)
12. [Bulk Student Creation](#bulk-student-creation)

---

## Overview

The Student Creation API allows you to register new students in your school management system. The API automatically generates admission numbers, email addresses, usernames, and login credentials for each student.

### Key Features

-   ✅ Automatic credential generation (admission number, email, username, password)
-   ✅ Optional guardian/parent linking (up to 2 guardians)
-   ✅ Medical, transport, and hostel information support
-   ✅ No `school_id` required (auto-detected from tenant context)
-   ✅ Full transaction support (all-or-nothing)
-   ✅ Comprehensive validation

---

## API Endpoint

### Create Single Student

```
POST /api/v1/students
```

**Base URLs:**

-   Production: `https://api.compasse.net/api/v1`
-   Local: `http://localhost:8000/api/v1`

---

## Authentication

All requests must include these headers:

```http
Authorization: Bearer {your_access_token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

**Example:**

```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc..." \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{...}'
```

---

## Required Fields

These fields are **mandatory** for creating a student:

| Field           | Type    | Validation                     | Description             | Example      |
| --------------- | ------- | ------------------------------ | ----------------------- | ------------ |
| `first_name`    | string  | required, max:255              | Student's first name    | "John"       |
| `last_name`     | string  | required, max:255              | Student's last name     | "Doe"        |
| `class_id`      | integer | required, exists:classes,id    | ID of the class         | 1            |
| `date_of_birth` | date    | required, date format          | Birth date (YYYY-MM-DD) | "2010-05-15" |
| `gender`        | string  | required, in:male,female,other | Student's gender        | "male"       |

### Validation Rules Summary

```php
'first_name' => 'required|string|max:255',
'last_name' => 'required|string|max:255',
'class_id' => 'required|exists:classes,id',
'date_of_birth' => 'required|date',
'gender' => 'required|in:male,female,other',
```

---

## Optional Fields

These fields are optional but recommended for complete student records:

### Basic Information

| Field         | Type    | Validation               | Description          | Example                 |
| ------------- | ------- | ------------------------ | -------------------- | ----------------------- |
| `middle_name` | string  | nullable, max:255        | Middle name          | "Smith"                 |
| `arm_id`      | integer | nullable, exists:arms,id | Class arm/section ID | 1                       |
| `phone`       | string  | nullable, max:20         | Student phone number | "+234801234567"         |
| `address`     | string  | nullable, max:500        | Home address         | "123 Main St, Lagos"    |
| `blood_group` | string  | nullable, max:10         | Blood type           | "O+", "A-", "B+", "AB-" |

### Parent/Guardian Information

| Field               | Type   | Validation               | Description              | Example            |
| ------------------- | ------ | ------------------------ | ------------------------ | ------------------ |
| `parent_name`       | string | nullable, max:255        | Parent/guardian name     | "Jane Doe"         |
| `parent_phone`      | string | nullable, max:20         | Parent phone number      | "+234809876543"    |
| `parent_email`      | email  | nullable, email, max:255 | Parent email address     | "jane@example.com" |
| `emergency_contact` | string | nullable, max:20         | Emergency contact number | "+234809876543"    |

### Extended Information

| Field            | Type         | Validation      | Description               | Example   |
| ---------------- | ------------ | --------------- | ------------------------- | --------- |
| `medical_info`   | object/array | nullable, array | Medical details (JSON)    | See below |
| `transport_info` | object/array | nullable, array | Transport details (JSON)  | See below |
| `hostel_info`    | object/array | nullable, array | Hostel information (JSON) | See below |
| `guardians`      | array        | nullable, array | Guardian details (array)  | See below |

### Medical Information Structure

```json
{
    "medical_info": {
        "allergies": ["Peanuts", "Shellfish"],
        "medications": ["Inhaler", "EpiPen"],
        "conditions": ["Asthma"],
        "blood_group": "O+",
        "doctor_name": "Dr. Smith",
        "doctor_phone": "+234801111111",
        "hospital": "General Hospital Lagos",
        "insurance_provider": "NHIS",
        "insurance_number": "INS123456",
        "special_needs": "None",
        "notes": "Check inhaler availability"
    }
}
```

### Transport Information Structure

```json
{
    "transport_info": {
        "uses_transport": true,
        "route_id": 1,
        "pickup_point": "Main Gate",
        "pickup_time": "07:30",
        "dropoff_point": "Main Gate",
        "dropoff_time": "15:00",
        "bus_number": "BUS-001",
        "guardian_pickup": false,
        "special_instructions": "Call parent before pickup"
    }
}
```

### Hostel Information Structure

```json
{
    "hostel_info": {
        "is_boarder": true,
        "hostel_name": "King's Hostel",
        "block": "A",
        "floor": 2,
        "room_number": "102",
        "bed_number": "3",
        "roommate_preferences": "Quiet environment",
        "dietary_requirements": "Vegetarian",
        "bedding_provided": true,
        "locker_number": "A102-3"
    }
}
```

### Guardian Information Structure

```json
{
    "guardians": [
        {
            "first_name": "Jane", // Required if guardians array exists
            "last_name": "Doe", // Required if guardians array exists
            "email": "jane.doe@example.com", // Required if guardians array exists
            "phone": "+234809876543", // Optional
            "relationship": "Mother", // Required if guardians array exists
            "is_primary": true, // Optional (boolean, default: first is primary)
            "occupation": "Doctor", // Optional
            "address": "123 Main St, Lagos", // Optional
            "can_pickup": true, // Optional
            "emergency_contact": true // Optional
        },
        {
            "first_name": "Robert",
            "last_name": "Doe",
            "email": "robert.doe@example.com",
            "phone": "+234808765432",
            "relationship": "Father",
            "is_primary": false,
            "occupation": "Engineer",
            "address": "123 Main St, Lagos"
        }
    ]
}
```

**Guardian Validation Rules:**

```php
'guardians' => 'nullable|array',
'guardians.*.first_name' => 'required_with:guardians|string|max:255',
'guardians.*.last_name' => 'required_with:guardians|string|max:255',
'guardians.*.email' => 'required_with:guardians|email',
'guardians.*.phone' => 'nullable|string|max:20',
'guardians.*.relationship' => 'required_with:guardians|string|max:255',
'guardians.*.is_primary' => 'nullable|boolean',
```

**Note:** Maximum of **2 guardians** per student.

---

## Auto-Generated Fields

These fields are **automatically generated** by the system and should **NOT** be included in your request:

| Field              | Description           | Format/Example               | Notes                            |
| ------------------ | --------------------- | ---------------------------- | -------------------------------- |
| `admission_number` | Unique student ID     | "ADM00001"                   | Sequential, auto-incremented     |
| `email`            | Student email address | "john.doe1@schooldomain.com" | Uses school's domain             |
| `username`         | Login username        | "john.doe123"                | Random suffix for uniqueness     |
| `password`         | Default password      | "Password@123"               | Should be changed on first login |
| `school_id`        | School identifier     | Auto from X-Subdomain        | From tenant context              |
| `user_id`          | User account ID       | Auto-generated               | Links to users table             |
| `status`           | Account status        | "active"                     | Default value                    |

### Credential Generation Rules

#### Admission Number

-   Format: `{PREFIX}{NUMBER}`
-   Example: `ADM00001`, `ADM00002`, etc.
-   Automatically sequential based on existing students

#### Email Address

-   Format: `{firstname}.{lastname}{id}@{schooldomain}`
-   Example: `john.doe1@westwoodschool.com`
-   Domain extracted from school's website URL
-   Fallback: `{subdomain}.samschool.com`

#### Username

-   Format: `{firstname}.{lastname}{random}`
-   Example: `john.doe456`
-   Random 3-digit suffix for uniqueness

#### Password

-   Default: `Password@123`
-   Users should change on first login
-   Meets standard password requirements

---

## Request Examples

### 1. Minimal Request (Required Fields Only)

```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "class_id": 1,
    "date_of_birth": "2010-05-15",
    "gender": "male"
  }'
```

### 2. Recommended Request (With Common Fields)

```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "middle_name": "Smith",
    "class_id": 1,
    "arm_id": 2,
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "phone": "+234801234567",
    "address": "123 Main Street, Victoria Island, Lagos",
    "blood_group": "O+",
    "parent_name": "Jane Doe",
    "parent_phone": "+234809876543",
    "parent_email": "jane.doe@example.com",
    "emergency_contact": "+234809876543"
  }'
```

### 3. Complete Request (With All Fields)

```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "middle_name": "Smith",
    "class_id": 1,
    "arm_id": 2,
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "phone": "+234801234567",
    "address": "123 Main Street, Victoria Island, Lagos, Nigeria",
    "blood_group": "O+",
    "parent_name": "Jane Doe",
    "parent_phone": "+234809876543",
    "parent_email": "jane.doe@example.com",
    "emergency_contact": "+234809876543",
    "medical_info": {
      "allergies": ["Peanuts", "Shellfish"],
      "medications": ["Inhaler"],
      "conditions": ["Asthma"],
      "doctor_name": "Dr. Smith",
      "doctor_phone": "+234801111111",
      "hospital": "General Hospital Lagos",
      "insurance_provider": "NHIS",
      "insurance_number": "INS123456"
    },
    "transport_info": {
      "uses_transport": true,
      "route_id": 1,
      "pickup_point": "Main Gate",
      "pickup_time": "07:30",
      "dropoff_point": "Main Gate",
      "dropoff_time": "15:00"
    },
    "hostel_info": {
      "is_boarder": true,
      "block": "A",
      "room_number": "102",
      "bed_number": "3"
    },
    "guardians": [
      {
        "first_name": "Jane",
        "last_name": "Doe",
        "email": "jane.doe@example.com",
        "phone": "+234809876543",
        "relationship": "Mother",
        "is_primary": true,
        "occupation": "Doctor",
        "address": "123 Main Street, Victoria Island, Lagos"
      },
      {
        "first_name": "Robert",
        "last_name": "Doe",
        "email": "robert.doe@example.com",
        "phone": "+234808765432",
        "relationship": "Father",
        "is_primary": false,
        "occupation": "Engineer",
        "address": "123 Main Street, Victoria Island, Lagos"
      }
    ]
  }'
```

### 4. JavaScript/Frontend Example

```javascript
async function createStudent(studentData) {
    try {
        const response = await fetch(
            "https://api.compasse.net/api/v1/students",
            {
                method: "POST",
                headers: {
                    Authorization: `Bearer ${accessToken}`,
                    "X-Subdomain": "westwood",
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    first_name: studentData.firstName,
                    last_name: studentData.lastName,
                    middle_name: studentData.middleName,
                    class_id: studentData.classId,
                    arm_id: studentData.armId,
                    date_of_birth: studentData.dateOfBirth,
                    gender: studentData.gender,
                    phone: studentData.phone,
                    address: studentData.address,
                    blood_group: studentData.bloodGroup,
                    parent_name: studentData.parentName,
                    parent_phone: studentData.parentPhone,
                    parent_email: studentData.parentEmail,
                    emergency_contact: studentData.emergencyContact,
                }),
            }
        );

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || "Failed to create student");
        }

        const result = await response.json();
        console.log("Student created:", result);

        // Show login credentials to admin
        alert(`Student created successfully!
               Email: ${result.login_credentials.email}
               Password: ${result.login_credentials.password}`);

        return result;
    } catch (error) {
        console.error("Error creating student:", error);
        throw error;
    }
}
```

---

## Response Examples

### Success Response (201 Created)

```json
{
    "message": "Student created successfully",
    "student": {
        "id": 1,
        "first_name": "John",
        "last_name": "Doe",
        "middle_name": "Smith",
        "admission_number": "ADM00001",
        "email": "john.doe1@westwoodschool.com",
        "username": "john.doe456",
        "class_id": 1,
        "arm_id": 2,
        "date_of_birth": "2010-05-15",
        "gender": "male",
        "phone": "+234801234567",
        "address": "123 Main Street, Victoria Island, Lagos",
        "blood_group": "O+",
        "parent_name": "Jane Doe",
        "parent_phone": "+234809876543",
        "parent_email": "jane.doe@example.com",
        "emergency_contact": "+234809876543",
        "status": "active",
        "medical_info": {
            "allergies": ["Peanuts", "Shellfish"],
            "medications": ["Inhaler"],
            "conditions": ["Asthma"]
        },
        "transport_info": {
            "uses_transport": true,
            "route_id": 1,
            "pickup_point": "Main Gate"
        },
        "hostel_info": {
            "is_boarder": true,
            "block": "A",
            "room_number": "102"
        },
        "school": {
            "id": 1,
            "name": "Westwood School",
            "subdomain": "westwood"
        },
        "class": {
            "id": 1,
            "name": "JSS 1",
            "level": "Junior Secondary"
        },
        "arm": {
            "id": 2,
            "name": "A",
            "capacity": 30
        },
        "user": {
            "id": 10,
            "name": "John Smith Doe",
            "email": "john.doe1@westwoodschool.com",
            "role": "student",
            "status": "active"
        },
        "guardians": [
            {
                "id": 1,
                "first_name": "Jane",
                "last_name": "Doe",
                "email": "jane.doe@example.com",
                "phone": "+234809876543",
                "relationship": "Mother",
                "is_primary": true,
                "user": {
                    "id": 11,
                    "name": "Jane Doe",
                    "email": "jane.doe1@westwoodschool.com",
                    "role": "guardian"
                }
            },
            {
                "id": 2,
                "first_name": "Robert",
                "last_name": "Doe",
                "email": "robert.doe@example.com",
                "phone": "+234808765432",
                "relationship": "Father",
                "is_primary": false,
                "user": {
                    "id": 12,
                    "name": "Robert Doe",
                    "email": "robert.doe2@westwoodschool.com",
                    "role": "guardian"
                }
            }
        ],
        "created_at": "2025-11-24T10:30:00.000000Z",
        "updated_at": "2025-11-24T10:30:00.000000Z"
    },
    "login_credentials": {
        "email": "john.doe1@westwoodschool.com",
        "password": "Password@123",
        "note": "Student should change password on first login"
    }
}
```

---

## Error Handling

### Validation Error (422 Unprocessable Entity)

When required fields are missing or invalid:

```json
{
    "error": "Validation failed",
    "messages": {
        "first_name": ["The first name field is required."],
        "last_name": ["The last name field is required."],
        "class_id": ["The selected class is invalid."],
        "date_of_birth": ["The date of birth must be a valid date."],
        "gender": ["The selected gender is invalid."]
    }
}
```

### School Not Found (400 Bad Request)

When tenant context cannot determine the school:

```json
{
    "error": "School not found",
    "message": "Unable to determine school from tenant context"
}
```

### Unauthorized (401 Unauthorized)

When authentication token is missing or invalid:

```json
{
    "message": "Unauthenticated."
}
```

### Forbidden (403 Forbidden)

When user doesn't have permission:

```json
{
    "message": "This action is unauthorized."
}
```

### Class Not Found (422 Unprocessable Entity)

When the specified class doesn't exist:

```json
{
    "error": "Validation failed",
    "messages": {
        "class_id": ["The selected class id is invalid."]
    }
}
```

### Server Error (500 Internal Server Error)

When an unexpected error occurs:

```json
{
    "error": "Student creation failed",
    "message": "Database connection timeout"
}
```

---

## Prerequisites

Before creating students, ensure the following resources are created in your system:

### 1. Academic Year (Required)

```bash
POST /api/v1/academic-years

{
  "name": "2024/2025",
  "start_date": "2024-09-01",
  "end_date": "2025-07-31",
  "status": "active"
}
```

### 2. Term (Required)

```bash
POST /api/v1/terms

{
  "name": "First Term",
  "academic_year_id": 1,
  "start_date": "2024-09-01",
  "end_date": "2024-12-20",
  "status": "active"
}
```

### 3. Class (Required)

```bash
POST /api/v1/classes

{
  "name": "JSS 1",
  "level": "Junior Secondary",
  "academic_year_id": 1,
  "term_id": 1,
  "capacity": 30,
  "status": "active"
}
```

### 4. Class Arm (Optional but Recommended)

```bash
POST /api/v1/arms

{
  "class_id": 1,
  "name": "A",
  "capacity": 30,
  "class_teacher_id": 1
}
```

### Complete Setup Flow

```bash
# Step 1: Create Academic Year
curl -X POST "https://api.compasse.net/api/v1/academic-years" \
  -H "Authorization: Bearer TOKEN" \
  -H "X-Subdomain: westwood" \
  -d '{"name":"2024/2025","start_date":"2024-09-01","end_date":"2025-07-31"}'

# Step 2: Create Term
curl -X POST "https://api.compasse.net/api/v1/terms" \
  -H "Authorization: Bearer TOKEN" \
  -H "X-Subdomain: westwood" \
  -d '{"name":"First Term","academic_year_id":1,"start_date":"2024-09-01","end_date":"2024-12-20"}'

# Step 3: Create Class
curl -X POST "https://api.compasse.net/api/v1/classes" \
  -H "Authorization: Bearer TOKEN" \
  -H "X-Subdomain: westwood" \
  -d '{"name":"JSS 1","academic_year_id":1,"term_id":1}'

# Step 4: Create Student
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer TOKEN" \
  -H "X-Subdomain: westwood" \
  -d '{
    "first_name":"John",
    "last_name":"Doe",
    "class_id":1,
    "date_of_birth":"2010-05-15",
    "gender":"male"
  }'
```

---

## Important Notes

### 1. No School ID Required ❌

**Don't include `school_id` in your request!**

```json
// ❌ BAD - Don't do this
{
  "school_id": 1,  // Not needed!
  "first_name": "John",
  ...
}

// ✅ GOOD - System gets school_id automatically
{
  "first_name": "John",
  "last_name": "Doe",
  ...
}
```

The system automatically determines the school from the `X-Subdomain` header.

### 2. Date Format 📅

Always use **ISO 8601** format: `YYYY-MM-DD`

```json
// ✅ CORRECT
"date_of_birth": "2010-05-15"

// ❌ WRONG
"date_of_birth": "15/05/2010"
"date_of_birth": "05-15-2010"
"date_of_birth": "15-05-2010"
```

### 3. Gender Values 👤

Only these values are accepted:

```json
"gender": "male"     // ✅
"gender": "female"   // ✅
"gender": "other"    // ✅
"gender": "Male"     // ❌ Case sensitive!
```

### 4. Guardian Limit 👨‍👩‍👧

Maximum of **2 guardians** per student:

```json
{
  "guardians": [
    {...},  // Guardian 1
    {...}   // Guardian 2
  ]
  // Adding more than 2 will only use the first 2
}
```

### 5. Email Domain 📧

Student email uses the school's website domain:

```
School website: https://www.westwoodschool.com
Student email: john.doe1@westwoodschool.com
             (firstname.lastname{id}@domain)
```

If no website is set:

```
Subdomain: westwood
Student email: john.doe1@westwood.samschool.com
```

### 6. Password Security 🔒

Default password is `Password@123`, but:

-   ✅ Users should change it on first login
-   ✅ Implement password change enforcement
-   ✅ Enable email verification
-   ✅ Use 2FA for sensitive accounts

### 7. Transaction Safety 💾

Student creation uses database transactions:

-   If any part fails, everything is rolled back
-   Ensures data integrity
-   Guardian creation happens atomically

### 8. JSON Fields Flexibility 📝

`medical_info`, `transport_info`, and `hostel_info` are JSON fields:

-   Can contain any custom structure
-   Flexible for future requirements
-   No strict schema enforced

Example:

```json
{
    "medical_info": {
        "any_custom_field": "value",
        "nested_objects": {
            "are": "supported"
        },
        "arrays": ["also", "work"]
    }
}
```

---

## Bulk Student Creation

For creating multiple students at once, use the Bulk API:

### Endpoint

```
POST /api/v1/bulk/students/register
```

### Request Example

```json
{
    "students": [
        {
            "first_name": "John",
            "last_name": "Doe",
            "class_id": 1,
            "date_of_birth": "2010-05-15",
            "gender": "male"
        },
        {
            "first_name": "Jane",
            "last_name": "Smith",
            "class_id": 1,
            "date_of_birth": "2010-08-20",
            "gender": "female"
        }
        // ... up to 10,000 students
    ]
}
```

### Performance

| Records | Mode      | Time           | Memory |
| ------- | --------- | -------------- | ------ |
| < 1,000 | Standard  | ~2-5 minutes   | 200MB  |
| 1,000+  | Optimized | ~40-60 seconds | 120MB  |
| 10,000  | Optimized | ~45-50 seconds | 120MB  |

### Documentation

For complete bulk operations documentation, see:

-   **BULK_OPERATIONS_AND_QUESTION_BANK_API_DOCUMENTATION.md**
-   **BULK_OPERATIONS_PERFORMANCE_GUIDE.md**

### Limits

-   **Minimum:** 1 student per request
-   **Standard Mode:** Up to 1,000 students
-   **Optimized Mode:** Up to 10,000 students
-   **Recommended:** 500-2,000 students per batch for optimal performance

---

## Additional Student APIs

### Get All Students

```bash
GET /api/v1/students
```

**Query Parameters:**

-   `page` - Page number (default: 1)
-   `per_page` - Items per page (default: 15)
-   `class_id` - Filter by class
-   `arm_id` - Filter by arm
-   `gender` - Filter by gender
-   `status` - Filter by status
-   `search` - Search by name

### Get Single Student

```bash
GET /api/v1/students/{id}
```

### Update Student

```bash
PUT /api/v1/students/{id}

{
  "first_name": "John",
  "last_name": "Doe Updated",
  "phone": "+234801234567"
}
```

### Delete Student

```bash
DELETE /api/v1/students/{id}
```

### Get Student Attendance

```bash
GET /api/v1/students/{id}/attendance
```

---

## Support

For issues or questions:

-   **Email:** support@samschool.com
-   **Documentation:** https://docs.samschool.com
-   **API Status:** https://status.samschool.com
-   **GitHub Issues:** https://github.com/samschool/api/issues

---

## Changelog

### Version 1.0.0 (November 24, 2025)

-   Initial release
-   Auto-generated credentials
-   Guardian linking support
-   Medical/Transport/Hostel info support
-   Removed school_id requirement
-   Added bulk creation support

---

**Last Updated:** November 24, 2025  
**API Version:** 1.0.0  
**Maintained By:** SamSchool Development Team
