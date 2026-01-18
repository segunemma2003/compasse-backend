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
    \"name\": \"Debug Class $timestamp\",
    \"subdomain\": \"debugclass$timestamp\",
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

# Get academic year and term
academic_years=$(curl -s -X GET "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
ACADEMIC_YEAR_ID=$(echo "$academic_years" | jq -r '.[0].id')

terms=$(curl -s -X GET "$BASE_URL/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
TERM_ID=$(echo "$terms" | jq -r '.[0].id')

echo "Academic Year ID: $ACADEMIC_YEAR_ID"
echo "Term ID: $TERM_ID"

# Create Class
echo ""
echo "Creating class..."
class_response=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X POST "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Grade 1\",
    \"academic_year_id\": $ACADEMIC_YEAR_ID,
    \"term_id\": $TERM_ID,
    \"capacity\": 30
  }")

status=$(echo "$class_response" | grep HTTP_STATUS | cut -d: -f2)
body=$(echo "$class_response" | sed '/HTTP_STATUS/d')

echo "Status: $status"
echo "Response:"
echo "$body"

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null
