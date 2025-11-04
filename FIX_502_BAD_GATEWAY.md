# Fixing 502 Bad Gateway Error

## Problem
- ✅ Nginx is running
- ❌ PHP-FPM is not responding (502 Bad Gateway)
- Nginx can't communicate with PHP-FPM

## Quick Fix Commands

### 1. Check PHP-FPM Status
```bash
sudo systemctl status php8.2-fpm
```

### 2. Start PHP-FPM (if not running)
```bash
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm
```

### 3. Check PHP-FPM Configuration
```bash
# Check if PHP-FPM is listening
sudo lsof -i :9000
# or check socket
ls -la /var/run/php/php8.2-fpm.sock
```

### 4. Verify Nginx PHP-FPM Configuration
```bash
# Check Nginx config
sudo cat /etc/nginx/sites-available/samschool | grep -A 5 "fastcgi_pass"
```

It should have:
```nginx
fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
```

### 5. Check PHP-FPM Pool Configuration
```bash
sudo cat /etc/php/8.2/fpm/pool.d/www.conf | grep -E "listen|user|group"
```

### 6. Restart Services
```bash
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

### 7. Check PHP-FPM Error Logs
```bash
sudo tail -f /var/log/php8.2-fpm.log
# or
sudo journalctl -u php8.2-fpm -n 50
```

### 8. Check Nginx Error Logs
```bash
sudo tail -f /var/log/nginx/error.log
```

## Common Fixes

### Fix 1: PHP-FPM Not Running
```bash
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm
sudo systemctl status php8.2-fpm
```

### Fix 2: Wrong Socket Path
Check Nginx config matches PHP-FPM socket:
```bash
# Find PHP-FPM socket
sudo find /var/run /run -name "*fpm*.sock" 2>/dev/null

# Update Nginx config if needed
sudo nano /etc/nginx/sites-available/samschool
# Change: fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
sudo nginx -t
sudo systemctl reload nginx
```

### Fix 3: Permissions Issue
```bash
# Check socket permissions
ls -la /var/run/php/php8.2-fpm.sock

# Fix permissions if needed
sudo chown www-data:www-data /var/run/php/php8.2-fpm.sock
sudo chmod 666 /var/run/php/php8.2-fpm.sock
```

### Fix 4: PHP-FPM Pool Configuration
```bash
# Edit PHP-FPM pool
sudo nano /etc/php/8.2/fpm/pool.d/www.conf

# Ensure these settings:
# listen = /var/run/php/php8.2-fpm.sock
# listen.owner = www-data
# listen.group = www-data
# listen.mode = 0660
# user = www-data
# group = www-data

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

### Fix 5: Check PHP Installation
```bash
# Verify PHP is installed
php -v

# Check PHP-FPM is installed
php-fpm8.2 -v

# If not installed:
sudo apt update
sudo apt install php8.2-fpm -y
```

## Complete Diagnostic Script

Run this on your server:

```bash
#!/bin/bash
echo "=== PHP-FPM & Nginx Diagnostic ==="
echo ""

echo "1. PHP-FPM Status:"
sudo systemctl status php8.2-fpm --no-pager | head -10

echo ""
echo "2. PHP-FPM Socket:"
ls -la /var/run/php/php8.2-fpm.sock 2>/dev/null || echo "Socket not found!"

echo ""
echo "3. Nginx PHP-FPM Config:"
sudo grep -A 3 "fastcgi_pass" /etc/nginx/sites-available/samschool

echo ""
echo "4. PHP-FPM Listen Config:"
sudo grep "listen" /etc/php/8.2/fpm/pool.d/www.conf | grep -v "^;"

echo ""
echo "5. Recent PHP-FPM Errors:"
sudo tail -10 /var/log/php8.2-fpm.log 2>/dev/null || sudo journalctl -u php8.2-fpm -n 10 --no-pager

echo ""
echo "6. Recent Nginx Errors:"
sudo tail -10 /var/log/nginx/error.log

echo ""
echo "7. Test PHP:"
echo "<?php phpinfo(); ?>" | php

echo ""
echo "=== Diagnostic Complete ==="
```

## Quick Fix Sequence

```bash
# 1. Stop services
sudo systemctl stop nginx
sudo systemctl stop php8.2-fpm

# 2. Start PHP-FPM
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm

# 3. Verify PHP-FPM is running
sudo systemctl status php8.2-fpm

# 4. Check socket exists
ls -la /var/run/php/php8.2-fpm.sock

# 5. Start Nginx
sudo systemctl start nginx

# 6. Test
curl http://localhost:8078/api/health
```

## Expected Results

After fixing, you should see:
```bash
# PHP-FPM status
● php8.2-fpm.service - The PHP 8.2 FastCGI Process Manager
   Active: active (running)

# Socket exists
-rw-rw-rw- 1 www-data www-data 0 Nov  4 06:10 /var/run/php/php8.2-fpm.sock

# Health endpoint works
{"status":"ok","timestamp":"2025-11-04T06:10:00.000000Z","version":"1.0.0"}
```

