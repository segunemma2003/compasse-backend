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
    local subdomain=$6
    
    TOTAL=$((TOTAL + 1))
    
    if [ -z "$subdomain" ]; then
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
    else
        if [ -z "$data" ]; then
            response=$(curl -s -w "\n%{http_code}" -X $method "$BASE_URL$endpoint" \
                -H "Authorization: Bearer $token" \
                -H "X-Subdomain: $subdomain" \
                -H "Content-Type: application/json")
        else
            response=$(curl -s -w "\n%{http_code}" -X $method "$BASE_URL$endpoint" \
                -H "Authorization: Bearer $token" \
                -H "X-Subdomain: $subdomain" \
                -H "Content-Type: application/json" \
                -d "$data")
        fi
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

# Test /auth/me for SuperAdmin
# Note: There's a separate endpoint for SuperAdmin
# test_api "Get SuperAdmin Profile" "GET" "/auth/me-superadmin" "" "$SUPERADMIN_TOKEN"

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
    "email": "admin@test'"$TIMESTAMP"'.com",
    "phone": "+234-800-'"$TIMESTAMP"'",
    "address": "Test Address",
    "admin_name": "Test Admin",
    "admin_email": "admin@test'"$TIMESTAMP"'.com",
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

# Get school details
test_api "Get School Details" "GET" "/admin/schools/$SCHOOL_ID" "" "$SUPERADMIN_TOKEN"

# Update school
UPDATE_SCHOOL_DATA='{
    "name": "Updated Test School",
    "phone": "+234-900-0000",
    "status": "active"
}'
test_api "Update School" "PUT" "/admin/schools/$SCHOOL_ID" "$UPDATE_SCHOOL_DATA" "$SUPERADMIN_TOKEN"

# Get school stats
test_api "Get School Stats" "GET" "/admin/schools/$SCHOOL_ID/stats" "" "$SUPERADMIN_TOKEN"

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

# ===========================================
# STEP 4: Subscription Management
# ===========================================
echo -e "${BLUE}=== 4. SUBSCRIPTION MANAGEMENT ===${NC}"

# List all plans
test_api "List All Plans" "GET" "/admin/plans" "" "$SUPERADMIN_TOKEN"

# Create a plan
CREATE_PLAN_DATA='{
    "name": "Premium Plan",
    "description": "Premium features",
    "price": 50000,
    "duration_days": 365,
    "features": {
        "modules": ["academic_management", "student_management", "cbt"],
        "limits": {
            "students": 1000,
            "teachers": 100,
            "storage": 50000
        }
    }
}'
test_api "Create Plan" "POST" "/admin/plans" "$CREATE_PLAN_DATA" "$SUPERADMIN_TOKEN"

# Get plan (assuming plan ID 1 exists or was just created)
# test_api "Get Plan Details" "GET" "/admin/plans/1" "" "$SUPERADMIN_TOKEN"

# Update plan
# UPDATE_PLAN_DATA='{"price": 55000}'
# test_api "Update Plan" "PUT" "/admin/plans/1" "$UPDATE_PLAN_DATA" "$SUPERADMIN_TOKEN"

# List subscriptions
test_api "List All Subscriptions" "GET" "/admin/subscriptions" "" "$SUPERADMIN_TOKEN"

# Create subscription for school
CREATE_SUB_DATA='{
    "school_id": '"$SCHOOL_ID"',
    "plan_id": 1,
    "start_date": "2026-01-18",
    "end_date": "2027-01-18"
}'
# test_api "Create Subscription" "POST" "/admin/subscriptions" "$CREATE_SUB_DATA" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 5: Module Management
# ===========================================
echo -e "${BLUE}=== 5. MODULE MANAGEMENT ===${NC}"

# List all modules
test_api "List All Modules" "GET" "/admin/modules" "" "$SUPERADMIN_TOKEN"

