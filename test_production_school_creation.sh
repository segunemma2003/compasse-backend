#!/bin/bash

API_URL="https://api.compasse.net/api/v1"

echo "🔐 Testing Super Admin Login on Production..."
LOGIN_RESPONSE=$(curl -s -X POST "$API_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "superadmin@compasse.net",
    "password": "Nigeria@60",
    "role": "super_admin"
  }')

echo "$LOGIN_RESPONSE" | jq '.'

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.access_token // .token // empty')

if [ -z "$TOKEN" ] || [ "$TOKEN" == "null" ]; then
  echo "❌ Login failed! No token received."
  exit 1
fi

echo ""
echo "✅ Login successful! Token: ${TOKEN:0:20}..."
echo ""

echo "🏫 Testing School Creation..."
SCHOOL_RESPONSE=$(curl -s -X POST "$API_URL/schools" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "name": "Westwood International School",
    "subdomain": "westwood'$(date +%s)'",
    "email": "admin@westwoodschool.com",
    "phone": "+234801234567",
    "address": "123 Education Street, Lagos",
    "website": "https://westwoodschool.com",
    "motto": "Excellence in Education"
  }')

echo "$SCHOOL_RESPONSE" | jq '.'

if echo "$SCHOOL_RESPONSE" | jq -e '.school // .data' > /dev/null 2>&1; then
  echo ""
  echo "✅ School created successfully!"
else
  echo ""
  echo "❌ School creation failed!"
fi

echo ""
echo "📊 Testing Dashboard Access..."
DASHBOARD_RESPONSE=$(curl -s -X GET "$API_URL/dashboard/super-admin" \
  -H "Authorization: Bearer $TOKEN")

echo "$DASHBOARD_RESPONSE" | jq '.'

echo ""
echo "✅ All tests completed!"
