#!/bin/bash

# COMPREHENSIVE SuperAdmin API Test - Tests EVERY endpoint
# This tests ALL superadmin capabilities, not just a few

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

BASE_URL="http://localhost:8000"
API_URL="${BASE_URL}/api/v1"
TOKEN=""
SCHOOL_ID=""
SUBDOMAIN=""
TENANT_ID=""

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
        echo "$body" | jq '.' 2>/dev/null | head -20 || echo "$body" | head -20
    else
        echo "$body" | head -20
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
echo -e "${BLUE}║  COMPREHENSIVE SUPERADMIN API TEST - ALL ENDPOINTS║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""
echo "Testing ALL SuperAdmin capabilities..."
echo ""

# ============================================
# SECTION 1: PUBLIC ENDPOINTS (No Auth)
# ============================================
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 1: PUBLIC ENDPOINTS (No Authentication)${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Health Check" "GET" "$BASE_URL/api/health" ""

test_api "Database Health Check" "GET" "$BASE_URL/api/health/db" ""

# ============================================
# SECTION 2: AUTHENTICATION
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 2: SUPERADMIN AUTHENTICATION${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

login_data='{"email":"superadmin@compasse.net","password":"Nigeria@60"}'
login_response=$(curl -s -X POST "$API_URL/auth/login" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d "$login_data")

TOKEN=$(echo "$login_response" | jq -r '.token // .access_token // empty')

if [ -n "$TOKEN" ]; then
    PASSED=$((PASSED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "${GREEN}✅ [1] SuperAdmin Login - PASSED${NC}"
else
    FAILED=$((FAILED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "${RED}❌ [1] SuperAdmin Login - FAILED${NC}"
    exit 1
fi

test_api "Get Current User (SuperAdmin)" "GET" "$API_URL/auth/me" ""

# ============================================
# SECTION 3: TENANT MANAGEMENT
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 3: TENANT MANAGEMENT${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

tenants_response=$(test_api "List All Tenants" "GET" "$API_URL/tenants" "")

# Extract first tenant ID for testing
TENANT_ID=$(echo "$tenants_response" | jq -r '.tenants.data[0].id // .data[0].id // empty' 2>/dev/null)

if [ -n "$TENANT_ID" ]; then
    test_api "Get Specific Tenant Details" "GET" "$API_URL/tenants/$TENANT_ID" ""
    
    test_api "Get Tenant Statistics" "GET" "$API_URL/tenants/$TENANT_ID/stats" ""
fi

# ============================================
# SECTION 4: SCHOOL LISTING & VIEWING
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 4: SCHOOL LISTING & VIEWING${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

schools_response=$(test_api "List All Schools" "GET" "$API_URL/schools" "")

test_api "List Schools with Pagination" "GET" "$API_URL/schools?per_page=5&page=1" ""

test_api "Search Schools by Name" "GET" "$API_URL/schools?search=school" ""

test_api "Filter Schools by Status" "GET" "$API_URL/schools?status=active" ""

# ============================================
# SECTION 5: DASHBOARDS & ANALYTICS
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 5: DASHBOARDS & ANALYTICS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "SuperAdmin Dashboard" "GET" "$API_URL/dashboard/super-admin" ""

test_api "SuperAdmin Analytics" "GET" "$API_URL/super-admin/analytics" ""

test_api "Database Status" "GET" "$API_URL/super-admin/database" ""

test_api "Security Logs" "GET" "$API_URL/super-admin/security" ""

# ============================================
# SECTION 6: CREATE NEW SCHOOL
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 6: CREATE NEW SCHOOL${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

timestamp=$(date +%s)
school_data="{
  \"name\": \"Test School $timestamp\",
  \"subdomain\": \"testschool$timestamp\",
  \"email\": \"admin@testschool$timestamp.com\",
  \"phone\": \"+234-800-TEST-001\",
  \"address\": \"123 Test Avenue, Lagos, Nigeria\",
  \"website\": \"https://testschool$timestamp.edu\",
  \"plan_id\": 1
}"

create_response=$(test_api "Create New School" "POST" "$API_URL/schools" "$school_data")

SCHOOL_ID=$(echo "$create_response" | jq -r '.school.id // .data.id // .id // empty' 2>/dev/null)
SUBDOMAIN=$(echo "$create_response" | jq -r '.tenant.subdomain // .data.subdomain // .school.subdomain // .subdomain // empty' 2>/dev/null)

if [ -z "$SCHOOL_ID" ]; then
    echo -e "${RED}⚠️  Could not extract school ID, using existing school for remaining tests${NC}"
    SCHOOL_ID=$(echo "$schools_response" | jq -r '.data[0].id // empty' 2>/dev/null)
    SUBDOMAIN=$(echo "$schools_response" | jq -r '.data[0].tenant.subdomain // .data[0].subdomain // empty' 2>/dev/null)
fi

echo ""
echo -e "${YELLOW}Created School ID: $SCHOOL_ID${NC}"
echo -e "${YELLOW}Subdomain: $SUBDOMAIN${NC}"

# ============================================
# SECTION 7: SCHOOL OPERATIONS (With School ID)
# ============================================
if [ -n "$SCHOOL_ID" ]; then
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}SECTION 7: SCHOOL DETAIL OPERATIONS${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    
    test_api "Get School Details" "GET" "$API_URL/schools/$SCHOOL_ID" ""
    
    # Update school
    update_data="{\"address\": \"456 Updated Street, Lagos\", \"phone\": \"+234-800-UPDATED\"}"
    test_api "Update School Information" "PUT" "$API_URL/schools/$SCHOOL_ID" "$update_data"
fi

# ============================================
# SECTION 8: SCHOOL CONTROL ACTIONS
# ============================================
if [ -n "$SCHOOL_ID" ]; then
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}SECTION 8: SCHOOL CONTROL & MANAGEMENT ACTIONS${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    
    test_api "Get School Users Count" "GET" "$API_URL/schools/$SCHOOL_ID/users-count" ""
    
    test_api "Get School Activity Logs" "GET" "$API_URL/schools/$SCHOOL_ID/activity-logs" ""
    
    test_api "Suspend School" "POST" "$API_URL/schools/$SCHOOL_ID/suspend" ""
    
    test_api "Activate School" "POST" "$API_URL/schools/$SCHOOL_ID/activate" ""
    
    email_data='{"subject":"Test Email from SuperAdmin","message":"This is a test email to verify email functionality.","send_to":"admin"}'
    test_api "Send Email to School Admin" "POST" "$API_URL/schools/$SCHOOL_ID/send-email" "$email_data"
    
    test_api "Reset Admin Password" "POST" "$API_URL/schools/$SCHOOL_ID/reset-admin-password" ""
fi

# ============================================
# SECTION 9: SCHOOL STATISTICS (Requires Tenant Context)
# ============================================
if [ -n "$SCHOOL_ID" ] && [ -n "$SUBDOMAIN" ]; then
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}SECTION 9: SCHOOL STATISTICS (With Tenant Context)${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    
    # These require X-Subdomain header
    stats_response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/$SCHOOL_ID/stats" \
        -H "Authorization: Bearer $TOKEN" \
        -H "X-Subdomain: $SUBDOMAIN" \
        -H "Accept: application/json")
    
    stats_body=$(echo "$stats_response" | sed '$d')
    stats_status=$(echo "$stats_response" | tail -n 1)
    
    TOTAL=$((TOTAL + 1))
    echo ""
    echo -e "${CYAN}[$TOTAL] Testing: Get School Statistics (with tenant context)${NC}"
    echo "$stats_body" | jq '.' 2>/dev/null | head -20 || echo "$stats_body" | head -20
    
    if [ "$stats_status" = "200" ]; then
        echo -e "${GREEN}✅ PASSED (Status: $stats_status)${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ FAILED (Status: $stats_status)${NC}"
        FAILED=$((FAILED + 1))
    fi
    
    # School Dashboard
    dashboard_response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/$SCHOOL_ID/dashboard" \
        -H "Authorization: Bearer $TOKEN" \
        -H "X-Subdomain: $SUBDOMAIN" \
        -H "Accept: application/json")
    
    dashboard_body=$(echo "$dashboard_response" | sed '$d')
    dashboard_status=$(echo "$dashboard_response" | tail -n 1)
    
    TOTAL=$((TOTAL + 1))
    echo ""
    echo -e "${CYAN}[$TOTAL] Testing: Get School Dashboard (with tenant context)${NC}"
    echo "$dashboard_body" | jq '.' 2>/dev/null | head -20 || echo "$dashboard_body" | head -20
    
    if [ "$dashboard_status" = "200" ]; then
        echo -e "${GREEN}✅ PASSED (Status: $dashboard_status)${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ FAILED (Status: $dashboard_status)${NC}"
        FAILED=$((FAILED + 1))
    fi
    
    # School Organogram
    organogram_response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/$SCHOOL_ID/organogram" \
        -H "Authorization: Bearer $TOKEN" \
        -H "X-Subdomain: $SUBDOMAIN" \
        -H "Accept: application/json")
    
    organogram_body=$(echo "$organogram_response" | sed '$d')
    organogram_status=$(echo "$organogram_response" | tail -n 1)
    
    TOTAL=$((TOTAL + 1))
    echo ""
    echo -e "${CYAN}[$TOTAL] Testing: Get School Organogram (with tenant context)${NC}"
    echo "$organogram_body" | jq '.' 2>/dev/null | head -20 || echo "$organogram_body" | head -20
    
    if [ "$organogram_status" = "200" ]; then
        echo -e "${GREEN}✅ PASSED (Status: $organogram_status)${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ FAILED (Status: $organogram_status)${NC}"
        FAILED=$((FAILED + 1))
    fi
fi

# ============================================
# SECTION 10: PUBLIC SCHOOL LOOKUP
# ============================================
if [ -n "$SUBDOMAIN" ]; then
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}SECTION 10: PUBLIC SCHOOL LOOKUP (No Auth)${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    
    # Temporarily remove token for public tests
    TEMP_TOKEN=$TOKEN
    TOKEN=""
    
    test_api "Lookup School by Subdomain (Path)" "GET" "$API_URL/schools/by-subdomain/$SUBDOMAIN" ""
    
    test_api "Lookup School by Subdomain (Query)" "GET" "$API_URL/schools/by-subdomain?subdomain=$SUBDOMAIN" ""
    
    test_api "Get School by Subdomain" "GET" "$API_URL/schools/subdomain/$SUBDOMAIN" ""
    
    verify_data="{\"subdomain\":\"$SUBDOMAIN\"}"
    test_api "Verify Tenant Exists" "POST" "$API_URL/tenants/verify" "$verify_data"
    
    # Restore token
    TOKEN=$TEMP_TOKEN
fi

# ============================================
# SECTION 11: AUTHENTICATION ACTIONS
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}SECTION 11: AUTHENTICATION ACTIONS${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

test_api "Refresh Token" "POST" "$API_URL/auth/refresh" ""

# ============================================
# SECTION 12: CLEANUP - DELETE SCHOOL
# ============================================
if [ -n "$SCHOOL_ID" ] && [ "$SCHOOL_ID" != "null" ]; then
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}SECTION 12: CLEANUP - DELETE SCHOOL${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    
    test_api "Delete School (Force)" "DELETE" "$API_URL/schools/$SCHOOL_ID?force=true" ""
fi

# Logout
test_api "Logout" "POST" "$API_URL/auth/logout" ""

# ============================================
# FINAL SUMMARY
# ============================================
echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              COMPREHENSIVE TEST SUMMARY            ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Total Tests Run: ${TOTAL}"
echo -e "${GREEN}Passed: ${PASSED}${NC}"
echo -e "${RED}Failed: ${FAILED}${NC}"
echo ""

if [ $TOTAL -gt 0 ]; then
    SUCCESS_RATE=$(awk "BEGIN {printf \"%.1f\", ($PASSED/$TOTAL)*100}")
    echo -e "Success Rate: ${SUCCESS_RATE}%"
fi

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}TESTED SECTIONS:${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo "✓ Section 1: Public Endpoints (2 tests)"
echo "✓ Section 2: Authentication (2 tests)"
echo "✓ Section 3: Tenant Management (3 tests)"
echo "✓ Section 4: School Listing & Filtering (4 tests)"
echo "✓ Section 5: Dashboards & Analytics (4 tests)"
echo "✓ Section 6: Create School (1 test)"
echo "✓ Section 7: School Details & Updates (2 tests)"
echo "✓ Section 8: School Control Actions (6 tests)"
echo "✓ Section 9: School Statistics with Tenant (3 tests)"
echo "✓ Section 10: Public School Lookup (4 tests)"
echo "✓ Section 11: Auth Actions (1 test)"
echo "✓ Section 12: Delete School (1 test)"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 ALL TESTS PASSED! 🎉${NC}"
    echo -e "${GREEN}All SuperAdmin APIs are working correctly!${NC}"
    exit 0
else
    echo -e "${YELLOW}⚠️  Some tests failed. Review the output above for details.${NC}"
    exit 1
fi

