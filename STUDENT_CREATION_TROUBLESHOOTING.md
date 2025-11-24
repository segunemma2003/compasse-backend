# Student Creation Troubleshooting Guide

Quick guide to diagnose and fix issues with student creation.

---

## 🔍 **Common Issues & Solutions**

### **Issue 1: Module Middleware Blocking Request**

#### **Symptom:**
```json
{
  "error": "Module not enabled",
  "message": "student_management module is not enabled for this school"
}
```

#### **Solution:**
The student routes are wrapped in `module:student_management` middleware. Ensure your school has this module enabled.

**Check Module Status:**
```bash
curl -X GET "https://api.compasse.net/api/v1/schools/{school_id}/modules" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

**Enable Module if Needed:**
```sql
-- In your tenant database
INSERT INTO school_modules (school_id, module_id, is_active) 
VALUES (1, (SELECT id FROM modules WHERE name = 'student_management'), true);
```

---

### **Issue 2: Class Doesn't Exist**

#### **Symptom:**
```json
{
  "error": "Validation failed",
  "messages": {
    "class_id": ["The selected class id is invalid."]
  }
}
```

#### **Solution:**
Create a class first before adding students.

**Create Class:**
```bash
curl -X POST "https://api.compasse.net/api/v1/classes" \
  -H "Authorization: Bearer YOUR_TOKEN" \
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

### **Issue 3: Academic Year/Term Missing**

#### **Symptom:**
Creating class fails because academic year or term doesn't exist.

#### **Solution:**
Create academic year and term first.

**1. Create Academic Year:**
```bash
curl -X POST "https://api.compasse.net/api/v1/academic-years" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "2025-2026",
    "start_date": "2025-09-01",
    "end_date": "2026-08-31",
    "is_current": true
  }'
```

**2. Create Term:**
```bash
curl -X POST "https://api.compasse.net/api/v1/terms" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "First Term",
    "academic_year_id": 1,
    "start_date": "2025-09-01",
    "end_date": "2025-12-20",
    "is_current": true
  }'
```

---

### **Issue 4: Tenant Not Initialized**

#### **Symptom:**
```json
{
  "error": "Tenant not found",
  "message": "No tenant found with subdomain: westwood"
}
```

#### **Solution:**
Ensure you're using the correct X-Subdomain header and the tenant exists.

**Check Tenants:**
```bash
# As super admin (NO X-Subdomain)
curl -X GET "https://api.compasse.net/api/v1/tenants" \
  -H "Authorization: Bearer SUPER_ADMIN_TOKEN"
```

---

### **Issue 5: School ID Not Found**

#### **Symptom:**
```json
{
  "error": "School not found",
  "message": "Unable to determine school from tenant context"
}
```

#### **Solution:**
This usually means the school record doesn't exist in the tenant database.

**Check School:**
```bash
curl -X GET "https://api.compasse.net/api/v1/schools" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

If empty, the tenant migration may not have created the school record. Run:
```bash
php artisan tenants:migrate
```

---

### **Issue 6: User Role ENUM Issue**

#### **Symptom:**
```json
{
  "error": "Student creation failed",
  "message": "Data truncated for column 'role'"
}
```

#### **Solution:**
The users table ENUM is missing the 'student' role.

**Run Migration:**
```bash
ssh root@api.compasse.net
cd /var/www/api.compasse.net
php artisan migrate  # Central database
php artisan tenants:migrate  # Tenant databases
```

---

### **Issue 7: Database Connection Error**

#### **Symptom:**
```json
{
  "error": "Database connection error",
  "message": "SQLSTATE[HY000] [2002] No such file or directory"
}
```

#### **Solution:**
Database configuration issue. Check `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

---

## ✅ **Correct Student Creation Request**

### **Minimal Request:**
```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "class_id": 1,
    "date_of_birth": "2008-05-15",
    "gender": "male"
  }'
```

### **Complete Request with Guardian:**
```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Jane",
    "last_name": "Smith",
    "middle_name": "Marie",
    "class_id": 1,
    "arm_id": 2,
    "date_of_birth": "2008-03-20",
    "gender": "female",
    "phone": "+1234567890",
    "address": "123 Main St, City",
    "blood_group": "O+",
    "parent_name": "Mrs. Smith",
    "parent_phone": "+0987654321",
    "parent_email": "parent@example.com",
    "emergency_contact": "+1111111111",
    "guardians": [
      {
        "first_name": "Sarah",
        "last_name": "Smith",
        "email": "sarah.smith@example.com",
        "phone": "+0987654321",
        "relationship": "Mother",
        "is_primary": true
      }
    ]
  }'
```

