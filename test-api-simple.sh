#!/bin/bash

# Simple API Test Script for SamSchool Backend
# Tests main website APIs and superadmin functionality

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Config
BASE_URL="http://localhost:8000"
API_URL="${BASE_URL}/api/v1"
TOKEN=""
SCHOOL_ID=""
SUBDOMAIN=""

PASSED=0
FAILED=0

test_endpoint() {
    local name=$1
    local method=$2
    local url=$3
    local data=$4
    local auth=$5
    local tenant=$6
    
    echo ""
    echo -e "${YELLOW}Testing: $name${NC}"
    
    local cmd="curl -s -w '\n%{http_code}' -X $method '$url' -H 'Content-Type: application/json' -H 'Accept: application/json'"
    
    if [ "$auth" = "true" ] && [ -n "$TOKEN" ]; then
        cmd="$cmd -H 'Authorization: Bearer $TOKEN'"
    fi
    
    if [ "$tenant" = "true" ] && [ -n "$SUBDOMAIN" ]; then
        cmd="$cmd -H 'X-Subdomain: $SUBDOMAIN'"
    fi
    
    if [ -n "$data" ]; then
        cmd="$cmd -d '$data'"
    fi
    
    response=$(eval $cmd)
    body=$(echo "$response" | sed '$d')
    status=$(echo "$response" | tail -n 1)
    
    echo "$body" | jq '.' 2>/dev/null || echo "$body"
    echo "Status: $status"
    
    if [ "$status" = "200" ] || [ "$status" = "201" ]; then
        echo -e "${GREEN}✅ PASSED${NC}"
        PASSED=$((PASSED + 1))
        return 0
    else
        echo -e "${RED}❌ FAILED (Status: $status)${NC}"
        FAILED=$((FAILED + 1))
        return 1
    fi
}

clear
echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   SamSchool Backend API Testing Suite             ║${NC}"
echo -e "${BLUE}║   Main Site & SuperAdmin Testing                  ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""

# ============================================
# PUBLIC ENDPOINTS
# ============================================
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}1. PUBLIC ENDPOINTS (No Auth)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

test_endpoint "Health Check" "GET" "$BASE_URL/api/health" "" false false
test_endpoint "Database Health" "GET" "$BASE_URL/api/health/db" "" false false

# ============================================
# SUPERADMIN LOGIN
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}2. SUPERADMIN AUTHENTICATION${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

login_data='{"email":"superadmin@compasse.net","password":"Nigeria@60"}'
login_response=$(curl -s -X POST "$API_URL/auth/login" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d "$login_data")

echo ""
echo -e "${YELLOW}Logging in as SuperAdmin...${NC}"
echo "$login_response" | jq '.'

TOKEN=$(echo "$login_response" | jq -r '.token // .access_token // empty')

if [ -n "$TOKEN" ]; then
    echo -e "${GREEN}✅ Login successful${NC}"
    echo "Token: ${TOKEN:0:30}..."
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ Login failed${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# SUPERADMIN AUTHENTICATED ENDPOINTS
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}3. SUPERADMIN AUTHENTICATED ENDPOINTS${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

test_endpoint "Get Current User (SuperAdmin)" "GET" "$API_URL/auth/me" "" true false

test_endpoint "List All Schools" "GET" "$API_URL/schools" "" true false

test_endpoint "List All Tenants" "GET" "$API_URL/tenants" "" true false

test_endpoint "SuperAdmin Dashboard" "GET" "$API_URL/dashboard/super-admin" "" true false

# ============================================
# CREATE SCHOOL
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}4. CREATE SCHOOL (SuperAdmin)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

timestamp=$(date +%s)
school_data="{\"name\":\"Test School $timestamp\",\"subdomain\":\"testschool$timestamp\",\"email\":\"admin@testschool.com\",\"phone\":\"+234-800-000-0000\",\"address\":\"123 Test Street, Lagos\",\"plan_id\":1}"

echo ""
echo -e "${YELLOW}Creating test school...${NC}"

create_response=$(curl -s -X POST "$API_URL/schools" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -d "$school_data")

echo "$create_response" | jq '.'

SCHOOL_ID=$(echo "$create_response" | jq -r '.school.id // .data.id // .id // empty')
SUBDOMAIN=$(echo "$create_response" | jq -r '.tenant.subdomain // .data.subdomain // .school.subdomain // .subdomain // empty')

if [ -n "$SCHOOL_ID" ]; then
    echo -e "${GREEN}✅ School created${NC}"
    echo "School ID: $SCHOOL_ID"
    echo "Subdomain: $SUBDOMAIN"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ School creation failed${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# PUBLIC SCHOOL LOOKUP
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}5. PUBLIC SCHOOL LOOKUP${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [ -n "$SUBDOMAIN" ]; then
    test_endpoint "Lookup School by Subdomain" "GET" "$API_URL/schools/by-subdomain/$SUBDOMAIN" "" false false
    
    test_endpoint "Verify Tenant" "POST" "$API_URL/tenants/verify" "{\"subdomain\":\"$SUBDOMAIN\"}" false false
fi

# ============================================
# TENANT-SPECIFIC OPERATIONS
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}6. TENANT-SPECIFIC OPERATIONS (With X-Subdomain)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [ -n "$SCHOOL_ID" ] && [ -n "$SUBDOMAIN" ]; then
    test_endpoint "Get School Details" "GET" "$API_URL/schools/$SCHOOL_ID" "" true true
    
    test_endpoint "Get School Stats" "GET" "$API_URL/schools/$SCHOOL_ID/stats" "" true true
    
    test_endpoint "Get Subscription Status" "GET" "$API_URL/subscriptions/status" "" true true
    
    test_endpoint "Get Available Plans" "GET" "$API_URL/subscriptions/plans" "" true true
    
    test_endpoint "List Users in School" "GET" "$API_URL/users" "" true true
fi

# ============================================
# CLEANUP
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}7. CLEANUP${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [ -n "$SCHOOL_ID" ]; then
    echo ""
    echo -e "${YELLOW}Deleting test school...${NC}"
    
    delete_response=$(curl -s -w '\n%{http_code}' -X DELETE "$API_URL/schools/$SCHOOL_ID" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json")
    
    delete_body=$(echo "$delete_response" | sed '$d')
    delete_status=$(echo "$delete_response" | tail -n 1)
    
    echo "$delete_body" | jq '.' 2>/dev/null || echo "$delete_body"
    
    if [ "$delete_status" = "200" ] || [ "$delete_status" = "204" ]; then
        echo -e "${GREEN}✅ School deleted${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${YELLOW}⚠️  Could not delete school (Status: $delete_status)${NC}"
        FAILED=$((FAILED + 1))
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
TOTAL=$((PASSED + FAILED))
echo "Total Tests: $TOTAL"
echo -e "${GREEN}Passed: $PASSED${NC}"
echo -e "${RED}Failed: $FAILED${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 All tests passed!${NC}"
    exit 0
else
    echo -e "${YELLOW}⚠️  $FAILED test(s) failed${NC}"
    exit 1
fi

