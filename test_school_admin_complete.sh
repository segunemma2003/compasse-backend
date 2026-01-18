#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

BASE_URL="http://localhost:8000/api/v1"
PASSED=0
FAILED=0

test_api() {
  local name="$1"
  local method="$2"
  local endpoint="$3"
  local headers="$4"
  local data="$5"
  local expected_status="$6"
  
  echo ""
  echo -e "${YELLOW}Testing: $name${NC}"
  
  if [ "$method" = "GET" ]; then
    response=$(curl -s -w "\n%{http_code}" -X GET "$BASE_URL$endpoint" $headers)
  elif [ "$method" = "POST" ]; then
    response=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL$endpoint" $headers -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "PUT" ]; then
    response=$(curl -s -w "\n%{http_code}" -X PUT "$BASE_URL$endpoint" $headers -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "DELETE" ]; then
    response=$(curl -s -w "\n%{http_code}" -X DELETE "$BASE_URL$endpoint" $headers)
  fi
  
  status_code=$(echo "$response" | tail -n1)
  body=$(echo "$response" | sed '$d')
  
  echo "$body" | jq '.' 2>/dev/null || echo "$body"
  
  if [ "$status_code" = "$expected_status" ]; then
    echo -e "${GREEN}✅ PASSED (Status: $status_code)${NC}"
    ((PASSED++))
    echo "$body"
  else
    echo -e "${RED}❌ FAILED (Expected: $expected_status, Got: $status_code)${NC}"
    ((FAILED++))
    echo ""
  fi
}

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  COMPLETE SCHOOL ADMIN API TEST       ${NC}"
echo -e "${BLUE}========================================${NC}"

# Login as SuperAdmin
echo ""
echo -e "${BLUE}Step 1: Login as SuperAdmin${NC}"
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')

SUPER_TOKEN=$(echo "$login" | jq -r '.token')
echo "SuperAdmin Token: ${SUPER_TOKEN:0:20}..."

# Create New School
echo ""
echo -e "${BLUE}Step 2: Create New School${NC}"
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Complete Test School $timestamp\",
    \"subdomain\": \"complete$timestamp\",
    \"email\": \"admin@complete.com\",
    \"phone\": \"+234-800-TEST\",
    \"address\": \"Test Address\",
    \"plan_id\": 1
  }")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

echo "School ID: $SCHOOL_ID"
echo "Subdomain: $SUBDOMAIN"
echo "Admin Email: $ADMIN_EMAIL"

# Login as School Admin
echo ""
echo -e "${BLUE}Step 3: Login as School Admin${NC}"
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")

ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')
USER_ROLE=$(echo "$admin_login" | jq -r '.user.role')

if [ -z "$ADMIN_TOKEN" ] || [ "$ADMIN_TOKEN" = "null" ]; then
  echo -e "${RED}❌ Login FAILED${NC}"
  exit 1
fi

echo -e "${GREEN}✅ Login SUCCESS! Role: $USER_ROLE${NC}"
echo "Token: ${ADMIN_TOKEN:0:30}..."

# Setup headers
AUTH_HEADER="-H \"Authorization: Bearer $ADMIN_TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  TESTING ALL SCHOOL ADMIN APIs        ${NC}"
echo -e "${BLUE}========================================${NC}"

# Test 1: Get Current User Profile
test_api "Get Current User Profile" "GET" "/auth/me" "$AUTH_HEADER" "" "200"

# Test 2: Get Dashboard Stats
test_api "Get Dashboard Stats" "GET" "/dashboard/stats" "$AUTH_HEADER" "" "200"

# Test 3: Get Available Roles
test_api "Get Available Roles" "GET" "/roles" "$AUTH_HEADER" "" "200"

# Test 4: List Users
test_api "List Users" "GET" "/users" "$AUTH_HEADER" "" "200"

# Test 5: Create User
test_api "Create User/Staff" "POST" "/users" "$AUTH_HEADER" '{
  "name": "Test Staff",
  "email": "staff@test.com",
  "password": "password123",
  "password_confirmation": "password123",
  "role": "staff",
  "phone": "+234-800-1111"
}' "201"

USER_ID=$(curl -s -X GET "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" | jq -r '.data[-1].id')

# Test 6: Get User Details
if [ -n "$USER_ID" ] && [ "$USER_ID" != "null" ]; then
  test_api "Get User Details" "GET" "/users/$USER_ID" "$AUTH_HEADER" "" "200"
fi

# Test 7: Update User
if [ -n "$USER_ID" ] && [ "$USER_ID" != "null" ]; then
  test_api "Update User" "PUT" "/users/$USER_ID" "$AUTH_HEADER" '{
    "name": "Test Staff Updated",
    "phone": "+234-800-2222"
  }' "200"
