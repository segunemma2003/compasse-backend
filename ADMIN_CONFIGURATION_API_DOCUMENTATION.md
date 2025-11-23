# Admin Configuration API Documentation

This document covers the APIs for managing school-wide configurations including Departments, Academic Years, and Terms.

---

## Table of Contents

1. [Departments API](#departments-api)
2. [Academic Years API](#academic-years-api)
3. [Terms API](#terms-api)
4. [Staff Roles Reference](#staff-roles-reference)

---

## Authentication & Headers

All endpoints require authentication and tenant identification:

```http
Authorization: Bearer {admin_token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
Accept: application/json
```

---

## Departments API

Departments are used to organize staff, teachers, and academic subjects within the school.

### 1. List All Departments

**Endpoint:** `GET /api/v1/departments`

**Query Parameters:**
- `per_page` (optional): Number of items per page (default: 15)
- `page` (optional): Page number
- `search` (optional): Search by department name

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "school_id": 1,
      "name": "Science Department",
      "description": "Handles all science-related subjects",
      "head_of_department_id": 5,
      "code": "SCI",
      "status": "active",
      "created_at": "2024-01-15T10:00:00.000000Z",
      "updated_at": "2024-01-15T10:00:00.000000Z",
      "head": {
        "id": 5,
        "name": "Dr. John Smith",
        "email": "j.smith@school.com"
      }
    },
    {
      "id": 2,
      "school_id": 1,
      "name": "Arts Department",
      "description": "Manages humanities and arts subjects",
      "head_of_department_id": 8,
      "code": "ART",
      "status": "active",
      "created_at": "2024-01-15T10:00:00.000000Z",
      "updated_at": "2024-01-15T10:00:00.000000Z",
      "head": {
        "id": 8,
        "name": "Mrs. Jane Doe",
        "email": "j.doe@school.com"
      }
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 2,
  "last_page": 1
}
```

---

### 2. Get Department Details

**Endpoint:** `GET /api/v1/departments/{id}`

**Response:**
```json
{
  "department": {
    "id": 1,
    "school_id": 1,
    "name": "Science Department",
    "description": "Handles all science-related subjects",
    "head_of_department_id": 5,
    "code": "SCI",
    "status": "active",
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z",
    "head": {
      "id": 5,
      "name": "Dr. John Smith",
      "email": "j.smith@school.com",
      "phone": "+1234567890"
    },
    "subjects": [
      {
        "id": 1,
        "name": "Physics",
        "code": "PHY101"
      },
      {
        "id": 2,
        "name": "Chemistry",
        "code": "CHE101"
      }
    ],
    "staff_count": 8,
    "teacher_count": 12
  }
}
```

---

### 3. Create Department

**Endpoint:** `POST /api/v1/departments`

**Request Body:**
```json
{
  "name": "Mathematics Department",
  "description": "Covers all mathematics courses",
  "code": "MATH",
  "head_of_department_id": 10,
  "status": "active"
}
```

**Validation Rules:**
- `name` (required, string, max 255, unique per school)
- `description` (optional, string)
- `code` (optional, string, max 20, unique per school)
- `head_of_department_id` (optional, integer, must exist in users/teachers)
- `status` (optional, enum: `active`, `inactive`)

**Success Response (201):**
```json
{
  "message": "Department created successfully",
  "department": {
    "id": 5,
    "school_id": 1,
    "name": "Mathematics Department",
    "description": "Covers all mathematics courses",
    "code": "MATH",
    "head_of_department_id": 10,
    "status": "active",
    "created_at": "2024-11-23T14:30:00.000000Z",
    "updated_at": "2024-11-23T14:30:00.000000Z"
  }
}
```

**Error Response (422):**
```json
{
  "error": "Validation failed",
  "messages": {
    "name": ["The name field is required."],
    "code": ["The code has already been taken."]
  }
}
```

---

### 4. Update Department

**Endpoint:** `PUT /api/v1/departments/{id}`

**Request Body:**
```json
{
  "name": "Updated Department Name",
  "description": "Updated description",
  "head_of_department_id": 12,
  "status": "active"
}
```

**Note:** All fields are optional in update requests.

**Success Response (200):**
```json
{
  "message": "Department updated successfully",
  "department": {
    "id": 5,
    "school_id": 1,
    "name": "Updated Department Name",
    "description": "Updated description",
    "code": "MATH",
    "head_of_department_id": 12,
    "status": "active",
    "created_at": "2024-11-23T14:30:00.000000Z",
    "updated_at": "2024-11-23T15:45:00.000000Z"
  }
}
```

---

### 5. Delete Department

**Endpoint:** `DELETE /api/v1/departments/{id}`

**Success Response (200):**
```json
{
  "message": "Department deleted successfully"
}
```

**Error Response (404):**
```json
{
  "error": "Department not found"
}
```

---

## Academic Years API

Academic years define the yearly educational cycle for the school.

### 1. List All Academic Years

**Endpoint:** `GET /api/v1/academic-years`

**Query Parameters:**
- `per_page` (optional): Number of items per page (default: 15)
- `status` (optional): Filter by status (`active`, `inactive`, `completed`)
- `is_current` (optional): Filter current academic year (boolean)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "school_id": 1,
      "name": "2024/2025",
      "start_date": "2024-09-01",
      "end_date": "2025-07-31",
      "is_current": true,
      "status": "active",
      "total_terms": 3,
      "created_at": "2024-01-15T10:00:00.000000Z",
      "updated_at": "2024-09-01T08:00:00.000000Z"
    },
    {
      "id": 2,
      "school_id": 1,
      "name": "2023/2024",
      "start_date": "2023-09-01",
      "end_date": "2024-07-31",
      "is_current": false,
      "status": "completed",
      "total_terms": 3,
      "created_at": "2023-01-15T10:00:00.000000Z",
      "updated_at": "2024-07-31T16:00:00.000000Z"
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 2,
  "last_page": 1
}
```

---

### 2. Get Academic Year Details

**Endpoint:** `GET /api/v1/academic-years/{id}`

**Response:**
```json
{
  "academic_year": {
    "id": 1,
    "school_id": 1,
    "name": "2024/2025",
    "start_date": "2024-09-01",
    "end_date": "2025-07-31",
    "is_current": true,
    "status": "active",
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-09-01T08:00:00.000000Z",
    "terms": [
      {
        "id": 1,
        "name": "First Term",
        "start_date": "2024-09-01",
        "end_date": "2024-12-20",
        "is_current": true,
        "status": "active"
      },
      {
        "id": 2,
        "name": "Second Term",
        "start_date": "2025-01-10",
        "end_date": "2025-04-15",
        "is_current": false,
        "status": "pending"
      },
      {
        "id": 3,
        "name": "Third Term",
        "start_date": "2025-04-20",
        "end_date": "2025-07-31",
        "is_current": false,
        "status": "pending"
      }
    ],
    "statistics": {
      "total_students": 450,
      "total_classes": 15,
      "total_subjects": 25,
      "terms_completed": 0
    }
  }
}
```

---

### 3. Create Academic Year

**Endpoint:** `POST /api/v1/academic-years`

**Request Body:**
```json
{
  "name": "2025/2026",
  "start_date": "2025-09-01",
  "end_date": "2026-07-31",
  "is_current": false,
  "status": "pending"
}
```

**Validation Rules:**
- `name` (required, string, max 50)
- `start_date` (required, date, format: YYYY-MM-DD)
- `end_date` (required, date, after start_date, format: YYYY-MM-DD)
- `is_current` (optional, boolean, default: false)
- `status` (optional, enum: `pending`, `active`, `completed`, default: `pending`)

**Important Notes:**
- Only one academic year can be marked as `is_current: true` at a time
- When setting `is_current: true`, all other academic years will be automatically set to `false`
- `end_date` must be after `start_date`

**Success Response (201):**
```json
{
  "message": "Academic year created successfully",
  "academic_year": {
    "id": 3,
    "school_id": 1,
    "name": "2025/2026",
    "start_date": "2025-09-01",
    "end_date": "2026-07-31",
    "is_current": false,
    "status": "pending",
    "created_at": "2024-11-23T14:30:00.000000Z",
    "updated_at": "2024-11-23T14:30:00.000000Z"
  }
}
```

**Error Response (422):**
```json
{
  "error": "Validation failed",
  "messages": {
    "name": ["The name field is required."],
    "end_date": ["The end date must be after start date."]
  }
}
```

---

### 4. Update Academic Year

**Endpoint:** `PUT /api/v1/academic-years/{id}`

**Request Body:**
```json
{
  "name": "2024/2025 Academic Session",
  "start_date": "2024-09-01",
  "end_date": "2025-07-31",
  "is_current": true,
  "status": "active"
}
```

**Note:** All fields are optional in update requests.

**Success Response (200):**
```json
{
  "message": "Academic year updated successfully",
  "academic_year": {
    "id": 1,
    "school_id": 1,
    "name": "2024/2025 Academic Session",
    "start_date": "2024-09-01",
    "end_date": "2025-07-31",
    "is_current": true,
    "status": "active",
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-11-23T15:45:00.000000Z"
  }
}
```

---

### 5. Delete Academic Year

**Endpoint:** `DELETE /api/v1/academic-years/{id}`

**Important:** 
- Cannot delete the current academic year (`is_current: true`)
- Cannot delete an academic year that has associated data (terms, results, etc.)

**Success Response (200):**
```json
{
  "message": "Academic year deleted successfully"
}
```

**Error Response (400):**
```json
{
  "error": "Cannot delete current academic year"
}
```

**Error Response (404):**
```json
{
  "error": "Academic year not found"
}
```

---

## Terms API

Terms (also called semesters or trimesters) divide an academic year into periods.

### 1. List All Terms

**Endpoint:** `GET /api/v1/terms`

**Query Parameters:**
- `per_page` (optional): Number of items per page (default: 15)
- `academic_year_id` (optional): Filter by academic year
- `status` (optional): Filter by status (`pending`, `active`, `completed`)
- `is_current` (optional): Filter current term (boolean)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "school_id": 1,
      "academic_year_id": 1,
      "name": "First Term",
      "start_date": "2024-09-01",
      "end_date": "2024-12-20",
      "is_current": true,
      "status": "active",
      "created_at": "2024-01-15T10:00:00.000000Z",
      "updated_at": "2024-09-01T08:00:00.000000Z",
      "academic_year": {
        "id": 1,
        "name": "2024/2025"
      }
    },
    {
      "id": 2,
      "school_id": 1,
      "academic_year_id": 1,
      "name": "Second Term",
      "start_date": "2025-01-10",
      "end_date": "2025-04-15",
      "is_current": false,
      "status": "pending",
      "created_at": "2024-01-15T10:00:00.000000Z",
      "updated_at": "2024-01-15T10:00:00.000000Z",
      "academic_year": {
        "id": 1,
        "name": "2024/2025"
      }
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 2,
  "last_page": 1
}
```

---

### 2. Get Term Details

**Endpoint:** `GET /api/v1/terms/{id}`

**Response:**
```json
{
  "term": {
    "id": 1,
    "school_id": 1,
    "academic_year_id": 1,
    "name": "First Term",
    "start_date": "2024-09-01",
    "end_date": "2024-12-20",
    "is_current": true,
    "status": "active",
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-09-01T08:00:00.000000Z",
    "academic_year": {
      "id": 1,
      "name": "2024/2025",
      "start_date": "2024-09-01",
      "end_date": "2025-07-31"
    },
    "statistics": {
      "total_students": 450,
      "total_exams": 25,
      "total_assignments": 120,
      "results_published": 380
    }
  }
}
```

---

### 3. Create Term

**Endpoint:** `POST /api/v1/terms`

**Request Body:**
```json
{
  "academic_year_id": 1,
  "name": "Third Term",
  "start_date": "2025-04-20",
  "end_date": "2025-07-31",
  "is_current": false,
  "status": "pending"
}
```

**Validation Rules:**
- `academic_year_id` (required, integer, must exist)
- `name` (required, string, max 50)
- `start_date` (required, date, format: YYYY-MM-DD, must be within academic year)
- `end_date` (required, date, after start_date, must be within academic year)
- `is_current` (optional, boolean, default: false)
- `status` (optional, enum: `pending`, `active`, `completed`, default: `pending`)

**Important Notes:**
- Only one term can be marked as `is_current: true` at a time
- Term dates must fall within the associated academic year's date range
- Terms cannot overlap within the same academic year

**Success Response (201):**
```json
{
  "message": "Term created successfully",
  "term": {
    "id": 3,
    "school_id": 1,
    "academic_year_id": 1,
    "name": "Third Term",
    "start_date": "2025-04-20",
    "end_date": "2025-07-31",
    "is_current": false,
    "status": "pending",
    "created_at": "2024-11-23T14:30:00.000000Z",
    "updated_at": "2024-11-23T14:30:00.000000Z"
  }
}
```

**Error Response (422):**
```json
{
  "error": "Validation failed",
  "messages": {
    "academic_year_id": ["The academic year id field is required."],
    "start_date": ["The start date must be within the academic year period."],
    "end_date": ["The term dates overlap with an existing term."]
  }
}
```

---

### 4. Update Term

**Endpoint:** `PUT /api/v1/terms/{id}`

**Request Body:**
```json
{
  "name": "First Term 2024/2025",
  "start_date": "2024-09-01",
  "end_date": "2024-12-20",
  "is_current": true,
  "status": "active"
}
```

**Note:** All fields are optional in update requests except `academic_year_id` cannot be changed.

**Success Response (200):**
```json
{
  "message": "Term updated successfully",
  "term": {
    "id": 1,
    "school_id": 1,
    "academic_year_id": 1,
    "name": "First Term 2024/2025",
    "start_date": "2024-09-01",
    "end_date": "2024-12-20",
    "is_current": true,
    "status": "active",
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-11-23T15:45:00.000000Z"
  }
}
```

---

### 5. Delete Term

**Endpoint:** `DELETE /api/v1/terms/{id}`

**Important:** 
- Cannot delete the current term (`is_current: true`)
- Cannot delete a term that has associated data (exams, results, etc.)

**Success Response (200):**
```json
{
  "message": "Term deleted successfully"
}
```

**Error Response (400):**
```json
{
  "error": "Cannot delete current term"
}
```

**Error Response (404):**
```json
{
  "error": "Term not found"
}
```

---

## Staff Roles Reference

When creating or updating staff, use these predefined role values:

| Role Value | Display Name | Description |
|-----------|-------------|-------------|
| `admin` | Administrator | School administrative staff |
| `staff` | General Staff | Generic staff member |
| `accountant` | Accountant | Finance and accounting staff |
| `librarian` | Librarian | Library management staff |
| `driver` | Driver | Transportation staff |
| `security` | Security | Security personnel |
| `cleaner` | Cleaner | Cleaning and maintenance staff |
| `caterer` | Caterer | Kitchen and catering staff |
| `nurse` | Nurse | Medical and health staff |

### Usage in Frontend

**Create a dropdown with these values:**

```javascript
const staffRoles = [
  { value: 'admin', label: 'Administrator' },
  { value: 'staff', label: 'General Staff' },
  { value: 'accountant', label: 'Accountant' },
  { value: 'librarian', label: 'Librarian' },
  { value: 'driver', label: 'Driver' },
  { value: 'security', label: 'Security' },
  { value: 'cleaner', label: 'Cleaner' },
  { value: 'caterer', label: 'Caterer' },
  { value: 'nurse', label: 'Nurse' }
];
```

**Validation:**
The backend will only accept these exact values (case-sensitive, lowercase).

---

## Common Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

**Solution:** Include valid Bearer token in Authorization header.

---

### 403 Forbidden
```json
{
  "error": "Unauthorized",
  "message": "You do not have permission to perform this action."
}
```

**Solution:** User must have `school_admin` or appropriate role.

---

### 404 Not Found
```json
{
  "error": "Resource not found"
}
```

**Solution:** Verify the ID exists and belongs to the current school.

---

### 422 Validation Error
```json
{
  "error": "Validation failed",
  "messages": {
    "field_name": ["Error message 1", "Error message 2"]
  }
}
```

**Solution:** Check validation rules and fix the request body.

---

### 500 Server Error
```json
{
  "error": "Failed to create resource",
  "message": "Detailed error message"
}
```

**Solution:** Check server logs or contact support.

---

## Integration Examples

### Example 1: Create Department with Head

```javascript
// 1. Fetch teachers to populate dropdown
const teachers = await fetch('/api/v1/teachers', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Subdomain': subdomain
  }
}).then(r => r.json());

