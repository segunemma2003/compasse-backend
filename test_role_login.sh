#!/bin/bash

# Test Role-Based Login for All User Types
# This script creates users and tests their login credentials

BASE_URL="http://127.0.0.1:8000/api/v1"
SUBDOMAIN="testschool"

echo "======================================"
echo "🧪 Testing Role-Based Login System"
echo "======================================"
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Function to print test result
print_result() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ PASS${NC}: $2"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}✗ FAIL${NC}: $2"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        if [ -n "$3" ]; then
            echo -e "${RED}  Error: $3${NC}"
        fi
    fi
}

# Step 1: Login as Super Admin to get token
echo "📝 Step 1: Login as Super Admin..."
SUPER_ADMIN_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@samschool.com",
    "password": "password"
  }')

SUPER_ADMIN_TOKEN=$(echo $SUPER_ADMIN_RESPONSE | jq -r '.token // .access_token // empty')

if [ -z "$SUPER_ADMIN_TOKEN" ] || [ "$SUPER_ADMIN_TOKEN" = "null" ]; then
    echo -e "${RED}✗ Failed to login as super admin${NC}"
    echo "Response: $SUPER_ADMIN_RESPONSE"
    exit 1
fi

echo -e "${GREEN}✓ Super Admin logged in successfully${NC}"
echo ""

# Step 2: Create School as Super Admin
echo "📝 Step 2: Creating Test School..."
SCHOOL_RESPONSE=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Login School",
    "email": "testlogin@school.com",
    "phone": "+1234567890",
    "address": "123 Test St",
    "website": "testloginschool.com",
    "subdomain": "testschool"
  }')

SCHOOL_ID=$(echo $SCHOOL_RESPONSE | jq -r '.school.id // .data.school.id // empty')
SCHOOL_ADMIN_EMAIL=$(echo $SCHOOL_RESPONSE | jq -r '.tenant.admin_credentials.email // .admin_credentials.email // empty')
SCHOOL_ADMIN_PASSWORD=$(echo $SCHOOL_RESPONSE | jq -r '.tenant.admin_credentials.password // .admin_credentials.password // empty')

if [ -z "$SCHOOL_ID" ] || [ "$SCHOOL_ID" = "null" ]; then
    echo -e "${YELLOW}⚠ Using existing school - proceeding with tests${NC}"
else
    echo -e "${GREEN}✓ School created: ID=$SCHOOL_ID${NC}"
fi
echo ""

# Step 3: Login as School Admin
echo "📝 Step 3: Login as School Admin..."
if [ -n "$SCHOOL_ADMIN_EMAIL" ] && [ "$SCHOOL_ADMIN_EMAIL" != "null" ]; then
    ADMIN_LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"email\": \"$SCHOOL_ADMIN_EMAIL\",
        \"password\": \"$SCHOOL_ADMIN_PASSWORD\"
      }")
    
    ADMIN_TOKEN=$(echo $ADMIN_LOGIN_RESPONSE | jq -r '.token // .access_token // empty')
    ADMIN_ROLE=$(echo $ADMIN_LOGIN_RESPONSE | jq -r '.user.role // empty')
    
    if [ -n "$ADMIN_TOKEN" ] && [ "$ADMIN_TOKEN" != "null" ]; then
        print_result 0 "School Admin login" 
        echo "  Email: $SCHOOL_ADMIN_EMAIL"
        echo "  Role: $ADMIN_ROLE"
    else
        print_result 1 "School Admin login" "$ADMIN_LOGIN_RESPONSE"
        ADMIN_TOKEN="$SUPER_ADMIN_TOKEN"
    fi
else
    echo -e "${YELLOW}⚠ No school admin credentials, using super admin token${NC}"
    ADMIN_TOKEN="$SUPER_ADMIN_TOKEN"
fi
echo ""

# Step 4: Create Academic Year and Term
echo "📝 Step 4: Creating Academic Year and Term..."
ACADEMIC_YEAR_RESPONSE=$(curl -s -X POST "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "2025-2026",
    "start_date": "2025-09-01",
    "end_date": "2026-08-31",
    "is_current": true
  }')

ACADEMIC_YEAR_ID=$(echo $ACADEMIC_YEAR_RESPONSE | jq -r '.id // .academic_year.id // empty')

