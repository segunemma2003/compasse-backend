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

echo "Testing Staff Creation with correct fields..."
echo ""

# Test with correct fields (role + employment_date)
staff=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/staff" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"first_name":"Staff","last_name":"Member","email":"staff@test.com","phone":"+234-800-3333","role":"staff","employment_date":"2025-01-01"}')

code=$(echo "$staff" | grep "HTTP:" | cut -d: -f2)
body=$(echo "$staff" | sed '/HTTP:/d')

echo "Status: $code"
if [ "$code" = "201" ] || [ "$code" = "200" ]; then
  echo "✅ Create Staff SUCCESS"
  echo "$body" | jq '.'
else
  echo "❌ Create Staff FAILED"
  echo "$body" | jq '.'
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
