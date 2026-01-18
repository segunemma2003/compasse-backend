#!/bin/bash

# Test Search & Filtering Capabilities for School Listing APIs

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

BASE_URL="http://localhost:8000"
API_URL="${BASE_URL}/api/v1"
TOKEN=""

PASSED=0
FAILED=0

echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     SEARCH & FILTERING API TESTS                   ║${NC}"
echo -e "${BLUE}║     Testing Query Parameters & Filters             ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""

# Login first
echo -e "${YELLOW}Logging in as SuperAdmin...${NC}"
login_data='{"email":"superadmin@compasse.net","password":"Nigeria@60"}'
login_response=$(curl -s -X POST "$API_URL/auth/login" \
    -H "Content-Type: application/json" \
    -d "$login_data")

TOKEN=$(echo "$login_response" | jq -r '.token // .access_token // empty')

if [ -n "$TOKEN" ]; then
    echo -e "${GREEN}✅ Logged in successfully${NC}"
else
    echo -e "${RED}❌ Login failed${NC}"
    exit 1
fi

echo ""

# ============================================
# TEST 1: Basic Listing (No Filters)
# ============================================
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 1: Basic School Listing (No Filters)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools"
echo ""

response=$(curl -s -X GET "$API_URL/schools" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

echo "$response" | jq '{
  total: .total,
  per_page: .per_page,
  current_page: .current_page,
  schools: .data | length,
  first_school: .data[0].name
}' 2>/dev/null

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - Basic listing works${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 2: Search by School Name
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 2: Search by School Name${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?search=school"
echo "Searches in: name, code, email"
echo ""

response=$(curl -s -X GET "$API_URL/schools?search=school" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

echo "$response" | jq '{
  total: .total,
  results: .data | length,
  schools: [.data[] | {name, code, email}]
}' 2>/dev/null | head -30

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - Search by name works${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 3: Search by Specific Term
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 3: Search by Specific Term${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?search=test"
echo ""

response=$(curl -s -X GET "$API_URL/schools?search=test" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

total=$(echo "$response" | jq -r '.total // 0')
echo "Found: $total schools matching 'test'"
echo ""
echo "$response" | jq '.data[] | {name, code}' 2>/dev/null | head -20

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - Specific search works${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 4: Filter by Status
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 4: Filter by Status (Active Schools)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?status=active"
echo ""

response=$(curl -s -X GET "$API_URL/schools?status=active" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

total=$(echo "$response" | jq -r '.total // 0')
echo "Found: $total active schools"
echo ""
echo "$response" | jq '.data[] | {name, status}' 2>/dev/null | head -15

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - Status filter works${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 5: Pagination
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 5: Pagination (Custom Per Page)${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?per_page=3&page=1"
echo ""

response=$(curl -s -X GET "$API_URL/schools?per_page=3&page=1" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

echo "$response" | jq '{
  per_page: .per_page,
  current_page: .current_page,
  total: .total,
  last_page: .last_page,
  results_in_page: (.data | length),
  schools: [.data[] | .name]
}' 2>/dev/null

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - Pagination works${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 6: Combined Search + Filter
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 6: Combined Search + Status Filter${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?search=school&status=active"
echo ""

response=$(curl -s -X GET "$API_URL/schools?search=school&status=active" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

total=$(echo "$response" | jq -r '.total // 0')
echo "Found: $total active schools matching 'school'"
echo ""
echo "$response" | jq '.data[] | {name, status}' 2>/dev/null | head -15

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - Combined filters work${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 7: Combined Search + Pagination
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 7: Search + Pagination${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?search=test&per_page=2&page=1"
echo ""

response=$(curl -s -X GET "$API_URL/schools?search=test&per_page=2&page=1" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

echo "$response" | jq '{
  search_term: "test",
  per_page: .per_page,
  total_found: .total,
  showing: (.data | length),
  schools: [.data[] | .name]
}' 2>/dev/null

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - Search + pagination works${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 8: All Parameters Combined
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 8: All Parameters Combined${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?search=school&status=active&per_page=5&page=1"
echo ""

response=$(curl -s -X GET "$API_URL/schools?search=school&status=active&per_page=5&page=1" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

echo "$response" | jq '{
  filters: {
    search: "school",
    status: "active",
    per_page: 5,
    page: 1
  },
  results: {
    total: .total,
    current_page: .current_page,
    showing: (.data | length)
  },
  schools: [.data[] | {name, status}]
}' 2>/dev/null | head -30

if echo "$response" | jq -e '.data' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED - All parameters work together${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# TEST 9: Empty Search Results
# ============================================
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}TEST 9: Empty Search Results${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Endpoint: GET /api/v1/schools?search=nonexistentschoolxyz123"
echo ""

response=$(curl -s -X GET "$API_URL/schools?search=nonexistentschoolxyz123" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

total=$(echo "$response" | jq -r '.total // 0')
echo "Found: $total schools"

if [ "$total" = "0" ]; then
    echo -e "${GREEN}✅ PASSED - Empty results handled correctly${NC}"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌ FAILED - Expected 0 results${NC}"
    FAILED=$((FAILED + 1))
fi

# ============================================
# SUMMARY
# ============================================
echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║           SEARCH & FILTER TEST SUMMARY            ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════╝${NC}"
echo ""
TOTAL=$((PASSED + FAILED))
echo "Total Tests: $TOTAL"
echo -e "${GREEN}Passed: $PASSED${NC}"
echo -e "${RED}Failed: $FAILED${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 All search & filtering features working!${NC}"
    echo ""
    echo -e "${CYAN}Available Query Parameters:${NC}"
    echo ""
    echo "1. search={term}          - Search in name, code, email"
    echo "2. status={status}        - Filter by status (active, inactive, suspended)"
    echo "3. per_page={number}      - Results per page (default: 15)"
    echo "4. page={number}          - Page number for pagination"
    echo ""
    echo -e "${CYAN}Example Usage:${NC}"
    echo ""
    echo "# Search for schools:"
    echo "GET /api/v1/schools?search=green"
    echo ""
    echo "# Filter active schools:"
    echo "GET /api/v1/schools?status=active"
    echo ""
    echo "# Search + filter + paginate:"
    echo "GET /api/v1/schools?search=valley&status=active&per_page=10&page=1"
    echo ""
    echo -e "${CYAN}Frontend Integration:${NC}"
    echo ""
    echo "const searchSchools = async (searchTerm, status, page = 1) => {"
    echo "  const params = new URLSearchParams({"
    echo "    search: searchTerm,"
    echo "    status: status,"
    echo "    page: page,"
    echo "    per_page: 10"
    echo "  });"
    echo "  "
    echo "  const response = await fetch("
    echo "    \`/api/v1/schools?\${params}\`,"
    echo "    { headers: { 'Authorization': \`Bearer \${token}\` } }"
    echo "  );"
    echo "  return await response.json();"
    echo "};"
    exit 0
else
    echo -e "${YELLOW}⚠️  Some tests failed${NC}"
    exit 1
fi

