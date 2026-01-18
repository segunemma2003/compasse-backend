#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

BASE_URL="http://localhost:8000/api/v1"
PASSED=0
FAILED=0
SKIPPED=0

log_test() {
  local name="$1"
  local status="$2"
  
  if [ "$status" = "PASS" ]; then
    echo -e "${GREEN}✅ $name${NC}"
    ((PASSED++))
  elif [ "$status" = "SKIP" ]; then
    echo -e "${YELLOW}⚠️  $name (SKIPPED)${NC}"
    ((SKIPPED++))
  else
    echo -e "${RED}❌ $name${NC}"
    ((FAILED++))
  fi
}

test_endpoint() {
  local method="$1"
  local url="$2"
  local headers="$3"
  local data="$4"
  
  if [ "$method" = "GET" ]; then
    response=$(curl -s -w "\nSTATUS_CODE:%{http_code}" -X GET "$url" $headers)
  elif [ "$method" = "POST" ]; then
    response=$(curl -s -w "\nSTATUS_CODE:%{http_code}" -X POST "$url" $headers -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "PUT" ]; then
    response=$(curl -s -w "\nSTATUS_CODE:%{http_code}" -X PUT "$url" $headers -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "DELETE" ]; then
    response=$(curl -s -w "\nSTATUS_CODE:%{http_code}" -X DELETE "$url" $headers)
  fi
  
  status=$(echo "$response" | grep "STATUS_CODE" | cut -d: -f2)
  body=$(echo "$response" | sed '/STATUS_CODE/d')
  
  echo "$body"
  return $status
}

echo -e "${CYAN}=========================================${NC}"
echo -e "${CYAN}  FIXED COMPREHENSIVE TEST   ${NC}"
echo -e "${CYAN}=========================================${NC}"

# Setup
echo -e "\n${BLUE}[SETUP]${NC}"
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')
[ "$SUPER_TOKEN" != "null" ] && log_test "SuperAdmin Login" "PASS" || log_test "SuperAdmin Login" "FAIL"

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")

SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')
[ "$SCHOOL_ID" != "null" ] && log_test "Create School" "PASS" || log_test "Create School" "FAIL"

admin_login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')
[ "$ADMIN_TOKEN" != "null" ] && log_test "School Admin Login" "PASS" || log_test "School Admin Login" "FAIL"

AUTH_HEADERS="-H \"Authorization: Bearer $ADMIN_TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""

# Test School Management
echo -e "\n${CYAN}[1] SCHOOL MANAGEMENT${NC}"
my_school=$(test_endpoint "GET" "$BASE_URL/schools/me" "$AUTH_HEADERS")
status_code=$?
[ $status_code -eq 200 ] && log_test "Get My School" "PASS" || log_test "Get My School" "FAIL"

# Test Teacher with proper error handling
echo -e "\n${CYAN}[2] TEACHER MANAGEMENT${NC}"
teacher=$(test_endpoint "POST" "$BASE_URL/teachers" "$AUTH_HEADERS" '{"first_name":"John","last_name":"Doe","email":"john@test.com","phone":"+234-800-1234","date_of_birth":"1985-05-15","gender":"male","employment_date":"2025-01-01"}')
status_code=$?
TEACHER_ID=$(echo "$teacher" | jq -r '.teacher.id // empty' 2>/dev/null)
[ $status_code -eq 201 ] && [ -n "$TEACHER_ID" ] && log_test "Create Teacher" "PASS" || log_test "Create Teacher" "FAIL"

if [ -n "$TEACHER_ID" ]; then
  teacher_detail=$(test_endpoint "GET" "$BASE_URL/teachers/$TEACHER_ID" "$AUTH_HEADERS")
  status_code=$?
  [ $status_code -eq 200 ] && log_test "Get Teacher Details" "PASS" || log_test "Get Teacher Details" "FAIL"
  
  teacher_update=$(test_endpoint "PUT" "$BASE_URL/teachers/$TEACHER_ID" "$AUTH_HEADERS" '{"phone":"+234-800-5678"}')
  status_code=$?
  [ $status_code -eq 200 ] && log_test "Update Teacher" "PASS" || log_test "Update Teacher" "FAIL"
fi

# Test Student Management
echo -e "\n${CYAN}[3] STUDENT MANAGEMENT${NC}"
ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

if [ -n "$ACADEMIC_YEAR_ID" ] && [ -n "$TERM_ID" ]; then
  class=$(test_endpoint "POST" "$BASE_URL/classes" "$AUTH_HEADERS" "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
  CLASS_ID=$(echo "$class" | jq -r '.id // empty' 2>/dev/null)
  
  if [ -n "$CLASS_ID" ]; then
    student=$(test_endpoint "POST" "$BASE_URL/students" "$AUTH_HEADERS" "{\"first_name\":\"Jane\",\"last_name\":\"Doe\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"female\"}")
    status_code=$?
    STUDENT_ID=$(echo "$student" | jq -r '.student.id // empty' 2>/dev/null)
    [ $status_code -eq 201 ] && [ -n "$STUDENT_ID" ] && log_test "Create Student" "PASS" || log_test "Create Student" "FAIL"
    
    if [ -n "$STUDENT_ID" ]; then
      student_detail=$(test_endpoint "GET" "$BASE_URL/students/$STUDENT_ID" "$AUTH_HEADERS")
      status_code=$?
      [ $status_code -eq 200 ] && log_test "Get Student Details" "PASS" || log_test "Get Student Details" "FAIL"
      
      student_update=$(test_endpoint "PUT" "$BASE_URL/students/$STUDENT_ID" "$AUTH_HEADERS" '{"phone":"+234-800-1111"}')
      status_code=$?
      [ $status_code -eq 200 ] && log_test "Update Student" "PASS" || log_test "Update Student" "FAIL"
    fi
  fi
fi

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1

# Summary
echo ""
echo -e "${CYAN}=========================================${NC}"
echo -e "${CYAN}           FINAL SUMMARY                 ${NC}"
echo -e "${CYAN}=========================================${NC}"
echo -e "${GREEN}✅ Passed: $PASSED${NC}"
echo -e "${RED}❌ Failed: $FAILED${NC}"
echo -e "${YELLOW}⚠️  Skipped: $SKIPPED${NC}"
echo -e "${BLUE}Total: $((PASSED + FAILED + SKIPPED))${NC}"

if [ $FAILED -eq 0 ]; then
  echo -e "\n${GREEN}🎉 ALL TESTS PASSED!${NC}"
  exit 0
else
  echo -e "\n${RED}⚠️  $FAILED test(s) failed${NC}"
  exit 1
fi
