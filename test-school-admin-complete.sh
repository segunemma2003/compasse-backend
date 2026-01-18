#!/bin/bash

# COMPREHENSIVE SCHOOL ADMIN TEST
# Tests tenant/school login and all school admin features

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m'

BASE_URL="http://localhost:8000"
API_URL="${BASE_URL}/api/v1"
TOKEN=""
USER_ROLE=""
SUBDOMAIN="testsch927320"  # Using existing school from previous tests

PASSED=0
FAILED=0
TOTAL=0

test_api() {
    local name=$1
    local method=$2
    local url=$3
    local data=$4
    
    TOTAL=$((TOTAL + 1))
    
    echo ""
    echo -e "${CYAN}[$TOTAL] Testing: $name${NC}"
    
    local cmd="curl -s -w '\n%{http_code}' -X $method '$url' -H 'Content-Type: application/json' -H 'Accept: application/json'"
    
    if [ -n "$TOKEN" ]; then
        cmd="$cmd -H 'Authorization: Bearer $TOKEN'"
    fi
    
    if [ -n "$SUBDOMAIN" ]; then
        cmd="$cmd -H 'X-Subdomain: $SUBDOMAIN'"
    fi
    
    if [ "$method" = "POST" ] || [ "$method" = "PUT" ]; then
        if [ -n "$data" ]; then
            cmd="$cmd -d '$data'"
        fi
    fi
    
    response=$(eval $cmd 2>&1)
    body=$(echo "$response" | sed '$d')
    status=$(echo "$response" | tail -n 1)
    
    # Show truncated response
    if command -v jq &> /dev/null; then
        echo "$body" | jq '.' 2>/dev/null | head -25 || echo "$body" | head -25
    else
        echo "$body" | head -25
    fi
    
    if [ "$status" = "200" ] || [ "$status" = "201" ]; then
        echo -e "${GREEN}✅ PASSED (Status: $status)${NC}"
        PASSED=$((PASSED + 1))
        echo "$body"
        return 0
    else
        echo -e "${RED}❌ FAILED (Status: $status)${NC}"
        FAILED=$((FAILED + 1))
        return 1
    fi
}

clear
echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     SCHOOL ADMIN COMPREHENSIVE API TEST           ║${NC}"
echo -e "${BLUE}║     Testing Tenant/School Login & Features        ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""

