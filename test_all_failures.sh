#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

echo "=== TEST 1: SuperAdmin Login ==="
login=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
code=$(echo "$login" | grep "HTTP:" | cut -d: -f2)
body=$(echo "$login" | sed '/HTTP:/d')
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ SuperAdmin Login WORKS"
  SUPER_TOKEN=$(echo "$body" | jq -r '.token')
  echo "Token: ${SUPER_TOKEN:0:30}..."
else
  echo "❌ SuperAdmin Login FAILED"
  echo "$body" | jq '.'
  exit 1
fi

echo ""
echo "=== Creating Test School ==="
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")
SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')
echo "School ID: $SCHOOL_ID"
echo "Subdomain: $SUBDOMAIN"

echo ""
echo "=== TEST 2: School Admin Login ==="
admin_login=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
code=$(echo "$admin_login" | grep "HTTP:" | cut -d: -f2)
body=$(echo "$admin_login" | sed '/HTTP:/d')
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ School Admin Login WORKS"
  ADMIN_TOKEN=$(echo "$body" | jq -r '.token')
  echo "Token: ${ADMIN_TOKEN:0:30}..."
else
  echo "❌ School Admin Login FAILED"
  echo "$body" | jq '.'
  exit 1
fi

# Get prerequisites
ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

# Create test data
user=$(curl -s -X POST "$BASE_URL/users" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"user@test.com","password":"password123","password_confirmation":"password123","role":"staff","phone":"+234-800"}')
USER_ID=$(echo "$user" | jq -r '.data.id')

teacher=$(curl -s -X POST "$BASE_URL/teachers" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"John","last_name":"Doe","email":"john@test.com","phone":"+234-800-1234","date_of_birth":"1985-05-15","gender":"male","employment_date":"2025-01-01"}')
TEACHER_ID=$(echo "$teacher" | jq -r '.teacher.id')

class=$(curl -s -X POST "$BASE_URL/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
CLASS_ID=$(echo "$class" | jq -r '.id')

student=$(curl -s -X POST "$BASE_URL/students" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"first_name\":\"Jane\",\"last_name\":\"Doe\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"female\"}")
STUDENT_ID=$(echo "$student" | jq -r '.student.id')

echo ""
echo "=== TEST 3: Get School Details (by ID) ==="
school_detail=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$BASE_URL/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$school_detail" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED (Expected - tenant users should use /schools/me)"

echo ""
echo "=== TEST 4: Update School ==="
school_update=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$BASE_URL/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"phone":"+234-900-0000"}')
code=$(echo "$school_update" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED"

echo ""
echo "=== TEST 5: Get User Details ==="
user_detail=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$BASE_URL/users/$USER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$user_detail" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED"

echo ""
echo "=== TEST 6: Get Teacher Details ==="
teacher_detail=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$BASE_URL/teachers/$TEACHER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
code=$(echo "$teacher_detail" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED"

echo ""
echo "=== TEST 7: Update Teacher ==="
teacher_update=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$BASE_URL/teachers/$TEACHER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"phone":"+234-800-9999"}')
code=$(echo "$teacher_update" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED"

echo ""
echo "=== TEST 8: Update Student ==="
student_update=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$BASE_URL/students/$STUDENT_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"phone":"+234-800-1111"}')
code=$(echo "$student_update" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED"

echo ""
echo "=== CLEANUP ==="
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
echo "Done"
