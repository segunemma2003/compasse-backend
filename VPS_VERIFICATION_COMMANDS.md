# VPS Server Verification Commands

## Server Information

-   **IP**: 31.97.155.60
-   **User**: root
-   **SSH Key**: ~/.ssh/vps_compasse_key

## SSH Connection

```bash
ssh -i ~/.ssh/vps_compasse_key root@31.97.155.60
```

## Deployment Verification Commands

### 1. Check Application Directory

```bash
# Navigate to project directory
cd /var/www/samschool-backend

# Check if project exists
ls -la

# Check git status
git status
git log -1 --oneline
```

### 2. Check Application Health

```bash
# Test local health endpoint
curl http://localhost:8078/api/health

# Test with verbose output
curl -v http://localhost:8078/api/health

# Expected response:
# {"status":"ok","timestamp":"...","version":"1.0.0"}
```

### 3. Check Services Status

```bash
# Check Nginx
sudo systemctl status nginx
sudo systemctl is-active nginx

# Check PHP-FPM
sudo systemctl status php8.2-fpm
sudo systemctl is-active php8.2-fpm

# Check queue workers
sudo supervisorctl status

# Check Horizon (if configured)
sudo supervisorctl status samschool-horizon:*
```

### 4. Check Application Logs

```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Deployment log
cat /var/log/samschool-deployments.log
tail -20 /var/log/samschool-deployments.log

# Nginx error log
sudo tail -f /var/log/nginx/error.log

# Nginx access log
sudo tail -f /var/log/nginx/access.log
```

### 5. Check Database Connection

```bash
cd /var/www/samschool-backend
php artisan tinker --execute="DB::connection()->getPdo(); echo 'Database connected successfully';"
```

### 6. Check Redis Connection

```bash
cd /var/www/samschool-backend
php artisan tinker --execute="Redis::ping(); echo 'Redis connected successfully';"
```

### 7. Check Environment Configuration

```bash
cd /var/www/samschool-backend
php artisan config:show app.name
php artisan config:show app.url
php artisan config:show database.default
```

### 8. Check File Permissions

```bash
cd /var/www/samschool-backend
ls -la storage/
ls -la bootstrap/cache/

# Should show www-data:www-data ownership
```

### 9. Check Nginx Configuration

```bash
# Check if Nginx is listening on port 8078
sudo netstat -tlnp | grep 8078
# or
sudo ss -tlnp | grep 8078

# Check Nginx config
sudo nginx -t

# View Nginx config for your site
sudo cat /etc/nginx/sites-available/samschool
```

### 10. Test Public Access (if domain configured)

```bash
# Replace with your actual domain/IP
curl http://31.97.155.60:8078/api/health
# or if domain is configured
curl https://your-domain.com/api/health
```

### 11. Check Process Status

```bash
# Check PHP processes
ps aux | grep php

# Check Nginx processes
ps aux | grep nginx

# Check queue workers
ps aux | grep queue
```

### 12. Check Disk Space

```bash
df -h
du -sh /var/www/samschool-backend
```

### 13. Quick Health Check Script

```bash
cd /var/www/samschool-backend

echo "=== Application Health Check ==="
echo "1. Health Endpoint:"
curl -s http://localhost:8078/api/health | jq . || curl -s http://localhost:8078/api/health

echo -e "\n2. Database:"
php artisan tinker --execute="try { DB::connection()->getPdo(); echo '✅ Connected'; } catch(Exception \$e) { echo '❌ Failed: ' . \$e->getMessage(); }"

echo -e "\n3. Redis:"
php artisan tinker --execute="try { Redis::ping(); echo '✅ Connected'; } catch(Exception \$e) { echo '❌ Failed: ' . \$e->getMessage(); }"

echo -e "\n4. Services:"
echo "Nginx: $(sudo systemctl is-active nginx)"
echo "PHP-FPM: $(sudo systemctl is-active php8.2-fpm)"
echo "Queue Workers: $(sudo supervisorctl status | grep -c RUNNING || echo 'Not configured')"

echo -e "\n5. Latest Deployment:"
tail -1 /var/log/samschool-deployments.log 2>/dev/null || echo "No deployment log found"
```

## Troubleshooting Commands

### If Health Endpoint Fails

```bash
# Check if Laravel is running
cd /var/www/samschool-backend
php artisan serve --host=0.0.0.0 --port=8079 &
# Test on alternate port
curl http://localhost:8079/api/health
```

### If Services Won't Start

```bash
# Check service logs
sudo journalctl -u nginx -n 50
sudo journalctl -u php8.2-fpm -n 50

# Restart services
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
```

### If Database Connection Fails

```bash
# Check MySQL status
sudo systemctl status mysql

# Test MySQL connection
mysql -u root -p -e "SHOW DATABASES;"

# Check .env database config
cd /var/www/samschool-backend
grep DB_ .env
```

### Clear Caches (if needed)

```bash
cd /var/www/samschool-backend
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
```

## GitHub Secrets Verification

Make sure these match your server:

-   `SERVER_HOST`: `31.97.155.60`
-   `SERVER_USER`: `root`
-   `SERVER_SSH_KEY`: Content of `~/.ssh/vps_compasse_key`
-   `SERVER_PORT`: `22` (default)
-   `PROJECT_PATH`: `/var/www/samschool-backend` (default)
-   `APP_URL`: Your public URL (optional, for public health checks)

## Quick Verification Script

Save this as `check-deployment.sh` on your server:

```bash
#!/bin/bash
cd /var/www/samschool-backend

echo "🔍 Checking Deployment Status..."
echo ""

# Health endpoint
echo "1️⃣  Health Endpoint:"
HEALTH=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8078/api/health)
if [ "$HEALTH" = "200" ]; then
    echo "   ✅ Health endpoint responding (HTTP $HEALTH)"
else
    echo "   ❌ Health endpoint failed (HTTP $HEALTH)"
fi

# Database
echo ""
echo "2️⃣  Database:"
php artisan tinker --execute="try { DB::connection()->getPdo(); echo '   ✅ Connected'; } catch(Exception \$e) { echo '   ❌ Failed'; }" 2>/dev/null

# Redis
echo ""
echo "3️⃣  Redis:"
php artisan tinker --execute="try { Redis::ping(); echo '   ✅ Connected'; } catch(Exception \$e) { echo '   ❌ Failed'; }" 2>/dev/null

# Services
echo ""
echo "4️⃣  Services:"
echo "   Nginx: $(sudo systemctl is-active nginx 2>/dev/null || echo 'unknown')"
echo "   PHP-FPM: $(sudo systemctl is-active php8.2-fpm 2>/dev/null || echo 'unknown')"

# Deployment log
echo ""
echo "5️⃣  Latest Deployment:"
tail -1 /var/log/samschool-deployments.log 2>/dev/null || echo "   No log found"

echo ""
echo "✅ Verification complete!"
```

Make it executable and run:

```bash
chmod +x check-deployment.sh
./check-deployment.sh
```
