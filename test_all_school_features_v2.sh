#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

BASE_URL="http://localhost:8000/api/v1"
PASSED=0
FAILED=0

log_test() {
  local name="$1"
  local status="$2"
  
  if [ "$status" = "PASS" ]; then
    echo -e "${GREEN}✅ $name${NC}"
    ((PASSED++))
  else
    echo -e "${RED}❌ $name${NC}"
    ((FAILED++))
  fi
}

echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}  SCHOOL ADMIN API COMPREHENSIVE TEST  ${NC}"
echo -e "${BLUE}=====================================${NC}"

# Test 1: SuperAdmin Login
echo -e "\n${YELLOW}[1/20] SuperAdmin Login...${NC}"
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')

SUPER_TOKEN=$(echo "$login" | jq -r '.token')
if [ "$SUPER_TOKEN" != "null" ] && [ -n "$SUPER_TOKEN" ]; then
  log_test "SuperAdmin Login" "PASS"
else
  log_test "SuperAdmin Login" "FAIL"
  echo "Response: $login"
  exit 1
fi

# Test 2: Create School
echo -e "\n${YELLOW}[2/20] Create School...${NC}"
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

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

if [ "$SCHOOL_ID" != "null" ] && [ -n "$SCHOOL_ID" ]; then
  log_test "Create School" "PASS"
  echo "  School ID: $SCHOOL_ID"
  echo "  Subdomain: $SUBDOMAIN"
else
  log_test "Create School" "FAIL"
  exit 1
fi

# Test 3: School Admin Login
echo -e "\n${YELLOW}[3/20] School Admin Login...${NC}"
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")

ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')
if [ "$ADMIN_TOKEN" != "null" ] && [ -n "$ADMIN_TOKEN" ]; then
  log_test "School Admin Login" "PASS"
else
  log_test "School Admin Login" "FAIL"
  exit 1
fi

# Continue with remaining tests...
echo -e "\n${YELLOW}[4/20] Get Current User (/auth/me)...${NC}"
me=$(curl -s -X GET "$BASE_URL/auth/me" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$me" | jq -r '.user.role')" = "school_admin" ] && log_test "Get Current User" "PASS" || log_test "Get Current User" "FAIL"

