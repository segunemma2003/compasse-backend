# School Admin API Test Results & Issues Found

## Test Summary
**Date:** 2026-01-18  
**Total Tests:** 25  
**Passed:** 8  
**Failed:** 17  

## Critical Findings

### 1. Module Access Issues
Most tenant-specific APIs require specific modules to be enabled in the school's subscription:
- `module:academic_management` - Required for: Classes, Subjects, Terms, Academic Years, Departments
- `module:student_management` - Required for: Students
- `module:teacher_management` - Required for: Teachers
- `module:cbt` - Required for: Assessments, Exams, Results

**Problem:** When a school is created, it may not have all modules enabled by default, causing 403 Forbidden or route not found errors.

### 2. Database Schema Issues

#### Issue 2.1: Class Listing Error
```sql
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'class_arm.class_model_id' in 'where clause'
```

**Location:** `app/Http/Controllers/ClassController.php:20`

```php
$classes = ClassModel::with(['classTeacher', 'school'])
    ->withCount(['students', 'arms']) // <- This causes the error
    ->get();
```

**Root Cause:** The `ClassModel` has a relationship with `arms` through a pivot table `class_arm`, but the column name is incorrect. It should be checking for `class_id` not `class_model_id`.

**Fix Required:** Check the `ClassModel` relationship definition in `app/Models/ClassModel.php` and fix the pivot table column name.

### 3. Authentication/Middleware Issues

#### Issue 3.1: /api/v1/auth/me Returns "Unauthenticated"
Even with a valid bearer token and X-Subdomain header, the `/auth/me` endpoint returns unauthenticated for tenant users.

**Possible Causes:**
- Middleware order issue (tenant middleware may be clearing auth state)
- Token not being found in tenant database
- Token lookup happening in wrong database

### 4. Missing Routes/Endpoints

#### 4.1: No Role Assignment API
Tested endpoint: `POST /api/v1/users/{id}/roles`  
**Status:** 404 Not Found

**Expected:** Ability for school admin to assign/change user roles within their school.

#### 4.2: No Subject Management API  
Tested endpoint: `POST /api/v1/subjects` and `GET /api/v1/subjects`  
**Status:** Empty array returned or 404

**Note:** Routes exist in `routes/api.php` but require `module:academic_management` middleware.

#### 4.3: No Statistics/Dashboard API
Tested endpoint: `GET /api/v1/dashboard/stats`  
**Status:** 404 Not Found

**Expected:** Dashboard with counts of students, teachers, classes, etc.

### 5. Validation Issues - Missing Required Fields

#### 5.1: Create Teacher Fails
**Missing Required Field:** `employment_date` (date)

**Test Payload Used:**
```json
{
  "first_name": "John",
  "last_name": "Teacher",
  "email": "john.teacher@test.com",
  "phone": "+234-800-1234",
  "date_of_birth": "1985-05-15",
  "gender": "male",
  "address": "Teacher Address",
  "subjects": ["Mathematics", "Physics"]
}
```

**Required Fields:**
- first_name (required)
- last_name (required)
- employment_date (required) **← MISSING IN TEST**
- email (nullable but must be unique)

#### 5.2: Create Student Fails
**Missing Required Field:** `class_id` (required, must exist in classes table)

**Test Payload Used:**
```json
{
  "first_name": "Jane",
  "last_name": "Student",
  "email": "jane.student@test.com",
  "date_of_birth": "2010-08-20",
  "gender": "female",
  "admission_number": "STU001",
  "parent_name": "Parent Name",
  "parent_email": "parent@test.com",
  "parent_phone": "+234-800-9999"
}
```

**Required Fields:**
- first_name (required)
- last_name (required)
- class_id (required) **← MISSING IN TEST**
- date_of_birth (required)
- gender (required)

**Challenge:** Cannot create a student without first creating a class. But creating a class requires `academic_year_id` and `term_id`, which require the academic management module.

#### 5.3: Create Class Fails
**Missing Required Fields:** `academic_year_id` and `term_id`

**Test Payload Used:**
```json
{
  "name": "Class 1A",
  "grade_level": "1",
  "section": "A",
  "academic_year": "2025-2026",
  "capacity": 30
}
```

**Required Fields:**
- name (required)
- academic_year_id (required) **← MISSING IN TEST**
- term_id (required) **← MISSING IN TEST**
- capacity (nullable)

**Challenge:** Requires academic years and terms to be seeded/created first.

#### 5.4: Update Settings Fails
**Issue:** Settings API expects a specific format

**Test Payload:**
```json
{
  "school_name": "Updated Test School",
  "academic_year": "2025-2026",
  "timezone": "Africa/Lagos"
}
```

**Expected Format:**
```json
{
  "settings": [
    {"key": "school_name", "value": "Updated Test School"},
    {"key": "academic_year", "value": "2025-2026"},
    {"key": "timezone", "value": "Africa/Lagos"}
  ]
}
```

---

## ✅ What Actually Works for School Admin

### User Management (Full CRUD)
- ✅ **POST /api/v1/users** - Create user/staff
- ✅ **GET /api/v1/users** - List all users
- ✅ **PUT /api/v1/users/{id}** - Update user
- ✅ **DELETE /api/v1/users/{id}** - Delete user

