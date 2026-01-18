#!/bin/bash

set -e  # Exit on error

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

echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}  COMPREHENSIVE SCHOOL ADMIN API TEST${NC}"
echo -e "${BLUE}============================================${NC}"

# Login as SuperAdmin
echo ""
echo -e "${BLUE}[1] SuperAdmin Login${NC}"
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')

SUPER_TOKEN=$(echo "$login" | jq -r '.token')
[[ "$SUPER_TOKEN" != "null" ]] && log_test "SuperAdmin Login" "PASS" || log_test "SuperAdmin Login" "FAIL"

# Create School
echo ""
echo -e "${BLUE}[2] Create School${NC}"
timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Full Test School $timestamp\",
    \"subdomain\": \"fulltest$timestamp\",
    \"email\": \"admin@fulltest.com\",
    \"phone\": \"+234-800-TEST\",
    \"address\": \"Test Address\",
    \"plan_id\": 1
  }")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

[[ "$SCHOOL_ID" != "null" ]] && log_test "Create School" "PASS" || log_test "Create School" "FAIL"
echo "School ID: $SCHOOL_ID, Subdomain: $SUBDOMAIN"

# School Admin Login
echo ""
echo -e "${BLUE}[3] School Admin Login${NC}"
admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")

ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')
[[ "$ADMIN_TOKEN" != "null" ]] && log_test "School Admin Login" "PASS" || log_test "School Admin Login" "FAIL"

# Test /auth/me
echo ""
echo -e "${BLUE}[4] Get Current User (/auth/me)${NC}"
me=$(curl -s -X GET "$BASE_URL/auth/me" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$me" | jq -r '.user.role') = "school_admin" ]] && log_test "Get Current User" "PASS" || log_test "Get Current User" "FAIL"

# Get Dashboard Stats
echo ""
echo -e "${BLUE}[5] Dashboard Statistics${NC}"
stats=$(curl -s -X GET "$BASE_URL/dashboard/stats" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$stats" | jq -r '.stats.users') =~ ^[0-9]+$ ]] && log_test "Dashboard Stats" "PASS" || log_test "Dashboard Stats" "FAIL"

# List & Get Available Roles
echo ""
echo -e "${BLUE}[6] Get Available Roles${NC}"
roles=$(curl -s -X GET "$BASE_URL/roles" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$roles" | jq -r '.roles.teacher') != "null" ]] && log_test "Get Roles" "PASS" || log_test "Get Roles" "FAIL"

# Create User
echo ""
echo -e "${BLUE}[7] Create User${NC}"
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
[[ "$USER_ID" != "null" ]] && log_test "Create User" "PASS" || log_test "Create User" "FAIL"

# List Users
echo ""
echo -e "${BLUE}[8] List Users${NC}"
users=$(curl -s -X GET "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$users" | jq -r '.data | length') -gt 0 ]] && log_test "List Users" "PASS" || log_test "List Users" "FAIL"

# Update User
echo ""
echo -e "${BLUE}[9] Update User${NC}"
update_user=$(curl -s -X PUT "$BASE_URL/users/$USER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Test Staff Updated"}')

[[ $(echo "$update_user" | jq -r '.user.name') = "Test Staff Updated" ]] && log_test "Update User" "PASS" || log_test "Update User" "FAIL"

# Assign Role
echo ""
echo -e "${BLUE}[10] Assign Role to User${NC}"
assign_role=$(curl -s -X POST "$BASE_URL/users/$USER_ID/assign-role" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{"role": "teacher"}')

[[ $(echo "$assign_role" | jq -r '.user.role') = "teacher" ]] && log_test "Assign Role" "PASS" || log_test "Assign Role" "FAIL"

# Create Teacher
echo ""
echo -e "${BLUE}[11] Create Teacher${NC}"
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
[[ "$TEACHER_ID" != "null" ]] && log_test "Create Teacher" "PASS" || log_test "Create Teacher" "FAIL"

# List Teachers
echo ""
echo -e "${BLUE}[12] List Teachers${NC}"
teachers=$(curl -s -X GET "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$teachers" | jq -r '.teachers.data | length') -gt 0 ]] && log_test "List Teachers" "PASS" || log_test "List Teachers" "FAIL"

