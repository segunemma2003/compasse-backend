#!/bin/bash

BASE_URL="http://localhost:8000/api/v1"

# SuperAdmin Login
echo "=== SuperAdmin Login ==="
login=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
code=$(echo "$login" | grep "HTTP:" | cut -d: -f2)
body=$(echo "$login" | sed '/HTTP:/d')
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ WORKS"
  SUPER_TOKEN=$(echo "$body" | jq -r '.token')
else
  echo "❌ FAILED"
  exit 1
fi

# Create school
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" -H "Authorization: Bearer $SUPER_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")
SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
echo ""
echo "School ID: $SCHOOL_ID"

# Test SuperAdmin routes with /admin/schools prefix
echo ""
echo "=== SuperAdmin GET School Details (via /admin/schools) ==="
school_detail=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$BASE_URL/admin/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $SUPER_TOKEN")
code=$(echo "$school_detail" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ WORKS"
  echo "$school_detail" | sed '/HTTP:/d' | jq '.school.name'
else
  echo "❌ FAILED"
  echo "$school_detail" | sed '/HTTP:/d' | head -5
fi

echo ""
echo "=== SuperAdmin UPDATE School (via /admin/schools) ==="
school_update=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$BASE_URL/admin/schools/$SCHOOL_ID" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"phone":"+234-900-0000"}')
code=$(echo "$school_update" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
if [ "$code" = "200" ]; then
  echo "✅ WORKS"
  echo "$school_update" | sed '/HTTP:/d' | jq '.school.phone'
else
  echo "❌ FAILED"
  echo "$school_update" | sed '/HTTP:/d' | head -5
fi

echo ""
echo "=== SuperAdmin Suspend School ==="
suspend=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/admin/schools/$SCHOOL_ID/suspend" \
  -H "Authorization: Bearer $SUPER_TOKEN")
code=$(echo "$suspend" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED"

echo ""
echo "=== SuperAdmin Activate School ==="
activate=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$BASE_URL/admin/schools/$SCHOOL_ID/activate" \
  -H "Authorization: Bearer $SUPER_TOKEN")
code=$(echo "$activate" | grep "HTTP:" | cut -d: -f2)
echo "Status: $code"
[ "$code" = "200" ] && echo "✅ WORKS" || echo "❌ FAILED"

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1
echo ""
echo "=== ALL SUPERADMIN ROUTES TESTED ==="
