# Automatic Updates via GitHub Actions

## How Updates Work

Your GitHub Actions workflow automatically handles updates when you:

### 1. Push to Main Branch

```bash
# Make your changes
git add .
git commit -m "Add new feature"
git push origin main
```

**What happens automatically:**

-   ✅ Tests run (PHP tests, security scans)
-   ✅ Code is deployed to VPS
-   ✅ Dependencies are updated
-   ✅ Assets are built
-   ✅ Database migrations run
-   ✅ Multi-tenant databases are set up
-   ✅ Services are restarted
-   ✅ Health checks are performed

### 2. Push to Production Branch

```bash
# For production deployments
git checkout production
git merge main
git push origin production
```

### 3. Manual Deployment

-   Go to GitHub Actions tab
-   Click "Deploy SamSchool Management System"
-   Click "Run workflow"

## What the Workflow Does

### Testing Phase

-   ✅ Runs PHP tests with coverage
-   ✅ Security audits (Composer & NPM)
-   ✅ Performance tests
-   ✅ Database connectivity tests

### Deployment Phase

-   ✅ Pulls latest code from Git
-   ✅ Installs/updates dependencies
-   ✅ Builds production assets
-   ✅ Sets proper file permissions
-   ✅ Clears and caches configurations
-   ✅ Runs database migrations
-   ✅ Sets up multi-tenant databases
-   ✅ Restarts services (Nginx, PHP-FPM)
-   ✅ Restarts queue workers and Horizon
-   ✅ Performs health checks

### Health Checks

-   ✅ Application health endpoint
-   ✅ Database connectivity
-   ✅ Redis connectivity
-   ✅ Queue workers status
-   ✅ Horizon status
-   ✅ New Relic integration (if configured)

## Monitoring Deployments

### 1. GitHub Actions Dashboard

-   Go to your repository → Actions tab
-   Monitor deployment progress
-   Check logs for any errors

### 2. Server Logs

```bash
# Check deployment logs
tail -f /var/log/samschool-deployments.log

# Check application logs
tail -f /var/www/samschool-backend/storage/logs/laravel.log

# Check worker logs
tail -f /var/www/samschool-backend/storage/logs/worker.log

# Check Horizon logs
tail -f /var/www/samschool-backend/storage/logs/horizon.log
```

### 3. Health Monitoring

```bash
# Check application health
curl https://your-domain.com/api/health

# Check queue workers
sudo supervisorctl status samschool-worker:*

# Check Horizon
php artisan horizon:status
```

## Rollback Process

If a deployment fails, you can rollback:

### 1. Automatic Rollback

The workflow includes health checks that will fail if the deployment is broken.

### 2. Manual Rollback

```bash
# SSH into your VPS
ssh user@your-vps-ip

# Navigate to project directory
cd /var/www/samschool-backend

# Rollback to previous commit
git log --oneline -5  # See recent commits
git reset --hard HEAD~1  # Rollback one commit
git push origin main --force

# Restart services
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
sudo supervisorctl restart samschool-worker:*
sudo supervisorctl restart samschool-horizon:*
```

## Best Practices

### 1. Development Workflow

```bash
# Create feature branch
git checkout -b feature/new-feature

# Make changes and commit
git add .
git commit -m "Add new feature"

# Push to GitHub
git push origin feature/new-feature

# Create Pull Request
# After review, merge to main
```

### 2. Production Deployments

```bash
# Test on main branch first
git push origin main

# After testing, deploy to production
git checkout production
git merge main
git push origin production
```

### 3. Emergency Fixes

```bash
# Make urgent fix
git add .
git commit -m "Emergency fix for critical issue"
git push origin main

# Monitor deployment
# Check health endpoints
```

## Troubleshooting

### Common Issues

1. **Deployment Fails**

    - Check GitHub Actions logs
    - Verify SSH keys and secrets
    - Check server disk space

2. **Health Checks Fail**

    - Check application logs
    - Verify database connection
    - Check Redis connection

3. **Queue Workers Not Working**
    - Check supervisor status
    - Restart workers manually
    - Check worker logs

### Manual Service Management

```bash
# Restart all services
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
sudo supervisorctl restart all

# Check service status
sudo systemctl status nginx
sudo systemctl status php8.2-fpm
sudo supervisorctl status
```
