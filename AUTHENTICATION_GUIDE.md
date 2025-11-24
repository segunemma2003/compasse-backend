# Authentication Guide

Complete guide for authentication with distinction between super admin and tenant users.

---

## 🔑 **Two Types of Authentication**

### **1. Super Admin Login (Central Database)**
- ✅ **NO** X-Subdomain header needed
- ✅ Uses central database
- ✅ Can manage all tenants/schools

### **2. Tenant User Login (School-Specific)**
- ✅ **REQUIRES** X-Subdomain header
- ✅ Uses tenant-specific database
- ✅ Includes: School Admins, Teachers, Students, Staff, Guardians

---

## 1️⃣ **Super Admin Login**

### **Endpoint:**
```
POST /api/v1/auth/login
```

### **Headers:**
```
Content-Type: application/json
```

**Note:** ❌ NO X-Subdomain header!

### **Request Body:**
```json
{
  "email": "superadmin@compasse.net",
  "password": "Nigeria@60"
}
```

### **Success Response:**
```json
{
  "message": "Login successful",
  "user": {
    "id": 2,
    "tenant_id": null,  // ✅ NULL because super admin is global
    "name": "Super Administrator",
    "email": "superadmin@compasse.net",
    "role": "super_admin",
    "status": "active"
  },
  "token": "166|MFlsI0KSGmHphYe9BYuumMJsm4SthOpOztEQrrbX4b85787a",
  "token_type": "Bearer"
}
```

### **Example (cURL):**
```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "superadmin@compasse.net",
    "password": "Nigeria@60"
  }'
```

### **Usage:**
```bash
# Store the token
TOKEN="166|MFlsI0KSGmHphYe9BYuumMJsm4SthOpOztEQrrbX4b85787a"

# Use it to manage schools (NO X-Subdomain needed!)
curl -X GET "https://api.compasse.net/api/v1/tenants" \
  -H "Authorization: Bearer $TOKEN"

# Create a new school
curl -X POST "https://api.compasse.net/api/v1/schools" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Westwood Schools",
    "email": "admin@westwoodschools.com",
    "subdomain": "westwood",
    "website": "westwoodschools.com"
  }'
```

---

## 2️⃣ **Tenant User Login (School Admin, Teachers, Students, etc.)**

### **Endpoint:**
```
POST /api/v1/auth/login
```

### **Headers:**
```
Content-Type: application/json
X-Subdomain: YOUR_SCHOOL_SUBDOMAIN
```

**Note:** ✅ X-Subdomain header is REQUIRED!

### **Request Body:**
```json
{
  "email": "john.doe1@westwoodschool.com",
  "password": "Password@123"
}
```

### **Success Response:**
```json
{
  "message": "Login successful",
  "user": {
    "id": 15,
    "tenant_id": null,  // Not set in tenant database
    "name": "Mr. John Doe",
    "email": "john.doe1@westwoodschool.com",
    "role": "teacher",
    "status": "active"
  },
  "token": "167|AbCdEfGhIjKlMnOpQrStUvWxYz123456789",
  "token_type": "Bearer",
  "tenant": {
    "id": "3fe04e65-4b24-4ae8-9162-bd579ab38de6",
    "subdomain": "westwood",
    "name": "Westwood Schools"
  }
}
```

### **Example (cURL):**
```bash
# Teacher Login
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: westwood" \
  -d '{
    "email": "john.doe1@westwoodschool.com",
    "password": "Password@123"
  }'

# Student Login
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: westwood" \
  -d '{
    "email": "jane.smith50@westwoodschool.com",
    "password": "Password@123"
  }'

# Guardian Login
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: westwood" \
  -d '{
    "email": "sarah.jones5@westwoodschool.com",
    "password": "Password@123"
  }'
```

### **Usage:**
```bash
# Store the token
TOKEN="167|AbCdEfGhIjKlMnOpQrStUvWxYz123456789"

# Use it to access tenant resources (X-Subdomain still needed!)
curl -X GET "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Subdomain: westwood"

# Create a class
curl -X POST "https://api.compasse.net/api/v1/classes" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Grade 10A",
    "academic_year_id": 1,
    "term_id": 1,
    "capacity": 30
  }'
```

---

## 📊 **Authentication Comparison Table**