// 2. Create department
const department = await fetch('/api/v1/departments', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Subdomain': subdomain,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    name: 'Computer Science Department',
    description: 'Handles all IT and CS courses',
    code: 'CS',
    head_of_department_id: selectedTeacherId
  })
}).then(r => r.json());
```

---

### Example 2: Setup Academic Year with Terms

```javascript
// 1. Create academic year
const academicYear = await fetch('/api/v1/academic-years', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Subdomain': subdomain,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    name: '2025/2026',
    start_date: '2025-09-01',
    end_date: '2026-07-31',
    is_current: true,
    status: 'active'
  })
}).then(r => r.json());

// 2. Create terms for the academic year
const terms = [
  { name: 'First Term', start: '2025-09-01', end: '2025-12-20' },
  { name: 'Second Term', start: '2026-01-10', end: '2026-04-15' },
  { name: 'Third Term', start: '2026-04-20', end: '2026-07-31' }
];

for (const term of terms) {
  await fetch('/api/v1/terms', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Subdomain': subdomain,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      academic_year_id: academicYear.academic_year.id,
      name: term.name,
      start_date: term.start,
      end_date: term.end,
      is_current: term.name === 'First Term',
      status: term.name === 'First Term' ? 'active' : 'pending'
    })
  });
}
```

---

### Example 3: Populate Department Dropdown for Staff Form

```javascript
// Fetch departments for dropdown
const departments = await fetch('/api/v1/departments', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Subdomain': subdomain
  }
}).then(r => r.json());

