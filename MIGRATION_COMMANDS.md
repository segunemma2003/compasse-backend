# Migration and Seeding Commands

## Quick Commands

### Option 1: All-in-One Script (Recommended)
```bash
./fresh-migrate-and-seed.sh
```

This will:
- Fresh migrate main database
- Seed main database (Super Admin, Modules, Plans, Roles)
- Fresh migrate all tenant databases
- Show super admin credentials

### Option 2: Manual Step-by-Step

#### 1. Fresh Migrate Main Database
```bash
php artisan migrate:fresh --force
```

#### 2. Seed Main Database (Super Admin + Others)
```bash
php artisan db:seed --force
```

Or seed only Super Admin:
```bash
php artisan db:seed --class=SuperAdminSeeder --force
```

#### 3. Fresh Migrate Tenant Databases

**If using stancl/tenancy:**
```bash
php artisan tenancy:migrate --fresh --force
```

**If using custom command:**
```bash
php artisan tenants:migrate --fresh --force
```

**For specific tenant:**
```bash
php artisan tenants:migrate --tenant=1 --fresh --force
```

#### 4. Seed Tenant Databases (if needed)
```bash
php artisan tenants:migrate --fresh --seed --force
```

## What Gets Seeded

### Main Database Seeding
- **SuperAdminSeeder**: Creates super admin user
  - Email: `superadmin@compasse.net`
  - Password: `Nigeria@60`
  - Role: `super_admin`
- **ModuleSeeder**: Creates available modules
- **PlanSeeder**: Creates subscription plans
- **RolesAndPermissionsSeeder**: Creates roles and permissions

### Tenant Database Seeding
- Tenant databases are seeded when you create a tenant with a school
- School admin user is automatically created

## Super Admin Credentials

After seeding:
- **Email**: `superadmin@compasse.net`
- **Password**: `Nigeria@60`
- **Role**: `super_admin`
- **Tenant**: System Administration (auto-created)

## Troubleshooting

### If tenant migration fails:
1. Check if tenants exist: `php artisan tinker` → `App\Models\Tenant::count()`
2. Create a tenant first, then run migrations
3. Check database permissions

### If super admin already exists:
The seeder will skip and show existing admin info.

### To reset everything:
```bash
php artisan migrate:fresh --force
php artisan db:seed --force
```

