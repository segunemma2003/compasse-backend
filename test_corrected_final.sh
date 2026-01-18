#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# SuperAdmin Login
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')
echo "✅ SuperAdmin Login (Token: ${SUPER_TOKEN:0:20}...)"

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
echo "✅ School Admin Login (Token: ${ADMIN_TOKEN:0:20}...)"
echo ""

echo "=== SCHOOL ADMIN TESTS ==="
curl -s -w "\nHTTP:%{http_code}\n" -X GET "$BASE_URL/schools/me" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | grep "HTTP:" | grep -q "200" && echo "✅ Get My School" || echo "❌ Get My School"
curl -s -w "\nHTTP:%{http_code}\n" -X GET "$BASE_URL/auth/me" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | grep "HTTP:" | grep -q "200" && echo "✅ Get Current User" || echo "❌ Get Current User"

ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

user=$(curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/users" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"name":"Test User","email":"testuser@test.com","password":"pass123","password_confirmation":"pass123","role":"staff","phone":"+234-800"}')
echo "$user" | grep "HTTP:" | grep -q "201\|200" && echo "✅ Create User" || echo "❌ Create User"
USER_ID=$(echo "$user" | sed '/HTTP:/d' | jq -r '.data.id')

curl -s -w "\nHTTP:%{http_code}\n" -X GET "$BASE_URL/users/$USER_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | grep "HTTP:" | grep -q "200" && echo "✅ Get User Details" || echo "❌ Get User Details"

teacher=$(curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/teachers" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"first_name":"John","last_name":"Doe","email":"john@test.com","phone":"+234-800","date_of_birth":"1985-05-15","gender":"male","employment_date":"2025-01-01"}')
echo "$teacher" | grep "HTTP:" | grep -q "201\|200" && echo "✅ Create Teacher" || echo "❌ Create Teacher"
TEACHER_ID=$(echo "$teacher" | sed '/HTTP:/d' | jq -r '.teacher.id')

curl -s -w "\nHTTP:%{http_code}\n" -X GET "$BASE_URL/teachers/$TEACHER_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | grep "HTTP:" | grep -q "200" && echo "✅ Get Teacher Details" || echo "❌ Get Teacher Details"
curl -s -w "\nHTTP:%{http_code}\n" -X PUT "$BASE_URL/teachers/$TEACHER_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"phone":"+234-900"}' | grep "HTTP:" | grep -q "200" && echo "✅ Update Teacher" || echo "❌ Update Teacher"

class=$(curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
echo "$class" | grep "HTTP:" | grep -q "201\|200" && echo "✅ Create Class" || echo "❌ Create Class"
CLASS_ID=$(echo "$class" | sed '/HTTP:/d' | jq -r '.id')

curl -s -w "\nHTTP:%{http_code}\n" -X GET "$BASE_URL/classes/$CLASS_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | grep "HTTP:" | grep -q "200" && echo "✅ Get Class Details" || echo "❌ Get Class Details"

student=$(curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/students" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "{\"first_name\":\"Alice\",\"last_name\":\"Bob\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"female\"}")
echo "$student" | grep "HTTP:" | grep -q "201\|200" && echo "✅ Create Student" || echo "❌ Create Student"
STUDENT_ID=$(echo "$student" | sed '/HTTP:/d' | jq -r '.student.id')

curl -s -w "\nHTTP:%{http_code}\n" -X GET "$BASE_URL/students/$STUDENT_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | grep "HTTP:" | grep -q "200" && echo "✅ Get Student Details" || echo "❌ Get Student Details"
curl -s -w "\nHTTP:%{http_code}\n" -X PUT "$BASE_URL/students/$STUDENT_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"phone":"+234-800-1111"}' | grep "HTTP:" | grep -q "200" && echo "✅ Update Student" || echo "❌ Update Student"

dept=$(curl -s -X POST "$BASE_URL/departments" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"name":"Science","code":"SCI"}')
DEPT_ID=$(echo "$dept" | jq -r '.id // .department.id')

curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/subjects" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "{\"name\":\"Math\",\"code\":\"MATH\",\"department_id\":$DEPT_ID}" | grep "HTTP:" | grep -q "201\|200" && echo "✅ Create Subject" || echo "❌ Create Subject"

curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/staff" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d '{"first_name":"Staff","last_name":"Member","email":"staff@test.com","phone":"+234-800","role":"staff","employment_date":"2025-01-01"}' | grep "HTTP:" | grep -q "201\|200" && echo "✅ Create Staff" || echo "❌ Create Staff"

echo ""
echo "=== SUPERADMIN TESTS ==="
curl -s -w "\nHTTP:%{http_code}\n" -X GET "$BASE_URL/admin/schools/$SCHOOL_ID" -H "Authorization: Bearer $SUPER_TOKEN" | grep "HTTP:" | grep -q "200" && echo "✅ SuperAdmin Get School" || echo "❌ SuperAdmin Get School"
curl -s -w "\nHTTP:%{http_code}\n" -X PUT "$BASE_URL/admin/schools/$SCHOOL_ID" -H "Authorization: Bearer $SUPER_TOKEN" -H "Content-Type: application/json" -d '{"phone":"+234-999"}' | grep "HTTP:" | grep -q "200" && echo "✅ SuperAdmin Update School" || echo "❌ SuperAdmin Update School"
curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/admin/schools/$SCHOOL_ID/suspend" -H "Authorization: Bearer $SUPER_TOKEN" | grep "HTTP:" | grep -q "200" && echo "✅ SuperAdmin Suspend School" || echo "❌ SuperAdmin Suspend School"
curl -s -w "\nHTTP:%{http_code}\n" -X POST "$BASE_URL/admin/schools/$SCHOOL_ID/activate" -H "Authorization: Bearer $SUPER_TOKEN" | grep "HTTP:" | grep -q "200" && echo "✅ SuperAdmin Activate School" || echo "❌ SuperAdmin Activate School"

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1

echo ""
echo "=== ALL CRITICAL APIS TESTED ==="
