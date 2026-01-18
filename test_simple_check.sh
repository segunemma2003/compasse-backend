#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

BASE_URL="http://localhost:8000/api/v1"

echo -e "${BLUE}Step 1: Login as SuperAdmin${NC}"
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')

echo "$login" | jq '.'
SUPER_TOKEN=$(echo "$login" | jq -r '.token')
echo "Token: ${SUPER_TOKEN:0:30}..."

echo ""
echo -e "${BLUE}Step 2: Create School${NC}"
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Test School $timestamp\",
    \"subdomain\": \"test$timestamp\",
    \"email\": \"admin@test.com\",
    \"phone\": \"+234-800-TEST\",
    \"address\": \"Test Address\",
    \"plan_id\": 1
  }")

echo "$school" | jq '.'
SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

echo "Subdomain: $SUBDOMAIN"
echo "Admin Email: $ADMIN_EMAIL"

echo ""
echo -e "${BLUE}Step 3: Login as School Admin${NC}"
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")

echo "$admin_login" | jq '.'
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')
echo "Admin Token: ${ADMIN_TOKEN:0:30}..."

echo ""
echo -e "${BLUE}Step 4: Test /auth/me${NC}"
me_response=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X GET "$BASE_URL/auth/me" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

http_code=$(echo "$me_response" | grep "HTTP_STATUS:" | cut -d: -f2)
body=$(echo "$me_response" | sed '/HTTP_STATUS:/d')

echo "HTTP Status: $http_code"
echo "$body" | jq '.'

if [ "$http_code" = "200" ]; then
  echo -e "${GREEN}✅ SUCCESS${NC}"
else
  echo -e "${RED}❌ FAILED${NC}"
  echo "Checking Laravel logs..."
  tail -50 storage/logs/laravel.log
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null