# Get Academic Years & Terms (auto-seeded)
echo ""
echo -e "${BLUE}[13] Get Academic Years${NC}"
academic_years=$(curl -s -X GET "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

ACADEMIC_YEAR_ID=$(echo "$academic_years" | jq -r '.data[0].id // .academic_years[0].id // empty')
[[ -n "$ACADEMIC_YEAR_ID" ]] && log_test "Get Academic Years" "PASS" || log_test "Get Academic Years" "FAIL"

echo ""
echo -e "${BLUE}[14] Get Terms${NC}"
terms=$(curl -s -X GET "$BASE_URL/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

TERM_ID=$(echo "$terms" | jq -r '.data[0].id // .terms[0].id // empty')
[[ -n "$TERM_ID" ]] && log_test "Get Terms" "PASS" || log_test "Get Terms" "FAIL"

# Create Class
if [ -n "$ACADEMIC_YEAR_ID" ] && [ -n "$TERM_ID" ]; then
  echo ""
  echo -e "${BLUE}[15] Create Class${NC}"
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
  [[ -n "$CLASS_ID" ]] && log_test "Create Class" "PASS" || log_test "Create Class" "FAIL"
else
  log_test "Create Class" "FAIL (No academic year/term)"
fi

# List Classes
echo ""
echo -e "${BLUE}[16] List Classes${NC}"
classes=$(curl -s -X GET "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$classes" | jq 'type') = "array" ]] && log_test "List Classes" "PASS" || log_test "List Classes" "FAIL"

# Create Student
if [ -n "$CLASS_ID" ]; then
  echo ""
  echo -e "${BLUE}[17] Create Student${NC}"
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
  [[ -n "$STUDENT_ID" ]] && log_test "Create Student" "PASS" || log_test "Create Student" "FAIL"
else
  log_test "Create Student" "FAIL (No class)"
fi

# List Students
echo ""
echo -e "${BLUE}[18] List Students${NC}"
students=$(curl -s -X GET "$BASE_URL/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$students" | jq '.data | length') -ge 0 ]] && log_test "List Students" "PASS" || log_test "List Students" "FAIL"

# Get Settings
echo ""
echo -e "${BLUE}[19] Get Settings${NC}"
settings=$(curl -s -X GET "$BASE_URL/settings" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

[[ $(echo "$settings" | jq -r '.settings') != "null" ]] && log_test "Get Settings" "PASS" || log_test "Get Settings" "FAIL"

# Delete User
if [ -n "$USER_ID" ]; then
  echo ""
  echo -e "${BLUE}[20] Delete User${NC}"
  delete_user=$(curl -s -X DELETE "$BASE_URL/users/$USER_ID" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: $SUBDOMAIN")

  [[ $(echo "$delete_user" | jq -r '.message') = "User deleted successfully" ]] && log_test "Delete User" "PASS" || log_test "Delete User" "FAIL"
fi

# Summary
echo ""
echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}              TEST SUMMARY${NC}"
echo -e "${BLUE}============================================${NC}"
echo -e "${GREEN}✅ Passed: $PASSED${NC}"
echo -e "${RED}❌ Failed: $FAILED${NC}"
echo -e "${BLUE}Total: $((PASSED + FAILED))${NC}"

# Cleanup
echo ""
echo -e "${YELLOW}Cleaning up...${NC}"
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null

if [ $FAILED -eq 0 ]; then
  echo -e "${GREEN}🎉 ALL TESTS PASSED!${NC}"
  exit 0
else
  echo -e "${RED}Some tests failed. Please review the output above.${NC}"
  exit 1
fi
