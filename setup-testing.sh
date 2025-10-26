#!/bin/bash

# SamSchool Backend Testing Setup Script
# This script sets up the local environment for testing all routes

echo "🚀 Setting up SamSchool Backend for Testing..."

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

# Clear and cache configuration
echo "⚡ Optimizing application..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Cache configuration for better performance
php artisan config:cache
php artisan route:cache

# Create storage links
echo "🔗 Creating storage links..."
php artisan storage:link

# Set proper permissions
echo "🔐 Setting permissions..."
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# Run tests
echo "🧪 Running tests..."
php artisan test

# Start the development server
echo "🌐 Starting development server..."
echo "Server will be available at: http://localhost:8000"
echo "API endpoints will be available at: http://localhost:8000/api/v1"
echo ""
echo "To test routes manually, run: php test-routes.php"
echo "To run specific tests: php artisan test --filter RouteTest"
echo ""
echo "Press Ctrl+C to stop the server"

php artisan serve --host=0.0.0.0 --port=8000
