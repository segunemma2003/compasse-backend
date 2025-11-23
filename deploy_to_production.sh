#!/bin/bash

# Deploy to Production Server (api.compasse.net)
# This script deploys the API simplification changes

echo "🚀 Deploying API Simplification to Production..."
echo ""

# SSH into production server and run deployment commands
ssh root@api.compasse.net << 'ENDSSH'

echo "📥 Step 1: Pulling latest code..."
cd /var/www/compasse-backend || exit 1
git pull origin main

echo ""
echo "🧹 Step 2: Clearing caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo ""
echo "🔄 Step 3: Optimizing..."
php artisan config:cache
php artisan route:cache

echo ""
echo "♻️  Step 4: Restarting PHP-FPM..."
systemctl restart php8.2-fpm

echo ""
echo "♻️  Step 5: Restarting Nginx..."
systemctl restart nginx

echo ""
echo "✅ Deployment Complete!"
echo ""
echo "🧪 Test the changes:"
echo "   curl -X POST https://api.compasse.net/api/v1/auth/login \\"
echo "     -H 'Content-Type: application/json' \\"
echo "     -d '{\"email\":\"admin@example.com\",\"password\":\"password\"}'"

ENDSSH

echo ""
echo "🎉 Done! API is now live with simplified endpoints."
