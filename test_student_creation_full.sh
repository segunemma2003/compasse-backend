#!/bin/bash

# Complete Student Creation Test with Full Setup
# Tests the entire flow: Academic Year -> Term -> Class -> Student

BASE_URL="http://127.0.0.1:8000/api/v1"
SUBDOMAIN="testsch927320"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "=========================================="
echo "🧪 Complete Student Creation Test"
echo "=========================================="
echo ""
echo "Subdomain: $SUBDOMAIN"
echo ""

# Step 1: Login as super admin to get school admin credentials
echo "${BLUE}Step 1: Getting school admin credentials...${NC}"
SUPER_TOKEN=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "superadmin@compasse.net",
    "password": "Nigeria@60"
  }' | jq -r '.token')

if [ "$SUPER_TOKEN" = "null" ] || [ -z "$SUPER_TOKEN" ]; then
    echo "${RED}❌ Super admin login failed${NC}"
    exit 1
fi
echo "${GREEN}✅ Super admin logged in${NC}"

# Get school info
SCHOOL_RESPONSE=$(curl -s -X GET "$BASE_URL/tenants" \
  -H "Authorization: Bearer $SUPER_TOKEN")

ADMIN_EMAIL=$(echo "$SCHOOL_RESPONSE" | jq -r '.[0].admin_email // empty')

if [ -z "$ADMIN_EMAIL" ] || [ "$ADMIN_EMAIL" = "null" ]; then
    echo "${YELLOW}⚠ Could not get admin email from tenants endpoint${NC}"
    echo "Trying to get school data directly..."
    
    # Try to get admin credentials from tenant data
    ADMIN_EMAIL="admin@testsch927320.samschool.com"
fi

echo "Admin Email: $ADMIN_EMAIL"
echo ""

# Step 2: Login as school admin
echo "${BLUE}Step 2: Logging in as school admin...${NC}"
echo "Trying with subdomain: $SUBDOMAIN"

# Try common admin passwords
for ADMIN_PASS in "Password@123" "Admin@123456" "Nigeria@60"; do
    ADMIN_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "Content-Type: application/json" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -d "{
        \"email\": \"$ADMIN_EMAIL\",
        \"password\": \"$ADMIN_PASS\"
      }")
    
    ADMIN_TOKEN=$(echo "$ADMIN_LOGIN" | jq -r '.token')
    
    if [ "$ADMIN_TOKEN" != "null" ] && [ -n "$ADMIN_TOKEN" ]; then
        echo "${GREEN}✅ School admin logged in with password: $ADMIN_PASS${NC}"
        break
    fi
done

if [ "$ADMIN_TOKEN" = "null" ] || [ -z "$ADMIN_TOKEN" ]; then
    echo "${RED}❌ School admin login failed${NC}"
    echo "Response: $ADMIN_LOGIN" | jq .
    exit 1
fi
echo ""

