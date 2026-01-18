#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

BASE_URL="http://localhost:8000/api/v1"
PASSED=0
FAILED=0

test_api() {
  local name="$1"
  local method="$2"
  local url="$3"
  local data="$4"
  
  if [ "$method" = "GET" ]; then
    response=$(curl -s -w "\nHTTP:%{http_code}" -X GET "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  elif [ "$method" = "POST" ]; then
    response=$(curl -s -w "\nHTTP:%{http_code}" -X POST "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "PUT" ]; then
    response=$(curl -s -w "\nHTTP:%{http_code}" -X PUT "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" -d "$data")
  elif [ "$method" = "DELETE" ]; then
    response=$(curl -s -w "\nHTTP:%{http_code}" -X DELETE "$url" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN")
  fi
  
  code=$(echo "$response" | grep "HTTP:" | cut -d: -f2)
  
  if [ "$code" = "200" ] || [ "$code" = "201" ]; then
    echo -e "${GREEN}✅ $name${NC}"
    ((PASSED++))
    return 0
  elif [ "$code" = "404" ] || [ "$code" = "500" ]; then
    echo -e "${RED}❌ $name (HTTP $code - Not Implemented)${NC}"
    ((FAILED++))
    return 1
  else
    echo -e "${RED}❌ $name (HTTP $code)${NC}"
    ((FAILED++))
    return 1
  fi
}

# Setup
echo -e "${CYAN}=== SETUP ===${NC}"
login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}')
SUPER_TOKEN=$(echo "$login" | jq -r '.token')

timestamp=$(date +%s)
school=$(curl -s -X POST "$BASE_URL/schools" -H "Authorization: Bearer $SUPER_TOKEN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Test $timestamp\",\"subdomain\":\"test$timestamp\",\"email\":\"admin@test.com\",\"phone\":\"+234-800\",\"address\":\"Test\",\"plan_id\":1}")
SCHOOL_ID=$(echo "$school" | jq -r '.school.id')
SUBDOMAIN=$(echo "$school" | jq -r '.tenant.subdomain')
ADMIN_EMAIL=$(echo "$school" | jq -r '.tenant.admin_credentials.email')
ADMIN_PASSWORD=$(echo "$school" | jq -r '.tenant.admin_credentials.password')

admin_login=$(curl -s -X POST "$BASE_URL/auth/login" -H "Content-Type: application/json" -H "X-Subdomain: $SUBDOMAIN" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$admin_login" | jq -r '.token')
echo -e "${GREEN}Setup complete${NC}\n"

# Get prerequisites
ACADEMIC_YEAR_ID=$(curl -s "$BASE_URL/academic-years" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')
TERM_ID=$(curl -s "$BASE_URL/terms" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" | jq -r '.[0].id')

# Create test data
class=$(curl -s -X POST "$BASE_URL/classes" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"name\":\"Grade 1\",\"academic_year_id\":$ACADEMIC_YEAR_ID,\"term_id\":$TERM_ID,\"capacity\":30}")
CLASS_ID=$(echo "$class" | jq -r '.id')

student=$(curl -s -X POST "$BASE_URL/students" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d "{\"first_name\":\"John\",\"last_name\":\"Doe\",\"class_id\":$CLASS_ID,\"date_of_birth\":\"2010-08-20\",\"gender\":\"male\"}")
STUDENT_ID=$(echo "$student" | jq -r '.student.id')

teacher=$(curl -s -X POST "$BASE_URL/teachers" -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Subdomain: $SUBDOMAIN" -H "Content-Type: application/json" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@test.com","phone":"+234-800","date_of_birth":"1985-05-15","gender":"female","employment_date":"2025-01-01"}')
TEACHER_ID=$(echo "$teacher" | jq -r '.teacher.id')

# Test all features
echo -e "${CYAN}=== 1. ASSESSMENT & GRADING ===${NC}"
test_api "List Grading Systems" "GET" "$BASE_URL/assessments/grading-systems"
test_api "Get Default Grading System" "GET" "$BASE_URL/assessments/grading-systems/default"
test_api "List Continuous Assessments" "GET" "$BASE_URL/assessments/continuous-assessments"
test_api "List Psychomotor Assessments" "GET" "$BASE_URL/assessments/psychomotor-assessments/class/$CLASS_ID?term_id=$TERM_ID&academic_year_id=$ACADEMIC_YEAR_ID"

echo -e "\n${CYAN}=== 2. RESULTS & REPORT CARDS ===${NC}"
test_api "Get Class Results" "GET" "$BASE_URL/assessments/results/class/$CLASS_ID?term_id=$TERM_ID&academic_year_id=$ACADEMIC_YEAR_ID"
test_api "Get Scoreboard" "GET" "$BASE_URL/assessments/scoreboards/class/$CLASS_ID?term_id=$TERM_ID&academic_year_id=$ACADEMIC_YEAR_ID"
test_api "Get Top Performers" "GET" "$BASE_URL/assessments/scoreboards/top-performers?term_id=$TERM_ID&academic_year_id=$ACADEMIC_YEAR_ID"

echo -e "\n${CYAN}=== 3. EXAMS & CBT ===${NC}"
test_api "List Exams" "GET" "$BASE_URL/assessments/exams"
test_api "List Assignments" "GET" "$BASE_URL/assessments/assignments"
test_api "List Quizzes" "GET" "$BASE_URL/quizzes"

echo -e "\n${CYAN}=== 4. TIMETABLE ===${NC}"
test_api "List Timetables" "GET" "$BASE_URL/timetable"
test_api "Get Class Timetable" "GET" "$BASE_URL/timetable/class/$CLASS_ID"
test_api "Get Teacher Timetable" "GET" "$BASE_URL/timetable/teacher/$TEACHER_ID"

echo -e "\n${CYAN}=== 5. ATTENDANCE ===${NC}"
test_api "List Attendance" "GET" "$BASE_URL/attendance"
test_api "Get Student Attendance" "GET" "$BASE_URL/attendance/student/$STUDENT_ID"
test_api "Get Class Attendance" "GET" "$BASE_URL/attendance/class/$CLASS_ID"
test_api "Get Attendance Reports" "GET" "$BASE_URL/attendance/reports"

echo -e "\n${CYAN}=== 6. LIBRARY ===${NC}"
test_api "List Library Books" "GET" "$BASE_URL/library/books"
test_api "Get Borrowed Books" "GET" "$BASE_URL/library/borrowed"
test_api "Get Library Stats" "GET" "$BASE_URL/library/stats"
test_api "List Digital Resources" "GET" "$BASE_URL/library/digital-resources"

echo -e "\n${CYAN}=== 7. FINANCE ===${NC}"
test_api "List Fees" "GET" "$BASE_URL/financial/fees"
test_api "Get Fee Structure" "GET" "$BASE_URL/financial/fees/structure"
test_api "Get Student Fees" "GET" "$BASE_URL/financial/fees/student/$STUDENT_ID"
test_api "List Payments" "GET" "$BASE_URL/financial/payments"
test_api "List Expenses" "GET" "$BASE_URL/financial/expenses"
test_api "List Payroll" "GET" "$BASE_URL/financial/payroll"

echo -e "\n${CYAN}=== 8. HOUSES & SPORTS ===${NC}"
test_api "List Houses" "GET" "$BASE_URL/houses"
test_api "List House Competitions" "GET" "$BASE_URL/houses/competitions"
test_api "List Sports Activities" "GET" "$BASE_URL/sports/activities"
test_api "List Sports Teams" "GET" "$BASE_URL/sports/teams"
test_api "List Sports Events" "GET" "$BASE_URL/sports/events"

echo -e "\n${CYAN}=== 9. COMMUNICATION ===${NC}"
test_api "List Messages" "GET" "$BASE_URL/communication/messages"
test_api "List Notifications" "GET" "$BASE_URL/communication/notifications"

echo -e "\n${CYAN}=== 10. ANALYTICS & PROMOTION ===${NC}"
test_api "Get School Analytics" "GET" "$BASE_URL/assessments/analytics/school?term_id=$TERM_ID&academic_year_id=$ACADEMIC_YEAR_ID"
test_api "Get Class Analytics" "GET" "$BASE_URL/assessments/analytics/class/$CLASS_ID?term_id=$TERM_ID&academic_year_id=$ACADEMIC_YEAR_ID"
test_api "List Promotions" "GET" "$BASE_URL/assessments/promotions"
test_api "Get Promotion Statistics" "GET" "$BASE_URL/assessments/promotions/statistics?academic_year_id=$ACADEMIC_YEAR_ID"

echo -e "\n${CYAN}=== 11. SCHOOL STORIES ===${NC}"
test_api "List Stories" "GET" "$BASE_URL/stories"

echo -e "\n${CYAN}=== 12. LIVESTREAMS ===${NC}"
test_api "List Livestreams" "GET" "$BASE_URL/livestreams"

echo -e "\n${CYAN}=== 13. ACHIEVEMENTS ===${NC}"
test_api "List Achievements" "GET" "$BASE_URL/achievements"
test_api "Get Student Achievements" "GET" "$BASE_URL/achievements/student/$STUDENT_ID"

echo -e "\n${CYAN}=== 14. ARMS ===${NC}"
test_api "List Arms" "GET" "$BASE_URL/arms"

echo -e "\n${CYAN}=== 15. GRADES ===${NC}"
test_api "List Grades" "GET" "$BASE_URL/grades"

# Cleanup
curl -s -X DELETE "$BASE_URL/schools/$SCHOOL_ID?force=true&delete_database=true" -H "Authorization: Bearer $SUPER_TOKEN" > /dev/null 2>&1

echo -e "\n${CYAN}================================${NC}"
echo -e "${GREEN}✅ Passed: $PASSED${NC}"
echo -e "${RED}❌ Failed: $FAILED${NC}"
echo -e "${CYAN}Total: $((PASSED + FAILED))${NC}"
