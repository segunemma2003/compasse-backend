#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# SuperAdmin Login
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

# Create school
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" -H "Authorization: Bearer $SUPER_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")
SCHOOL_ID=$(echo "$school" | jq -r '.school.id')

echo "School ID: $SCHOOL_ID"
echo ""

echo "=== TEST 1: SuperAdmin Get School Details (NO TENANT HEADER) ==="
school_detail=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$BASE_URL/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $SUPER_TOKEN")
code=$(echo "$school_detail" | grep "HTTP:" | cut -d: -f2)
body=$(echo "$school_detail" | sed '/HTTP:/d')
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ SuperAdmin can get school details"
  echo "$body" | jq '.school.name, .school.id'
else
  echo "❌ SuperAdmin CANNOT get school details"
  echo "$body" | head -10
fi

echo ""
echo "=== TEST 2: SuperAdmin Update School (NO TENANT HEADER) ==="
school_update=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$BASE_URL/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"phone":"+234-900-0000"}')
code=$(echo "$school_update" | grep "HTTP:" | cut -d: -f2)
body=$(echo "$school_update" | sed '/HTTP:/d')
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ SuperAdmin can update school"
  echo "$body" | jq '.school.phone'
else
  echo "❌ SuperAdmin CANNOT update school"
  echo "$body" | head -10
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
