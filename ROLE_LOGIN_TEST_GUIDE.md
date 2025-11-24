# Role-Based Login Testing Guide

Complete guide for testing auto-generated credentials and role-based login for all user types.

---

## 🎯 What We're Testing

1. **Teachers** can login with auto-generated credentials (role: `teacher`)
2. **Students** can login with auto-generated credentials (role: `student`)
3. **Staff** can login with auto-generated credentials (role: `librarian`, `accountant`, etc.)
4. **Guardians** can login with auto-generated credentials (role: `guardian`)

---

## 📋 Prerequisites

- School must be created
- Admin token must be available
- X-Subdomain header must match school subdomain

---

## 🧪 Test 1: Create and Login as TEACHER

### Step 1: Create Teacher
```bash
curl -X POST "https://api.compasse.net/api/v1/teachers" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "title": "Mr.",
    "qualification": "MSc Mathematics",
    "employment_date": "2025-01-15"
  }'
```

### Expected Response:
```json
{
  "message": "Teacher created successfully",
  "teacher": {
    "id": 1,
    "employee_id": "TCH20250001",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe1@westwoodschool.com",
    ...
  },
  "login_credentials": {
    "email": "john.doe1@westwoodschool.com",
    "username": "john.doe1",
    "password": "Password@123",
    "role": "teacher"
  }
}
```

### Step 2: Login as Teacher
```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john.doe1@westwoodschool.com",
    "password": "Password@123"
  }'
```

### Expected Login Response:
```json
{
  "token": "2|eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 10,
    "name": "Mr. John Doe",
    "email": "john.doe1@westwoodschool.com",
    "role": "teacher",  // ✅ VERIFY THIS
    "status": "active"
  }
}
```

✅ **Success Criteria:**
- Token is returned
- User role is `teacher`
- User email matches created teacher

---

## 🧪 Test 2: Create and Login as STUDENT

### Step 1: Create Student
```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Jane",
    "last_name": "Smith",
    "class_id": 5,
    "date_of_birth": "2008-05-15",
    "gender": "female"
  }'
```

### Expected Response:
```json
{
  "message": "Student created successfully",
  "student": {
    "id": 50,
    "admission_number": "2025001",
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane.smith50@westwoodschool.com",
    ...
  },
  "login_credentials": {
    "email": "jane.smith50@westwoodschool.com",
    "username": "jane.smith50",
    "password": "Password@123",
    "role": "student"
  }
}
```

### Step 2: Login as Student
```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane.smith50@westwoodschool.com",
    "password": "Password@123"
  }'
```

### Expected Login Response:
```json
{
  "token": "3|eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 51,
    "name": "Jane Smith",
    "email": "jane.smith50@westwoodschool.com",
    "role": "student",  // ✅ VERIFY THIS
    "status": "active"
  }
}
```

✅ **Success Criteria:**
- Token is returned
- User role is `student`
- User email matches created student

---

## 🧪 Test 3: Create and Login as STAFF

### Step 1: Create Staff
```bash
curl -X POST "https://api.compasse.net/api/v1/staff" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Mike",
    "last_name": "Brown",
    "role": "librarian",
    "employment_date": "2025-01-15"
  }'
```

### Expected Response:
```json
{
  "message": "Staff created successfully",
  "staff": {
    "id": 10,
    "employee_id": "EMP20250010",
    "first_name": "Mike",
    "last_name": "Brown",
    "email": "mike.brown10@westwoodschool.com",
    "role": "librarian",
    ...
  },
  "login_credentials": {
    "email": "mike.brown10@westwoodschool.com",
    "password": "Password@123"
  }
}
```

### Step 2: Login as Staff
```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "mike.brown10@westwoodschool.com",
    "password": "Password@123"
  }'
```

### Expected Login Response:
```json
{
  "token": "4|eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 15,
    "name": "Mike Brown",
    "email": "mike.brown10@westwoodschool.com",
    "role": "librarian",  // ✅ VERIFY THIS
    "status": "active"
  }
}
```

✅ **Success Criteria:**
- Token is returned
- User role is `librarian`
- User email matches created staff

