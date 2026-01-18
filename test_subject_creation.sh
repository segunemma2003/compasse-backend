#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# SuperAdmin setup
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

# School admin login
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

# Get academic year and term
ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

# Create class
class=$(curl -s -X POST "$BASE_URL/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
CLASS_ID=$(echo "$class" | jq -r '.id')

echo "Testing Subject Creation..."
echo "Class ID: $CLASS_ID"
echo ""

# Test subject creation
subject=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/subjects" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Mathematics\",\"code\":\"MATH\",\"class_id\":$CLASS_ID}")

code=$(echo "$subject" | grep "HTTP:" | cut -d: -f2)
body=$(echo "$subject" | sed '/HTTP:/d')

echo "Status: $code"
if [ "$code" = "201" ] || [ "$code" = "200" ]; then
  echo "✅ Create Subject SUCCESS"
  echo "$body" | jq '.'
else
  echo "❌ Create Subject FAILED"
  echo "$body" | jq '.' 2>/dev/null || echo "$body" | head -20
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
