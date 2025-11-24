#!/bin/bash

# Simple Student Creation Test - Creates everything from scratch

BASE_URL="http://127.0.0.1:8000/api/v1"
TIMESTAMP=$(date +%s)
SUBDOMAIN="testschool$TIMESTAMP"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "=========================================="
echo "🧪 Student Creation Test (Fresh School)"
echo "=========================================="
echo ""

# Step 1: Login as super admin
echo "${BLUE}Step 1: Login as super admin...${NC}"
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
echo "${GREEN}✅ Logged in${NC}"
echo ""

# Step 2: Create school
echo "${BLUE}Step 2: Create school...${NC}"
SCHOOL_RESPONSE=$(curl -s -X POST "$BASE_URL/schools" \
  -H "Authorization: Bearer $SUPER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Test School $TIMESTAMP\",
    \"subdomain\": \"$SUBDOMAIN\",
    \"email\": \"admin@$SUBDOMAIN.com\",
    \"phone\": \"+1234567890\",
    \"address\": \"123 Test St\",
    \"website\": \"https://$SUBDOMAIN.com\"
  }")

ADMIN_EMAIL=$(echo "$SCHOOL_RESPONSE" | jq -r '.tenant.admin_credentials.email // .admin_credentials.email // empty')
ADMIN_PASSWORD=$(echo "$SCHOOL_RESPONSE" | jq -r '.tenant.admin_credentials.password // .admin_credentials.password // empty')

if [ -z "$ADMIN_EMAIL" ] || [ "$ADMIN_EMAIL" = "null" ]; then
    echo "${RED}❌ School creation failed${NC}"
    echo "$SCHOOL_RESPONSE" | jq .
    exit 1
fi

echo "${GREEN}✅ School created${NC}"
echo "  Subdomain: $SUBDOMAIN"
echo "  Admin Email: $ADMIN_EMAIL"
echo "  Admin Password: $ADMIN_PASSWORD"
echo ""

# Wait a bit for database setup
sleep 2

# Step 3: Login as school admin
echo "${BLUE}Step 3: Login as school admin...${NC}"
ADMIN_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -d "{
    \"email\": \"$ADMIN_EMAIL\",
    \"password\": \"$ADMIN_PASSWORD\"
  }")

ADMIN_TOKEN=$(echo "$ADMIN_LOGIN" | jq -r '.token')

if [ "$ADMIN_TOKEN" = "null" ] || [ -z "$ADMIN_TOKEN" ]; then
    echo "${RED}❌ Admin login failed${NC}"
    echo "$ADMIN_LOGIN" | jq .
    exit 1
fi
echo "${GREEN}✅ Admin logged in${NC}"
echo ""

# Step 4: Create academic year
echo "${BLUE}Step 4: Create academic year...${NC}"
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
    echo "${RED}❌ Academic year creation failed${NC}"
    echo "$YEAR_RESPONSE" | jq .
    exit 1
fi
echo "${GREEN}✅ Academic year created: ID=$YEAR_ID${NC}"
echo ""

# Step 5: Create term
echo "${BLUE}Step 5: Create term...${NC}"
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
    echo "${RED}❌ Term creation failed${NC}"
    echo "$TERM_RESPONSE" | jq .
    exit 1
fi
echo "${GREEN}✅ Term created: ID=$TERM_ID${NC}"
echo ""

# Step 6: Create class
echo "${BLUE}Step 6: Create class...${NC}"
CLASS_RESPONSE=$(curl -s -X POST "$BASE_URL/classes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Grade 10A\",
    \"academic_year_id\": $YEAR_ID,
    \"term_id\": $TERM_ID,
    \"capacity\": 30,
    \"description\": \"Test class for student creation\"
  }")

CLASS_ID=$(echo "$CLASS_RESPONSE" | jq -r '.id // .class.id // empty')

if [ -z "$CLASS_ID" ] || [ "$CLASS_ID" = "null" ]; then
    echo "${RED}❌ Class creation failed${NC}"
    echo "$CLASS_RESPONSE" | jq .
    exit 1
fi
echo "${GREEN}✅ Class created: ID=$CLASS_ID${NC}"
echo ""

# Step 7: Create student
echo "${BLUE}Step 7: Create student...${NC}"
echo ""
STUDENT_RESPONSE=$(curl -s -X POST "$BASE_URL/students" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Subdomain: $SUBDOMAIN" \
  -H "Content-Type: application/json" \
  -d "{
    \"first_name\": \"John\",
    \"last_name\": \"Doe\",
    \"class_id\": $CLASS_ID,
    \"date_of_birth\": \"2008-05-15\",
    \"gender\": \"male\",
    \"phone\": \"+1234567890\",
    \"address\": \"123 Main Street, Test City\"
  }")

STUDENT_ID=$(echo "$STUDENT_RESPONSE" | jq -r '.student.id // empty')

echo "=========================================="
echo "📊 RESULT"
echo "=========================================="
echo ""

if [ -z "$STUDENT_ID" ] || [ "$STUDENT_ID" = "null" ]; then
    echo "${RED}❌ FAILED: Student creation failed${NC}"
    echo ""
    echo "Full Response:"
    echo "$STUDENT_RESPONSE" | jq .
    exit 1
else
    echo "${GREEN}✅ SUCCESS: Student created!${NC}"
    echo ""
    echo "Student Details:"
    echo "$STUDENT_RESPONSE" | jq '{
      id: .student.id,
      admission_number: .student.admission_number,
      name: (.student.first_name + " " + .student.last_name),
      email: .student.email,
      username: .student.username,
      class_id: .student.class_id
    }'
    echo ""
    echo "Login Credentials:"
    echo "$STUDENT_RESPONSE" | jq '.login_credentials'
    echo ""
    
    # Step 8: Test student login
    echo "${BLUE}Step 8: Test student login...${NC}"
    STUDENT_EMAIL=$(echo "$STUDENT_RESPONSE" | jq -r '.login_credentials.email // .student.email')
    
    STUDENT_LOGIN=$(curl -s -X POST "$BASE_URL/auth/login" \
      -H "Content-Type: application/json" \
      -H "X-Subdomain: $SUBDOMAIN" \
      -d "{
        \"email\": \"$STUDENT_EMAIL\",
        \"password\": \"Password@123\"
      }")
    
    STUDENT_TOKEN=$(echo "$STUDENT_LOGIN" | jq -r '.token')
    STUDENT_ROLE=$(echo "$STUDENT_LOGIN" | jq -r '.user.role')
    
    if [ "$STUDENT_TOKEN" != "null" ] && [ -n "$STUDENT_TOKEN" ]; then
        echo "${GREEN}✅ Student login successful!${NC}"
        echo "  Email: $STUDENT_EMAIL"
        echo "  Role: $STUDENT_ROLE"
        echo "  Token: ${STUDENT_TOKEN:0:30}..."
        
        if [ "$STUDENT_ROLE" = "student" ]; then
            echo "${GREEN}✅ Role is correct: student${NC}"
        else
            echo "${RED}❌ Wrong role: $STUDENT_ROLE (expected: student)${NC}"
        fi
    else
        echo "${RED}❌ Student login failed${NC}"
        echo "$STUDENT_LOGIN" | jq .
    fi
fi

echo ""
echo "=========================================="
echo "✅ Test Complete!"
echo "=========================================="
echo ""
echo "Summary:"
echo "  School: $SUBDOMAIN"
echo "  Class ID: $CLASS_ID"
echo "  Student ID: $STUDENT_ID"
echo "  Student Email: $STUDENT_EMAIL"