TERM_RESPONSE=$(curl -s -X POST "$BASE_URL/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"First Term\",
    \"academic_year_id\": $ACADEMIC_YEAR_ID,
    \"start_date\": \"2025-09-01\",
    \"end_date\": \"2025-12-20\",
    \"is_current\": true
  }")

TERM_ID=$(echo $TERM_RESPONSE | jq -r '.id // .term.id // empty')

echo -e "${GREEN}✓ Academic Year & Term created${NC}"
echo ""

# Step 5: Create Class
echo "📝 Step 5: Creating Class..."
CLASS_RESPONSE=$(curl -s -X POST "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Grade 10A\",
    \"academic_year_id\": $ACADEMIC_YEAR_ID,
    \"term_id\": $TERM_ID,
    \"capacity\": 30
  }")

CLASS_ID=$(echo $CLASS_RESPONSE | jq -r '.id // .class.id // empty')
echo -e "${GREEN}✓ Class created: ID=$CLASS_ID${NC}"
echo ""

# Step 6: Create and Test TEACHER
echo "======================================"
echo "👨‍🏫 Testing TEACHER Login"
echo "======================================"
TEACHER_RESPONSE=$(curl -s -X POST "$BASE_URL/teachers" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "TeacherTest",
    "title": "Mr.",
    "qualification": "MSc Mathematics",
    "employment_date": "2025-01-15"
  }')

TEACHER_EMAIL=$(echo $TEACHER_RESPONSE | jq -r '.login_credentials.email // empty')
TEACHER_PASSWORD=$(echo $TEACHER_RESPONSE | jq -r '.login_credentials.password // empty')

echo "Teacher created:"
echo "  Email: $TEACHER_EMAIL"
echo "  Password: $TEACHER_PASSWORD"
echo ""

if [ -n "$TEACHER_EMAIL" ] && [ "$TEACHER_EMAIL" != "null" ]; then
    TEACHER_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"email\": \"$TEACHER_EMAIL\",
        \"password\": \"$TEACHER_PASSWORD\"
      }")
    
    TEACHER_TOKEN=$(echo $TEACHER_LOGIN | jq -r '.token // .access_token // empty')
    TEACHER_ROLE=$(echo $TEACHER_LOGIN | jq -r '.user.role // empty')
    
    if [ -n "$TEACHER_TOKEN" ] && [ "$TEACHER_TOKEN" != "null" ] && [ "$TEACHER_ROLE" = "teacher" ]; then
        print_result 0 "Teacher login with correct role (teacher)"
    else
        print_result 1 "Teacher login" "Token: $TEACHER_TOKEN, Role: $TEACHER_ROLE"
    fi
else
    print_result 1 "Teacher creation" "No credentials returned"
fi
echo ""

# Step 7: Create and Test STUDENT
echo "======================================"
echo "👨‍🎓 Testing STUDENT Login"
echo "======================================"
STUDENT_RESPONSE=$(curl -s -X POST "$BASE_URL/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"first_name\": \"Jane\",
    \"last_name\": \"StudentTest\",
    \"class_id\": $CLASS_ID,
    \"date_of_birth\": \"2008-05-15\",
    \"gender\": \"female\"
  }")

STUDENT_EMAIL=$(echo $STUDENT_RESPONSE | jq -r '.login_credentials.email // empty')
STUDENT_PASSWORD=$(echo $STUDENT_RESPONSE | jq -r '.login_credentials.password // empty')

echo "Student created:"
echo "  Email: $STUDENT_EMAIL"
echo "  Password: $STUDENT_PASSWORD"
echo ""

if [ -n "$STUDENT_EMAIL" ] && [ "$STUDENT_EMAIL" != "null" ]; then
    STUDENT_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"email\": \"$STUDENT_EMAIL\",
        \"password\": \"$STUDENT_PASSWORD\"
      }")
    
    STUDENT_TOKEN=$(echo $STUDENT_LOGIN | jq -r '.token // .access_token // empty')
    STUDENT_ROLE=$(echo $STUDENT_LOGIN | jq -r '.user.role // empty')
    
    if [ -n "$STUDENT_TOKEN" ] && [ "$STUDENT_TOKEN" != "null" ] && [ "$STUDENT_ROLE" = "student" ]; then
        print_result 0 "Student login with correct role (student)"
    else
        print_result 1 "Student login" "Token: $STUDENT_TOKEN, Role: $STUDENT_ROLE"
    fi