# Create module
CREATE_MODULE_DATA='{
    "name": "Advanced Analytics",
    "code": "advanced_analytics",
    "description": "Advanced analytics module",
    "category": "analytics"
}'
test_api "Create Module" "POST" "/admin/modules" "$CREATE_MODULE_DATA" "$SUPERADMIN_TOKEN"

# Get module details (assuming ID 1)
# test_api "Get Module Details" "GET" "/admin/modules/1" "" "$SUPERADMIN_TOKEN"

# Update module
# UPDATE_MODULE_DATA='{"description": "Updated analytics module"}'
# test_api "Update Module" "PUT" "/admin/modules/1" "$UPDATE_MODULE_DATA" "$SUPERADMIN_TOKEN"

# Enable module for school
ENABLE_MODULE_DATA='{
    "school_id": '"$SCHOOL_ID"',
    "module_code": "academic_management"
}'
test_api "Enable Module for School" "POST" "/admin/modules/enable" "$ENABLE_MODULE_DATA" "$SUPERADMIN_TOKEN"

# Disable module for school
DISABLE_MODULE_DATA='{
    "school_id": '"$SCHOOL_ID"',
    "module_code": "livestream"
}'
test_api "Disable Module for School" "POST" "/admin/modules/disable" "$DISABLE_MODULE_DATA" "$SUPERADMIN_TOKEN"

# Get school modules
test_api "Get School Enabled Modules" "GET" "/admin/schools/$SCHOOL_ID/modules" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 6: System Settings & Configuration
# ===========================================
echo -e "${BLUE}=== 6. SYSTEM SETTINGS ===${NC}"

# Get system settings
test_api "Get System Settings" "GET" "/admin/settings" "" "$SUPERADMIN_TOKEN"

# Update system settings
UPDATE_SETTINGS_DATA='{
    "app_name": "SamSchool",
    "maintenance_mode": false,
    "registration_enabled": true
}'
test_api "Update System Settings" "PUT" "/admin/settings" "$UPDATE_SETTINGS_DATA" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 7: Analytics & Reports
# ===========================================
echo -e "${BLUE}=== 7. ANALYTICS & REPORTS ===${NC}"

# Get system analytics
test_api "Get System Analytics" "GET" "/admin/analytics" "" "$SUPERADMIN_TOKEN"

# Get revenue reports
test_api "Get Revenue Reports" "GET" "/admin/reports/revenue?start_date=2026-01-01&end_date=2026-01-31" "" "$SUPERADMIN_TOKEN"

# Get usage statistics
test_api "Get Usage Statistics" "GET" "/admin/statistics/usage" "" "$SUPERADMIN_TOKEN"

# Get school comparison
test_api "Get School Comparison" "GET" "/admin/statistics/schools-comparison" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 8: User Management (SuperAdmin)
# ===========================================
echo -e "${BLUE}=== 8. SUPERADMIN USER MANAGEMENT ===${NC}"

# List all superadmins
test_api "List All SuperAdmins" "GET" "/admin/superadmins" "" "$SUPERADMIN_TOKEN"

# Create new superadmin
CREATE_SUPERADMIN_DATA='{
    "name": "New SuperAdmin",
    "email": "newsuperadmin'"$TIMESTAMP"'@samschool.com",
    "password": "password123"
}'
test_api "Create SuperAdmin" "POST" "/admin/superadmins" "$CREATE_SUPERADMIN_DATA" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 9: Audit Logs
# ===========================================
echo -e "${BLUE}=== 9. AUDIT LOGS ===${NC}"

# Get all audit logs
test_api "Get All Audit Logs" "GET" "/admin/audit-logs?page=1&per_page=20" "" "$SUPERADMIN_TOKEN"

# Get logs by school
test_api "Get School Audit Logs" "GET" "/admin/audit-logs/school/$SCHOOL_ID" "" "$SUPERADMIN_TOKEN"

