# Quick Production Login Test

## ⚡ Fast Way to Test Role-Based Login on Production

### Step 1: Get Your Admin Token

Login to your school's admin account:

```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "YOUR_ADMIN_EMAIL",
    "password": "YOUR_ADMIN_PASSWORD"
  }'
```

Copy the `token` from the response.

---

### Step 2: Create a Teacher

```bash
curl -X POST "https://api.compasse.net/api/v1/teachers" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Test",
    "last_name": "Teacher",
    "title": "Mr.",
    "employment_date": "2025-01-15"
  }'
```

**Expected Response:**
```json
{
  "message": "Teacher created successfully",
  "teacher": { ... },
  "login_credentials": {
    "email": "test.teacher1@yourschool.com",
    "username": "test.teacher1",
    "password": "Password@123",
    "role": "teacher"
  }
}
```

**Copy the email from login_credentials.**

---

### Step 3: Login as Teacher

```bash
curl -X POST "https://api.compasse.net/api/v1/auth/login" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test.teacher1@yourschool.com",
    "password": "Password@123"
  }'
```

**Expected Response:**
```json
{
  "token": "7|eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 15,
    "name": "Mr. Test Teacher",
    "email": "test.teacher1@yourschool.com",
    "role": "teacher",  // ✅ VERIFY THIS IS "teacher"
    "status": "active"
  }
}
```

---

### ✅ Success = You See:

1. ✅ Token is returned
2. ✅ `role` is `"teacher"`
3. ✅ Email matches: `test.teacher1@yourschool.com`

---

### 🔁 Repeat for Other Roles

#### Create Student:
```bash
curl -X POST "https://api.compasse.net/api/v1/students" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Test",
    "last_name": "Student",
    "class_id": YOUR_CLASS_ID,
    "date_of_birth": "2008-05-15",
    "gender": "male"
  }'
```
Then login with returned credentials (role should be `"student"`).

#### Create Staff:
```bash
curl -X POST "https://api.compasse.net/api/v1/staff" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Test",
    "last_name": "Staff",
    "role": "librarian",
    "employment_date": "2025-01-15"
  }'
```
Then login with returned credentials (role should be `"librarian"`).

#### Create Guardian:
```bash
curl -X POST "https://api.compasse.net/api/v1/guardians" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "X-Subdomain: YOUR_SCHOOL_SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Test",
    "last_name": "Guardian",
    "phone": "+1234567890",
    "occupation": "Engineer"
  }'
```
Then login with returned credentials (role should be `"guardian"`).

---

## 🎯 What to Check

For EACH user type, verify:

| Check | Expected |
|-------|----------|
| Creation returns `login_credentials` | ✅ Yes |
| Email format is `firstname.lastname{id}@school.com` | ✅ Yes |
| Password is `Password@123` | ✅ Yes |
| Login returns `token` | ✅ Yes |
| Login returns correct `role` | ✅ Yes |
| User can authenticate with token | ✅ Yes |

---

## 🚨 If It Fails

1. **Check migrations**: `ssh root@api.compasse.net "cd /var/www/api.compasse.net && php artisan migrate"`
2. **Check role ENUM**: Run the `2025_11_24_add_more_roles_to_users_table` migration
3. **Check X-Subdomain**: Must match your school's subdomain
4. **Check school website**: Set in school settings for proper email domain

---

## 💡 Quick Verification Script

Save this as `test_one_role.sh`:

```bash
#!/bin/bash

ADMIN_TOKEN="YOUR_ADMIN_TOKEN"
SUBDOMAIN="YOUR_SUBDOMAIN"
BASE_URL="https://api.compasse.net/api/v1"

# Create teacher
echo "Creating teacher..."
RESPONSE=$(curl -s -X POST "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Quick",
    "last_name": "Test",
    "title": "Mr.",
    "employment_date": "2025-01-15"
  }')

EMAIL=$(echo $RESPONSE | jq -r '.login_credentials.email')
PASSWORD=$(echo $RESPONSE | jq -r '.login_credentials.password')

echo "Teacher created: $EMAIL / $PASSWORD"
echo ""

# Login as teacher
echo "Logging in as teacher..."
LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"$EMAIL\",
    \"password\": \"$PASSWORD\"
  }")

TOKEN=$(echo $LOGIN | jq -r '.token')
ROLE=$(echo $LOGIN | jq -r '.user.role')

echo "Login response:"
echo "  Token: ${TOKEN:0:30}..."
echo "  Role: $ROLE"
echo ""

if [ "$ROLE" = "teacher" ]; then
    echo "✅ SUCCESS: Teacher can login with correct role!"
else
    echo "❌ FAILED: Role is $ROLE, expected teacher"
fi
```

Run it:
```bash
chmod +x test_one_role.sh
./test_one_role.sh
```

---

**Last Updated:** November 24, 2025

