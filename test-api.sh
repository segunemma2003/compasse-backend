#!/bin/bash

# API Test Script for SamSchool Backend
# Tests main website APIs and superadmin functionality

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
BASE_URL="http://localhost:8000"
API_URL="${BASE_URL}/api/v1"
TOKEN=""
SCHOOL_ID=""
TENANT_ID=""

# Test counter
PASSED=0
FAILED=0
TOTAL=0

# Function to print test header
print_header() {
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}"
}

# Function to print test result
print_result() {
    TOTAL=$((TOTAL + 1))
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✅ PASSED:${NC} $2"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ FAILED:${NC} $2"
        FAILED=$((FAILED + 1))
    fi
}

# Function to make API request and check response
test_api() {
    local method=$1
    local endpoint=$2
    local data=$3
    local description=$4
    local expected_status=${5:-200}
    local use_auth=${6:-false}
    local use_tenant=${7:-false}
    
    echo ""
    echo -e "${YELLOW}Testing:${NC} $description"
    echo -e "Endpoint: $method $endpoint"
    
    # Build curl command with headers
    local curl_cmd="curl -s -w \"\n%{http_code}\" -X $method \"$endpoint\" -H \"Content-Type: application/json\" -H \"Accept: application/json\""
    
    if [ "$use_auth" = "true" ] && [ -n "$TOKEN" ]; then
        curl_cmd="$curl_cmd -H \"Authorization: Bearer $TOKEN\""
    fi
    
    if [ "$use_tenant" = "true" ] && [ -n "$SUBDOMAIN" ]; then
        curl_cmd="$curl_cmd -H \"X-Subdomain: $SUBDOMAIN\""
    fi
    
    if [ -n "$data" ]; then
        curl_cmd="$curl_cmd -d '$data'"
    fi
    
    # Execute curl command
    response=$(eval $curl_cmd)
    
    # Split response and status code
    body=$(echo "$response" | sed '$d')
    status=$(echo "$response" | tail -n 1)
    
    # Pretty print JSON if jq is available
    if command -v jq &> /dev/null; then
        echo "$body" | jq '.' 2>/dev/null || echo "$body"
    else
        echo "$body"
    fi
    
    # Check status code
    if [ "$status" -eq "$expected_status" ]; then
        print_result 0 "$description (Status: $status)"
        return 0
    else
        print_result 1 "$description (Expected: $expected_status, Got: $status)"
        return 1
    fi
}

# Start testing
clear
echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   SamSchool Backend API Testing Suite             ║${NC}"
echo -e "${BLUE}║   Testing Main Website & SuperAdmin APIs          ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""
echo "Base URL: $BASE_URL"
echo "API URL: $API_URL"
echo ""

# ============================================
# 1. Public Endpoints (No Auth Required)
# ============================================
print_header "1. PUBLIC ENDPOINTS (No Authentication)"

test_api "GET" "$BASE_URL/api/health" "" "Health Check" 200

test_api "GET" "$BASE_URL/api/health/db" "" "Database Health Check" 200

# ============================================
# 2. SuperAdmin Authentication
# ============================================
print_header "2. SUPERADMIN AUTHENTICATION"

# Login as SuperAdmin
login_response=$(curl -s -X POST "$API_URL/auth/login" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d '{
        "email": "superadmin@compasse.net",
        "password": "Nigeria@60"
    }')

echo "Login Response:"
echo "$login_response" | jq '.' 2>/dev/null || echo "$login_response"

# Extract token
TOKEN=$(echo "$login_response" | jq -r '.token // .access_token // empty' 2>/dev/null)

if [ -z "$TOKEN" ]; then
    echo -e "${RED}❌ FAILED: Could not login as superadmin${NC}"
    echo "Login response: $login_response"
    print_result 1 "SuperAdmin Login"
else
    echo -e "${GREEN}✅ Successfully logged in as SuperAdmin${NC}"
    echo "Token: ${TOKEN:0:20}..."
    print_result 0 "SuperAdmin Login"
fi

# Note: /auth/me requires tenant context, skip for superadmin test
# SuperAdmin doesn't have tenant context

# ============================================
# 3. School Management (SuperAdmin)
# ============================================
print_header "3. SCHOOL MANAGEMENT (SuperAdmin Only)"

if [ -n "$TOKEN" ]; then
    # List all schools
    test_api "GET" "$API_URL/schools" "" "List All Schools" 200 true false
    
    # Create a new school
    create_school_response=$(curl -s -X POST "$API_URL/schools" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d '{
            "name": "Test School '$(date +%s)'",
            "subdomain": "testschool'$(date +%s)'",
            "email": "admin@testschool.com",
            "phone": "+234-800-000-0000",
            "address": "123 Test Street, Lagos",
            "plan_id": 1
        }')
    
    echo ""
    echo -e "${YELLOW}Creating Test School...${NC}"
    echo "$create_school_response" | jq '.' 2>/dev/null || echo "$create_school_response"
    
    # Extract school ID
    SCHOOL_ID=$(echo "$create_school_response" | jq -r '.data.id // .school.id // empty' 2>/dev/null)
    TENANT_ID=$(echo "$create_school_response" | jq -r '.data.tenant_id // .tenant_id // empty' 2>/dev/null)
    SUBDOMAIN=$(echo "$create_school_response" | jq -r '.data.subdomain // .subdomain // empty' 2>/dev/null)
    
    if [ -n "$SCHOOL_ID" ]; then
        echo -e "${GREEN}✅ School created successfully${NC}"
        echo "School ID: $SCHOOL_ID"
        echo "Tenant ID: $TENANT_ID"
        echo "Subdomain: $SUBDOMAIN"
        print_result 0 "Create School"
    else
        print_result 1 "Create School"
    fi
