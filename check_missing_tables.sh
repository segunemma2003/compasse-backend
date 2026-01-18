#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# Setup
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" -H "Authorization: Bearer $SUPER_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

admin_login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')

echo "Testing APIs that failed..."
echo ""

# Test each failed API
echo "1. Grading Systems:"
curl -s -X GET "$BASE_URL/assessments/grading-systems" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message' | head -3

echo ""
echo "2. Arms:"
curl -s -X GET "$BASE_URL/arms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

echo ""
echo "3. Digital Resources:"
curl -s -X GET "$BASE_URL/library/digital-resources" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

echo ""
echo "4. Fee Structure:"
curl -s -X GET "$BASE_URL/financial/fees/structure" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

echo ""
echo "5. Expenses:"
curl -s -X GET "$BASE_URL/financial/expenses" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

echo ""
echo "6. Payroll:"
curl -s -X GET "$BASE_URL/financial/payroll" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

echo ""
echo "7. Analytics:"
curl -s -X GET "$BASE_URL/analytics/school" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

echo ""
echo "8. Promotions:"
curl -s -X GET "$BASE_URL/promotions" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

echo ""
echo "9. Livestreams:"
curl -s -X GET "$BASE_URL/livestreams" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq '.error // .message // "Success"' | head -3

# Cleanup
SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
