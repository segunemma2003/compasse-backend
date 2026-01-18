#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

test_api() {
  local name="$1"
  local method="$2"
  local url="$3"
  local headers="$4"
  local data="$5"
  
  if [ "$method" = "GET" ]; then
    response=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X GET "$url" $(echo $headers | tr ' ' '\n' | sed 's/^/-H /'))
  elif [ "$method" = "POST" ]; then
    response=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X POST "$url" $(echo $headers | tr ' ' '\n' | sed 's/^/-H /') -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "PUT" ]; then
    response=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X PUT "$url" $(echo $headers | tr ' ' '\n' | sed 's/^/-H /') -H "Content-Type: application/json" -d "$data")
  fi
  
  status=$(echo "$response" | grep "HTTP_STATUS" | cut -d: -f2)
  body=$(echo "$response" | sed '/HTTP_STATUS/d')
  
  if [[ "$status" -ge 200 && "$status" -lt 300 ]]; then
    echo "✅ $name (HTTP $status)"
    return 0
  else
    echo "❌ $name (HTTP $status)"
    return 1
  fi
}

# Setup
echo "=== SETUP ==="
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" -H "Authorization: Bearer $SUPER_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

admin_login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

echo "School ID: $SCHOOL_ID | Subdomain: $SUBDOMAIN"
echo ""

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

echo "=== TESTS ==="
test_api "Get User Details" "GET" "$BASE_URL/users/$USER_ID" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN"
test_api "Get Teacher Details" "GET" "$BASE_URL/teachers/$TEACHER_ID" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN"
test_api "Update Teacher" "PUT" "$BASE_URL/teachers/$TEACHER_ID" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN" '{"phone":"+234-800-9999"}'
test_api "Get Class Details" "GET" "$BASE_URL/classes/$CLASS_ID" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN"
test_api "Get Student Details" "GET" "$BASE_URL/students/$STUDENT_ID" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN"
test_api "Update Student" "PUT" "$BASE_URL/students/$STUDENT_ID" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN" '{"phone":"+234-800-1111"}'
test_api "Get My School" "GET" "$BASE_URL/schools/me" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN"
test_api "Create Subject" "POST" "$BASE_URL/subjects" "Authorization: Bearer $ADMIN_TOKEN X-Subdomain: $SUBDOMAIN" "{\"name\":\"Math\",\"code\":\"MATH\",\"class_id\":$CLASS_ID}"

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1

echo ""
echo "=== DONE ==="
