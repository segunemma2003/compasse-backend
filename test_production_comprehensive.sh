#!/bin/bash

BASE_URL="https://api.compasse.net/api/v1"
SUPER_ADMIN_EMAIL="superadmin@compasse.net"
SUPER_ADMIN_PASSWORD="Nigeria@60"
TIMESTAMP=$(date +%s)
SCHOOL_NAME="TestSchool${TIMESTAMP}"
SUBDOMAIN="testschool${TIMESTAMP}"

echo "========================================="
echo "COMPREHENSIVE PRODUCTION API TESTING"
echo "========================================="
echo "Testing on: api.compasse.net"
echo "Time: $(date)"
echo ""

# Step 1: Super Admin Login
echo "1️⃣  Super Admin Login..."
LOGIN_RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"${SUPER_ADMIN_EMAIL}\",
    \"password\": \"${SUPER_ADMIN_PASSWORD}\"
  }")

SUPER_TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token // empty')

if [ -z "$SUPER_TOKEN" ]; then
  echo "❌ Super Admin login failed"
  echo "$LOGIN_RESPONSE" | jq '.'
  exit 1
fi

echo "✅ Super Admin logged in"
echo ""

# Step 2: Create School
echo "2️⃣  Creating School: $SCHOOL_NAME..."
CREATE_SCHOOL_RESPONSE=$(curl -s -X POST "${BASE_URL}/schools" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -d "{
    \"name\": \"${SCHOOL_NAME}\",
    \"subdomain\": \"${SUBDOMAIN}\",
    \"school\": {
      \"name\": \"${SCHOOL_NAME}\",
      \"address\": \"123 Test Street\",
      \"phone\": \"+1234567890\",
      \"email\": \"info@${SUBDOMAIN}.samschool.com\"
    }
  }")

TENANT_ID=$(echo "$CREATE_SCHOOL_RESPONSE" | jq -r '.tenant.id // empty')
ADMIN_EMAIL=$(echo "$CREATE_SCHOOL_RESPONSE" | jq -r '.tenant.admin_credentials.email // empty')
ADMIN_PASSWORD=$(echo "$CREATE_SCHOOL_RESPONSE" | jq -r '.tenant.admin_credentials.password // empty')

if [ -z "$TENANT_ID" ]; then
  echo "❌ School creation failed"
  echo "$CREATE_SCHOOL_RESPONSE" | jq '.'
  exit 1
fi

echo "✅ School created"
echo "   Admin: $ADMIN_EMAIL"
echo ""

sleep 5

# Step 3: School Admin Login
echo "3️⃣  School Admin Login..."
ADMIN_LOGIN_RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: ${SUBDOMAIN}" \
  -d "{
    \"email\": \"${ADMIN_EMAIL}\",
    \"password\": \"${ADMIN_PASSWORD}\"
  }")

ADMIN_TOKEN=$(echo "$ADMIN_LOGIN_RESPONSE" | jq -r '.token // empty')

if [ -z "$ADMIN_TOKEN" ]; then
  echo "❌ School Admin login failed"
  exit 1
fi

echo "✅ School Admin logged in"
echo ""

# Function to test endpoint
test_api() {
  local method=$1
  local endpoint=$2
  local description=$3
  local data=$4
  
  echo -n "   Testing: $description... "
  
  if [ "$method" = "GET" ]; then
    response=$(curl -s -X GET "${BASE_URL}${endpoint}" \
      -H "Authorization: Bearer $ADMIN_TOKEN" \
      -H "X-Subdomain: ${SUBDOMAIN}")
  elif [ "$method" = "POST" ]; then
    response=$(curl -s -X POST "${BASE_URL}${endpoint}" \
      -H "Authorization: Bearer $ADMIN_TOKEN" \
      -H "X-Subdomain: ${SUBDOMAIN}" \
      -H "Content-Type: application/json" \
      -d "$data")
  fi
  
  if echo "$response" | jq -e '.error' > /dev/null 2>&1; then
    echo "❌"
    return 1
  else
    echo "✅"
    return 0
  fi
}

PASSED=0
FAILED=0

echo "========================================="
echo "TESTING ALL ADMIN APIS (32+ endpoints)"
echo "========================================="
echo ""

# Auth
echo "🔐 AUTH MODULE"
test_api "GET" "/auth/me" "Get Current User" && ((PASSED++)) || ((FAILED++))
echo ""

# Dashboard
echo "📊 DASHBOARD MODULE"
test_api "GET" "/dashboard/stats" "Dashboard Stats" && ((PASSED++)) || ((FAILED++))
echo ""

# Students
echo "👨‍🎓 STUDENTS MODULE"
test_api "GET" "/students" "List Students" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/students/analytics" "Student Analytics" && ((PASSED++)) || ((FAILED++))
echo ""

# Teachers
echo "👨‍🏫 TEACHERS MODULE"
test_api "GET" "/teachers" "List Teachers" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/teachers/analytics" "Teacher Analytics" && ((PASSED++)) || ((FAILED++))
echo ""

# Staff
echo "👔 STAFF MODULE"
test_api "GET" "/staff" "List Staff" && ((PASSED++)) || ((FAILED++))
echo ""

# Parents
echo "👨‍👩‍👧 PARENTS MODULE"
test_api "GET" "/parents" "List Parents" && ((PASSED++)) || ((FAILED++))
echo ""

