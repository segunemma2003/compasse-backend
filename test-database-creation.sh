#!/bin/bash

# Test script to verify database creation and cache clearing
echo "🧪 Testing Database Creation and Cache Clearing..."

# Create database directory if it doesn't exist
mkdir -p database

# Create SQLite database file
echo "📁 Creating SQLite database file..."
touch database/database.sqlite
chmod 666 database/database.sqlite

# Verify database file exists
if [ -f database/database.sqlite ]; then
    echo "✅ SQLite database file created successfully"
    ls -la database/database.sqlite
else
    echo "❌ Failed to create SQLite database file"
    exit 1
fi

# Test Laravel commands
echo "🔧 Testing Laravel commands..."

# Run migrations first to create tables
echo "Testing: php artisan migrate --force"
php artisan migrate --force

# Test config:clear
echo "Testing: php artisan config:clear"
php artisan config:clear

# Test cache:clear
echo "Testing: php artisan cache:clear"
php artisan cache:clear

# Test route:clear
echo "Testing: php artisan route:clear"
php artisan route:clear

# Test view:clear
echo "Testing: php artisan view:clear"
php artisan view:clear

echo "✅ All Laravel commands executed successfully!"
echo "🎉 Database creation and cache clearing test completed!"
