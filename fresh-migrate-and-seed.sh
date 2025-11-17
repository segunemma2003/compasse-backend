#!/bin/bash

# Fresh Migration and Seeding Script
# This script will:
# 1. Fresh migrate main database
# 2. Seed main database (including super admin)
# 3. Fresh migrate all tenant databases
# 4. Seed tenant databases (if needed)

echo "🔄 FRESH MIGRATION AND SEEDING"
echo "=========================================="
echo ""

# Step 1: Fresh migrate main database
echo "📦 Step 1: Fresh migrating main database..."
php artisan migrate:fresh --force

if [ $? -ne 0 ]; then
    echo "❌ Main database migration failed!"
    exit 1
fi

echo "✅ Main database migrated successfully!"
echo ""

# Step 2: Seed main database (includes super admin)
echo "🌱 Step 2: Seeding main database (Super Admin, Modules, Plans, Roles)..."
php artisan db:seed --force

if [ $? -ne 0 ]; then
    echo "❌ Main database seeding failed!"
    exit 1
fi

echo "✅ Main database seeded successfully!"
echo ""

# Step 3: Fresh migrate tenant databases
echo "📦 Step 3: Fresh migrating tenant databases..."

# Check if tenants exist
TENANT_COUNT=$(php artisan tinker --execute="echo \App\Models\Tenant::count();" 2>/dev/null | tail -1 | tr -d '[:space:]')

if [ -z "$TENANT_COUNT" ] || [ "$TENANT_COUNT" = "0" ]; then
    echo "ℹ️  No tenants found. Tenant migrations will run when tenants are created."
    echo "   You can create a tenant and then run: php artisan tenants:migrate-fresh"
else
    echo "   Found $TENANT_COUNT tenant(s)"
    
    # Try stancl/tenancy command first, fallback to custom
    if php artisan list | grep -q "tenancy:migrate"; then
        echo "   Using stancl/tenancy migration command..."
        php artisan tenancy:migrate --fresh --force 2>&1
    else
        echo "   Using custom migration command..."
        php artisan tenants:migrate-fresh --force 2>&1
    fi
    
    if [ $? -eq 0 ]; then
        echo "✅ Tenant databases migrated successfully!"
    else
        echo "⚠️  Tenant migration had issues (may need to create tenant databases first)"
    fi
fi
echo ""

echo "=========================================="
echo "✅ ALL DONE!"
echo ""
echo "Super Admin Credentials:"
echo "  Email: superadmin@compasse.net"
echo "  Password: Nigeria@60"
echo "=========================================="

