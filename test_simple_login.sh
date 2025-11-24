#!/bin/bash

# Simple Login Test - Tests if created users can login with their roles
# This assumes you have already created users via the API

BASE_URL="http://127.0.0.1:8000/api/v1"
SUBDOMAIN="testschool"

echo "======================================"
echo "🧪 Simple Role Login Test"
echo "======================================"
echo ""
echo "This will create users and test their login"
echo ""

# You need to provide an admin token
read -p "Enter your admin token (or press Enter to skip): " ADMIN_TOKEN

if [ -z "$ADMIN_TOKEN" ]; then
    echo "Creating users requires an admin token."
    echo ""
    echo "To test manually:"
    echo "1. Create a teacher via API"
    echo "2. Use the returned email and password to login"
    echo "3. Verify the token and role in response"
    echo ""
    echo "Example:"
    echo "curl -X POST '$BASE_URL/auth/login' \\"
    echo "  -H 'X-Subdomain: $SUBDOMAIN' \\"
    echo "  -H 'Content-Type: application/json' \\"
    echo "  -d '{\"email\": \"john.doe1@testloginschool.com\", \"password\": \"Password@123\"}'"
    echo ""
    exit 0
fi

echo ""
echo "Using admin token: ${ADMIN_TOKEN:0:20}..."
echo ""

# Test creating and logging in as different roles
declare -a ROLES=("teacher" "student" "staff" "guardian")
PASSED=0
FAILED=0

for ROLE in "${ROLES[@]}"; do
    echo "======================================"
    echo "Testing $ROLE"
    echo "======================================"
    
    # Create user based on role
    if [ "$ROLE" = "teacher" ]; then
        CREATE_RESPONSE=$(curl -s -X POST "$BASE_URL/teachers" \
          -H "Authorization: Bearer $ADMIN_TOKEN" \
          -H "X-Subdomain: $SUBDOMAIN" \
          -H "Content-Type: application/json" \
          -d "{
            \"first_name\": \"Test\",
            \"last_name\": \"Teacher$(date +%s)\",
            \"title\": \"Mr.\",
            \"employment_date\": \"2025-01-15\"
          }")
    elif [ "$ROLE" = "student" ]; then
        CREATE_RESPONSE=$(curl -s -X POST "$BASE_URL/students" \
          -H "Authorization: Bearer $ADMIN_TOKEN" \
          -H "X-Subdomain: $SUBDOMAIN" \
          -H "Content-Type: application/json" \
          -d "{
            \"first_name\": \"Test\",
            \"last_name\": \"Student$(date +%s)\",
            \"class_id\": 1,
            \"date_of_birth\": \"2008-05-15\",
            \"gender\": \"male\"
          }")
    elif [ "$ROLE" = "staff" ]; then
        CREATE_RESPONSE=$(curl -s -X POST "$BASE_URL/staff" \
          -H "Authorization: Bearer $ADMIN_TOKEN" \
          -H "X-Subdomain: $SUBDOMAIN" \
          -H "Content-Type: application/json" \
          -d "{
            \"first_name\": \"Test\",
            \"last_name\": \"Staff$(date +%s)\",
            \"role\": \"librarian\",
            \"employment_date\": \"2025-01-15\"
          }")
    elif [ "$ROLE" = "guardian" ]; then
        CREATE_RESPONSE=$(curl -s -X POST "$BASE_URL/guardians" \
          -H "Authorization: Bearer $ADMIN_TOKEN" \
          -H "X-Subdomain: $SUBDOMAIN" \
          -H "Content-Type: application/json" \
          -d "{
            \"first_name\": \"Test\",
            \"last_name\": \"Guardian$(date +%s)\",
            \"phone\": \"+1234567890\",
            \"occupation\": \"Engineer\"
          }")
    fi
    
    EMAIL=$(echo $CREATE_RESPONSE | jq -r '.login_credentials.email // empty')
    PASSWORD=$(echo $CREATE_RESPONSE | jq -r '.login_credentials.password // empty')
    
    if [ -z "$EMAIL" ] || [ "$EMAIL" = "null" ]; then
        echo "✗ Failed to create $ROLE"
        echo "Response: $CREATE_RESPONSE"
        FAILED=$((FAILED + 1))
        echo ""
        continue
    fi
    
    echo "Created $ROLE:"
    echo "  Email: $EMAIL"
    echo "  Password: $PASSWORD"
    
    # Try to login
    LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"email\": \"$EMAIL\",
        \"password\": \"$PASSWORD\"
      }")
    
    TOKEN=$(echo $LOGIN_RESPONSE | jq -r '.token // .access_token // empty')
    USER_ROLE=$(echo $LOGIN_RESPONSE | jq -r '.user.role // empty')
    USER_NAME=$(echo $LOGIN_RESPONSE | jq -r '.user.name // empty')
    
    if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
        echo "✓ Login successful!"
        echo "  Token: ${TOKEN:0:30}..."
        echo "  Role: $USER_ROLE"
        echo "  Name: $USER_NAME"
        PASSED=$((PASSED + 1))
    else
        echo "✗ Login failed!"
        echo "Response: $LOGIN_RESPONSE"
        FAILED=$((FAILED + 1))
    fi
    
    echo ""
done

echo "======================================"
echo "📊 SUMMARY"
echo "======================================"
echo "Passed: $PASSED"
echo "Failed: $FAILED"
echo ""

if [ $FAILED -eq 0 ]; then
    echo "🎉 All logins successful!"
    exit 0
else
    echo "❌ Some logins failed"
    exit 1
fi