# Classes
echo "🏫 CLASSES MODULE"
test_api "GET" "/classes" "List Classes" && ((PASSED++)) || ((FAILED++))
echo ""

# Subjects
echo "📚 SUBJECTS MODULE"
test_api "GET" "/subjects" "List Subjects" && ((PASSED++)) || ((FAILED++))
echo ""

# Departments
echo "🏢 DEPARTMENTS MODULE"
test_api "GET" "/departments" "List Departments" && ((PASSED++)) || ((FAILED++))
echo ""

# Academic Years & Terms
echo "📅 ACADEMIC YEARS & TERMS"
test_api "GET" "/academic-years" "List Academic Years" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/terms" "List Terms" && ((PASSED++)) || ((FAILED++))
echo ""

# Timetable
echo "🗓️  TIMETABLE MODULE"
test_api "GET" "/timetable" "List Timetable" && ((PASSED++)) || ((FAILED++))
echo ""

# Attendance
echo "✋ ATTENDANCE MODULE"
test_api "GET" "/attendance" "List Attendance" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/attendance/students" "Student Attendance" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/attendance/teachers" "Teacher Attendance" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/attendance/reports" "Attendance Reports" && ((PASSED++)) || ((FAILED++))
echo ""

# Assignments
echo "📝 ASSIGNMENTS MODULE"
test_api "GET" "/assignments" "List Assignments" && ((PASSED++)) || ((FAILED++))
echo ""

# Exams
echo "📋 EXAMS MODULE"
test_api "GET" "/exams" "List Exams" && ((PASSED++)) || ((FAILED++))
echo ""

# Results
echo "🎯 RESULTS MODULE"
test_api "GET" "/results" "List Results" && ((PASSED++)) || ((FAILED++))
echo ""

# Announcements
echo "📢 ANNOUNCEMENTS MODULE"
test_api "GET" "/announcements" "List Announcements" && ((PASSED++)) || ((FAILED++))
echo ""

# Transport
echo "🚌 TRANSPORT MODULE"
test_api "GET" "/transport/routes" "List Routes" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/transport/vehicles" "List Vehicles" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/transport/drivers" "List Drivers" && ((PASSED++)) || ((FAILED++))
echo ""

# Houses
echo "🏠 HOUSES MODULE"
test_api "GET" "/houses" "List Houses" && ((PASSED++)) || ((FAILED++))
echo ""

# Sports
echo "⚽ SPORTS MODULE"
test_api "GET" "/sports/activities" "List Activities" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/sports/teams" "List Teams" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/sports/events" "List Events" && ((PASSED++)) || ((FAILED++))
echo ""

# Inventory
echo "📦 INVENTORY MODULE"
test_api "GET" "/inventory/categories" "List Categories" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/inventory/items" "List Items" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/inventory/transactions" "List Transactions" && ((PASSED++)) || ((FAILED++))
echo ""

# Library
echo "📖 LIBRARY MODULE"
test_api "GET" "/library/books" "List Books" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/library/borrows" "List Borrows" && ((PASSED++)) || ((FAILED++))
echo ""

# Finance
echo "💰 FINANCE MODULE"
test_api "GET" "/finance/fees" "List Fees" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/finance/payments" "List Payments" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/finance/expenses" "List Expenses" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/finance/reports" "Finance Reports" && ((PASSED++)) || ((FAILED++))
echo ""

# Communication
echo "💬 COMMUNICATION MODULE"
test_api "GET" "/notifications" "List Notifications" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/messages" "List Messages" && ((PASSED++)) || ((FAILED++))
echo ""

# Reports
echo "📊 REPORTS MODULE"
test_api "GET" "/reports/academic" "Academic Reports" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/reports/financial" "Financial Reports" && ((PASSED++)) || ((FAILED++))
echo ""

# Settings
echo "⚙️  SETTINGS MODULE"
test_api "GET" "/settings" "Get Settings" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/settings/school" "Get School Settings" && ((PASSED++)) || ((FAILED++))
test_api "GET" "/settings/modules" "List Modules" && ((PASSED++)) || ((FAILED++))
echo ""

# Subscription
echo "💳 SUBSCRIPTION MODULE"
test_api "GET" "/subscription/status" "Subscription Status" && ((PASSED++)) || ((FAILED++))
echo ""

echo "========================================="
echo "📊 TEST RESULTS SUMMARY"
echo "========================================="
echo "Total Tests: $((PASSED + FAILED))"
echo "✅ Passed: $PASSED"
echo "❌ Failed: $FAILED"
echo ""

PERCENTAGE=$((PASSED * 100 / (PASSED + FAILED)))
echo "Success Rate: ${PERCENTAGE}%"
echo ""

if [ $FAILED -eq 0 ]; then
  echo "🎉 ALL TESTS PASSED!"
  echo "========================================="
  echo ""
  EXIT_CODE=0
else
  echo "⚠️  Some tests failed"
  echo "========================================="
  echo ""
  EXIT_CODE=1
fi

echo "📋 Test School Details:"
echo "   Subdomain: $SUBDOMAIN"
echo "   Admin Email: $ADMIN_EMAIL"
echo "   Admin Password: $ADMIN_PASSWORD"
echo "========================================="

exit $EXIT_CODE