else
    echo -e "${RED}Skipping school management tests - not authenticated${NC}"
fi

# ============================================
# 4. Tenant Management (SuperAdmin)
# ============================================
print_header "4. TENANT MANAGEMENT (SuperAdmin Only)"

if [ -n "$TOKEN" ]; then
    # List all tenants
    test_api "GET" "$API_URL/tenants" "" "List All Tenants" 200 "-H \"Authorization: Bearer $TOKEN\""
    
    # Get specific tenant stats
    if [ -n "$TENANT_ID" ]; then
        test_api "GET" "$API_URL/tenants/$TENANT_ID/stats" "" "Get Tenant Statistics" 200 "-H \"Authorization: Bearer $TOKEN\""
    fi
fi

# ============================================
# 5. Public School Lookup
# ============================================
print_header "5. PUBLIC SCHOOL LOOKUP (No Auth Required)"

if [ -n "$SUBDOMAIN" ]; then
    test_api "GET" "$API_URL/schools/by-subdomain/$SUBDOMAIN" "" "Lookup School by Subdomain" 200
    
    test_api "GET" "$API_URL/schools/subdomain/$SUBDOMAIN" "" "Get School by Subdomain (Alternative)" 200
fi

# ============================================
# 6. Tenant Verification
# ============================================
print_header "6. TENANT VERIFICATION (Public)"

if [ -n "$SUBDOMAIN" ]; then
    test_api "POST" "$API_URL/tenants/verify" "{\"subdomain\": \"$SUBDOMAIN\"}" "Verify Tenant Exists" 200
fi

# ============================================
# 7. SuperAdmin Dashboard
# ============================================
print_header "7. SUPERADMIN DASHBOARD"

if [ -n "$TOKEN" ]; then
    test_api "GET" "$API_URL/dashboard/super-admin" "" "SuperAdmin Dashboard Stats" 200 "-H \"Authorization: Bearer $TOKEN\""
    
    test_api "GET" "$API_URL/super-admin/analytics" "" "SuperAdmin Analytics" 200 "-H \"Authorization: Bearer $TOKEN\""
fi

# ============================================
# 8. School-Specific Operations (Tenant Context)
# ============================================
print_header "8. SCHOOL-SPECIFIC OPERATIONS (Tenant Context)"

if [ -n "$TOKEN" ] && [ -n "$SCHOOL_ID" ] && [ -n "$SUBDOMAIN" ]; then
    # Get school details (with tenant context)
    test_api "GET" "$API_URL/schools/$SCHOOL_ID" "" "Get School Details" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
    
    # Update school
    test_api "PUT" "$API_URL/schools/$SCHOOL_ID" \
        "{\"name\": \"Test School Updated\", \"address\": \"456 Updated Street\"}" \
        "Update School Details" 200 \
        "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
    
    # Get school stats
    test_api "GET" "$API_URL/schools/$SCHOOL_ID/stats" "" "Get School Statistics" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
    
    # Get school dashboard
    test_api "GET" "$API_URL/schools/$SCHOOL_ID/dashboard" "" "Get School Dashboard" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
fi

# ============================================
# 9. Subscription Management (Tenant Context)
# ============================================
print_header "9. SUBSCRIPTION MANAGEMENT"

if [ -n "$TOKEN" ] && [ -n "$SUBDOMAIN" ]; then
    # Get subscription plans
    test_api "GET" "$API_URL/subscriptions/plans" "" "Get Available Plans" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
    
    # Get modules
    test_api "GET" "$API_URL/subscriptions/modules" "" "Get Available Modules" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
    
    # Get subscription status
    test_api "GET" "$API_URL/subscriptions/status" "" "Get School Subscription Status" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
    
    # Get school modules
    test_api "GET" "$API_URL/subscriptions/school/modules" "" "Get School Active Modules" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
    
    # Get school limits
    test_api "GET" "$API_URL/subscriptions/school/limits" "" "Get School Usage Limits" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
fi

# ============================================
# 10. User Management (Tenant Context)
# ============================================
print_header "10. USER MANAGEMENT (Tenant Context)"

if [ -n "$TOKEN" ] && [ -n "$SUBDOMAIN" ]; then
    # List users in the school
    test_api "GET" "$API_URL/users" "" "List School Users" 200 "-H \"Authorization: Bearer $TOKEN\" -H \"X-Subdomain: $SUBDOMAIN\""
fi

# ============================================
# CLEANUP
# ============================================
print_header "CLEANUP"

if [ -n "$TOKEN" ] && [ -n "$SCHOOL_ID" ]; then
    echo ""
    echo -e "${YELLOW}Cleaning up test school...${NC}"
    
    delete_response=$(curl -s -w "\n%{http_code}" -X DELETE "$API_URL/schools/$SCHOOL_ID" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json")
    
    delete_body=$(echo "$delete_response" | sed '$d')
    delete_status=$(echo "$delete_response" | tail -n 1)
    
    echo "$delete_body" | jq '.' 2>/dev/null || echo "$delete_body"
    
    if [ "$delete_status" -eq 200 ] || [ "$delete_status" -eq 204 ]; then
        echo -e "${GREEN}✅ Test school deleted successfully${NC}"
        print_result 0 "Delete Test School"
    else
        echo -e "${YELLOW}⚠️  Could not delete test school (Status: $delete_status)${NC}"
        print_result 1 "Delete Test School"
    fi
fi

# ============================================
# SUMMARY
# ============================================
echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                  TEST SUMMARY                      ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Total Tests: ${TOTAL}"
echo -e "${GREEN}Passed: ${PASSED}${NC}"
echo -e "${RED}Failed: ${FAILED}${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}⚠️  Some tests failed. Please check the output above.${NC}"
    exit 1
fi

