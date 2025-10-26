# Environment Setup for Port 8078

## 🚀 **Updated Configuration for Port 8078**

Since port 80 is occupied, we'll use port 8078 for the SamSchool Management System.

### **1. VPS Setup Script**

Use the updated setup script: `vps-setup-port-8078.sh`

```bash
# Make executable and run
chmod +x vps-setup-port-8078.sh
./vps-setup-port-8078.sh
```

### **2. Nginx Configuration**

The Nginx configuration now uses port 8078:

```nginx
server {
    listen 8078;
    server_name your-domain.com www.your-domain.com;
    root /var/www/samschool-backend/public;
    index index.php;

    # ... rest of configuration
}
```

### **3. Environment Variables**

Update your `.env` file:

```env
APP_NAME="SamSchool Management System"
APP_ENV=production
APP_KEY=base64:your-app-key-here
APP_DEBUG=false
APP_URL=http://your-domain.com:8078

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=samschool_main
DB_USERNAME=samschool
DB_PASSWORD=your_secure_password

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
HORIZON_PREFIX=samschool

# Multi-tenancy
TENANT_DB_PREFIX=tenant_
TENANT_DB_CONNECTION=tenant
TENANT_AUTO_CREATE_DB=true
TENANT_AUTO_MIGRATE=true
TENANT_SUBDOMAIN_ENABLED=true
TENANT_WILDCARD_DOMAIN=*.samschool.com
TENANT_MAIN_DOMAIN=samschool.com

# New Relic (optional)
NEW_RELIC_LICENSE_KEY=your-new-relic-key
NEW_RELIC_APP_NAME="SamSchool Management System"
```

### **4. GitHub Actions Health Checks**

The GitHub Actions workflow now checks:

-   `http://localhost:8078/api/health`
-   `http://localhost:8078/api/v1/health`

### **5. Firewall Configuration**

```bash
# Allow port 8078
sudo ufw allow 8078
sudo ufw allow 22
sudo ufw --force enable
```

### **6. SSL Certificate Setup**

For SSL on port 8078, you have a few options:

#### **Option A: Use Certbot with custom port**

```bash
# Install Certbot
sudo apt install -y certbot python3-certbot-nginx

# Get SSL certificate (will automatically configure for port 8078)
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

#### **Option B: Manual SSL configuration**

```nginx
server {
    listen 8078 ssl;
    server_name your-domain.com www.your-domain.com;

    ssl_certificate /path/to/your/certificate.crt;
    ssl_certificate_key /path/to/your/private.key;

    root /var/www/samschool-backend/public;
    index index.php;

    # ... rest of configuration
}
```

### **7. Access URLs**

After deployment, your application will be accessible at:

-   **Main Application**: `http://your-domain.com:8078`
-   **Health Check**: `http://your-domain.com:8078/api/health`
-   **API Health**: `http://your-domain.com:8078/api/v1/health`
-   **API Base**: `http://your-domain.com:8078/api/v1/`

### **8. GitHub Actions Secrets**

Make sure your GitHub secrets are configured:

| Secret Name      | Value                                    | Description                  |
| ---------------- | ---------------------------------------- | ---------------------------- |
| `SERVER_HOST`    | `your-vps-ip`                            | Your VPS IP address          |
| `SERVER_USER`    | `ubuntu` or `root`                       | VPS username                 |
| `SERVER_SSH_KEY` | `-----BEGIN OPENSSH PRIVATE KEY-----...` | Your private SSH key         |
| `SERVER_PORT`    | `22`                                     | SSH port (optional)          |
| `PROJECT_PATH`   | `/var/www/samschool-backend`             | Project directory (optional) |

### **9. Testing the Setup**

#### **A. Test Nginx Configuration**

```bash
sudo nginx -t
```

#### **B. Test Application Health**

```bash
curl http://localhost:8078/api/health
```

#### **C. Test from External**

```bash
curl http://your-domain.com:8078/api/health
```

### **10. Deployment Process**

1. **Push to main branch** → GitHub Actions triggered
2. **Tests run** → PHP tests, security scans
3. **Deployment** → Code deployed to VPS
4. **Health checks** → Tests `http://localhost:8078/api/health`
5. **Success** → Application running on port 8078

### **11. Troubleshooting**

#### **Port 8078 not accessible:**

```bash
# Check if Nginx is listening on port 8078
sudo netstat -tlnp | grep 8078

# Check Nginx status
sudo systemctl status nginx

# Check Nginx configuration
sudo nginx -t
```

#### **Firewall issues:**

```bash
# Check firewall status
sudo ufw status

# Allow port 8078
sudo ufw allow 8078
```

#### **Application not responding:**

```bash
# Check PHP-FPM status
sudo systemctl status php8.2-fpm

# Check application logs
tail -f /var/www/samschool-backend/storage/logs/laravel.log
```

### **12. Production Considerations**

#### **A. Load Balancer Setup (if needed)**

If you need to use a load balancer in front of port 8078:

```nginx
upstream samschool_backend {
    server 127.0.0.1:8078;
}

server {
    listen 80;
    server_name your-domain.com;

    location / {
        proxy_pass http://samschool_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

#### **B. Reverse Proxy Setup**

```nginx
server {
    listen 80;
    server_name your-domain.com;

    location / {
        proxy_pass http://127.0.0.1:8078;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### **13. Monitoring**

#### **A. Health Check Script**

```bash
#!/bin/bash
# health-check-8078.sh

echo "Checking SamSchool Management System on port 8078..."

# Check if port 8078 is listening
if netstat -tlnp | grep -q ":8078"; then
    echo "✅ Port 8078 is listening"
else
    echo "❌ Port 8078 is not listening"
    exit 1
fi

# Check application health
if curl -f http://localhost:8078/api/health > /dev/null 2>&1; then
    echo "✅ Application health check passed"
else
    echo "❌ Application health check failed"
    exit 1
fi

echo "🎉 All checks passed!"
```

#### **B. Cron Job for Health Monitoring**

```bash
# Add to crontab
*/5 * * * * /path/to/health-check-8078.sh
```

This setup ensures your SamSchool Management System runs smoothly on port 8078 with proper health checks and monitoring! 🚀
