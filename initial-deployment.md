# Initial Deployment Process

## Step 1: Prepare Your VPS

1. **Run the VPS setup script**:

```bash
# On your VPS
wget https://raw.githubusercontent.com/your-username/samschool-backend/main/vps-setup.sh
chmod +x vps-setup.sh
./vps-setup.sh
```

2. **Clone your repository**:

```bash
cd /var/www
git clone https://github.com/your-username/samschool-backend.git
cd samschool-backend
```

3. **Install dependencies**:

```bash
composer install --no-dev --optimize-autoloader
npm ci --only=production
npm run build
```

4. **Configure environment**:

```bash
cp .env.example .env
# Edit .env with your production settings
nano .env
```

5. **Generate application key**:

```bash
php artisan key:generate
```

6. **Set permissions**:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

7. **Run migrations**:

```bash
php artisan migrate --force
```

8. **Create storage link**:

```bash
php artisan storage:link
```

## Step 2: Configure GitHub Secrets

1. Go to your GitHub repository
2. Settings → Secrets and variables → Actions
3. Add the required secrets (see github-secrets-setup.md)

## Step 3: Test Deployment

1. **Push to main branch**:

```bash
git add .
git commit -m "Initial deployment setup"
git push origin main
```

2. **Check GitHub Actions**:
    - Go to Actions tab in your GitHub repository
    - Monitor the deployment process
    - Check for any errors

## Step 4: Verify Deployment

1. **Check application health**:

```bash
curl https://your-domain.com/api/health
```

2. **Test registration**:

```bash
curl -X POST https://your-domain.com/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password123","password_confirmation":"password123","role":"super_admin"}'
```

## Step 5: Configure Domain and SSL

1. **Update Nginx configuration**:

```bash
sudo nano /etc/nginx/sites-available/samschool
# Update server_name with your actual domain
```

2. **Install SSL certificate**:

```bash
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

3. **Restart services**:

```bash
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
```
