#!/bin/bash

# Complete SamSchool Management System Setup Script
# This script sets up the entire system with all features

echo "🚀 Setting up Complete SamSchool Management System..."

# Check if we're in the right directory
if [ ! -f "composer.json" ]; then
    echo "❌ Please run this script from the project root directory"
    exit 1
fi

# Install PHP dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# Install Node.js dependencies (if package.json exists)
if [ -f "package.json" ]; then
    echo "📦 Installing Node.js dependencies..."
    npm install
fi

# Copy environment file
if [ ! -f ".env" ]; then
    echo "📋 Creating environment file..."
    cp .env.example .env
    echo "⚠️  Please update .env file with your database credentials"
fi

# Generate application key
echo "🔑 Generating application key..."
php artisan key:generate

# Create database if it doesn't exist
echo "🗄️  Setting up database..."
php artisan migrate:fresh --seed

# Create storage links
echo "🔗 Creating storage links..."
php artisan storage:link

# Set proper permissions
echo "🔐 Setting permissions..."
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# Clear and cache configuration
echo "⚡ Optimizing application..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Cache configuration for better performance
php artisan config:cache
php artisan route:cache

# Install and configure New Relic (if license key is provided)
if [ ! -z "$NEW_RELIC_LICENSE_KEY" ]; then
    echo "📊 Configuring New Relic monitoring..."
    echo "NEW_RELIC_LICENSE_KEY=$NEW_RELIC_LICENSE_KEY" >> .env
    echo "NEW_RELIC_APP_NAME=SamSchool Management System" >> .env
fi

# Install and configure Horizon
echo "⚡ Setting up Laravel Horizon..."
php artisan horizon:install

# Create Horizon configuration
echo "📋 Creating Horizon configuration..."
php artisan vendor:publish --tag=horizon-config

# Set up Redis for queues
echo "🔴 Setting up Redis for queues..."
php artisan queue:table
php artisan queue:failed-table
php artisan migrate

# Create system roles and permissions
echo "👥 Setting up RBAC system..."
php artisan db:seed --class=RolePermissionSeeder

# Create test data
echo "🧪 Creating test data..."
php artisan db:seed --class=TestDataSeeder

# Run tests
echo "🧪 Running tests..."
php artisan test

# Test all routes
echo "🔍 Testing all routes..."
php complete-system-test.php

# Start services
echo "🌐 Starting services..."

# Start Horizon in background
echo "⚡ Starting Laravel Horizon..."
php artisan horizon &

# Start queue worker in background
echo "⚡ Starting queue worker..."
php artisan queue:work --daemon &

# Start the development server
echo "🌐 Starting development server..."
echo "Server will be available at: http://localhost:8000"
echo "API endpoints will be available at: http://localhost:8000/api/v1"
echo "Horizon dashboard will be available at: http://localhost:8000/horizon"
echo ""
echo "🎉 Complete SamSchool Management System is now running!"
echo ""
echo "📋 System Features:"
echo "✅ Multi-tenancy with database isolation"
echo "✅ RBAC with comprehensive roles and permissions"
echo "✅ Livestream system with Google Meet integration"
echo "✅ Attendance tracking for students and staff"
echo "✅ Invoice generation and payment processing"
echo "✅ AI-powered lesson notes and exam generation"
echo "✅ Physical and online library management"
echo "✅ Clock in/out system for staff"
echo "✅ Performance monitoring with New Relic"
echo "✅ Queue management with Laravel Horizon"
echo "✅ File upload with S3 presigned URLs"
echo "✅ Subscription and module management"
echo "✅ Real-time notifications and messaging"
echo "✅ Financial management and reporting"
echo "✅ Academic management and grading"
echo "✅ Communication and SMS/Email integration"
echo "✅ Administrative and event management"
echo ""
echo "🔧 Management Commands:"
echo "- Test routes: php test-routes.php"
echo "- Complete system test: php complete-system-test.php"
echo "- Run specific tests: php artisan test --filter RouteTest"
echo "- Monitor queues: php artisan horizon"
echo "- Clear cache: php artisan cache:clear"
echo ""
echo "Press Ctrl+C to stop all services"

# Keep the script running
wait
