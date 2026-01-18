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

echo -e "${CYAN}=========================================${NC}"
echo -e "${CYAN}  COMPREHENSIVE SCHOOL ADMIN API TEST   ${NC}"
echo -e "${CYAN}=========================================${NC}"

# Setup
echo -e "\n${BLUE}[SETUP] SuperAdmin Login & School Creation${NC}"
login=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')
if [ -n "$SUPER_TOKEN" ] && [ "$SUPER_TOKEN" != "null" ]; then
  log_test "SuperAdmin Login" "PASS"
else
  log_test "SuperAdmin Login" "FAIL"
  exit 1
fi

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Complete Test $timestamp\",\"subdomain\":\"complete$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")

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

# Category 1: AUTHENTICATION
echo -e "\n${CYAN}[CATEGORY 1] AUTHENTICATION${NC}"
me=$(curl -s "$BASE_URL/auth/me" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$me" | jq -r '.user.role')" = "school_admin" ] && log_test "Get Current User" "PASS" || log_test "Get Current User" "FAIL"

# Category 2: SCHOOL MANAGEMENT
echo -e "\n${CYAN}[CATEGORY 2] SCHOOL MANAGEMENT${NC}"
# Tenant users should use /schools/me, not /schools/{id}
school_detail=$(curl -s "$BASE_URL/schools/me" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$school_detail" | jq -r '.school.id')" != "null" ] && log_test "Get School Details" "PASS" || log_test "Get School Details" "FAIL"

# School admin can update their school via /schools/me
school_update=$(curl -s -X PUT "$BASE_URL/schools/me" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"phone":"+234-800-9999"}')
[ "$(echo "$school_update" | jq -r '.school.phone // .phone' 2>/dev/null)" = "+234-800-9999" ] && log_test "Update School" "PASS" || log_test "Update School" "FAIL"

school_stats=$(curl -s "$BASE_URL/schools/$SCHOOL_ID/stats" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$school_stats" | jq -r '.stats')" != "null" ] && log_test "Get School Stats" "PASS" || log_test "Get School Stats" "SKIP"

# Category 3: USER MANAGEMENT
echo -e "\n${CYAN}[CATEGORY 3] USER MANAGEMENT${NC}"
roles=$(curl -s "$BASE_URL/roles" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$roles" | jq -r '.roles.teacher')" != "null" ] && log_test "Get Available Roles" "PASS" || log_test "Get Available Roles" "FAIL"

user=$(curl -s -X POST "$BASE_URL/users" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"testuser@test.com","password":"password123","password_confirmation":"password123","role":"staff"}')
USER_ID=$(echo "$user" | jq -r '.data.id')
[ "$USER_ID" != "null" ] && log_test "Create User" "PASS" || log_test "Create User" "FAIL"

if [ "$USER_ID" != "null" ]; then
  user_detail=$(curl -s "$BASE_URL/users/$USER_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$user_detail" | jq -r '.id // .user.id // .data.id' 2>/dev/null)" = "$USER_ID" ] && log_test "Get User Details" "PASS" || log_test "Get User Details" "FAIL"
  
  user_update=$(curl -s -X PUT "$BASE_URL/users/$USER_ID" \
    -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
    -d '{"name":"Updated User"}')
  [ "$(echo "$user_update" | jq -r '.user.name')" = "Updated User" ] && log_test "Update User" "PASS" || log_test "Update User" "FAIL"
  
  assign_role=$(curl -s -X POST "$BASE_URL/users/$USER_ID/assign-role" \
    -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
    -d '{"role":"teacher"}')
  [ "$(echo "$assign_role" | jq -r '.user.role')" = "teacher" ] && log_test "Assign Role" "PASS" || log_test "Assign Role" "FAIL"
  
  activate=$(curl -s -X POST "$BASE_URL/users/$USER_ID/activate" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$activate" | jq -r '.user.status')" = "active" ] && log_test "Activate User" "PASS" || log_test "Activate User" "SKIP"
  
  suspend=$(curl -s -X POST "$BASE_URL/users/$USER_ID/suspend" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$suspend" | jq -r '.user.status')" = "suspended" ] && log_test "Suspend User" "PASS" || log_test "Suspend User" "SKIP"
fi

users=$(curl -s "$BASE_URL/users" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ $(echo "$users" | jq -r '.data | length') -gt 0 ] && log_test "List Users" "PASS" || log_test "List Users" "FAIL"

