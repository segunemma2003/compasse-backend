#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# Setup
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
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

ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

class=$(curl -s -X POST "$BASE_URL/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
CLASS_ID=$(echo "$class" | jq -r '.id')

student=$(curl -s -X POST "$BASE_URL/students" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"first_name\":\"John\",\"last_name\":\"Doe\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"male\"}")
STUDENT_ID=$(echo "$student" | jq -r '.student.id // .id')

echo "Testing specific failing APIs..."
echo ""

echo "1. Student Attendance:"
curl -s -X GET "$BASE_URL/students/$STUDENT_ID/attendance" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.'

echo ""
echo "2. Student Results:"
curl -s -X GET "$BASE_URL/students/$STUDENT_ID/results" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.'

echo ""
echo "3. Get School Modules:"
curl -s -X GET "$BASE_URL/subscriptions/school/modules" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.'

echo ""
echo "4. Get Class Students:"
curl -s -X GET "$BASE_URL/classes/$CLASS_ID/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.'

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
