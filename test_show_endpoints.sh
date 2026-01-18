#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# SuperAdmin Login
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

# Create School
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

# School Admin Login
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

echo "Subdomain: $SUBDOMAIN"
echo "Admin Token: ${ADMIN_TOKEN:0:20}..."
echo ""

# Get prerequisites
ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

# Create User
echo "Creating user..."
user=$(curl -s -X POST "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"user@test.com","password":"password123","password_confirmation":"password123","role":"staff","phone":"+234-800"}')
USER_ID=$(echo "$user" | jq -r '.data.id')
echo "User ID: $USER_ID"

# Test Get User Details
echo ""
echo "=== Testing Get User Details ==="
user_detail=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/users/$USER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$user_detail" | grep "HTTP_CODE" | cut -d: -f2)
body=$(echo "$user_detail" | sed '/HTTP_CODE/d')
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ PASS"
  echo "$body" | jq '.id, .name, .email' 2>/dev/null || echo "$body" | head -5
else
  echo "❌ FAIL"
  echo "$body" | head -10
fi

# Create Class
echo ""
echo "Creating class..."
class=$(curl -s -X POST "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
CLASS_ID=$(echo "$class" | jq -r '.id')
echo "Class ID: $CLASS_ID"

# Test Get Class Details
echo ""
echo "=== Testing Get Class Details ==="
class_detail=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/classes/$CLASS_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$class_detail" | grep "HTTP_CODE" | cut -d: -f2)
body=$(echo "$class_detail" | sed '/HTTP_CODE/d')
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ PASS"
  echo "$body" | jq '.id, .name' 2>/dev/null || echo "$body" | head -5
else
  echo "❌ FAIL"
  echo "$body" | head -10
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
