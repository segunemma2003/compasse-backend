#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# SuperAdmin Login
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')
echo "SuperAdmin Token: ${SUPER_TOKEN:0:20}..."

# Create School
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" -H "Authorization: Bearer $SUPER_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")
SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

# School Admin Login  
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

# Get prerequisites
ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

# Create resources
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
echo "=== TESTING DETAIL ENDPOINTS ==="

# Test each endpoint
test_endpoint() {
  local name="$1"
  local url="$2"
  
  response=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  code=$(echo "$response" | grep "HTTP:" | cut -d: -f2)
  
  if [ "$code" = "200" ]; then
    echo "✅ $name (HTTP 200)"
  else
    echo "❌ $name (HTTP $code)"
  fi
}

test_endpoint "Get User Details" "$BASE_URL/users/$USER_ID"
test_endpoint "Get Teacher Details" "$BASE_URL/teachers/$TEACHER_ID"
test_endpoint "Get Class Details" "$BASE_URL/classes/$CLASS_ID"
test_endpoint "Get Student Details" "$BASE_URL/students/$STUDENT_ID"
test_endpoint "Get My School" "$BASE_URL/schools/me"

# Test Update endpoints
echo ""
echo "=== TESTING UPDATE ENDPOINTS ==="

update=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$BASE_URL/teachers/$TEACHER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"phone":"+234-800-9999"}')
code=$(echo "$update" | grep "HTTP:" | cut -d: -f2)
[ "$code" = "200" ] && echo "✅ Update Teacher (HTTP 200)" || echo "❌ Update Teacher (HTTP $code)"

update=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$BASE_URL/students/$STUDENT_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"phone":"+234-800-1111"}')
code=$(echo "$update" | grep "HTTP:" | cut -d: -f2)
[ "$code" = "200" ] && echo "✅ Update Student (HTTP 200)" || echo "❌ Update Student (HTTP $code)"

# Test Create Subject  
echo ""
echo "=== TESTING SKIPPED ENDPOINTS ==="

subject=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/subjects" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Mathematics\",\"code\":\"MATH\",\"class_id\":$CLASS_ID}")
code=$(echo "$subject" | grep "HTTP:" | cut -d: -f2)
[ "$code" = "201" ] || [ "$code" = "200" ] && echo "✅ Create Subject (HTTP $code)" || echo "❌ Create Subject (HTTP $code)"

staff=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/staff" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"Staff","last_name":"Member","email":"staff@test.com","phone":"+234-800-3333","position":"Administrator"}')
code=$(echo "$staff" | grep "HTTP:" | cut -d: -f2)
[ "$code" = "201" ] || [ "$code" = "200" ] && echo "✅ Create Staff (HTTP $code)" || echo "❌ Create Staff (HTTP $code)"

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1

echo ""
echo "=== DONE ==="