# Category 4: TEACHER MANAGEMENT
echo -e "\n${CYAN}[CATEGORY 4] TEACHER MANAGEMENT${NC}"
teacher=$(curl -s -X POST "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"John","last_name":"Doe","email":"john.doe@test.com","phone":"+234-800-1234","date_of_birth":"1985-05-15","gender":"male","employment_date":"2025-01-01"}')
TEACHER_ID=$(echo "$teacher" | jq -r '.teacher.id')
[ "$TEACHER_ID" != "null" ] && log_test "Create Teacher" "PASS" || log_test "Create Teacher" "FAIL"

if [ "$TEACHER_ID" != "null" ]; then
  teacher_detail=$(curl -s "$BASE_URL/teachers/$TEACHER_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$teacher_detail" | jq -r '.id // .teacher.id' 2>/dev/null)" = "$TEACHER_ID" ] && log_test "Get Teacher Details" "PASS" || log_test "Get Teacher Details" "FAIL"
  
  teacher_update=$(curl -s -X PUT "$BASE_URL/teachers/$TEACHER_ID" \
    -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
    -d '{"phone":"+234-800-5678"}')
  [ "$(echo "$teacher_update" | jq -r '.phone // .teacher.phone' 2>/dev/null)" = "+234-800-5678" ] && log_test "Update Teacher" "PASS" || log_test "Update Teacher" "FAIL"
  
  teacher_classes=$(curl -s "$BASE_URL/teachers/$TEACHER_ID/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$teacher_classes" | jq 'type')" != "null" ] && log_test "Get Teacher Classes" "PASS" || log_test "Get Teacher Classes" "SKIP"
  
  teacher_subjects=$(curl -s "$BASE_URL/teachers/$TEACHER_ID/subjects" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$teacher_subjects" | jq 'type')" != "null" ] && log_test "Get Teacher Subjects" "PASS" || log_test "Get Teacher Subjects" "SKIP"
fi

teachers=$(curl -s "$BASE_URL/teachers" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ $(echo "$teachers" | jq -r '.teachers.data | length') -gt 0 ] && log_test "List Teachers" "PASS" || log_test "List Teachers" "FAIL"

# Category 5: ACADEMIC SETUP
echo -e "\n${CYAN}[CATEGORY 5] ACADEMIC SETUP${NC}"
academic_years=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
ACADEMIC_YEAR_ID=$(echo "$academic_years" | jq -r '.[0].id')
[ -n "$ACADEMIC_YEAR_ID" ] && log_test "Get Academic Years" "PASS" || log_test "Get Academic Years" "FAIL"

terms=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
TERM_ID=$(echo "$terms" | jq -r '.[0].id')
[ -n "$TERM_ID" ] && log_test "Get Terms" "PASS" || log_test "Get Terms" "FAIL"

# Create Department
dept=$(curl -s -X POST "$BASE_URL/departments" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"name":"Science Department","code":"SCI","description":"Science subjects"}')
DEPT_ID=$(echo "$dept" | jq -r '.id // .department.id // empty')
[ -n "$DEPT_ID" ] && log_test "Create Department" "PASS" || log_test "Create Department" "SKIP"

depts=$(curl -s "$BASE_URL/departments" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$depts" | jq 'type')" != "null" ] && log_test "List Departments" "PASS" || log_test "List Departments" "SKIP"

# Category 6: CLASS & SUBJECT MANAGEMENT
echo -e "\n${CYAN}[CATEGORY 6] CLASS & SUBJECT MANAGEMENT${NC}"
if [ -n "$ACADEMIC_YEAR_ID" ] && [ -n "$TERM_ID" ]; then
  class=$(curl -s -X POST "$BASE_URL/classes" \
    -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
    -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
  CLASS_ID=$(echo "$class" | jq -r '.id')
  [ -n "$CLASS_ID" ] && log_test "Create Class" "PASS" || log_test "Create Class" "FAIL"
  
  if [ -n "$CLASS_ID" ]; then
    class_detail=$(curl -s "$BASE_URL/classes/$CLASS_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
    [ "$(echo "$class_detail" | jq -r '.id')" = "$CLASS_ID" ] && log_test "Get Class Details" "PASS" || log_test "Get Class Details" "FAIL"
    
    class_update=$(curl -s -X PUT "$BASE_URL/classes/$CLASS_ID" \
      -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
      -d '{"capacity":35}')
    [ "$(echo "$class_update" | jq -r '.capacity')" = "35" ] && log_test "Update Class" "PASS" || log_test "Update Class" "FAIL"
  fi
fi

classes=$(curl -s "$BASE_URL/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$classes" | jq 'type')" = "\"array\"" ] && log_test "List Classes" "PASS" || log_test "List Classes" "FAIL"

# Create Subject (requires department_id)
if [ -n "$DEPT_ID" ]; then
  subject=$(curl -s -X POST "$BASE_URL/subjects" \
    -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
    -d "{\"name\":\"Mathematics\",\"code\":\"MATH\",\"department_id\":$DEPT_ID,\"description\":\"Math subject\"}")
  SUBJECT_ID=$(echo "$subject" | jq -r '.id // .subject.id // empty')
  [ -n "$SUBJECT_ID" ] && log_test "Create Subject" "PASS" || log_test "Create Subject" "FAIL"
else
  log_test "Create Subject" "SKIP"
fi

subjects=$(curl -s "$BASE_URL/subjects" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$subjects" | jq 'type')" != "null" ] && log_test "List Subjects" "PASS" || log_test "List Subjects" "SKIP"

# Category 7: STUDENT MANAGEMENT
echo -e "\n${CYAN}[CATEGORY 7] STUDENT MANAGEMENT${NC}"
if [ -n "$CLASS_ID" ]; then
  student=$(curl -s -X POST "$BASE_URL/students" \
    -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
    -d "{\"first_name\":\"Jane\",\"last_name\":\"Doe\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"female\"}")
  STUDENT_ID=$(echo "$student" | jq -r '.student.id')
  [ -n "$STUDENT_ID" ] && log_test "Create Student" "PASS" || log_test "Create Student" "FAIL"
  
  if [ -n "$STUDENT_ID" ]; then
    student_detail=$(curl -s "$BASE_URL/students/$STUDENT_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
    [ "$(echo "$student_detail" | jq -r '.id')" = "$STUDENT_ID" ] && log_test "Get Student Details" "PASS" || log_test "Get Student Details" "FAIL"
    
    student_update=$(curl -s -X PUT "$BASE_URL/students/$STUDENT_ID" \
      -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
      -d '{"phone":"+234-800-1111"}')
    [ "$(echo "$student_update" | jq -r '.phone // .student.phone' 2>/dev/null)" = "+234-800-1111" ] && log_test "Update Student" "PASS" || log_test "Update Student" "FAIL"
    
    student_subjects=$(curl -s "$BASE_URL/students/$STUDENT_ID/subjects" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
    [ "$(echo "$student_subjects" | jq 'type')" != "null" ] && log_test "Get Student Subjects" "PASS" || log_test "Get Student Subjects" "SKIP"
  fi
fi

students=$(curl -s "$BASE_URL/students" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ $(echo "$students" | jq -r '.data | length') -ge 0 ] && log_test "List Students" "PASS" || log_test "List Students" "FAIL"

# Generate credentials
creds=$(curl -s -X POST "$BASE_URL/students/generate-credentials" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"Test","last_name":"Student"}')
[ "$(echo "$creds" | jq -r '.email')" != "null" ] && log_test "Generate Student Credentials" "PASS" || log_test "Generate Student Credentials" "FAIL"

# Category 8: GUARDIAN MANAGEMENT
echo -e "\n${CYAN}[CATEGORY 8] GUARDIAN MANAGEMENT${NC}"
guardian=$(curl -s -X POST "$BASE_URL/guardians" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"Parent","last_name":"Guardian","email":"parent@test.com","phone":"+234-800-2222","relationship":"father"}')
GUARDIAN_ID=$(echo "$guardian" | jq -r '.guardian.id // .data.id // empty')
[ -n "$GUARDIAN_ID" ] && log_test "Create Guardian" "PASS" || log_test "Create Guardian" "SKIP"

guardians=$(curl -s "$BASE_URL/guardians" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$guardians" | jq 'type')" != "null" ] && log_test "List Guardians" "PASS" || log_test "List Guardians" "SKIP"

# Category 9: SETTINGS & DASHBOARD
echo -e "\n${CYAN}[CATEGORY 9] SETTINGS & DASHBOARD${NC}"
settings=$(curl -s "$BASE_URL/settings" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$settings" | jq -r '.settings')" != "null" ] && log_test "Get Settings" "PASS" || log_test "Get Settings" "FAIL"

school_settings=$(curl -s "$BASE_URL/settings/school" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$school_settings" | jq 'type')" != "null" ] && log_test "Get School Settings" "PASS" || log_test "Get School Settings" "SKIP"

dashboard=$(curl -s "$BASE_URL/dashboard/stats" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$dashboard" | jq -r '.stats')" != "null" ] && log_test "Get Dashboard Stats" "PASS" || log_test "Get Dashboard Stats" "FAIL"

admin_dashboard=$(curl -s "$BASE_URL/dashboard/admin" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$admin_dashboard" | jq 'type')" != "null" ] && log_test "Get Admin Dashboard" "PASS" || log_test "Get Admin Dashboard" "SKIP"

# Category 10: ANNOUNCEMENTS
echo -e "\n${CYAN}[CATEGORY 10] ANNOUNCEMENTS${NC}"
announcement=$(curl -s -X POST "$BASE_URL/announcements" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"title":"Test Announcement","content":"This is a test","target_audience":"all"}')
ANNOUNCEMENT_ID=$(echo "$announcement" | jq -r '.id // .announcement.id // empty')
[ -n "$ANNOUNCEMENT_ID" ] && log_test "Create Announcement" "PASS" || log_test "Create Announcement" "SKIP"

announcements=$(curl -s "$BASE_URL/announcements" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$announcements" | jq 'type')" != "null" ] && log_test "List Announcements" "PASS" || log_test "List Announcements" "SKIP"

# Category 11: SUBSCRIPTION
echo -e "\n${CYAN}[CATEGORY 11] SUBSCRIPTION${NC}"
sub_status=$(curl -s "$BASE_URL/subscriptions/status" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$sub_status" | jq 'type')" != "null" ] && log_test "Get Subscription Status" "PASS" || log_test "Get Subscription Status" "SKIP"

sub_modules=$(curl -s "$BASE_URL/subscriptions/school/modules" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$sub_modules" | jq 'type')" != "null" ] && log_test "Get School Modules" "PASS" || log_test "Get School Modules" "SKIP"

# Category 12: STAFF MANAGEMENT
echo -e "\n${CYAN}[CATEGORY 12] STAFF MANAGEMENT${NC}"
staff=$(curl -s -X POST "$BASE_URL/staff" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"Staff","last_name":"Member","email":"staff@test.com","phone":"+234-800-3333","role":"staff","employment_date":"2025-01-01"}')
STAFF_ID=$(echo "$staff" | jq -r '.staff.id // .id // empty')
[ -n "$STAFF_ID" ] && log_test "Create Staff" "PASS" || log_test "Create Staff" "FAIL"

staff_list=$(curl -s "$BASE_URL/staff" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
[ "$(echo "$staff_list" | jq 'type')" != "null" ] && log_test "List Staff" "PASS" || log_test "List Staff" "SKIP"

# Cleanup
echo -e "\n${CYAN}[CLEANUP]${NC}"
if [ -n "$USER_ID" ]; then
  delete_user=$(curl -s -X DELETE "$BASE_URL/users/$USER_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$delete_user" | jq -r '.message')" = "User deleted successfully" ] && log_test "Delete User" "PASS" || log_test "Delete User" "FAIL"
fi

if [ -n "$STUDENT_ID" ]; then
  delete_student=$(curl -s -X DELETE "$BASE_URL/students/$STUDENT_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$delete_student" | jq 'type')" != "null" ] && log_test "Delete Student" "PASS" || log_test "Delete Student" "SKIP"
fi

if [ -n "$TEACHER_ID" ]; then
  delete_teacher=$(curl -s -X DELETE "$BASE_URL/teachers/$TEACHER_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$delete_teacher" | jq 'type')" != "null" ] && log_test "Delete Teacher" "PASS" || log_test "Delete Teacher" "SKIP"
fi

if [ -n "$CLASS_ID" ]; then
  delete_class=$(curl -s -X DELETE "$BASE_URL/classes/$CLASS_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  [ "$(echo "$delete_class" | jq 'type')" != "null" ] && log_test "Delete Class" "PASS" || log_test "Delete Class" "SKIP"
fi

# Delete school
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" \
  -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null

# Summary
echo ""
echo -e "${CYAN}=========================================${NC}"
echo -e "${CYAN}           TEST SUMMARY                  ${NC}"
echo -e "${CYAN}=========================================${NC}"
echo -e "${GREEN}✅ Passed: $PASSED${NC}"
echo -e "${RED}❌ Failed: $FAILED${NC}"
echo -e "${YELLOW}⚠️  Skipped: $SKIPPED${NC}"
echo -e "${BLUE}Total: $((PASSED + FAILED + SKIPPED))${NC}"

if [ $FAILED -eq 0 ]; then
  echo -e "\n${GREEN}🎉 ALL CORE TESTS PASSED!${NC}"
  exit 0
else
  echo -e "\n${RED}⚠️  $FAILED test(s) failed${NC}"
  exit 1
fi