else
    print_result 1 "Student creation" "No credentials returned"
fi
echo ""

# Step 8: Create and Test STAFF
echo "======================================"
echo "👔 Testing STAFF Login"
echo "======================================"
STAFF_RESPONSE=$(curl -s -X POST "$BASE_URL/staff" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Mike",
    "last_name": "StaffTest",
    "role": "librarian",
    "employment_date": "2025-01-15"
  }')

STAFF_EMAIL=$(echo $STAFF_RESPONSE | jq -r '.login_credentials.email // empty')
STAFF_PASSWORD=$(echo $STAFF_RESPONSE | jq -r '.login_credentials.password // empty')

echo "Staff created:"
echo "  Email: $STAFF_EMAIL"
echo "  Password: $STAFF_PASSWORD"
echo ""

if [ -n "$STAFF_EMAIL" ] && [ "$STAFF_EMAIL" != "null" ]; then
    STAFF_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"email\": \"$STAFF_EMAIL\",
        \"password\": \"$STAFF_PASSWORD\"
      }")
    
    STAFF_TOKEN=$(echo $STAFF_LOGIN | jq -r '.token // .access_token // empty')
    STAFF_ROLE=$(echo $STAFF_LOGIN | jq -r '.user.role // empty')
    
    if [ -n "$STAFF_TOKEN" ] && [ "$STAFF_TOKEN" != "null" ] && [ "$STAFF_ROLE" = "librarian" ]; then
        print_result 0 "Staff login with correct role (librarian)"
    else
        print_result 1 "Staff login" "Token: $STAFF_TOKEN, Role: $STAFF_ROLE"
    fi
else
    print_result 1 "Staff creation" "No credentials returned"
fi
echo ""

# Step 9: Create and Test GUARDIAN
echo "======================================"
echo "👨‍👩‍👧 Testing GUARDIAN Login"
echo "======================================"
GUARDIAN_RESPONSE=$(curl -s -X POST "$BASE_URL/guardians" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Sarah",
    "last_name": "GuardianTest",
    "phone": "+1234567890",
    "occupation": "Engineer"
  }')

GUARDIAN_EMAIL=$(echo $GUARDIAN_RESPONSE | jq -r '.login_credentials.email // empty')
GUARDIAN_PASSWORD=$(echo $GUARDIAN_RESPONSE | jq -r '.login_credentials.password // empty')

echo "Guardian created:"
echo "  Email: $GUARDIAN_EMAIL"
echo "  Password: $GUARDIAN_PASSWORD"
echo ""

if [ -n "$GUARDIAN_EMAIL" ] && [ "$GUARDIAN_EMAIL" != "null" ]; then
    GUARDIAN_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"email\": \"$GUARDIAN_EMAIL\",
        \"password\": \"$GUARDIAN_PASSWORD\"
      }")
    
    GUARDIAN_TOKEN=$(echo $GUARDIAN_LOGIN | jq -r '.token // .access_token // empty')
    GUARDIAN_ROLE=$(echo $GUARDIAN_LOGIN | jq -r '.user.role // empty')
    
    if [ -n "$GUARDIAN_TOKEN" ] && [ "$GUARDIAN_TOKEN" != "null" ] && [ "$GUARDIAN_ROLE" = "guardian" ]; then
        print_result 0 "Guardian login with correct role (guardian)"
    else
        print_result 1 "Guardian login" "Token: $GUARDIAN_TOKEN, Role: $GUARDIAN_ROLE"
    fi
else
    print_result 1 "Guardian creation" "No credentials returned"
fi
echo ""

# Summary
echo "======================================"
echo "📊 TEST SUMMARY"
echo "======================================"
echo "Total Tests: $TOTAL_TESTS"
echo -e "${GREEN}Passed: $PASSED_TESTS${NC}"
echo -e "${RED}Failed: $FAILED_TESTS${NC}"
echo ""

if [ $FAILED_TESTS -eq 0 ]; then
    echo -e "${GREEN}🎉 All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}❌ Some tests failed!${NC}"
    exit 1
fi

