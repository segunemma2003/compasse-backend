# Post-Deployment Checklist

Run this checklist after deploying to production (`api.compasse.net`).

## Pre-Deployment Verification

- [ ] Code pushed to repository
- [ ] GitHub Actions / deploy.yml workflow triggered
- [ ] Deployment completed without errors
- [ ] Server is accessible at `https://api.compasse.net`

## Post-Deployment Tests

### 1. Quick Health Check
```bash
curl https://api.compasse.net/api/health
```
**Expected**: `{"status":"ok","timestamp":"...","version":"1.0.0"}`

### 2. Run Automated Test Suite
```bash
php test-post-deployment.php
```
This will test:
- ✅ Health check
- ✅ Authentication
- ✅ User endpoints
- ✅ School creation
- ✅ School retrieval
- ✅ Tenant management

### 3. Manual Verification

#### Authentication
```bash
curl -X POST https://api.compasse.net/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"superadmin@compasse.net","password":"Nigeria@60"}'
```
**Expected**: 200 OK with token

#### School Creation
```bash
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
**Expected**: 201 Created with school data

## Common Issues & Fixes

### Issue: Route Not Found (404)
**Fix**: Clear route cache
```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```

### Issue: Database Connection Error
**Fix**: Check `.env` file and database credentials
```bash
php artisan config:cache
```

### Issue: Tenant Database Not Found
**Fix**: 
1. Verify tenant exists: `php artisan tinker` → `App\Models\Tenant::all()`
2. If using stancl/tenancy, run: `php artisan tenancy:migrate`

### Issue: Authentication Fails
**Fix**: 
1. Verify superadmin exists: `php artisan db:seed --class=SuperAdminSeeder`
2. Check user table: `SELECT * FROM users WHERE email='superadmin@compasse.net'`

## Database Verification

### Check Main Database
```bash
php artisan tinker
```
```php
// Check tenants
App\Models\Tenant::count();

// Check users
App\Models\User::where('email', 'superadmin@compasse.net')->first();

// Check schools
App\Models\School::count();
```

### Check Tenant Databases (if using stancl/tenancy)
```bash
php artisan tenancy:list
```

## Logs to Check

### Laravel Logs
```bash
tail -f storage/logs/laravel.log
```

### Nginx/Apache Error Logs
```bash
tail -f /var/log/nginx/error.log
# or
tail -f /var/log/apache2/error.log
```

## Performance Checks

### Response Times
- Health check: Should be < 100ms
- Login: Should be < 500ms
- School creation: Should be < 1000ms

### Database Queries
Check for N+1 queries or slow queries in logs.

## Security Checks

- [ ] HTTPS is working (no mixed content)
- [ ] CORS headers are correct
- [ ] Authentication tokens are being validated
- [ ] Rate limiting is active (if configured)

## Final Verification

After all tests pass:

1. ✅ All endpoints responding correctly
2. ✅ Authentication working
3. ✅ School creation working
4. ✅ Database connections stable
5. ✅ No errors in logs
6. ✅ Response times acceptable

## Rollback Plan (If Needed)

If deployment fails:

```bash
# Revert to previous commit
git revert HEAD
git push origin main

# Or restore from backup
# (depends on your backup strategy)
```

## Support Contacts

If issues persist:
- Check GitHub Actions logs
- Review server logs
- Verify environment variables
- Check database connectivity

---

**Last Updated**: After deployment verification
**Test Script**: `test-post-deployment.php`

