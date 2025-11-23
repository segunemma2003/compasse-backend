#!/bin/bash

BASE_URL="https://api.compasse.net/api/v1"
SUPER_ADMIN_EMAIL="superadmin@compasse.net"
SUPER_ADMIN_PASSWORD="Nigeria@60"
TIMESTAMP=$(date +%s)
SCHOOL_NAME="TestSchool${TIMESTAMP}"
SUBDOMAIN="testschool${TIMESTAMP}"

echo "========================================="
echo "DETAILED API TESTING"
echo "========================================="
echo ""

# Super Admin Login
LOGIN_RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"${SUPER_ADMIN_EMAIL}\", \"password\": \"${SUPER_ADMIN_PASSWORD}\"}")

SUPER_TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token // empty')
if [ -z "$SUPER_TOKEN" ]; then exit 1; fi

# Create School
CREATE_SCHOOL_RESPONSE=$(curl -s -X POST "${BASE_URL}/schools" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -d "{\"name\": \"${SCHOOL_NAME}\", \"subdomain\": \"${SUBDOMAIN}\", \"school\": {\"name\": \"${SCHOOL_NAME}\", \"address\": \"123 Test\", \"phone\": \"+1234567890\", \"email\": \"info@${SUBDOMAIN}.com\"}}")

TENANT_ID=$(echo "$CREATE_SCHOOL_RESPONSE" | jq -r '.tenant.id // empty')
ADMIN_EMAIL=$(echo "$CREATE_SCHOOL_RESPONSE" | jq -r '.tenant.admin_credentials.email // empty')
ADMIN_PASSWORD=$(echo "$CREATE_SCHOOL_RESPONSE" | jq -r '.tenant.admin_credentials.password // empty')

if [ -z "$TENANT_ID" ]; then exit 1; fi
sleep 5

# School Admin Login
ADMIN_LOGIN_RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: ${SUBDOMAIN}" \
  -d "{\"email\": \"${ADMIN_EMAIL}\", \"password\": \"${ADMIN_PASSWORD}\"}")

ADMIN_TOKEN=$(echo "$ADMIN_LOGIN_RESPONSE" | jq -r '.token // empty')
if [ -z "$ADMIN_TOKEN" ]; then exit 1; fi

# Test function with detailed error reporting
test_api() {
  local method=$1
  local endpoint=$2
  local description=$3
  
  echo -n "Testing: $description... "
  
  response=$(curl -s -X GET "${BASE_URL}${endpoint}" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "X-Subdomain: ${SUBDOMAIN}")
  
  if echo "$response" | jq -e '.error' > /dev/null 2>&1; then
    echo "❌ FAILED"
    echo "   Error: $(echo "$response" | jq -r '.error')"
    echo "   Message: $(echo "$response" | jq -r '.message')"
    echo ""
    return 1
  else
    echo "✅"
    return 0
  fi
}

PASSED=0
FAILED=0
FAILED_TESTS=""

# Run all tests
echo "Running all 47 tests..."
echo ""

test_api "GET" "/auth/me" "Auth: Get Current User" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="auth/me\n"; }
test_api "GET" "/dashboard/stats" "Dashboard: Stats" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="dashboard/stats\n"; }
test_api "GET" "/students" "Students: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="students\n"; }
test_api "GET" "/students/analytics" "Students: Analytics" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="students/analytics\n"; }
test_api "GET" "/teachers" "Teachers: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="teachers\n"; }
test_api "GET" "/teachers/analytics" "Teachers: Analytics" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="teachers/analytics\n"; }
test_api "GET" "/staff" "Staff: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="staff\n"; }
test_api "GET" "/parents" "Parents: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="parents\n"; }
test_api "GET" "/classes" "Classes: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="classes\n"; }
test_api "GET" "/subjects" "Subjects: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="subjects\n"; }
test_api "GET" "/departments" "Departments: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="departments\n"; }
test_api "GET" "/academic-years" "Academic Years: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="academic-years\n"; }
test_api "GET" "/terms" "Terms: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="terms\n"; }
test_api "GET" "/timetable" "Timetable: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="timetable\n"; }
test_api "GET" "/attendance" "Attendance: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="attendance\n"; }
test_api "GET" "/attendance/students" "Attendance: Students" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="attendance/students\n"; }
test_api "GET" "/attendance/teachers" "Attendance: Teachers" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="attendance/teachers\n"; }
test_api "GET" "/attendance/reports" "Attendance: Reports" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="attendance/reports\n"; }
test_api "GET" "/assignments" "Assignments: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="assignments\n"; }
test_api "GET" "/exams" "Exams: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="exams\n"; }
test_api "GET" "/results" "Results: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="results\n"; }
test_api "GET" "/announcements" "Announcements: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="announcements\n"; }
test_api "GET" "/transport/routes" "Transport: Routes" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="transport/routes\n"; }
test_api "GET" "/transport/vehicles" "Transport: Vehicles" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="transport/vehicles\n"; }
test_api "GET" "/transport/drivers" "Transport: Drivers" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="transport/drivers\n"; }
test_api "GET" "/houses" "Houses: List" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="houses\n"; }
test_api "GET" "/sports/activities" "Sports: Activities" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="sports/activities\n"; }
test_api "GET" "/sports/teams" "Sports: Teams" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="sports/teams\n"; }
test_api "GET" "/sports/events" "Sports: Events" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="sports/events\n"; }
test_api "GET" "/inventory/categories" "Inventory: Categories" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="inventory/categories\n"; }
test_api "GET" "/inventory/items" "Inventory: Items" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="inventory/items\n"; }
test_api "GET" "/inventory/transactions" "Inventory: Transactions" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="inventory/transactions\n"; }
test_api "GET" "/library/books" "Library: Books" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="library/books\n"; }
test_api "GET" "/library/borrows" "Library: Borrows" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="library/borrows\n"; }
test_api "GET" "/finance/fees" "Finance: Fees" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="finance/fees\n"; }
test_api "GET" "/finance/payments" "Finance: Payments" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="finance/payments\n"; }
test_api "GET" "/finance/expenses" "Finance: Expenses" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="finance/expenses\n"; }
test_api "GET" "/finance/reports" "Finance: Reports" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="finance/reports\n"; }
test_api "GET" "/notifications" "Communication: Notifications" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="notifications\n"; }
test_api "GET" "/messages" "Communication: Messages" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="messages\n"; }
test_api "GET" "/reports/academic" "Reports: Academic" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="reports/academic\n"; }
test_api "GET" "/reports/financial" "Reports: Financial" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="reports/financial\n"; }
test_api "GET" "/settings" "Settings: Get" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="settings\n"; }
test_api "GET" "/settings/school" "Settings: School" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="settings/school\n"; }
test_api "GET" "/settings/modules" "Settings: Modules" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="settings/modules\n"; }
test_api "GET" "/subscription/status" "Subscription: Status" && ((PASSED++)) || { ((FAILED++)); FAILED_TESTS+="subscription/status\n"; }

echo ""
echo "========================================="
echo "RESULTS"
echo "========================================="
echo "Total: $((PASSED + FAILED))"
echo "Passed: $PASSED"
echo "Failed: $FAILED"
echo ""

if [ $FAILED -gt 0 ]; then
  echo "Failed endpoints:"
  echo -e "$FAILED_TESTS"
fi
