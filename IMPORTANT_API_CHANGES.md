# Important API Changes & Improvements

## Date: November 23, 2025

---

## Summary of Changes

### 1. **Removed `school_id` Requirement from All Endpoints** ✅

**Why?**  
Since `X-Subdomain` header already identifies the tenant/school, requiring `school_id` in the request body was redundant and confusing.

**What Changed?**  
- `school_id` is now **automatically derived** from the tenant context
- **No need to pass `school_id`** in request bodies for:
  - Student creation
  - Staff creation
  - Teacher creation
  - Guardian creation
  - Any other tenant-specific operations

**Before:**
```json
POST /api/v1/students
Headers: { "X-Subdomain": "westwood", "Authorization": "Bearer ..." }
Body: {
  "school_id": 1,  // ❌ Required (redundant!)
  "first_name": "John",
  "last_name": "Doe",
  ...
}
```

**After:**
```json
POST /api/v1/students
Headers: { "X-Subdomain": "westwood", "Authorization": "Bearer ..." }
Body: {
  // ✅ No school_id needed!
  "first_name": "John",
  "last_name": "Doe",
  ...
}
```

---

### 2. **Auto-Generated Login Credentials for All Roles** ✅

**Email Pattern:** `firstname.lastname{id}@{subdomain}.samschool.com`  
**Password:** `Password@123` (should be changed on first login)

**Applies to:**
- **Students** - Auto: `admission_number`, `email`, `username`, `password`
- **Teachers** - Auto: `employee_id` (TCH20250001), `email`, `username`, `password`
- **Staff** - Auto: `employee_id`, `email`, `username`, `password`
- **Guardians** - Auto: `email`, `username`, `password`
- Any user created through the system

**Example:**
- Student John Doe (ID: 123) → `john.doe123@westwood.samschool.com`
- Staff Jane Smith (ID: 45) → `jane.smith45@westwood.samschool.com`

**API Response Includes Credentials:**
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

### 3. **Guardian/Parent System Enhanced** ✅

**Features:**
- Each student can have **1-2 guardians**
- Guardians automatically get user accounts
- Primary guardian designation
- Guardian dashboard to view all wards' information

**Student Creation with Guardians:**
```json
POST /api/v1/students
{
  "first_name": "John",
  "last_name": "Doe",
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
}
```

**Guardian Dashboard:**
```
GET /api/v1/dashboard/parent
Headers: { "Authorization": "Bearer {guardian_token}", "X-Subdomain": "westwood" }
```

---

### 4. **Admin Access to Exams & Results Confirmed** ✅

School admins (with `school_admin` role) have full access to:

**Exam APIs:**
- `GET /api/v1/exams` - List all exams
- `POST /api/v1/exams` - Create exam
- `PUT /api/v1/exams/{id}` - Update exam
- `DELETE /api/v1/exams/{id}` - Delete exam
- `GET /api/v1/exams/{id}` - Get exam details

**Result APIs:**
- `GET /api/v1/results` - List all results
- `POST /api/v1/results` - Create result
- `PUT /api/v1/results/{id}` - Update result
- `DELETE /api/v1/results/{id}` - Delete result
- `POST /api/v1/results/mid-term/generate` - Generate mid-term results
- `POST /api/v1/results/end-term/generate` - Generate end-of-term results
- `POST /api/v1/results/annual/generate` - Generate annual results
- `GET /api/v1/results/student/{studentId}` - Get student results
- `GET /api/v1/results/class/{classId}` - Get class results
- `POST /api/v1/results/publish` - Publish results
- `POST /api/v1/results/unpublish` - Unpublish results

**CBT (Computer-Based Testing) APIs:**
- `GET /api/v1/cbt/{exam}/questions` - Get CBT questions
- `POST /api/v1/cbt/{exam}/start` - Start CBT session
- `POST /api/v1/cbt/session/{sessionId}/answer` - Submit answer
- `POST /api/v1/cbt/session/{sessionId}/submit` - Submit exam
- `GET /api/v1/cbt/session/{sessionId}/results` - Get CBT results

---

## Updated API Endpoints (No `school_id` Required)

### Student Management
```http
POST   /api/v1/students           # No school_id needed
PUT    /api/v1/students/{id}
DELETE /api/v1/students/{id}
GET    /api/v1/students
GET    /api/v1/students/{id}
```

### Staff Management
```http
POST   /api/v1/staff              # No school_id needed
PUT    /api/v1/staff/{id}
DELETE /api/v1/staff/{id}
GET    /api/v1/staff
GET    /api/v1/staff/{id}
```

### Teacher Management
```http
POST   /api/v1/teachers            # No school_id, user_id, or employee_id needed!
PUT    /api/v1/teachers/{id}
DELETE /api/v1/teachers/{id}
GET    /api/v1/teachers
GET    /api/v1/teachers/{id}
```

**Auto-Generated for Teachers:**
- `employee_id` - Format: `TCH{year}{number}` (e.g., TCH20250001)
- `email` - Format: `firstname.lastname{id}@school.samschool.com`
- `username` - Format: `firstname.lastname{id}`
- `password` - Default: `Password@123`

### Guardian Management
```http
POST   /api/v1/guardians           # No school_id needed
PUT    /api/v1/guardians/{id}
DELETE /api/v1/guardians/{id}
GET    /api/v1/guardians
GET    /api/v1/guardians/{id}
POST   /api/v1/guardians/{id}/assign-student
DELETE /api/v1/guardians/{id}/remove-student
GET    /api/v1/guardians/{id}/students
```

### Class Management
```http
POST   /api/v1/classes             # No school_id needed
PUT    /api/v1/classes/{id}
DELETE /api/v1/classes/{id}
GET    /api/v1/classes
GET    /api/v1/classes/{id}
```

