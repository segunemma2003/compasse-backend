# Quick Test Commands

## After Deployment - Run These Commands

### 1. Quick Health Check
```bash
curl https://api.compasse.net/api/health
```

### 2. Test Authentication
```bash
curl -X POST https://api.compasse.net/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}'
```

### 3. Test School Creation (with token from step 2)
```bash
# Replace YOUR_TOKEN with actual token from login
curl -X POST https://api.compasse.net/api/v1/schools \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Tenant-ID: 1" \
  -d '{
    "tenant_id": 1,
    "name": "Test School",
    "address": "123 Test St",
    "phone": "+1234567890",
    "email": "test@school.com"
  }'
```

### 4. Run Comprehensive Test Suite
```bash
php test-all-apis-comprehensive.php
```

### 5. Run Auto Test (Waits 7 min then tests)
```bash
php auto-test-after-deploy.php
```

## Current Status

✅ **Working:**
- Health check endpoint
- Authentication (login works)
- Token generation

❌ **Needs Deployment:**
- School creation route (404 - route not found)
- Tenant management routes (401 - authentication issue)
- All tenant-specific routes

## What to Check After Deployment

1. **Routes are deployed:**
   ```bash
   # On production server
   php artisan route:clear
   php artisan config:clear
   ```

2. **Database connections:**
   - Main database accessible
   - Tenant databases can be created/accessed

3. **Authentication:**
   - Tokens are being validated correctly
   - User roles are being checked

## Expected Results After Deployment

All tests should show:
- ✅ Health Check
- ✅ Authentication
- ✅ School Creation (201 Created)
- ✅ All other endpoints (200 OK or appropriate status)

