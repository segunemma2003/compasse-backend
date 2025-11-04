# Fix: SQLite to MySQL Connection Issue

## Problem

Changed `.env` to use MySQL but still getting SQLite errors because Laravel config is cached.

## Solution: Clear All Caches

Run these commands on your server:

```bash
cd /var/www/samschool-backend

# 1. Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 2. Verify .env file has MySQL settings
grep "^DB_CONNECTION" .env
# Should show: DB_CONNECTION=mysql

# 3. Verify MySQL credentials
grep "^DB_" .env | head -6

# 4. Test database connection
php artisan tinker --execute="DB::connection()->getPdo(); echo '✅ MySQL connected';"

# 5. Rebuild cache (production)
php artisan config:cache
php artisan route:cache
```

## Complete Fix Sequence

```bash
cd /var/www/samschool-backend

# Step 1: Verify .env settings
echo "=== Current DB Settings ==="
grep "^DB_CONNECTION" .env
grep "^DB_HOST" .env
grep "^DB_DATABASE" .env
grep "^DB_USERNAME" .env

# Step 2: Clear ALL caches
echo ""
echo "=== Clearing Caches ==="
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Step 3: Verify MySQL connection
echo ""
echo "=== Testing MySQL Connection ==="
php artisan tinker --execute="
try {
    DB::connection()->getPdo();
    echo '✅ MySQL connection successful!';
    echo PHP_EOL . 'Database: ' . config('database.connections.mysql.database');
} catch(Exception \$e) {
    echo '❌ Connection failed: ' . \$e->getMessage();
}
"

# Step 4: Rebuild cache (if successful)
php artisan config:cache

echo ""
echo "✅ Done! Laravel should now use MySQL."
```

## Verify .env File Has Correct Settings

Your `.env` should have:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=samschool_main
DB_USERNAME=root
DB_PASSWORD=your_password
```

## Check Which Database is Being Used

```bash
cd /var/www/samschool-backend
php artisan tinker --execute="
echo 'Default Connection: ' . config('database.default') . PHP_EOL;
echo 'DB Driver: ' . config('database.connections.' . config('database.default') . '.driver') . PHP_EOL;
"
```

## If Database Doesn't Exist

```bash
# Create the database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS samschool_main CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations
cd /var/www/samschool-backend
php artisan migrate --force
```

## Common Issues

### Issue 1: Config Cache Still Active

```bash
# Force clear config cache
php artisan config:clear
rm -f bootstrap/cache/config.php
```

### Issue 2: Wrong Database Connection

```bash
# Check what Laravel thinks the default is
php artisan tinker --execute="echo config('database.default');"

# If it shows 'sqlite', the cache wasn't cleared properly
php artisan config:clear
php artisan config:cache
```

### Issue 3: MySQL Connection Fails

```bash
# Test MySQL connection directly
DB_USER=$(grep "^DB_USERNAME" .env | cut -d '=' -f2)
DB_PASS=$(grep "^DB_PASSWORD" .env | cut -d '=' -f2)
DB_NAME=$(grep "^DB_DATABASE" .env | cut -d '=' -f2)

mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1;"
```

## Quick Fix Script

Save this as `fix-mysql-connection.sh` on your server:

```bash
#!/bin/bash
cd /var/www/samschool-backend

echo "🔧 Fixing MySQL Connection..."
echo ""

# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Verify connection
php artisan tinker --execute="
try {
    \$pdo = DB::connection()->getPdo();
    echo '✅ MySQL Connected!' . PHP_EOL;
    echo 'Database: ' . config('database.connections.mysql.database') . PHP_EOL;
} catch(Exception \$e) {
    echo '❌ Error: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

# Rebuild cache
php artisan config:cache

echo ""
echo "✅ Done! MySQL connection should be active."
```

Make it executable and run:

```bash
chmod +x fix-mysql-connection.sh
./fix-mysql-connection.sh
```
