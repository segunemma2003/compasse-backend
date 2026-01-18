#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;36m'
NC='\033[0m' # No Color

# Base URL
BASE_URL="http://localhost:8000/api/v1"

# Counters
PASSED=0
FAILED=0
TOTAL=0

# Test result function
test_api() {
    local name=$1
    local method=$2
    local endpoint=$3
    local data=$4
    local token=$5
    
    TOTAL=$((TOTAL + 1))
    
    if [ -z "$data" ]; then
        response=$(curl -s -w "\n%{http_code}" -X $method "$BASE_URL$endpoint" \
            -H "Authorization: Bearer $token" \
            -H "Content-Type: application/json")
    else
        response=$(curl -s -w "\n%{http_code}" -X $method "$BASE_URL$endpoint" \
            -H "Authorization: Bearer $token" \
            -H "Content-Type: application/json" \
            -d "$data")
    fi
    
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')
    
    if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 300 ]; then
        echo -e "${GREEN}✅ $name${NC}"
        PASSED=$((PASSED + 1))
        echo "$body" | jq . 2>/dev/null || echo "$body"
    else
        echo -e "${RED}❌ $name (HTTP $http_code)${NC}"
        FAILED=$((FAILED + 1))
        echo "$body" | jq . 2>/dev/null || echo "$body"
    fi
    echo ""
}

# Test result function for public APIs (no auth)
test_public_api() {
    local name=$1
    local method=$2
    local endpoint=$3
    local data=$4
    
    TOTAL=$((TOTAL + 1))
    
    if [ -z "$data" ]; then
        response=$(curl -s -w "\n%{http_code}" -X $method "$BASE_URL$endpoint" \
            -H "Content-Type: application/json")
    else
        response=$(curl -s -w "\n%{http_code}" -X $method "$BASE_URL$endpoint" \
            -H "Content-Type: application/json" \
            -d "$data")
    fi
    
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')
    
    if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 300 ]; then
        echo -e "${GREEN}✅ $name${NC}"
        PASSED=$((PASSED + 1))
        echo "$body" | jq . 2>/dev/null || echo "$body"
    else
        echo -e "${RED}❌ $name (HTTP $http_code)${NC}"
        FAILED=$((FAILED + 1))
        echo "$body" | jq . 2>/dev/null || echo "$body"
    fi
    echo ""
}

echo -e "${BLUE}=========================================${NC}"
echo -e "${BLUE}SUPERADMIN API COMPREHENSIVE TEST${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""

# ===========================================
# STEP 1: SuperAdmin Login
# ===========================================
echo -e "${BLUE}=== 1. AUTHENTICATION ===${NC}"

LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/login" \
    -H "Content-Type: application/json" \
    -d '{
        "email": "superadmin@compasse.net",
        "password": "Nigeria@60"
    }')

SUPERADMIN_TOKEN=$(echo $LOGIN_RESPONSE | jq -r '.token // .access_token // empty')

if [ -z "$SUPERADMIN_TOKEN" ] || [ "$SUPERADMIN_TOKEN" == "null" ]; then
    echo -e "${RED}❌ SuperAdmin Login Failed${NC}"
    echo "$LOGIN_RESPONSE" | jq .
    exit 1
else
    echo -e "${GREEN}✅ SuperAdmin Login${NC}"
    echo "$LOGIN_RESPONSE" | jq .
    echo ""
    PASSED=$((PASSED + 1))
    TOTAL=$((TOTAL + 1))
fi

# ===========================================
# STEP 2: School Management (CRUD)
# ===========================================
echo -e "${BLUE}=== 2. SCHOOL MANAGEMENT ===${NC}"

# List all schools
test_api "List All Schools" "GET" "/schools?page=1&per_page=10" "" "$SUPERADMIN_TOKEN"

# Create a new school
TIMESTAMP=$(date +%Y%m%d%H%M%S)
TEST_SUBDOMAIN="test-$TIMESTAMP"

CREATE_SCHOOL_DATA='{
    "name": "Test School '"$TIMESTAMP"'",
    "subdomain": "'"$TEST_SUBDOMAIN"'",
    "email": "admin@test.com",
    "phone": "+2348001234567",
    "address": "Test Address",
    "admin_name": "Test Admin",
    "admin_email": "admin'"$TIMESTAMP"'@test.com",
    "admin_password": "password123"
}'

CREATE_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/schools" \
    -H "Authorization: Bearer $SUPERADMIN_TOKEN" \
    -H "Content-Type: application/json" \
    -d "$CREATE_SCHOOL_DATA")

http_code=$(echo "$CREATE_RESPONSE" | tail -n1)
body=$(echo "$CREATE_RESPONSE" | sed '$d')

if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 300 ]; then
    echo -e "${GREEN}✅ Create School${NC}"
    PASSED=$((PASSED + 1))
    SCHOOL_ID=$(echo "$body" | jq -r '.school.id // .data.id // .id')
    echo "$body" | jq .
    echo "Created School ID: $SCHOOL_ID"
else
    echo -e "${RED}❌ Create School (HTTP $http_code)${NC}"
    FAILED=$((FAILED + 1))
    echo "$body"
    exit 1
fi
TOTAL=$((TOTAL + 1))
echo ""

