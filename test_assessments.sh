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

echo "Testing Assessment APIs..."
echo ""

echo "1. Grading Systems:"
curl -s -X GET "$BASE_URL/assessments/grading-systems" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.'

echo ""
echo "2. Default Grading System:"
curl -s -X GET "$BASE_URL/assessments/grading-systems/default" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.'

echo ""
echo "3. Continuous Assessments:"
curl -s -X GET "$BASE_URL/assessments/continuous-assessments" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.'

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