fi

# Test 8: Assign Role to User
if [ -n "$USER_ID" ] && [ "$USER_ID" != "null" ]; then
  test_api "Assign Role to User" "POST" "/users/$USER_ID/assign-role" "$AUTH_HEADER" '{
    "role": "teacher"
  }' "200"
fi

# Test 9: Create Teacher (with employment_date)
test_api "Create Teacher" "POST" "/teachers" "$AUTH_HEADER" '{
  "first_name": "John",
  "last_name": "Teacher",
  "email": "john.teacher@test.com",
  "phone": "+234-800-3333",
  "date_of_birth": "1985-05-15",
  "gender": "male",
  "employment_date": "2025-01-01"
}' "201"

# Test 10: List Teachers
test_api "List Teachers" "GET" "/teachers" "$AUTH_HEADER" "" "200"

# Get Teacher ID
TEACHER_ID=$(curl -s -X GET "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" | jq -r '.teachers.data[0].id // .data[0].id // empty')

# Test 11: Get Teacher Details
if [ -n "$TEACHER_ID" ] && [ "$TEACHER_ID" != "null" ]; then
  test_api "Get Teacher Details" "GET" "/teachers/$TEACHER_ID" "$AUTH_HEADER" "" "200"
fi

# Test 12: Update Teacher
if [ -n "$TEACHER_ID" ] && [ "$TEACHER_ID" != "null" ]; then
  test_api "Update Teacher" "PUT" "/teachers/$TEACHER_ID" "$AUTH_HEADER" '{
    "phone": "+234-800-4444"
  }' "200"
fi

# Test 13: Create Class (with academic_year_id and term_id)
# First get academic year and term
ACADEMIC_YEAR_ID=$(curl -s -X GET "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" 2>/dev/null | jq -r '.data[0].id // .academic_years[0].id // empty')

TERM_ID=$(curl -s -X GET "$BASE_URL/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" 2>/dev/null | jq -r '.data[0].id // .terms[0].id // empty')

if [ -n "$ACADEMIC_YEAR_ID" ] && [ "$ACADEMIC_YEAR_ID" != "null" ] && [ -n "$TERM_ID" ] && [ "$TERM_ID" != "null" ]; then
  test_api "Create Class" "POST" "/classes" "$AUTH_HEADER" "{
    \"name\": \"Grade 1\",
    \"academic_year_id\": $ACADEMIC_YEAR_ID,
    \"term_id\": $TERM_ID,
    \"capacity\": 30
  }" "201"
else
  echo -e "${YELLOW}⚠️  Skipping class creation - no academic year/term available${NC}"
  ((FAILED++))
fi

# Test 14: List Classes
test_api "List Classes" "GET" "/classes" "$AUTH_HEADER" "" "200"

# Get Class ID
CLASS_ID=$(curl -s -X GET "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" 2>/dev/null | jq -r '.[0].id // .data[0].id // .classes[0].id // empty')

# Test 15: Create Student (with class_id)
if [ -n "$CLASS_ID" ] && [ "$CLASS_ID" != "null" ]; then
  test_api "Create Student" "POST" "/students" "$AUTH_HEADER" "{
    \"first_name\": \"Jane\",
    \"last_name\": \"Student\",
    \"class_id\": $CLASS_ID,
    \"date_of_birth\": \"2010-08-20\",
    \"gender\": \"female\"
  }" "201"
else
  echo -e "${YELLOW}⚠️  Skipping student creation - no class available${NC}"
  ((FAILED++))
fi

# Test 16: List Students
test_api "List Students" "GET" "/students" "$AUTH_HEADER" "" "200"

# Test 17: List Subjects (may be empty)
test_api "List Subjects" "GET" "/subjects" "$AUTH_HEADER" "" "200"

# Test 18: Get Settings
test_api "Get School Settings" "GET" "/settings" "$AUTH_HEADER" "" "200"

# Test 19: Delete User
if [ -n "$USER_ID" ] && [ "$USER_ID" != "null" ]; then
  test_api "Delete User" "DELETE" "/users/$USER_ID" "$AUTH_HEADER" "" "200"
fi

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}         TEST SUMMARY                   ${NC}"
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}Passed: $PASSED${NC}"
echo -e "${RED}Failed: $FAILED${NC}"
echo -e "${BLUE}Total: $((PASSED + FAILED))${NC}"

# Cleanup
echo ""
echo -e "${YELLOW}Cleaning up test school...${NC}"
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" | jq '.'

if [ $FAILED -eq 0 ]; then
  echo -e "${GREEN}✅ ALL TESTS PASSED!${NC}"
  exit 0
else
  echo -e "${RED}❌ Some tests failed${NC}"
  exit 1
fi