# Get school details (SuperAdmin specific endpoint)
test_api "Get School Details" "GET" "/admin/schools/$SCHOOL_ID" "" "$SUPERADMIN_TOKEN"

# Update school (SuperAdmin specific endpoint)
UPDATE_SCHOOL_DATA='{
    "name": "Updated Test School",
    "phone": "+234-900-0000",
    "status": "active"
}'
test_api "Update School" "PUT" "/admin/schools/$SCHOOL_ID" "$UPDATE_SCHOOL_DATA" "$SUPERADMIN_TOKEN"

# Get school stats
test_api "Get School Stats" "GET" "/admin/schools/$SCHOOL_ID/stats" "" "$SUPERADMIN_TOKEN"

# Get school dashboard
test_api "Get School Dashboard" "GET" "/admin/schools/$SCHOOL_ID/dashboard" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 3: School Control Actions
# ===========================================
echo -e "${BLUE}=== 3. SCHOOL CONTROL ACTIONS ===${NC}"

# Suspend school
test_api "Suspend School" "POST" "/admin/schools/$SCHOOL_ID/suspend" '{"reason":"Testing suspension"}' "$SUPERADMIN_TOKEN"

# Activate school
test_api "Activate School" "POST" "/admin/schools/$SCHOOL_ID/activate" "" "$SUPERADMIN_TOKEN"

# Send email to school
EMAIL_DATA='{
    "subject": "Test Email",
    "message": "This is a test email from SuperAdmin",
    "recipients": ["admin"]
}'
test_api "Send Email to School" "POST" "/admin/schools/$SCHOOL_ID/send-email" "$EMAIL_DATA" "$SUPERADMIN_TOKEN"

# Get users count
test_api "Get School Users Count" "GET" "/admin/schools/$SCHOOL_ID/users-count" "" "$SUPERADMIN_TOKEN"

# Get activity logs
test_api "Get School Activity Logs" "GET" "/admin/schools/$SCHOOL_ID/activity-logs" "" "$SUPERADMIN_TOKEN"

# Reset admin password
# test_api "Reset School Admin Password" "POST" "/admin/schools/$SCHOOL_ID/reset-admin-password" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 4: Tenant Management
# ===========================================
echo -e "${BLUE}=== 4. TENANT MANAGEMENT ===${NC}"

# List all tenants
test_api "List All Tenants" "GET" "/tenants" "" "$SUPERADMIN_TOKEN"

# Get tenant by school ID (if tenant created)
# test_api "Get Tenant Details" "GET" "/tenants/$SCHOOL_ID" "" "$SUPERADMIN_TOKEN"

# Get tenant stats
# test_api "Get Tenant Stats" "GET" "/tenants/$SCHOOL_ID/stats" "" "$SUPERADMIN_TOKEN"

# Verify tenant
test_api "Verify Tenant" "GET" "/tenants/verify?subdomain=$TEST_SUBDOMAIN" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 5: SuperAdmin Dashboard & Analytics
# ===========================================
echo -e "${BLUE}=== 5. SUPERADMIN DASHBOARD ===${NC}"

# Get SuperAdmin analytics
test_api "Get SuperAdmin Analytics" "GET" "/super-admin/analytics" "" "$SUPERADMIN_TOKEN"

# Get database status
test_api "Get Database Status" "GET" "/super-admin/database" "" "$SUPERADMIN_TOKEN"

# Get security info
test_api "Get Security Info" "GET" "/super-admin/security" "" "$SUPERADMIN_TOKEN"

# Get dashboard (alternative endpoint)
test_api "Get Dashboard" "GET" "/dashboard/super-admin" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 6: Search & Filtering
# ===========================================
echo -e "${BLUE}=== 6. SEARCH & FILTERING ===${NC}"

# Search schools by subdomain
test_api "Get School by Subdomain" "GET" "/schools/by-subdomain/$TEST_SUBDOMAIN" "" "$SUPERADMIN_TOKEN"

# Alternative subdomain lookup
test_api "Get School by Subdomain (Alt)" "GET" "/schools/subdomain/$TEST_SUBDOMAIN" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 7: Public APIs (No Auth Required)
# ===========================================
echo -e "${BLUE}=== 7. PUBLIC APIs ===${NC}"

# Note: These should work without authentication
# but we need to check if they exist in routes

# Check if there are any public endpoints
# test_public_api "Get Public School Info" "GET" "/public/schools/$TEST_SUBDOMAIN"

# ===========================================
# STEP 8: School Deletion (LAST TEST)
# ===========================================
echo -e "${BLUE}=== 8. SCHOOL DELETION ===${NC}"

# Delete school (with database)
test_api "Delete School (Force)" "DELETE" "/schools/$SCHOOL_ID?force=true&delete_database=true" "" "$SUPERADMIN_TOKEN"

# ===========================================
# FINAL RESULTS
# ===========================================
echo -e "${BLUE}================================${NC}"
echo -e "${GREEN}✅ Passed: $PASSED${NC}"
echo -e "${RED}❌ Failed: $FAILED${NC}"
echo -e "${BLUE}Total: $TOTAL${NC}"
echo -e "${BLUE}================================${NC}"

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}❌ Some tests failed!${NC}"
    exit 1
fi

