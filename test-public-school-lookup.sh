#!/bin/bash

# Test Public School Lookup APIs - NO AUTHENTICATION REQUIRED
# These are the APIs that anyone can use to check if a school exists

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

BASE_URL="http://localhost:8000"
API_URL="${BASE_URL}/api/v1"

PASSED=0
FAILED=0

echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     PUBLIC SCHOOL LOOKUP API TESTS                 ║${NC}"
echo -e "${BLUE}║     No Authentication Required                     ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""

# Get an existing school subdomain first
echo -e "${YELLOW}Getting an existing school subdomain for testing...${NC}"
schools=$(curl -s "$API_URL/schools/by-subdomain/testsch927320")
echo "$schools" | jq '.'
echo ""

# Use a known subdomain from your database
SUBDOMAIN="testsch927320"  # Using one from previous tests

echo -e "${BLUE}Testing with subdomain: $SUBDOMAIN${NC}"
echo ""

# ============================================
# TEST 1: Check if school exists (Path parameter)
# ============================================
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 1: Check School Exists (Path Parameter)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools/by-subdomain/{subdomain}"
echo "Purpose: Quick check if school exists"
echo ""

response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/by-subdomain/$SUBDOMAIN" \
    -H "Accept: application/json")

body=$(echo "$response" | sed '$d')
status=$(echo "$response" | tail -n 1)

echo "$body" | jq '.' 2>/dev/null || echo "$body"
echo ""

if [ "$status" = "200" ]; then
    echo -e "${GREEN}✅ PASSED - School found!${NC}"
    PASSED=$((PASSED + 1))
    
    # Extract school info
    exists=$(echo "$body" | jq -r '.exists // false')
    tenant_name=$(echo "$body" | jq -r '.tenant.name // "N/A"')
    tenant_subdomain=$(echo "$body" | jq -r '.tenant.subdomain // "N/A"')
    tenant_status=$(echo "$body" | jq -r '.tenant.status // "N/A"')
    
    echo ""
    echo "School Details:"
    echo "  - Exists: $exists"
    echo "  - Name: $tenant_name"
    echo "  - Subdomain: $tenant_subdomain"
    echo "  - Status: $tenant_status"
else
    echo -e "${RED}❌ FAILED (Status: $status)${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 2: Check school exists (Query parameter)
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 2: Check School Exists (Query Parameter)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools/by-subdomain?subdomain={subdomain}"
echo "Purpose: Alternative way to check school"
echo ""

response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/by-subdomain?subdomain=$SUBDOMAIN" \
    -H "Accept: application/json")

body=$(echo "$response" | sed '$d')
status=$(echo "$response" | tail -n 1)

echo "$body" | jq '.' 2>/dev/null || echo "$body"
echo ""

if [ "$status" = "200" ]; then
    echo -e "${GREEN}✅ PASSED - School found!${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED (Status: $status)${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 3: Get full school information
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 3: Get Full School Information${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools/subdomain/{subdomain}"
echo "Purpose: Get detailed school info with stats"
echo ""

response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/subdomain/$SUBDOMAIN" \
    -H "Accept: application/json")

body=$(echo "$response" | sed '$d')
status=$(echo "$response" | tail -n 1)

echo "$body" | jq '.' 2>/dev/null || echo "$body"
echo ""

if [ "$status" = "200" ]; then
    echo -e "${GREEN}✅ PASSED - Full school info retrieved!${NC}"
    PASSED=$((PASSED + 1))
    
    # Extract detailed info
    school_name=$(echo "$body" | jq -r '.school.name // "N/A"')
    school_address=$(echo "$body" | jq -r '.school.address // "N/A"')
    school_phone=$(echo "$body" | jq -r '.school.phone // "N/A"')
    school_email=$(echo "$body" | jq -r '.school.email // "N/A"')
    
    echo ""
    echo "Detailed School Information:"
    echo "  - Name: $school_name"
    echo "  - Address: $school_address"
    echo "  - Phone: $school_phone"
    echo "  - Email: $school_email"
else
    echo -e "${RED}❌ FAILED (Status: $status)${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 4: Verify tenant with POST
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 4: Verify Tenant (POST)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: POST /api/v1/tenants/verify"
echo "Purpose: Verify tenant exists and is active"
echo ""

response=$(curl -s -w '\n%{http_code}' -X POST "$API_URL/tenants/verify" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d "{\"subdomain\":\"$SUBDOMAIN\"}")

body=$(echo "$response" | sed '$d')
status=$(echo "$response" | tail -n 1)

echo "$body" | jq '.' 2>/dev/null || echo "$body"
echo ""

if [ "$status" = "200" ]; then
    echo -e "${GREEN}✅ PASSED - Tenant verified!${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED (Status: $status)${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 5: Test with non-existent school
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 5: Test Non-Existent School${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools/by-subdomain/nonexistentschool"
echo "Purpose: Verify proper 404 response"
echo ""

response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/by-subdomain/nonexistentschool" \
    -H "Accept: application/json")

body=$(echo "$response" | sed '$d')
status=$(echo "$response" | tail -n 1)

echo "$body" | jq '.' 2>/dev/null || echo "$body"
echo ""

if [ "$status" = "404" ]; then
    echo -e "${GREEN}✅ PASSED - Correctly returns 404 for non-existent school${NC}"
    PASSED=$((PASSED + 1))
    
    exists=$(echo "$body" | jq -r '.exists // "N/A"')
    echo "  - Exists: $exists (should be false)"
else
    echo -e "${RED}❌ FAILED (Expected 404, got: $status)${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 6: Test without subdomain parameter
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 6: Test Missing Subdomain Parameter${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools/by-subdomain"
echo "Purpose: Verify proper error handling"
echo ""

response=$(curl -s -w '\n%{http_code}' -X GET "$API_URL/schools/by-subdomain" \
    -H "Accept: application/json")

body=$(echo "$response" | sed '$d')
status=$(echo "$response" | tail -n 1)

echo "$body" | jq '.' 2>/dev/null || echo "$body"
echo ""

if [ "$status" = "400" ]; then
    echo -e "${GREEN}✅ PASSED - Correctly returns 400 for missing parameter${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED (Expected 400, got: $status)${NC}"
    FAILED=$((FAILED + 1))
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
    echo -e "${GREEN}🎉 All public school lookup APIs working!${NC}"
    echo ""
    echo "Frontend Integration Examples:"
    echo ""
    echo "1. Quick check if school exists:"
    echo "   fetch('http://localhost:8000/api/v1/schools/by-subdomain/myschool')"
    echo ""
    echo "2. Get full school info:"
    echo "   fetch('http://localhost:8000/api/v1/schools/subdomain/myschool')"
    echo ""
    echo "3. Verify tenant:"
    echo "   fetch('http://localhost:8000/api/v1/tenants/verify', {"
    echo "     method: 'POST',"
    echo "     body: JSON.stringify({subdomain: 'myschool'})"
    echo "   })"
    exit 0
else
    echo -e "${YELLOW}⚠️  Some tests failed${NC}"
    exit 1
fi