// Transform for dropdown
const departmentOptions = departments.data.map(dept => ({
  value: dept.id,
  label: dept.name
}));

// Use in form
<Select
  options={departmentOptions}
  onChange={(selected) => setFormData({ 
    ...formData, 
    department: selected.label // Store department name
  })}
/>
```

---

## Best Practices

### 1. Current Academic Year/Term Management
- Always ensure only ONE academic year has `is_current: true`
- Always ensure only ONE term has `is_current: true`
- Update `is_current` flags when transitioning between periods

### 2. Date Validation
- Validate dates on the frontend before submission
- Ensure term dates fall within academic year dates
- Check for overlapping terms within the same academic year

### 3. Deletion Safety
- Warn users before deleting departments/academic years/terms
- Check for associated data (staff, results, etc.) before deletion
- Consider "archiving" instead of hard deletion for historical data

### 4. Department Code Generation
- Generate department codes automatically if not provided
- Use 3-4 character codes based on department name
- Ensure uniqueness within the school

### 5. Status Management
- Update status based on dates:
  - `pending` - before start date
  - `active` - between start and end dates
  - `completed` - after end date

---

## Testing

### Test Department Creation
```bash
curl -X POST "https://api.compasse.net/api/v1/departments" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Science Department",
    "description": "Science and lab courses",
    "code": "SCI"
  }'
```

### Test Academic Year Creation
```bash
curl -X POST "https://api.compasse.net/api/v1/academic-years" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "2025/2026",
    "start_date": "2025-09-01",
    "end_date": "2026-07-31",
    "is_current": true
  }'
```

### Test Term Creation
```bash
curl -X POST "https://api.compasse.net/api/v1/terms" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "academic_year_id": 1,
    "name": "First Term",
    "start_date": "2025-09-01",
    "end_date": "2025-12-20",
    "is_current": true
  }'
```

---

## Support

For issues or questions, please contact the backend development team or refer to:
- Main API Documentation: `COMPLETE_ADMIN_API_DOCUMENTATION.md`
- Frontend Integration Guide: `FRONTEND_INTEGRATION_GUIDE.md`

---

**Last Updated:** November 23, 2025  
**API Version:** 1.0  
**Base URL:** `https://api.compasse.net/api/v1`

