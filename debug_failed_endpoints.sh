#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# Setup
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Debug $timestamp\",\"subdomain\":\"debug$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

echo "School ID: $SCHOOL_ID"
echo "Subdomain: $SUBDOMAIN"
echo ""

# Test 1: Get School Details
echo "=== TEST 1: Get School Details ==="
curl -s "$BASE_URL/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" | head -20

echo ""
echo ""

# Test 2: Get User Details (create user first)
user=$(curl -s -X POST "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"name":"Test","email":"test@test.com","password":"pass","password_confirmation":"pass","role":"staff"}')
USER_ID=$(echo "$user" | jq -r '.data.id')

echo "=== TEST 2: Get User Details (ID: $USER_ID) ==="
curl -s "$BASE_URL/users/$USER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" | head -20

echo ""
echo ""

# Test 3: Get Teacher Details
teacher=$(curl -s -X POST "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"Test","last_name":"Teacher","email":"teacher@test.com","phone":"+234-800","date_of_birth":"1985-05-15","gender":"male","employment_date":"2025-01-01"}')
TEACHER_ID=$(echo "$teacher" | jq -r '.teacher.id')

echo "=== TEST 3: Get Teacher Details (ID: $TEACHER_ID) ==="
curl -s "$BASE_URL/teachers/$TEACHER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" | head -20

echo ""
echo ""

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null
