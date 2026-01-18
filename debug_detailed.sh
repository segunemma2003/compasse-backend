#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# Setup
echo "=== SETUP ==="
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')
echo "SuperAdmin Token: ${SUPER_TOKEN:0:20}..."

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Debug $timestamp\",\"subdomain\":\"debug$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

echo "School ID: $SCHOOL_ID"
echo "Subdomain: $SUBDOMAIN"

admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')
echo "Admin Token: ${ADMIN_TOKEN:0:20}..."

# Test 1: School Details
echo ""
echo "=== TEST 1: Get School Details ==="
echo "URL: $BASE_URL/schools/$SCHOOL_ID"
response=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$response" | grep "HTTP_CODE" | cut -d: -f2)
body=$(echo "$response" | sed '/HTTP_CODE/d')
echo "Status: $code"
echo "Body: $body" | head -5

# Test 2: Create User and Get Details
echo ""
echo "=== TEST 2: Create User ==="
user=$(curl -s -X POST "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Debug User","email":"debuguser@test.com","password":"password123","password_confirmation":"password123","role":"staff","phone":"+234-800-1111"}')
echo "Create User Response:"
echo "$user" | jq '.'
USER_ID=$(echo "$user" | jq -r '.data.id // .user.id // .id // empty')
echo "User ID extracted: $USER_ID"

if [ -n "$USER_ID" ] && [ "$USER_ID" != "null" ]; then
  echo ""
  echo "=== TEST 3: Get User Details ==="
  echo "URL: $BASE_URL/users/$USER_ID"
  user_detail=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/users/$USER_ID" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: $SUBDOMAIN")
  code=$(echo "$user_detail" | grep "HTTP_CODE" | cut -d: -f2)
  body=$(echo "$user_detail" | sed '/HTTP_CODE/d')
  echo "Status: $code"
  echo "Body: $body" | jq '.'
fi

# Test 4: Create Teacher and Get Details
echo ""
echo "=== TEST 4: Create Teacher ==="
teacher=$(curl -s -X POST "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"first_name":"Debug","last_name":"Teacher","email":"debugteacher@test.com","phone":"+234-800-2222","date_of_birth":"1985-05-15","gender":"male","employment_date":"2025-01-01"}')
echo "Create Teacher Response:"
echo "$teacher" | jq '.'
TEACHER_ID=$(echo "$teacher" | jq -r '.teacher.id // .data.id // .id // empty')
echo "Teacher ID extracted: $TEACHER_ID"

if [ -n "$TEACHER_ID" ] && [ "$TEACHER_ID" != "null" ]; then
  echo ""
  echo "=== TEST 5: Get Teacher Details ==="
  echo "URL: $BASE_URL/teachers/$TEACHER_ID"
  teacher_detail=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/teachers/$TEACHER_ID" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: $SUBDOMAIN")
  code=$(echo "$teacher_detail" | grep "HTTP_CODE" | cut -d: -f2)
  body=$(echo "$teacher_detail" | sed '/HTTP_CODE/d')
  echo "Status: $code"
  echo "Body: $body" | head -10
  
  echo ""
  echo "=== TEST 6: Update Teacher ==="
  teacher_update=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X PUT "$BASE_URL/teachers/$TEACHER_ID" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: $SUBDOMAIN" \
    -H "Content-Type: application/json" \
    -d '{"phone":"+234-800-9999"}')
  code=$(echo "$teacher_update" | grep "HTTP_CODE" | cut -d: -f2)
  body=$(echo "$teacher_update" | sed '/HTTP_CODE/d')
  echo "Status: $code"
  echo "Body: $body" | jq '.'
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null