| Feature | Super Admin | Tenant Users |
|---------|-------------|--------------|
| **Database** | Central (mysql) | Tenant-specific |
| **X-Subdomain Required** | ❌ NO | ✅ YES |
| **Can Access** | All tenants/schools | Only their school |
| **Roles** | `super_admin` | `school_admin`, `teacher`, `student`, `staff`, `guardian` |
| **Default Password** | `Nigeria@60` | `Password@123` (auto-generated users) |
| **tenant_id in users table** | `null` | `null` (isolation by database) |

---

## 🔐 **Authentication Flow**

### **Super Admin:**
```
1. POST /api/v1/auth/login (no X-Subdomain)
2. Backend checks central database
3. Returns token + user (role: super_admin)
4. Use token for all super admin operations
```

### **Tenant User:**
```
1. POST /api/v1/auth/login with X-Subdomain: westwood
2. Backend finds tenant by subdomain
3. Switches to tenant database
4. Authenticates user in tenant database
5. Returns token + user + tenant info
6. Use token + X-Subdomain for all tenant operations
```

---

## 🚨 **Common Mistakes**

### **❌ WRONG: Adding X-Subdomain to super admin login**
```bash
# This will fail!
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: westwood" \
  -d '{
    "email": "superadmin@compasse.net",
    "password": "Nigeria@60"
  }'
```
**Error:** Super admin doesn't exist in tenant database!

### **❌ WRONG: Missing X-Subdomain for tenant user**
```bash
# This will fail!
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john.doe1@westwoodschool.com",
    "password": "Password@123"
  }'
```
**Error:** Tenant user doesn't exist in central database!

### **❌ WRONG: Using wrong database for API calls**
```bash
# Teacher tries to access without X-Subdomain
curl -X GET "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer TEACHER_TOKEN"
```
**Error:** Will look in wrong database!

---

## ✅ **Correct Usage Examples**

### **Example 1: Super Admin Creates School**
```bash
# 1. Login as super admin (no X-Subdomain)
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "superadmin@compasse.net",
    "password": "Nigeria@60"
  }'

# Response includes token: "166|abc..."

# 2. Use token to create school (no X-Subdomain)
curl -X POST "https://api.compasse.net/api/v1/schools" \
  -H "Authorization: Bearer 166|abc..." \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New School",
    "subdomain": "newschool",
    "email": "admin@newschool.com"
  }'
```

### **Example 2: School Admin Manages Students**
```bash
# 1. Login as school admin (with X-Subdomain)
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: westwood" \
  -d '{
    "email": "admin@westwoodschool.com",
    "password": "Password@123"
  }'

# Response includes token: "167|xyz..."

# 2. Create student (with X-Subdomain)
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer 167|xyz..." \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Student",
    "class_id": 1,
    "date_of_birth": "2008-01-01",
    "gender": "male"
  }'

# 3. List students (with X-Subdomain)
curl -X GET "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer 167|xyz..." \
  -H "X-Subdomain: westwood"
```

---

## 🔄 **Token Usage**

### **Token Lifetime:**
- Tokens don't expire by default (Sanctum)
- Can be revoked manually
- Each login creates a new token

### **Using Tokens:**
```bash
# Always include in Authorization header
Authorization: Bearer YOUR_TOKEN_HERE

# For tenant users, also include X-Subdomain
X-Subdomain: YOUR_SCHOOL_SUBDOMAIN
```

### **Logout:**
```bash
# Super Admin
curl -X POST "https://api.compasse.net/api/v1/auth/logout" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Tenant User
curl -X POST "https://api.compasse.net/api/v1/auth/logout" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

---

## 📝 **Quick Reference**

### **Super Admin Credentials:**
- **Email:** `superadmin@compasse.net`
- **Password:** `Nigeria@60`
- **Headers:** None (just Content-Type)

### **Auto-Generated User Credentials:**
- **Password:** `Password@123`
- **Email Format:** `firstname.lastname{id}@school.com`
- **Headers:** Content-Type + X-Subdomain

### **Roles:**
| Role | Access Level | X-Subdomain |
|------|--------------|-------------|
| `super_admin` | All schools | ❌ No |
| `school_admin` | One school | ✅ Yes |
| `teacher` | One school | ✅ Yes |
| `student` | One school | ✅ Yes |
| `staff` | One school | ✅ Yes |
| `guardian` | One school | ✅ Yes |

---

## 🧪 **Testing Authentication**

### **Test Super Admin:**
```bash
./test_super_admin_auth.sh
```

### **Test Tenant User:**
```bash
./test_tenant_auth.sh westwood
```

---

**Last Updated:** November 24, 2025  
**Status:** ✅ Verified Working

