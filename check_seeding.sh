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
    \"name\": \"Check School $timestamp\",
    \"subdomain\": \"check$timestamp\",
    \"email\": \"admin@check.com\",
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

echo "Subdomain: $SUBDOMAIN"
echo "Token: ${ADMIN_TOKEN:0:30}..."

# Check academic years
echo ""
echo "=== Academic Years ==="
curl -s -X GET "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" | jq '.'

# Check terms
echo ""
echo "=== Terms ==="
curl -s -X GET "$BASE_URL/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" | jq '.'

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null
