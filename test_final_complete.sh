#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# SuperAdmin Login
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

# Create school
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

echo "=== TESTING ALL WORKING ENDPOINTS ==="
echo ""

test_api() {
  local name="$1"
  local method="$2"
  local url="$3"
  local data="$4"
  
  if [ "$method" = "GET" ]; then
    response=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  elif [ "$method" = "POST" ]; then
    response=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "PUT" ]; then
    response=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "$data")
  fi
  
  code=$(echo "$response" | grep "HTTP:" | cut -d: -f2)
  
  if [ "$code" = "200" ] || [ "$code" = "201" ]; then
    echo "✅ $name (HTTP $code)"
    return 0
  else
    echo "❌ $name (HTTP $code)"
    return 1
  fi
}

# Test all endpoints
test_api "Get My School" "GET" "$BASE_URL/schools/me"
test_api "Get Current User" "GET" "$BASE_URL/auth/me"

ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

test_api "Create User" "POST" "$BASE_URL/users" '{"name":"Test","email":"test@test.com","password":"pass123","password_confirmation":"pass123","role":"staff","phone":"+234-800"}'
USER_ID=$(curl -s -X POST "$BASE_URL/users" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"name":"User2","email":"user2@test.com","password":"pass123","password_confirmation":"pass123","role":"staff","phone":"+234-800"}' | jq -r '.data.id')
test_api "Get User Details" "GET" "$BASE_URL/users/$USER_ID"

test_api "Create Teacher" "POST" "$BASE_URL/teachers" '{"first_name":"John","last_name":"Doe","email":"john@test.com","phone":"+234-800","date_of_birth":"1985-05-15","gender":"male","employment_date":"2025-01-01"}'
TEACHER_ID=$(curl -s -X POST "$BASE_URL/teachers" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"first_name":"Jane","last_name":"Smith","email":"jane@test.com","phone":"+234-800","date_of_birth":"1985-05-15","gender":"female","employment_date":"2025-01-01"}' | jq -r '.teacher.id')
test_api "Get Teacher Details" "GET" "$BASE_URL/teachers/$TEACHER_ID"
test_api "Update Teacher" "PUT" "$BASE_URL/teachers/$TEACHER_ID" '{"phone":"+234-900"}'

test_api "Create Class" "POST" "$BASE_URL/classes" "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}"
CLASS_ID=$(curl -s -X POST "$BASE_URL/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "{\"name\":\"Grade 2\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}" | jq -r '.id')
test_api "Get Class Details" "GET" "$BASE_URL/classes/$CLASS_ID"

test_api "Create Student" "POST" "$BASE_URL/students" "{\"first_name\":\"Alice\",\"last_name\":\"Bob\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"female\"}"
STUDENT_ID=$(curl -s -X POST "$BASE_URL/students" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "{\"first_name\":\"Bob\",\"last_name\":\"Smith\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"male\"}" | jq -r '.student.id')
test_api "Get Student Details" "GET" "$BASE_URL/students/$STUDENT_ID"
test_api "Update Student" "PUT" "$BASE_URL/students/$STUDENT_ID" '{"phone":"+234-800-1111"}'

DEPT_ID=$(curl -s -X POST "$BASE_URL/departments" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"name":"Science","code":"SCI"}' | jq -r '.id // .department.id')
test_api "Create Subject" "POST" "$BASE_URL/subjects" "{\"name\":\"Math\",\"code\":\"MATH\",\"department_id\":$DEPT_ID}"

test_api "Create Staff" "POST" "$BASE_URL/staff" '{"first_name":"Staff","last_name":"Member","email":"staff@test.com","phone":"+234-800","role":"staff","employment_date":"2025-01-01"}'

echo ""
echo "=== SUPERADMIN ROUTES ==="
test_api "SuperAdmin Get School" "GET" "$BASE_URL/admin/schools/$SCHOOL_ID"
test_api "SuperAdmin Update School" "PUT" "$BASE_URL/admin/schools/$SCHOOL_ID" '{"phone":"+234-999"}'

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1

echo ""
echo "=== ALL TESTS COMPLETE ==="