### Subject Management
```http
POST   /api/v1/subjects            # No school_id needed
PUT    /api/v1/subjects/{id}
DELETE /api/v1/subjects/{id}
GET    /api/v1/subjects
GET    /api/v1/subjects/{id}
```

### Department Management
```http
POST   /api/v1/departments         # No school_id needed
PUT    /api/v1/departments/{id}
DELETE /api/v1/departments/{id}
GET    /api/v1/departments
GET    /api/v1/departments/{id}
```

---

## Migration Guide for Frontend

### Old Way (DEPRECATED)
```javascript
// ❌ Old way - passing school_id
const createStudent = async (data) => {
  const response = await fetch('/api/v1/students', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Subdomain': 'westwood',
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      school_id: 1,  // Redundant!
      first_name: 'John',
      last_name: 'Doe',
      ...data
    })
  });
};
```

### New Way (RECOMMENDED)
```javascript
// ✅ New way - no school_id needed
const createStudent = async (data) => {
  const response = await fetch('/api/v1/students', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Subdomain': 'westwood',  // This is enough!
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      first_name: 'John',
      last_name: 'Doe',
      ...data
      // No school_id needed!
    })
  });
  
  const result = await response.json();
  
  // Access auto-generated credentials
  console.log('Login Email:', result.login_credentials.email);
  console.log('Password:', result.login_credentials.password);
};
```

---

## Benefits of These Changes

### 1. **Simpler API** ✅
- Fewer fields to pass
- Less confusion about required fields
- More intuitive API design

### 2. **Better Security** ✅
- School context is always enforced by tenant middleware
- Can't accidentally create records in wrong school
- Single source of truth (X-Subdomain header)

### 3. **Reduced Errors** ✅
- No mismatch between X-Subdomain and school_id
- Validation is cleaner
- Fewer API 400 errors

### 4. **Better UX** ✅
- Frontend doesn't need to track school_id
- Credentials are returned immediately
- Guardians get automatic accounts

---

## Required Headers for All Tenant-Specific Requests

```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
Content-Type: application/json
Accept: application/json
```

**Example:**
```http
POST /api/v1/students HTTP/1.1
Host: api.compasse.net
Authorization: Bearer 2|eyJ0eXAiOiJKV1QiLCJhbGc...
X-Subdomain: westwood
Content-Type: application/json

{
  "first_name": "John",
  "last_name": "Doe",
  "class_id": 5,
  "date_of_birth": "2010-05-15",
  "gender": "male"
}
```

---

## Breaking Changes (Action Required)

### ❌ **Remove `school_id` from Request Bodies**

**Affected Endpoints:**
- `POST /api/v1/students`
- `POST /api/v1/staff`
- `POST /api/v1/teachers`
- `POST /api/v1/guardians`
- `POST /api/v1/classes`
- `POST /api/v1/subjects`
- `POST /api/v1/departments`
- Any other POST/PUT endpoints that previously required `school_id`

**Migration Steps:**
1. Remove `school_id` from all request bodies
2. Ensure `X-Subdomain` header is always present
3. Update validation schemas in frontend
4. Test all creation/update flows

---

## Backward Compatibility

**Note:** For a transition period, passing `school_id` in the request body will be **ignored** (not cause errors). The system will always use the school from the tenant context.

However, it's recommended to remove `school_id` from all requests to:
- Keep code clean
- Avoid confusion
- Follow best practices

---

## Testing the Changes

### Test Student Creation (No school_id)
```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "class_id": 5,
    "date_of_birth": "2010-05-15",
    "gender": "male"
  }'
```

### Test Staff Creation (No school_id)
```bash
curl -X POST "https://api.compasse.net/api/v1/staff" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Jane",
    "last_name": "Smith",
    "phone": "+1234567890",
    "role": "librarian",
    "department": "Library",
    "employment_date": "2025-01-15"
  }'
```

### Test Teacher Creation (No school_id, user_id, employee_id)
```bash
curl -X POST "https://api.compasse.net/api/v1/teachers" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Michael",
    "last_name": "Brown",
    "title": "Mr.",
    "phone": "+1234567890",
    "qualification": "MSc Mathematics",
    "specialization": "Algebra",
    "employment_date": "2025-01-15",
    "department_id": 2
  }'
```

### Test Guardian Creation (No school_id, user_id)
```bash
curl -X POST "https://api.compasse.net/api/v1/guardians" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Robert",
    "last_name": "Johnson",
    "phone": "+1234567890",
    "occupation": "Engineer",
    "relationship_to_student": "Father"
  }'
```

### Test Guardian Dashboard
```bash
curl -X GET "https://api.compasse.net/api/v1/dashboard/parent" \
  -H "Authorization: Bearer GUARDIAN_TOKEN" \
  -H "X-Subdomain: testschool"
```

---

## Documentation References

For complete API documentation, see:

1. **Guardian & Credentials:** `GUARDIAN_AND_AUTO_CREDENTIALS_API_DOCUMENTATION.md`
2. **Configuration APIs:** `ADMIN_CONFIGURATION_API_DOCUMENTATION.md`
3. **Complete Admin APIs:** `COMPLETE_ADMIN_API_DOCUMENTATION.md`
4. **Frontend Integration:** `FRONTEND_INTEGRATION_GUIDE.md`

---

## Support

If you encounter any issues with these changes:

1. Verify `X-Subdomain` header is present in all requests
2. Ensure authentication token is valid
3. Check that the tenant/school exists
4. Review error responses for specific validation issues

For additional help, contact the backend development team.

---

**Last Updated:** November 23, 2025  
**API Version:** 1.0  
**Breaking Changes:** Yes (school_id removal)  
**Migration Required:** Yes (update frontend)