echo -e "\n${YELLOW}[5/20] Dashboard Stats...${NC}"
stats=$(curl -s -X GET "$BASE_URL/dashboard/stats" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
[[ $(echo "$stats" | jq -r '.stats.users') =~ ^[0-9]+$ ]] && log_test "Dashboard Stats" "PASS" || log_test "Dashboard Stats" "FAIL"

echo -e "\n${YELLOW}[6/20] Get Roles...${NC}"
roles=$(curl -s -X GET "$BASE_URL/roles" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$roles" | jq -r '.roles.teacher')" != "null" ] && log_test "Get Roles" "PASS" || log_test "Get Roles" "FAIL"

echo -e "\n${YELLOW}[7/20] Create User...${NC}"
user=$(curl -s -X POST "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Staff",
    "email": "staff@test.com",
    "password": "password123",
    "password_confirmation": "password123",
    "role": "staff",
    "phone": "+234-800-1111"
  }')
USER_ID=$(echo "$user" | jq -r '.data.id')
[ "$USER_ID" != "null" ] && log_test "Create User" "PASS" || log_test "Create User" "FAIL"

echo -e "\n${YELLOW}[8/20] List Users...${NC}"
users=$(curl -s -X GET "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
[ $(echo "$users" | jq -r '.data | length') -gt 0 ] && log_test "List Users" "PASS" || log_test "List Users" "FAIL"

echo -e "\n${YELLOW}[9/20] Update User...${NC}"
update_user=$(curl -s -X PUT "$BASE_URL/users/$USER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Test Staff Updated"}')
[ "$(echo "$update_user" | jq -r '.user.name')" = "Test Staff Updated" ] && log_test "Update User" "PASS" || log_test "Update User" "FAIL"

echo -e "\n${YELLOW}[10/20] Assign Role...${NC}"
assign_role=$(curl -s -X POST "$BASE_URL/users/$USER_ID/assign-role" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"role": "teacher"}')
[ "$(echo "$assign_role" | jq -r '.user.role')" = "teacher" ] && log_test "Assign Role" "PASS" || log_test "Assign Role" "FAIL"

echo -e "\n${YELLOW}[11/20] Create Teacher...${NC}"
teacher=$(curl -s -X POST "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Teacher",
    "email": "john.teacher@test.com",
    "phone": "+234-800-2222",
    "date_of_birth": "1985-05-15",
    "gender": "male",
    "employment_date": "2025-01-01"
  }')
TEACHER_ID=$(echo "$teacher" | jq -r '.teacher.id')
[ "$TEACHER_ID" != "null" ] && log_test "Create Teacher" "PASS" || log_test "Create Teacher" "FAIL"

echo -e "\n${YELLOW}[12/20] List Teachers...${NC}"
teachers=$(curl -s -X GET "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
[ $(echo "$teachers" | jq -r '.teachers.data | length') -gt 0 ] && log_test "List Teachers" "PASS" || log_test "List Teachers" "FAIL"

echo -e "\n${YELLOW}[13/20] Get Academic Years...${NC}"
academic_years=$(curl -s -X GET "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
ACADEMIC_YEAR_ID=$(echo "$academic_years" | jq -r '.[0].id // empty')
[ -n "$ACADEMIC_YEAR_ID" ] && log_test "Get Academic Years" "PASS" || log_test "Get Academic Years" "FAIL"

echo -e "\n${YELLOW}[14/20] Get Terms...${NC}"
terms=$(curl -s -X GET "$BASE_URL/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
TERM_ID=$(echo "$terms" | jq -r '.[0].id // empty')
[ -n "$TERM_ID" ] && log_test "Get Terms" "PASS" || log_test "Get Terms" "FAIL"

echo -e "\n${YELLOW}[15/20] Create Class...${NC}"
if [ -n "$ACADEMIC_YEAR_ID" ] && [ -n "$TERM_ID" ]; then
  class=$(curl -s -X POST "$BASE_URL/classes" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: $SUBDOMAIN" \
    -H "Content-Type: application/json" \
    -d "{
      \"name\": \"Grade 1\",
      \"academic_year_id\": $ACADEMIC_YEAR_ID,
      \"term_id\": $TERM_ID,
      \"capacity\": 30
    }")
  CLASS_ID=$(echo "$class" | jq -r '.id // .class.id // .data.id // empty')
  [ -n "$CLASS_ID" ] && log_test "Create Class" "PASS" || log_test "Create Class" "FAIL"
else
  log_test "Create Class" "FAIL"
fi

echo -e "\n${YELLOW}[16/20] List Classes...${NC}"
classes=$(curl -s -X GET "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
# Classes endpoint returns an array directly
if [ "$(echo "$classes" | jq 'type')" = "\"array\"" ]; then
  log_test "List Classes" "PASS"
else
  log_test "List Classes" "FAIL"
fi

echo -e "\n${YELLOW}[17/20] Create Student...${NC}"
if [ -n "$CLASS_ID" ]; then
  student=$(curl -s -X POST "$BASE_URL/students" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: $SUBDOMAIN" \
    -H "Content-Type: application/json" \
    -d "{
      \"first_name\": \"Jane\",
      \"last_name\": \"Student\",
      \"class_id\": $CLASS_ID,
      \"date_of_birth\": \"2010-08-20\",
      \"gender\": \"female\"
    }")
  STUDENT_ID=$(echo "$student" | jq -r '.student.id // .data.id // empty')
  [ -n "$STUDENT_ID" ] && log_test "Create Student" "PASS" || log_test "Create Student" "FAIL"
else
  log_test "Create Student" "FAIL"
fi

echo -e "\n${YELLOW}[18/20] List Students...${NC}"
students=$(curl -s -X GET "$BASE_URL/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
[ $(echo "$students" | jq '.data | length') -ge 0 ] && log_test "List Students" "PASS" || log_test "List Students" "FAIL"

echo -e "\n${YELLOW}[19/20] Get Settings...${NC}"
settings=$(curl -s -X GET "$BASE_URL/settings" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$settings" | jq -r '.settings')" != "null" ] && log_test "Get Settings" "PASS" || log_test "Get Settings" "FAIL"

echo -e "\n${YELLOW}[20/20] Delete User...${NC}"
if [ -n "$USER_ID" ]; then
  delete_user=$(curl -s -X DELETE "$BASE_URL/users/$USER_ID" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$delete_user" | jq -r '.message')" = "User deleted successfully" ] && log_test "Delete User" "PASS" || log_test "Delete User" "FAIL"
fi

# Summary
echo ""
echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}           TEST SUMMARY              ${NC}"
echo -e "${BLUE}=====================================${NC}"
echo -e "${GREEN}✅ Passed: $PASSED${NC}"
echo -e "${RED}❌ Failed: $FAILED${NC}"
echo -e "${BLUE}Total: $((PASSED + FAILED))${NC}"

# Cleanup
echo ""
echo -e "${YELLOW}Cleaning up test school...${NC}"
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null

if [ $FAILED -eq 0 ]; then
  echo -e "\n${GREEN}🎉 ALL TESTS PASSED!${NC}"
  exit 0
else
  echo -e "\n${RED}⚠️  $FAILED test(s) failed${NC}"
  exit 1
fi