# Step 3: Check/Create Academic Year
echo "${BLUE}Step 3: Setting up Academic Year...${NC}"
YEARS_RESPONSE=$(curl -s -X GET "$BASE_URL/academic-years" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

YEAR_ID=$(echo "$YEARS_RESPONSE" | jq -r '.[0].id // empty')

if [ -z "$YEAR_ID" ] || [ "$YEAR_ID" = "null" ]; then
    echo "Creating new academic year..."
    YEAR_RESPONSE=$(curl -s -X POST "$BASE_URL/academic-years" \
      -H "Authorization: Bearer $ADMIN_TOKEN" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d '{
        "name": "2025-2026",
        "start_date": "2025-09-01",
        "end_date": "2026-08-31",
        "is_current": true
      }')
    
    YEAR_ID=$(echo "$YEAR_RESPONSE" | jq -r '.id // .academic_year.id // empty')
    
    if [ -z "$YEAR_ID" ] || [ "$YEAR_ID" = "null" ]; then
        echo "${RED}❌ Failed to create academic year${NC}"
        echo "$YEAR_RESPONSE" | jq .
        exit 1
    fi
    echo "${GREEN}✅ Academic year created: ID=$YEAR_ID${NC}"
else
    echo "${GREEN}✅ Using existing academic year: ID=$YEAR_ID${NC}"
fi
echo ""

# Step 4: Check/Create Term
echo "${BLUE}Step 4: Setting up Term...${NC}"
TERMS_RESPONSE=$(curl -s -X GET "$BASE_URL/terms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

TERM_ID=$(echo "$TERMS_RESPONSE" | jq -r '.[0].id // empty')

if [ -z "$TERM_ID" ] || [ "$TERM_ID" = "null" ]; then
    echo "Creating new term..."
    TERM_RESPONSE=$(curl -s -X POST "$BASE_URL/terms" \
      -H "Authorization: Bearer $ADMIN_TOKEN" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"name\": \"First Term\",
        \"academic_year_id\": $YEAR_ID,
        \"start_date\": \"2025-09-01\",
        \"end_date\": \"2025-12-20\",
        \"is_current\": true
      }")
    
    TERM_ID=$(echo "$TERM_RESPONSE" | jq -r '.id // .term.id // empty')
    
    if [ -z "$TERM_ID" ] || [ "$TERM_ID" = "null" ]; then
        echo "${RED}❌ Failed to create term${NC}"
        echo "$TERM_RESPONSE" | jq .
        exit 1
    fi
    echo "${GREEN}✅ Term created: ID=$TERM_ID${NC}"
else
    echo "${GREEN}✅ Using existing term: ID=$TERM_ID${NC}"
fi
echo ""

# Step 5: Check/Create Class
echo "${BLUE}Step 5: Setting up Class...${NC}"
CLASSES_RESPONSE=$(curl -s -X GET "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN")

CLASS_ID=$(echo "$CLASSES_RESPONSE" | jq -r '.[0].id // empty')

if [ -z "$CLASS_ID" ] || [ "$CLASS_ID" = "null" ]; then
    echo "Creating new class..."
    CLASS_RESPONSE=$(curl -s -X POST "$BASE_URL/classes" \
      -H "Authorization: Bearer $ADMIN_TOKEN" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -H "Content-Type: application/json" \
      -d "{
        \"name\": \"Grade 10A\",
        \"academic_year_id\": $YEAR_ID,
        \"term_id\": $TERM_ID,
        \"capacity\": 30
      }")
    
    CLASS_ID=$(echo "$CLASS_RESPONSE" | jq -r '.id // .class.id // empty')
    
    if [ -z "$CLASS_ID" ] || [ "$CLASS_ID" = "null" ]; then
        echo "${RED}❌ Failed to create class${NC}"
        echo "$CLASS_RESPONSE" | jq .
        exit 1
    fi
    echo "${GREEN}✅ Class created: ID=$CLASS_ID${NC}"
else
    echo "${GREEN}✅ Using existing class: ID=$CLASS_ID${NC}"
fi
echo ""

# Step 6: Create Student
echo "${BLUE}Step 6: Creating Student...${NC}"
TIMESTAMP=$(date +%s)
STUDENT_RESPONSE=$(curl -s -X POST "$BASE_URL/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"first_name\": \"John\",
    \"last_name\": \"TestStudent$TIMESTAMP\",
    \"class_id\": $CLASS_ID,
    \"date_of_birth\": \"2008-05-15\",
    \"gender\": \"male\",
    \"phone\": \"+1234567890\",
    \"address\": \"123 Test Street\"
  }")

STUDENT_ID=$(echo "$STUDENT_RESPONSE" | jq -r '.student.id // empty')

echo ""
echo "=========================================="
echo "📊 STUDENT CREATION RESULT"
echo "=========================================="

if [ -z "$STUDENT_ID" ] || [ "$STUDENT_ID" = "null" ]; then
    echo "${RED}❌ FAILED: Student creation failed${NC}"
    echo ""
    echo "Error Response:"
    echo "$STUDENT_RESPONSE" | jq .
    exit 1
else
    echo "${GREEN}✅ SUCCESS: Student created!${NC}"
    echo ""
    echo "Student Details:"
    echo "$STUDENT_RESPONSE" | jq '.student | {id, admission_number, first_name, last_name, email, username, class_id}'
    echo ""
    echo "Login Credentials:"
    echo "$STUDENT_RESPONSE" | jq '.login_credentials'
    echo ""
    
    # Test student login
    echo "${BLUE}Step 7: Testing student login...${NC}"
    STUDENT_EMAIL=$(echo "$STUDENT_RESPONSE" | jq -r '.login_credentials.email')
    STUDENT_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "Content-Type: application/json" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -d "{
        \"email\": \"$STUDENT_EMAIL\",
        \"password\": \"Password@123\"
      }")
    
    STUDENT_TOKEN=$(echo "$STUDENT_LOGIN" | jq -r '.token')
    STUDENT_ROLE=$(echo "$STUDENT_LOGIN" | jq -r '.user.role')
    
    if [ "$STUDENT_TOKEN" != "null" ] && [ -n "$STUDENT_TOKEN" ] && [ "$STUDENT_ROLE" = "student" ]; then
        echo "${GREEN}✅ Student can login successfully!${NC}"
        echo "  Role: $STUDENT_ROLE"
        echo "  Token: ${STUDENT_TOKEN:0:30}..."
    else
        echo "${RED}❌ Student login failed${NC}"
        echo "$STUDENT_LOGIN" | jq .
    fi
fi

echo ""
echo "=========================================="
echo "✅ Test Complete!"
echo "=========================================="