---

## 🧪 Test 4: Create and Login as GUARDIAN

### Step 1: Create Guardian
```bash
curl -X POST "https://api.compasse.net/api/v1/guardians" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Sarah",
    "last_name": "Johnson",
    "phone": "+1234567890",
    "occupation": "Engineer"
  }'
```

### Expected Response:
```json
{
  "message": "Guardian created successfully",
  "guardian": {
    "id": 5,
    "first_name": "Sarah",
    "last_name": "Johnson",
    "email": "sarah.johnson5@westwoodschool.com",
    ...
  },
  "login_credentials": {
    "email": "sarah.johnson5@westwoodschool.com",
    "username": "sarah.johnson5",
    "password": "Password@123",
    "role": "guardian"
  }
}
```

### Step 2: Login as Guardian
```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "sarah.johnson5@westwoodschool.com",
    "password": "Password@123"
  }'
```

### Expected Login Response:
```json
{
  "token": "5|eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 20,
    "name": "Sarah Johnson",
    "email": "sarah.johnson5@westwoodschool.com",
    "role": "guardian",  // ✅ VERIFY THIS
    "status": "active"
  }
}
```

✅ **Success Criteria:**
- Token is returned
- User role is `guardian`
- User email matches created guardian

---

## 📊 Testing Checklist

Use this checklist to verify all functionality:

- [ ] **Teacher Creation**: Returns login_credentials
- [ ] **Teacher Login**: Returns token with role="teacher"
- [ ] **Student Creation**: Returns login_credentials
- [ ] **Student Login**: Returns token with role="student"
- [ ] **Staff Creation**: Returns login_credentials
- [ ] **Staff Login**: Returns token with role matching staff role
- [ ] **Guardian Creation**: Returns login_credentials
- [ ] **Guardian Login**: Returns token with role="guardian"
- [ ] **Email Pattern**: Matches `firstname.lastname{id}@school.com`
- [ ] **Default Password**: All users have `Password@123`
- [ ] **No school_id Required**: All creation endpoints work without passing school_id

---

## 🔍 Troubleshooting

### Issue: "Invalid credentials"
**Possible Causes:**
- Wrong X-Subdomain header
- User not created in correct tenant database
- Password mismatch

**Solution:**
- Verify X-Subdomain matches school subdomain
- Check user exists in tenant database
- Use exact password: `Password@123`

### Issue: "User not found"
**Possible Causes:**
- User record created but not in users table
- Tenant database not initialized

**Solution:**
- Check if `user_id` is set in teacher/student/staff/guardian record
- Verify user exists in tenant database
- Run tenant migrations: `php artisan tenants:migrate`

### Issue: Wrong role returned
**Possible Causes:**
- User created with wrong role
- Role ENUM not updated

**Solution:**
- Check users table role column
- Run migration: `2025_11_24_add_more_roles_to_users_table.php`
- Verify role is in ENUM list

---

## 🎯 Expected Email Patterns

| Role | Pattern | Example |
|------|---------|---------|
| Teacher | `firstname.lastname{id}@school.com` | `john.doe1@westwoodschool.com` |
| Student | `firstname.lastname{id}@school.com` | `jane.smith50@westwoodschool.com` |
| Staff | `firstname.lastname{id}@school.com` | `mike.brown10@westwoodschool.com` |
| Guardian | `firstname.lastname{id}@school.com` | `sarah.johnson5@westwoodschool.com` |

---

## ✅ All Tests Passing Means:

1. ✅ Auto-credential generation working
2. ✅ Email format correct (using school website)
3. ✅ User accounts created automatically
4. ✅ Roles assigned correctly
5. ✅ Authentication working for all user types
6. ✅ Multi-tenancy working (X-Subdomain)
7. ✅ No school_id required in requests

---

## 📝 Notes

- Default password for ALL users: `Password@123`
- Users should change password on first login
- Email format uses school website URL, not subdomain
- All credentials returned in API response
- Credentials stored securely (hashed passwords)

---

**Last Updated:** November 24, 2025  
**Status:** ✅ Ready for Testing

