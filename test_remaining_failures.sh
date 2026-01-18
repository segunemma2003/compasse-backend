#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# Setup
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

AUTH_HEADERS="-H \"Authorization: Bearer $ADMIN_TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""

# Get IDs
ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

# Create Class
class=$(curl -s -X POST "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
CLASS_ID=$(echo "$class" | jq -r '.id')

# Create Student
student=$(curl -s -X POST "$BASE_URL/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{\"first_name\":\"Jane\",\"last_name\":\"Doe\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"female\"}")
STUDENT_ID=$(echo "$student" | jq -r '.student.id')

echo "=== TEST 1: Get Class Details ==="
echo "URL: $BASE_URL/classes/$CLASS_ID"
class_detail=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/classes/$CLASS_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$class_detail" | grep "HTTP_CODE" | cut -d: -f2)
body=$(echo "$class_detail" | sed '/HTTP_CODE/d')
echo "Status: $code"
if [ $code -eq 200 ]; then
  echo "✅ PASS"
else
  echo "❌ FAIL"
  echo "$body" | head -10
fi

echo ""
echo "=== TEST 2: Update Student ==="
echo "URL: $BASE_URL/students/$STUDENT_ID"
student_update=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X PUT "$BASE_URL/students/$STUDENT_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"phone":"+234-800-1111"}')
code=$(echo "$student_update" | grep "HTTP_CODE" | cut -d: -f2)
body=$(echo "$student_update" | sed '/HTTP_CODE/d')
echo "Status: $code"
if [ $code -eq 200 ]; then
  echo "✅ PASS"
  echo "$body" | jq '.'
else
  echo "❌ FAIL"
  echo "$body" | head -10
fi

echo ""
echo "=== TEST 3: Get School Details ==="
echo "URL: $BASE_URL/schools/$SCHOOL_ID"
school_detail=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$school_detail" | grep "HTTP_CODE" | cut -d: -f2)
body=$(echo "$school_detail" | sed '/HTTP_CODE/d')
echo "Status: $code"
if [ $code -eq 200 ]; then
  echo "✅ PASS"
else
  echo "❌ FAIL"
  echo "$body" | head -10
fi

echo ""
echo "=== TEST 4: Get My School ==="
echo "URL: $BASE_URL/schools/me"
my_school=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X GET "$BASE_URL/schools/me" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$my_school" | grep "HTTP_CODE" | cut -d: -f2)
body=$(echo "$my_school" | sed '/HTTP_CODE/d')
echo "Status: $code"
if [ $code -eq 200 ]; then
  echo "✅ PASS"
  echo "$body" | jq '.'
else
  echo "❌ FAIL"
  echo "$body" | head -10
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
