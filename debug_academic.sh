#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# Login SuperAdmin
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

# Create School
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Debug $timestamp\",
    \"subdomain\": \"debug$timestamp\",
    \"email\": \"admin@debug.com\",
    \"phone\": \"+234-800-TEST\",
    \"address\": \"Test\",
    \"plan_id\": 1
  }")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

# Login School Admin
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

echo "Testing academic years endpoint..."
academic_years=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X GET "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

status=$(echo "$academic_years" | grep HTTP_STATUS | cut -d: -f2)
body=$(echo "$academic_years" | sed '/HTTP_STATUS/d')

echo "Status: $status"
echo "Response:"
echo "$body" | jq '.'

echo ""
echo "Extracting ID from response (trying different paths)..."
echo "Try 1 - .data[0].id: $(echo "$body" | jq -r '.data[0].id // "null"')"
echo "Try 2 - .[0].id: $(echo "$body" | jq -r '.[0].id // "null"')"
echo "Try 3 - .academic_years[0].id: $(echo "$body" | jq -r '.academic_years[0].id // "null"')"

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null