# ============================================
# SECTION 1: SCHOOL ADMIN LOGIN
# ============================================
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 1: SCHOOL ADMIN LOGIN (Tenant Context)${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""
echo "Using subdomain: $SUBDOMAIN"
echo ""

# First, get the school admin credentials
echo -e "${YELLOW}Getting school admin email...${NC}"
school_info=$(curl -s "$API_URL/schools/subdomain/$SUBDOMAIN")
echo "$school_info" | jq '{school: .school.name, subdomain: .subdomain}' 2>/dev/null
echo ""

# Login as school admin
echo -e "${YELLOW}Logging in as School Admin...${NC}"
login_data="{\"email\":\"admin@demoschool1768764303.samschool.com\",\"password\":\"Password@12345\",\"subdomain\":\"$SUBDOMAIN\"}"

login_response=$(curl -s -X POST "$API_URL/auth/login" \
    -H "Content-Type: application/json" \
    -H "X-Subdomain: $SUBDOMAIN" \
    -H "Accept: application/json" \
    -d "$login_data")

echo "$login_response" | jq '.' 2>/dev/null || echo "$login_response"
echo ""

TOKEN=$(echo "$login_response" | jq -r '.token // .access_token // empty' 2>/dev/null)
USER_ROLE=$(echo "$login_response" | jq -r '.user.role // empty' 2>/dev/null)
USER_NAME=$(echo "$login_response" | jq -r '.user.name // empty' 2>/dev/null)
USER_EMAIL=$(echo "$login_response" | jq -r '.user.email // empty' 2>/dev/null)

if [ -n "$TOKEN" ]; then
    PASSED=$((PASSED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "${GREEN}✅ [1] School Admin Login - PASSED${NC}"
    echo -e "${GREEN}User: $USER_NAME${NC}"
    echo -e "${GREEN}Email: $USER_EMAIL${NC}"
    echo -e "${GREEN}Role: $USER_ROLE${NC}"
    echo -e "${GREEN}Token: ${TOKEN:0:30}...${NC}"
    echo ""
    
    # Check if role is returned
    if [ -n "$USER_ROLE" ]; then
        echo -e "${GREEN}✅ YES! User role is returned: '$USER_ROLE'${NC}"
    else
        echo -e "${YELLOW}⚠️  User role not found in response${NC}"
    fi
else
    FAILED=$((FAILED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "${RED}❌ [1] School Admin Login - FAILED${NC}"
    echo "Trying with different admin credentials..."
    
    # Try alternative login (using most recent created admin)
    login_data2="{\"email\":\"admin@demoschool1768764303.samschool.com\",\"password\":\"Password@20260118\"}"
    login_response2=$(curl -s -X POST "$API_URL/auth/login" \
        -H "Content-Type: application/json" \
        -H "X-Subdomain: $SUBDOMAIN" \
        -d "$login_data2")
    
    TOKEN=$(echo "$login_response2" | jq -r '.token // empty')
    USER_ROLE=$(echo "$login_response2" | jq -r '.user.role // empty')
    
    if [ -n "$TOKEN" ]; then
        echo -e "${GREEN}✅ Alternative login succeeded${NC}"
    else
        echo -e "${RED}Could not login as school admin. Exiting.${NC}"
        exit 1
    fi
fi

# ============================================
# SECTION 2: AUTHENTICATION MANAGEMENT
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 2: AUTHENTICATION & PROFILE${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Get Current User Profile" "GET" "$API_URL/auth/me" ""

# ============================================
# SECTION 3: SCHOOL INFORMATION
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 3: SCHOOL INFORMATION MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

# Get school ID from response
SCHOOL_ID=$(curl -s "$API_URL/schools/subdomain/$SUBDOMAIN" | jq -r '.school.id')

if [ -n "$SCHOOL_ID" ] && [ "$SCHOOL_ID" != "null" ]; then
    test_api "Get School Details" "GET" "$API_URL/schools/$SCHOOL_ID" ""
    
    test_api "Get School Statistics" "GET" "$API_URL/schools/$SCHOOL_ID/stats" ""
    
    test_api "Get School Dashboard" "GET" "$API_URL/schools/$SCHOOL_ID/dashboard" ""
    
    test_api "Get School Organogram" "GET" "$API_URL/schools/$SCHOOL_ID/organogram" ""
    
    # Update school
    update_data='{"address":"Updated Address via Admin API","phone":"+234-800-UPDATED"}'
    test_api "Update School Information" "PUT" "$API_URL/schools/$SCHOOL_ID" "$update_data"
fi

# ============================================
# SECTION 4: USER MANAGEMENT
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 4: USER MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "List All Users in School" "GET" "$API_URL/users" ""

test_api "List Users (Paginated)" "GET" "$API_URL/users?per_page=5&page=1" ""

# ============================================
# SECTION 5: SUBSCRIPTION MANAGEMENT
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 5: SUBSCRIPTION & MODULES${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Get Subscription Status" "GET" "$API_URL/subscriptions/status" ""

test_api "Get Available Plans" "GET" "$API_URL/subscriptions/plans" ""

test_api "Get Available Modules" "GET" "$API_URL/subscriptions/modules" ""

test_api "Get School Active Modules" "GET" "$API_URL/subscriptions/school/modules" ""

test_api "Get School Usage Limits" "GET" "$API_URL/subscriptions/school/limits" ""

# ============================================
# SECTION 6: ACADEMIC MANAGEMENT
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 6: ACADEMIC MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "List Academic Years" "GET" "$API_URL/academic-years" ""

test_api "List Terms" "GET" "$API_URL/terms" ""

test_api "List Departments" "GET" "$API_URL/departments" ""

test_api "List Classes" "GET" "$API_URL/classes" ""

test_api "List Subjects" "GET" "$API_URL/subjects" ""

test_api "List Arms" "GET" "$API_URL/arms" ""

# ============================================
# SECTION 7: STUDENT MANAGEMENT
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 7: STUDENT MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "List Students" "GET" "$API_URL/students" ""

test_api "List Students (Paginated)" "GET" "$API_URL/students?per_page=10&page=1" ""

# ============================================
# SECTION 8: TEACHER MANAGEMENT
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 8: TEACHER MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "List Teachers" "GET" "$API_URL/teachers" ""

# ============================================
# SECTION 9: ATTENDANCE
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 9: ATTENDANCE MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "List Attendance Records" "GET" "$API_URL/attendance" ""

test_api "Get Students Attendance" "GET" "$API_URL/attendance/students" ""

test_api "Get Teachers Attendance" "GET" "$API_URL/attendance/teachers" ""

# ============================================
# SECTION 10: ASSESSMENTS & RESULTS
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 10: ASSESSMENTS & RESULTS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "List Exams" "GET" "$API_URL/assessments/exams" ""

test_api "List Assignments" "GET" "$API_URL/assessments/assignments" ""

test_api "List Grading Systems" "GET" "$API_URL/assessments/grading-systems" ""

test_api "Get Default Grading System" "GET" "$API_URL/assessments/grading-systems/default" ""

test_api "List Continuous Assessments" "GET" "$API_URL/assessments/continuous-assessments" ""

# ============================================
# SECTION 11: DASHBOARDS
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 11: ROLE-SPECIFIC DASHBOARDS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Admin Dashboard" "GET" "$API_URL/dashboard/admin" ""

test_api "Teacher Dashboard" "GET" "$API_URL/dashboard/teacher" ""

test_api "Student Dashboard" "GET" "$API_URL/dashboard/student" ""

test_api "Parent Dashboard" "GET" "$API_URL/dashboard/parent" ""

# ============================================
# SECTION 12: FILE UPLOADS
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 12: FILE MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Get Presigned URLs" "GET" "$API_URL/uploads/presigned-urls" ""

# ============================================
# SECTION 13: SETTINGS
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 13: SCHOOL SETTINGS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Get School Settings" "GET" "$API_URL/settings/school" ""

# ============================================
# SECTION 14: REPORTS
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 14: REPORTS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Get Academic Reports" "GET" "$API_URL/reports/academic" ""

test_api "Get Attendance Reports" "GET" "$API_URL/reports/attendance" ""

test_api "Get Performance Reports" "GET" "$API_URL/reports/performance" ""

# ============================================
# SECTION 15: COMMUNICATION
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 15: COMMUNICATION${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "List Messages" "GET" "$API_URL/communication/messages" ""

test_api "List Notifications" "GET" "$API_URL/communication/notifications" ""

# ============================================
# SUMMARY
# ============================================
echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║        SCHOOL ADMIN API TEST SUMMARY              ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""
echo "Subdomain Tested: $SUBDOMAIN"
echo "User Role: $USER_ROLE"
echo ""
echo "Total Tests: $TOTAL"
echo -e "${GREEN}Passed: $PASSED${NC}"
echo -e "${RED}Failed: $FAILED${NC}"
echo ""

if [ $TOTAL -gt 0 ]; then
    SUCCESS_RATE=$(awk "BEGIN {printf \"%.1f\", ($PASSED/$TOTAL)*100}")
    echo -e "Success Rate: ${SUCCESS_RATE}%"
fi

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}KEY FINDINGS:${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""
echo "✓ Login Endpoint: SAME as SuperAdmin (POST /api/v1/auth/login)"
echo "✓ Differentiation: Use X-Subdomain header for tenant login"
echo "✓ User Role Returned: ${USER_ROLE:-'Not found'}"
echo "✓ Token Type: Bearer"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 All school admin APIs working!${NC}"
    exit 0
else
    echo -e "${YELLOW}⚠️  Some APIs need investigation${NC}"
    exit 1
fi