### **Expected Success Response:**
```json
{
  "message": "Student created successfully",
  "student": {
    "id": 1,
    "admission_number": "2025001",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe1@westwoodschool.com",
    "username": "john.doe1",
    "class_id": 1,
    "date_of_birth": "2008-05-15",
    "gender": "male",
    "status": "active",
    "user_id": 50,
    "school": {...},
    "class": {...},
    "user": {...}
  },
  "login_credentials": {
    "email": "john.doe1@westwoodschool.com",
    "password": "Password@123",
    "note": "Student should change password on first login"
  }
}
```

---

## 🔧 **Step-by-Step Setup**

### **Complete Setup Process:**

```bash
# 1. Login as super admin
TOKEN=$(curl -s -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email": "superadmin@compasse.net", "password": "Nigeria@60"}' \
  | jq -r '.token')

# 2. Create school (if not exists)
curl -X POST "https://api.compasse.net/api/v1/schools" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test School",
    "subdomain": "testschool",
    "email": "admin@testschool.com",
    "website": "testschool.com"
  }'

# 3. Login as school admin
ADMIN_TOKEN=$(curl -s -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: testschool" \
  -d '{"email": "admin@testschool.com", "password": "RETURNED_PASSWORD"}' \
  | jq -r '.token')

# 4. Create academic year
YEAR_ID=$(curl -s -X POST "https://api.compasse.net/api/v1/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "2025-2026",
    "start_date": "2025-09-01",
    "end_date": "2026-08-31",
    "is_current": true
  }' | jq -r '.id')

# 5. Create term
TERM_ID=$(curl -s -X POST "https://api.compasse.net/api/v1/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"First Term\",
    \"academic_year_id\": $YEAR_ID,
    \"start_date\": \"2025-09-01\",
    \"end_date\": \"2025-12-20\",
    \"is_current\": true
  }" | jq -r '.id')

# 6. Create class
CLASS_ID=$(curl -s -X POST "https://api.compasse.net/api/v1/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Grade 10A\",
    \"academic_year_id\": $YEAR_ID,
    \"term_id\": $TERM_ID,
    \"capacity\": 30
  }" | jq -r '.id')

# 7. Create student
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: testschool" \
  -H "Content-Type: application/json" \
  -d "{
    \"first_name\": \"John\",
    \"last_name\": \"Doe\",
    \"class_id\": $CLASS_ID,
    \"date_of_birth\": \"2008-05-15\",
    \"gender\": \"male\"
  }"
```

---

## 🧪 **Quick Test Script**

Save this as `test_student_creation.sh`:

```bash
#!/bin/bash

API_URL="http://127.0.0.1:8000/api/v1"
SUBDOMAIN="testschool"

echo "Testing Student Creation..."

# Get token (adjust credentials as needed)
TOKEN=$(curl -s -X POST "$API_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d '{"email": "admin@testschool.com", "password": "YOUR_PASSWORD"}' \
  | jq -r '.token')

if [ "$TOKEN" = "null" ] || [ -z "$TOKEN" ]; then
    echo "❌ Login failed"
    exit 1
fi

echo "✅ Login successful"

# Try to create student
RESPONSE=$(curl -s -X POST "$API_URL/students" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Test",
    "last_name": "Student",
    "class_id": 1,
    "date_of_birth": "2008-01-01",
    "gender": "male"
  }')

echo "Response:"
echo "$RESPONSE" | jq .

# Check if successful
if echo "$RESPONSE" | jq -e '.student.id' > /dev/null 2>&1; then
    echo "✅ Student created successfully"
else
    echo "❌ Student creation failed"
fi
```

Run it:
```bash
chmod +x test_student_creation.sh
./test_student_creation.sh
```

---

## 📊 **Diagnostic Checklist**

- [ ] X-Subdomain header is included
- [ ] Authorization token is valid
- [ ] Class exists (with valid academic_year_id and term_id)
- [ ] Academic year and term exist
- [ ] School record exists in tenant database
- [ ] User role ENUM includes 'student'
- [ ] Database migrations are up to date
- [ ] Module middleware is not blocking (or module is enabled)

---

## 🆘 **Still Not Working?**

### **Get Full Error Details:**
```bash
# Check Laravel logs
tail -f storage/logs/laravel.log

# Check specific error
curl -v -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{...}'
```

### **Common Error Codes:**
| Code | Meaning | Solution |
|------|---------|----------|
| 400 | Bad Request | Check request format |
| 401 | Unauthorized | Check token and X-Subdomain |
| 404 | Not Found | Check route and tenant |
| 422 | Validation Error | Check required fields |
| 500 | Server Error | Check logs |

---

**Last Updated:** November 24, 2025  
**Status:** ✅ Comprehensive troubleshooting guide