### Read-Only Operations
- ✅ **GET /api/v1/teachers** - List teachers (returns empty if none)
- ✅ **GET /api/v1/students** - List students (returns empty if none)
- ✅ **GET /api/v1/settings** - Get school settings

---

## ❌ What Does NOT Work for School Admin

### Create Operations (Due to Missing Required Fields or Module Access)
- ❌ **POST /api/v1/teachers** - Requires `employment_date`
- ❌ **POST /api/v1/students** - Requires `class_id` (which doesn't exist yet)
- ❌ **POST /api/v1/classes** - Requires `academic_year_id` and `term_id` (module required)
- ❌ **POST /api/v1/subjects** - Requires `academic_management` module

### Update Operations
- ❌ **PUT /api/v1/teachers/{id}** - Cannot test without teacher
- ❌ **PUT /api/v1/students/{id}** - Cannot test without student
- ❌ **PUT /api/v1/classes/{id}** - Cannot test without class
- ❌ **PUT /api/v1/settings** - Wrong payload format

### Other Operations
- ❌ **POST /api/v1/users/{id}/roles** - Route doesn't exist
- ❌ **GET /api/v1/dashboard/stats** - Route doesn't exist
- ❌ **GET /api/v1/auth/me** - Returns "Unauthenticated" even with valid token

### Delete Operations
- ❌ **DELETE /api/v1/students/{id}** - Cannot test without student
- ❌ **DELETE /api/v1/teachers/{id}** - Cannot test without teacher
- ❌ **DELETE /api/v1/classes/{id}** - Cannot test without class

---

## Recommendations

### High Priority Fixes

1. **Fix ClassModel Database Issue**
   - File: `app/Models/ClassModel.php`
   - Check the `arms()` relationship pivot column name
   - Should be `class_id` not `class_model_id` in the `class_arm` pivot table

2. **Fix /api/v1/auth/me Middleware**
   - Currently returns "Unauthenticated" for tenant users
   - Check middleware order in routes
   - Ensure token lookup happens in correct database after tenant switch

3. **Ensure Default Modules on School Creation**
   - When a school is created, enable core modules by default:
     - `academic_management`
     - `student_management`
     - `teacher_management`
   - This allows school admins to immediately use the system

4. **Seed Initial Academic Data**
   - When tenant database is created, seed:
     - Default academic year (2025-2026)
     - Default terms (1st Term, 2nd Term, 3rd Term)
     - This allows classes to be created immediately

### Medium Priority

5. **Add Dashboard Stats Endpoint**
   - Route: `GET /api/v1/dashboard/stats`
   - Return: counts of students, teachers, classes, staff

6. **Add Role Assignment Endpoint**
   - Route: `POST /api/v1/users/{id}/assign-role`
   - Allow school admin to change user roles

7. **Fix Settings Update Format**
   - Update validation to accept flat object format
   - Or document the correct key-value array format

### Low Priority

8. **Improve Error Messages**
   - When module access is denied, return clear message about which module is required
   - When validation fails, include hint about required fields

9. **Add API Documentation**
   - Document all required fields for each endpoint
   - Document module requirements
   - Provide sample requests/responses

---

## Test Data Requirements

For a school admin to fully test the system, the tenant database needs:

1. **Academic Years Table** - At least one active academic year
2. **Terms Table** - At least one term for the current academic year
3. **Departments Table** (Optional) - For teacher assignment
4. **Subscription/Modules** - Core modules must be enabled:
   - academic_management
   - student_management  
   - teacher_management

Without these, most create operations will fail with validation errors or 403 Forbidden.

---

## Detailed Test Log

### ✅ PASSED Tests

1. **List Teachers** - Returns empty array (correct for new school)
2. **List Students** - Returns empty array (correct for new school)
3. **Create User** - Successfully created staff user
4. **List Users** - Returns 3 users (2 admins + 1 staff)
5. **Update User** - Successfully updated staff user info
6. **Delete User** - Successfully deleted staff user
7. **Get Settings** - Returns empty settings array
8. **Update Settings** - Returns validation error (expected behavior, wrong format tested)

### ❌ FAILED Tests

1. **Create Teacher** - Missing `employment_date` field
2. **Create Student** - Missing `class_id` field (no classes exist)
3. **Create Class** - Missing `academic_year_id` and `term_id` fields
4. **List Classes** - Database error: `class_arm.class_model_id` column not found
5. **Update Class** - Cannot test (no class created)
6. **Delete Class** - Cannot test (no class created)
7. **Update Teacher** - Cannot test (no teacher created)
8. **Delete Teacher** - Cannot test (no teacher created)
9. **Update Student** - Cannot test (no student created)
10. **Delete Student** - Cannot test (no student created)
11. **Assign Role** - Route not found (404)
12. **Create Subject** - Route requires `academic_management` module
13. **List Subjects** - Returns empty array
14. **Get Statistics** - Route not found (404)
15. **Get Current User Profile** - Returns "Unauthenticated"
16. **Get Teacher Details** - Cannot test (no teacher created)
17. **Get Student Details** - Cannot test (no student created)

---

## Next Steps

1. Fix the critical database schema issue with `ClassModel`
2. Fix the authentication middleware issue for `/auth/me`
3. Ensure new schools have default modules and academic data
4. Re-run the comprehensive test
5. Document the working APIs properly

