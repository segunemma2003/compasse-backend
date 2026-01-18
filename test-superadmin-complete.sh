#!/bin/bash

# Comprehensive SuperAdmin API Test Script
# Tests all superadmin capabilities including school management, email sending, etc.

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

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
    
    echo ""
    echo -e "${YELLOW}Testing: $name${NC}"
    
    local cmd="curl -s -w '\n%{http_code}' -X $method '$url' -H 'Content-Type: application/json' -H 'Accept: application/json'"
    
    if [ -n "$TOKEN" ]; then
        cmd="$cmd -H 'Authorization: Bearer $TOKEN'"
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
echo -e "${BLUE}║        SuperAdmin Complete Feature Test           ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""

# Login
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}1. SUPERADMIN AUTHENTICATION${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

login_data='{"email":"superadmin@compasse.net","password":"Nigeria@60"}'
login_response=$(curl -s -X POST "$API_URL/auth/login" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d "$login_data")

echo "$login_response" | jq '.'

TOKEN=$(echo "$login_response" | jq -r '.token // .access_token // empty')

if [ -n "$TOKEN" ]; then
    echo -e "${GREEN}✅ Login successful${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ Login failed${NC}"
    FAILED=$((FAILED + 1))
    exit 1
fi

# Create School
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}2. CREATE SCHOOL${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

timestamp=$(date +%s)
school_data="{\"name\":\"Demo School $timestamp\",\"subdomain\":\"demoschool$timestamp\",\"email\":\"admin@demoschool.com\",\"phone\":\"+234-800-123-4567\",\"address\":\"123 Main Street, Lagos, Nigeria\",\"plan_id\":1}"

create_response=$(curl -s -X POST "$API_URL/schools" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -d "$school_data")

echo "$create_response" | jq '.'

SCHOOL_ID=$(echo "$create_response" | jq -r '.school.id // .data.id // .id // empty')
SUBDOMAIN=$(echo "$create_response" | jq -r '.tenant.subdomain // .data.subdomain // .school.subdomain // .subdomain // empty')

if [ -n "$SCHOOL_ID" ]; then
    echo -e "${GREEN}✅ School created (ID: $SCHOOL_ID, Subdomain: $SUBDOMAIN)${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ School creation failed${NC}"
    FAILED=$((FAILED + 1))
fi

# Test SuperAdmin Operations
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}3. SUPERADMIN SCHOOL MANAGEMENT OPERATIONS${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [ -n "$SCHOOL_ID" ]; then
    # Get users count
    echo ""
    echo -e "${YELLOW}Getting users count...${NC}"
    users_response=$(curl -s -X GET "$API_URL/schools/$SCHOOL_ID/users-count" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json")
    echo "$users_response" | jq '.'
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Users count retrieved${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ Users count failed${NC}"
        FAILED=$((FAILED + 1))
    fi
    
    # Suspend school
    echo ""
    echo -e "${YELLOW}Suspending school...${NC}"
    suspend_response=$(curl -s -X POST "$API_URL/schools/$SCHOOL_ID/suspend" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json")
    echo "$suspend_response" | jq '.'
    if echo "$suspend_response" | jq -e '.message' > /dev/null 2>&1; then
        echo -e "${GREEN}✅ School suspended${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ School suspension failed${NC}"
        FAILED=$((FAILED + 1))
    fi
    
    # Activate school
    echo ""
    echo -e "${YELLOW}Activating school...${NC}"
    activate_response=$(curl -s -X POST "$API_URL/schools/$SCHOOL_ID/activate" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json")
    echo "$activate_response" | jq '.'
    if echo "$activate_response" | jq -e '.message' > /dev/null 2>&1; then
        echo -e "${GREEN}✅ School activated${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ School activation failed${NC}"
        FAILED=$((FAILED + 1))
    fi
    
    # Send email to school
    echo ""
    echo -e "${YELLOW}Sending email to school admin...${NC}"
    email_data="{\"subject\":\"Welcome to SamSchool Platform\",\"message\":\"Your school has been successfully added to our platform.\",\"send_to\":\"admin\"}"
    email_response=$(curl -s -X POST "$API_URL/schools/$SCHOOL_ID/send-email" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json" \
        -d "$email_data")
    echo "$email_response" | jq '.'
    if echo "$email_response" | jq -e '.message' > /dev/null 2>&1; then
        echo -e "${GREEN}✅ Email queued${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ Email sending failed${NC}"
        FAILED=$((FAILED + 1))
    fi
    
    # Reset admin password
    echo ""
    echo -e "${YELLOW}Resetting school admin password...${NC}"
    reset_response=$(curl -s -X POST "$API_URL/schools/$SCHOOL_ID/reset-admin-password" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Accept: application/json")
    echo "$reset_response" | jq '.'
    if echo "$reset_response" | jq -e '.message' > /dev/null 2>&1; then
        echo -e "${GREEN}✅ Admin password reset${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌ Password reset failed${NC}"
        FAILED=$((FAILED + 1))
    fi
fi

# Delete School
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}4. DELETE SCHOOL (CLEANUP)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [ -n "$SCHOOL_ID" ]; then
    echo ""
    echo -e "${YELLOW}Deleting test school (force delete)...${NC}"
    
    delete_response=$(curl -s -w '\n%{http_code}' -X DELETE "$API_URL/schools/$SCHOOL_ID?force=true" \
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
        echo "This is expected if there are database constraints"
        FAILED=$((FAILED + 1))
    fi
fi

# Summary
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
    SUCCESS_RATE=$(awk "BEGIN {printf \"%.0f\", ($PASSED/$TOTAL)*100}")
    echo -e "${YELLOW}⚠️  $FAILED test(s) failed (${SUCCESS_RATE}% success rate)${NC}"
    exit 1
fi

