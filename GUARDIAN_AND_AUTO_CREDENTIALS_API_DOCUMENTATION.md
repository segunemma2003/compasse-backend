# Guardian & Auto-Generated Credentials API Documentation

This document covers the Guardian/Parent system and auto-generated login credentials for students, staff, and other roles.

---

## Table of Contents

1. [Auto-Generated Login Credentials](#auto-generated-login-credentials)
2. [Guardian/Parent System Overview](#guardianparent-system-overview)
3. [Student Creation with Guardians](#student-creation-with-guardians)
4. [Guardian Management APIs](#guardian-management-apis)
5. [Guardian Dashboard API](#guardian-dashboard-api)
6. [Staff Auto-Generation](#staff-auto-generation)

---

## Auto-Generated Login Credentials

### Overview

When creating students, staff, or other users, the system automatically generates login credentials following this pattern:

**Email Format:** `firstname.lastname{user_id}@{school_subdomain}.samschool.com`

**Default Password:** `Password@123`

### Examples

| Role     | Name         | ID  | Generated Email                         |
| -------- | ------------ | --- | --------------------------------------- |
| Student  | John Doe     | 123 | `john.doe123@westwood.samschool.com`    |
| Staff    | Jane Smith   | 45  | `jane.smith45@westwood.samschool.com`   |
| Guardian | Mike Johnson | 78  | `mike.johnson78@westwood.samschool.com` |

### Credential Response Format

When creating any user, the API returns login credentials:

```json
{
  "message": "Student created successfully",
  "student": { ... },
  "login_credentials": {
    "email": "john.doe123@westwood.samschool.com",
    "password": "Password@123",
    "note": "Student should change password on first login"
  }
}
```

---

## Guardian/Parent System Overview

### Key Features

1. **Multiple Guardians**: Each student can have 1-2 guardians
2. **Primary Guardian**: One guardian is designated as primary contact
3. **Auto-User Creation**: Guardian user accounts are automatically created
4. **Unified Dashboard**: Guardians can view all their wards' information
5. **Relationship Tracking**: System tracks relationship type (Father, Mother, Uncle, etc.)

### Guardian-Student Relationship

Guardians are linked to students through a many-to-many relationship with these pivot fields:

-   `relationship` - e.g., "Father", "Mother", "Guardian", "Uncle"
-   `is_primary` - Boolean (true for primary/main contact)
-   `emergency_contact` - Boolean (can be contacted in emergencies)

---

## Student Creation with Guardians

### Endpoint

`POST /api/v1/students`

### Headers

```http
Authorization: Bearer {admin_token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

### Request Body (With Guardians)

**Note:** No `school_id` required! System auto-gets it from `X-Subdomain` header.

```json
{
    "first_name": "John",
    "last_name": "Doe",
    "middle_name": "Michael",
    "class_id": 5,
    "arm_id": 2,
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "phone": "+1234567890",
    "address": "123 Main St, City, State",
    "blood_group": "O+",
    "emergency_contact": "+1234567890",
    "medical_info": {
        "allergies": ["Peanuts"],
        "conditions": []
    },
    "guardians": [
        {
            "first_name": "Robert",
            "last_name": "Doe",
            "middle_name": "James",
            "email": "robert.doe@example.com",
            "phone": "+1234567890",
            "address": "123 Main St, City, State",
            "occupation": "Engineer",
            "employer": "Tech Corp",
            "relationship": "Father",
            "is_primary": true,
            "emergency_contact": true
        },
        {
            "first_name": "Mary",
            "last_name": "Doe",
            "email": "mary.doe@example.com",
            "phone": "+1234567891",
            "relationship": "Mother",
            "is_primary": false,
            "emergency_contact": true
        }
    ]
}
```

### Validation Rules (Guardians Array)

-   `guardians` (optional, array, max 2 items)
-   `guardians.*.first_name` (required_with:guardians, string, max 255)
-   `guardians.*.last_name` (required_with:guardians, string, max 255)
-   `guardians.*.email` (required_with:guardians, email)
-   `guardians.*.phone` (optional, string, max 20)
-   `guardians.*.relationship` (required_with:guardians, string) - e.g., "Father", "Mother", "Guardian"
-   `guardians.*.is_primary` (optional, boolean, default: first guardian is primary)

### Success Response (201)

```json
{
    "message": "Student created successfully",
    "student": {
        "id": 123,
        "school_id": 1,
        "admission_number": "WES2025SS001",
        "first_name": "John",
        "last_name": "Doe",
        "middle_name": "Michael",
        "email": "john.doe123@westwood.samschool.com",
        "username": "john.doe",
        "phone": "+1234567890",
        "date_of_birth": "2010-05-15",
        "gender": "male",
        "class_id": 5,
        "arm_id": 2,
        "status": "active",
        "user_id": 456,
        "created_at": "2025-11-23T10:00:00.000000Z",
        "updated_at": "2025-11-23T10:00:00.000000Z",
        "user": {
            "id": 456,
            "name": "John Doe",
            "email": "john.doe123@westwood.samschool.com",
            "role": "student"
        },
        "guardians": [
            {
                "id": 78,
                "first_name": "Robert",
                "last_name": "Doe",
                "email": "robert.doe@example.com",
                "phone": "+1234567890",
                "user_id": 789,
                "pivot": {
                    "relationship": "Father",
                    "is_primary": true,
                    "emergency_contact": true
                }
            },
            {
                "id": 79,
                "first_name": "Mary",
                "last_name": "Doe",
                "email": "mary.doe@example.com",
                "phone": "+1234567891",
                "user_id": 790,
                "pivot": {
                    "relationship": "Mother",
                    "is_primary": false,
                    "emergency_contact": true
                }
            }
        ]
    },
    "login_credentials": {
        "email": "john.doe123@westwood.samschool.com",
        "password": "Password@123",
        "note": "Student should change password on first login"
    }
}
```

### Notes

-   **Maximum 2 guardians** per student
-   **First guardian is primary by default** if `is_primary` not specified
-   **Auto-creates user accounts** for both student and guardians
-   **Email uniqueness**: If guardian email already exists, existing guardian is linked instead of creating duplicate
-   **Auto-generated credentials** for both student and guardians

---

## Guardian Management APIs

### 1. List All Guardians

**Endpoint:** `GET /api/v1/guardians`

**Query Parameters:**

-   `per_page` (optional): Items per page (default: 15)
-   `status` (optional): Filter by status (`active`, `inactive`)
-   `search` (optional): Search by name, email, or phone

**Response:**

```json
{
    "guardians": {
        "data": [
            {
                "id": 1,
                "school_id": 1,
                "user_id": 789,
                "first_name": "Robert",
                "last_name": "Doe",
                "email": "robert.doe@example.com",
                "phone": "+1234567890",
                "address": "123 Main St",
                "occupation": "Engineer",
                "employer": "Tech Corp",
                "status": "active",
                "created_at": "2025-01-15T10:00:00.000000Z",
                "updated_at": "2025-01-15T10:00:00.000000Z",
                "user": {
                    "id": 789,
                    "name": "Robert Doe",
                    "email": "robert.doe@example.com",
                    "role": "guardian"
                },
                "students": [
                    {
                        "id": 123,
                        "first_name": "John",
                        "last_name": "Doe",
                        "pivot": {
                            "relationship": "Father",
                            "is_primary": true
                        }
                    }
                ]
            }
        ],
        "current_page": 1,
        "per_page": 15,
        "total": 1
    }
}
```

---

### 2. Get Guardian Details

**Endpoint:** `GET /api/v1/guardians/{id}`

**Response:**

```json
{
  "guardian": {
    "id": 1,
    "first_name": "Robert",
    "last_name": "Doe",
    "email": "robert.doe@example.com",
    "phone": "+1234567890",
    "user": { ... },
    "students": [
      {
        "id": 123,
        "first_name": "John",
        "last_name": "Doe",
        "email": "john.doe123@westwood.samschool.com",
        "class": {
          "id": 5,
          "name": "Grade 10"
        },
        "arm": {
          "id": 2,
          "name": "A"
        },
        "pivot": {
          "relationship": "Father",
          "is_primary": true,
          "emergency_contact": true
        }
      }
    ]
  },
  "students_performance": [
    {
      "student": { ... },
      "academic_performance": {
        "total_exams": 12,
        "average_score": 85.5,
        "highest_score": 95,
        "lowest_score": 72
      },
      "attendance_rate": 96.5
    }
  ]
}
```

---

### 3. Create Guardian

**Endpoint:** `POST /api/v1/guardians`

**Request Body:**

```json
{
    "first_name": "Robert",
    "last_name": "Doe",
    "middle_name": "James",
    "email": "robert.doe@example.com",
    "phone": "+1234567890",
    "address": "123 Main St, City",
    "occupation": "Engineer",
    "employer": "Tech Corp",
    "relationship_to_student": "Father",
    "emergency_contact": "+1234567890"
}
```

**Note:** No `school_id` or `user_id` required! The system will:

-   Auto-get `school_id` from X-Subdomain header
-   Auto-create a user account with generated credentials
-   Auto-generate email if not provided: `firstname.lastname{id}@school.samschool.com`

**Success Response (201):**

```json
{
  "message": "Guardian created successfully",
  "guardian": {
    "id": 1,
    "user_id": 789,
    "school_id": 1,
    "first_name": "Robert",
    "last_name": "Doe",
    "email": "robert.doe@example.com",
    ...
  },
  "login_credentials": {
    "email": "robert.doe1@westwood.samschool.com",
    "username": "robert.doe1",
    "password": "Password@123",
    "role": "guardian",
    "note": "Guardian should change password on first login"
  }
  }
}
```

---

### 4. Update Guardian

**Endpoint:** `PUT /api/v1/guardians/{id}`

**Request Body:** (All fields optional)

```json
{
    "first_name": "Robert",
    "phone": "+1234567899",
    "occupation": "Senior Engineer",
    "status": "active"
}
```

**Success Response (200):**

```json
{
  "message": "Guardian updated successfully",
  "guardian": { ... }
}
```

---

### 5. Delete Guardian

**Endpoint:** `DELETE /api/v1/guardians/{id}`

**Success Response (200):**

```json
{
    "message": "Guardian deleted successfully"
}
```

---

### 6. Assign Student to Guardian

**Endpoint:** `POST /api/v1/guardians/{guardian_id}/assign-student`

**Request Body:**

```json
{
    "student_id": 123,
    "relationship": "Father",
    "is_primary": true,
    "emergency_contact": true
}
```

**Success Response (200):**

```json
{
    "message": "Student assigned to guardian successfully"
}
```

---

### 7. Remove Student from Guardian

**Endpoint:** `DELETE /api/v1/guardians/{guardian_id}/remove-student`

**Request Body:**

```json
{
    "student_id": 123
}
```

**Success Response (200):**

```json
{
    "message": "Student removed from guardian successfully"
}
```

---

### 8. Get Guardian's Students

**Endpoint:** `GET /api/v1/guardians/{guardian_id}/students`

**Response:**

```json
{
  "students": [
    {
      "id": 123,
      "first_name": "John",
      "last_name": "Doe",
      "email": "john.doe123@westwood.samschool.com",
      "class": { ... },
      "arm": { ... },
      "user": { ... }
    }
  ]
}
```

---

### 9. Get Guardian's Notifications

**Endpoint:** `GET /api/v1/guardians/{guardian_id}/notifications`

**Response:**

```json
{
    "notifications": {
        "data": [
            {
                "id": 1,
                "type": "student_attendance",
                "data": {
                    "message": "Your ward John Doe was absent today",
                    "student_id": 123,
                    "date": "2025-11-23"
                },
                "read_at": null,
                "created_at": "2025-11-23T08:00:00.000000Z"
            }
        ],
        "current_page": 1,
        "per_page": 20,
        "total": 15
    }
}
```

---

### 10. Get Guardian's Messages

**Endpoint:** `GET /api/v1/guardians/{guardian_id}/messages`

**Response:**

```json
{
    "messages": {
        "data": [
            {
                "id": 1,
                "sender_id": 5,
                "subject": "Parent-Teacher Meeting",
                "body": "You are invited to attend...",
                "read_at": null,
                "created_at": "2025-11-22T10:00:00.000000Z"
            }
        ],
        "current_page": 1,
        "per_page": 20,
        "total": 8
    }
}
```

---

### 11. Get Guardian's Payments

**Endpoint:** `GET /api/v1/guardians/{guardian_id}/payments`

**Response:**

```json
{
    "payments": {
        "data": [
            {
                "id": 1,
                "student_id": 123,
                "fee_id": 45,
                "amount": 500.0,
                "payment_method": "bank_transfer",
                "status": "completed",
                "created_at": "2025-11-20T14:30:00.000000Z"
            }
        ],
        "current_page": 1,
        "per_page": 20,
        "total": 5
    }
}
```

---

## Guardian Dashboard API

### Endpoint

`GET /api/v1/dashboard/parent`

### Headers

```http
Authorization: Bearer {guardian_token}
X-Subdomain: {school_subdomain}
```

### Response

```json
{
    "user": {
        "id": 789,
        "name": "Robert Doe",
        "email": "robert.doe@example.com",
        "role": "guardian"
    },
    "guardian": {
        "id": 1,
        "first_name": "Robert",
        "last_name": "Doe",
        "phone": "+1234567890",
        "email": "robert.doe@example.com"
    },
    "stats": {
        "children_count": 2,
        "children": [
            {
                "id": 123,
                "first_name": "John",
                "last_name": "Doe",
                "email": "john.doe123@westwood.samschool.com",
                "class_id": 5,
                "status": "active"
            },
            {
                "id": 124,
                "first_name": "Jane",
                "last_name": "Doe",
                "email": "jane.doe124@westwood.samschool.com",
                "class_id": 8,
                "status": "active"
            }
        ],
        "pending_fees": 1500.0,
        "recent_announcements": [
            {
                "id": 1,
                "title": "Parent-Teacher Meeting",
                "content": "Scheduled for next week...",
                "target_audience": "parents",
                "created_at": "2025-11-22T10:00:00.000000Z"
            }
        ]
    },
    "role": "parent"
}
```

### Guardian Dashboard Features

The parent dashboard provides:

1. **Children Overview**: List of all wards
2. **Pending Fees**: Total outstanding fees across all children
3. **Recent Announcements**: School announcements targeted at parents
4. **Quick Stats**: Children count, attendance, performance summaries

### Additional Guardian Dashboard Endpoints

You can fetch detailed information for each ward using these endpoints:

-   **Ward's Attendance**: `GET /api/v1/students/{student_id}/attendance`
-   **Ward's Results**: `GET /api/v1/students/{student_id}/results`
-   **Ward's Assignments**: `GET /api/v1/students/{student_id}/assignments`
-   **Ward's Exams**: `GET /api/v1/students/{student_id}/exams`
-   **Ward's Timetable**: `GET /api/v1/timetable/class/{class_id}` (filtered by student's class)

---

## Staff Auto-Generation

### Endpoint

`POST /api/v1/staff`

### Headers

```http
Authorization: Bearer {admin_token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
```

### Request Body

```json
{
    "first_name": "Jane",
    "last_name": "Smith",
    "middle_name": "Marie",
    "phone": "+1234567890",
    "role": "librarian",
    "department": "Library",
    "employment_date": "2025-01-15",
    "school_id": 1
}
```

**Note:** `employee_id` and `email` are optional. If not provided, they will be auto-generated.

### Validation Rules

-   `employee_id` (optional, string, max 50, unique)
-   `first_name` (required, string, max 255)
-   `last_name` (required, string, max 255)
-   `middle_name` (optional, string, max 255)
-   `email` (optional, email, unique) - Auto-generated if not provided
-   `phone` (optional, string, max 20)
-   `role` (required, enum): `admin`, `staff`, `accountant`, `librarian`, `driver`, `security`, `cleaner`, `caterer`, `nurse`
-   `department` (optional, string, max 255)
-   `employment_date` (required, date)
-   `school_id` (optional, integer)

### Success Response (201)

```json
{
    "message": "Staff created successfully",
    "staff": {
        "id": 45,
        "school_id": 1,
        "employee_id": "WES2025ST0001",
        "first_name": "Jane",
        "last_name": "Smith",
        "middle_name": "Marie",
        "email": "jane.smith45@westwood.samschool.com",
        "phone": "+1234567890",
        "role": "librarian",
        "department": "Library",
        "employment_date": "2025-01-15",
        "status": "active",
        "user_id": 890,
        "created_at": "2025-11-23T10:00:00.000000Z",
        "updated_at": "2025-11-23T10:00:00.000000Z"
    },
    "login_credentials": {
        "email": "jane.smith45@westwood.samschool.com",
        "password": "Password@123",
        "note": "Staff should change password on first login"
    }
}
```

### Staff Email Pattern

**Format:** `firstname.lastname{staff_id}@{school_subdomain}.samschool.com`

**Example:** `jane.smith45@westwood.samschool.com`

### Staff Employee ID Pattern

**Format:** `{SCHOOL_ABBR}{YEAR}ST{SEQUENCE}`

**Example:** `WES2025ST0001`

-   `WES` = Westwood School (first 3 letters)
-   `2025` = Current year
-   `ST` = Staff indicator
-   `0001` = Sequential 4-digit number

---

## Authentication for Auto-Generated Accounts

### Login Endpoint

`POST /api/v1/auth/login`

### Request Body

```json
{
    "email": "john.doe123@westwood.samschool.com",
    "password": "Password@123"
}
```

### Headers

```http
X-Subdomain: westwood
Content-Type: application/json
```

### Success Response (200)

```json
{
    "message": "Login successful",
    "user": {
        "id": 456,
        "name": "John Doe",
        "email": "john.doe123@westwood.samschool.com",
        "role": "student",
        "status": "active"
    },
    "token": "1|eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
}
```

### First Login Flow

1. **User logs in** with auto-generated credentials
2. **System should prompt** password change (frontend logic)
3. **User changes password** via `PUT /api/v1/auth/change-password`

### Change Password Endpoint

`PUT /api/v1/auth/change-password`

**Request Body:**

```json
{
    "current_password": "Password@123",
    "new_password": "MyNewSecurePassword@456",
    "new_password_confirmation": "MyNewSecurePassword@456"
}
```

**Headers:**

```http
Authorization: Bearer {user_token}
X-Subdomain: {school_subdomain}
```

---

## Best Practices

### 1. Password Security

-   ✅ **Force password change on first login** (implement in frontend)
-   ✅ **Minimum password strength**: 8 characters, mix of uppercase, lowercase, numbers, special chars
-   ✅ **Password expiry**: Consider implementing 90-day password expiry for security

### 2. Guardian Management

-   ✅ **Always assign at least 1 guardian** when creating a student
-   ✅ **Designate primary guardian** for main communications
-   ✅ **Verify guardian emails** before account creation to avoid duplicates
-   ✅ **Send welcome emails** with login credentials to guardians

### 3. Email Uniqueness

-   ✅ **Check for existing guardians** by email before creating new ones
-   ✅ **Auto-link existing guardians** if email matches
-   ✅ **Handle email conflicts** gracefully with clear error messages

### 4. Credential Distribution

-   ✅ **Email credentials** securely to guardians and staff
-   ✅ **SMS backup** for credentials (optional)
-   ✅ **Printed credential cards** for students (optional)
-   ✅ **Secure storage** of credentials in admin panel

---

## Frontend Integration Examples

### Example 1: Create Student with 2 Guardians

```javascript
const createStudent = async (studentData) => {
    const response = await fetch("/api/v1/students", {
        method: "POST",
        headers: {
            Authorization: `Bearer ${token}`,
            "X-Subdomain": subdomain,
            "Content-Type": "application/json",
        },
        body: JSON.stringify({
            first_name: "John",
            last_name: "Doe",
            date_of_birth: "2010-05-15",
            gender: "male",
            class_id: 5,
            guardians: [
                {
                    first_name: "Robert",
                    last_name: "Doe",
                    email: "robert.doe@example.com",
                    phone: "+1234567890",
                    relationship: "Father",
                    is_primary: true,
                },
                {
                    first_name: "Mary",
                    last_name: "Doe",
                    email: "mary.doe@example.com",
                    phone: "+1234567891",
                    relationship: "Mother",
                    is_primary: false,
                },
            ],
        }),
    });

    const result = await response.json();

    // Display credentials to admin
    console.log("Student Email:", result.login_credentials.email);
    console.log("Student Password:", result.login_credentials.password);

    // Email credentials to guardians
    result.student.guardians.forEach((guardian) => {
        sendCredentialEmail(guardian.email, result.login_credentials);
    });
};
```

---

### Example 2: Guardian Login and View Dashboard

```javascript
// 1. Guardian logs in
const login = async (email, password) => {
    const response = await fetch("/api/v1/auth/login", {
        method: "POST",
        headers: {
            "X-Subdomain": "westwood",
            "Content-Type": "application/json",
        },
        body: JSON.stringify({ email, password }),
    });

    const { token, user } = await response.json();
    return { token, user };
};

// 2. Fetch guardian dashboard
const fetchGuardianDashboard = async (token) => {
    const response = await fetch("/api/v1/dashboard/parent", {
        headers: {
            Authorization: `Bearer ${token}`,
            "X-Subdomain": "westwood",
        },
    });

    const dashboard = await response.json();

    // Display children
    dashboard.stats.children.forEach((child) => {
        console.log(`Ward: ${child.first_name} ${child.last_name}`);
    });

    // Display pending fees
    console.log(`Total Pending Fees: $${dashboard.stats.pending_fees}`);

    return dashboard;
};
```

---

### Example 3: Create Staff with Auto-Generated Credentials

```javascript
const createStaff = async (staffData) => {
    const response = await fetch("/api/v1/staff", {
        method: "POST",
        headers: {
            Authorization: `Bearer ${token}`,
            "X-Subdomain": subdomain,
            "Content-Type": "application/json",
        },
        body: JSON.stringify({
            first_name: "Jane",
            last_name: "Smith",
            phone: "+1234567890",
            role: "librarian",
            department: "Library",
            employment_date: "2025-01-15",
            // email and employee_id are auto-generated
        }),
    });

    const result = await response.json();

    // Display credentials to admin
    alert(`Staff Account Created!
    Email: ${result.login_credentials.email}
    Password: ${result.login_credentials.password}
    Employee ID: ${result.staff.employee_id}
  `);

    // Email credentials to staff member
    sendWelcomeEmail(result.staff.email, result.login_credentials);
};
```

---

## Testing

### Test Student Creation with Guardians

```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "school_id": 1,
    "class_id": 5,
    "date_of_birth": "2010-05-15",
    "gender": "male",
    "guardians": [
      {
        "first_name": "Robert",
        "last_name": "Doe",
        "email": "robert.doe@example.com",
        "phone": "+1234567890",
        "relationship": "Father",
        "is_primary": true
      }
    ]
  }'
```

### Test Guardian Login

```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "robert.doe@example.com",
    "password": "Password@123"
  }'
```

### Test Guardian Dashboard

```bash
curl -X GET "https://api.compasse.net/api/v1/dashboard/parent" \
  -H "Authorization: Bearer GUARDIAN_TOKEN" \
  -H "X-Subdomain: testschool"
```

---

## Support

For issues or questions, please refer to:

-   Main API Documentation: `COMPLETE_ADMIN_API_DOCUMENTATION.md`
-   Configuration APIs: `ADMIN_CONFIGURATION_API_DOCUMENTATION.md`
-   Frontend Integration Guide: `FRONTEND_INTEGRATION_GUIDE.md`

---

**Last Updated:** November 23, 2025  
**API Version:** 1.0  
**Base URL:** `https://api.compasse.net/api/v1`