# Get logs by user
# test_api "Get User Audit Logs" "GET" "/admin/audit-logs/user/1" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 10: Payment & Transaction Management
# ===========================================
echo -e "${BLUE}=== 10. PAYMENTS & TRANSACTIONS ===${NC}"

# List all payments
test_api "List All Payments" "GET" "/admin/payments?page=1" "" "$SUPERADMIN_TOKEN"

# Get payment details
# test_api "Get Payment Details" "GET" "/admin/payments/1" "" "$SUPERADMIN_TOKEN"

# Verify payment
# VERIFY_PAYMENT_DATA='{"status": "verified"}'
# test_api "Verify Payment" "PUT" "/admin/payments/1/verify" "$VERIFY_PAYMENT_DATA" "$SUPERADMIN_TOKEN"

# List all transactions
test_api "List All Transactions" "GET" "/admin/transactions?page=1" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 11: System Health & Monitoring
# ===========================================
echo -e "${BLUE}=== 11. SYSTEM HEALTH & MONITORING ===${NC}"

# Get system health
test_api "Get System Health" "GET" "/admin/system/health" "" "$SUPERADMIN_TOKEN"

# Get database stats
test_api "Get Database Statistics" "GET" "/admin/system/database-stats" "" "$SUPERADMIN_TOKEN"

# Get server info
test_api "Get Server Information" "GET" "/admin/system/server-info" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 12: Notifications & Announcements
# ===========================================
echo -e "${BLUE}=== 12. NOTIFICATIONS & ANNOUNCEMENTS ===${NC}"

# Send system-wide notification
NOTIFICATION_DATA='{
    "title": "System Maintenance",
    "message": "System will be down for maintenance",
    "type": "warning",
    "target": "all_schools"
}'
test_api "Send System Notification" "POST" "/admin/notifications/broadcast" "$NOTIFICATION_DATA" "$SUPERADMIN_TOKEN"

# Create system announcement
ANNOUNCEMENT_DATA='{
    "title": "New Features",
    "content": "We have added new features",
    "priority": "normal"
}'
test_api "Create System Announcement" "POST" "/admin/announcements" "$ANNOUNCEMENT_DATA" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 13: Backup & Restore
# ===========================================
echo -e "${BLUE}=== 13. BACKUP & RESTORE ===${NC}"

# List backups
test_api "List System Backups" "GET" "/admin/backups" "" "$SUPERADMIN_TOKEN"

# Create backup
# test_api "Create System Backup" "POST" "/admin/backups/create" "" "$SUPERADMIN_TOKEN"

# Download backup
# test_api "Download Backup" "GET" "/admin/backups/1/download" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 14: Search & Filtering
# ===========================================
echo -e "${BLUE}=== 14. SEARCH & FILTERING ===${NC}"

# Global search
test_api "Global Search (Schools)" "GET" "/admin/search?q=test&type=schools" "" "$SUPERADMIN_TOKEN"

# Search users across all schools
test_api "Search Users" "GET" "/admin/search/users?q=admin" "" "$SUPERADMIN_TOKEN"

# Search students across all schools
test_api "Search Students" "GET" "/admin/search/students?q=john" "" "$SUPERADMIN_TOKEN"

# ===========================================
# STEP 15: Public APIs (No Auth Required)
# ===========================================
echo -e "${BLUE}=== 15. PUBLIC APIs ===${NC}"

# Check if school exists
test_api "Check School Exists" "GET" "/public/schools/check?subdomain=$TEST_SUBDOMAIN" ""

# Get school info
test_api "Get School Public Info" "GET" "/public/schools/$TEST_SUBDOMAIN" ""

# Get available plans (public)
test_api "Get Public Plans" "GET" "/public/plans" ""

# ===========================================
# STEP 16: School Deletion (LAST TEST)
# ===========================================
echo -e "${BLUE}=== 16. SCHOOL DELETION ===${NC}"

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

