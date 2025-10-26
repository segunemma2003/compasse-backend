#!/bin/bash

# SamSchool Management System - Server Setup Script
# This script sets up the complete production environment

set -e  # Exit on any error

echo "🚀 Setting up SamSchool Management System on server..."

# Configuration
PROJECT_DIR="/var/www/samschool-backend"
NGINX_CONFIG="/etc/nginx/sites-available/samschool"
SUPERVISOR_CONFIG="/etc/supervisor/conf.d/samschool.conf"
SERVICE_USER="www-data"
PHP_VERSION="8.2"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_header() {
    echo -e "${BLUE}=== $1 ===${NC}"
}

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   print_error "This script should not be run as root. Please run as a regular user with sudo privileges."
   exit 1
fi

print_header "SamSchool Management System Server Setup"

# Update system packages
print_status "Updating system packages..."
sudo apt update && sudo apt upgrade -y

# Install required packages
print_status "Installing required packages..."
sudo apt install -y \
    nginx \
    mysql-server \
    redis-server \
    supervisor \
    git \
    curl \
    wget \
    unzip \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    gnupg \
    lsb-release

# Install PHP 8.2
print_status "Installing PHP 8.2..."
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y \
    php8.2 \
    php8.2-fpm \
    php8.2-mysql \
    php8.2-redis \
    php8.2-xml \
    php8.2-curl \
    php8.2-gd \
    php8.2-mbstring \
    php8.2-zip \
    php8.2-bcmath \
    php8.2-intl \
    php8.2-soap \
    php8.2-xsl \
    php8.2-readline \
    php8.2-cli \
    php8.2-common

# Install Composer
print_status "Installing Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
fi

# Install Node.js 18
print_status "Installing Node.js 18..."
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Configure MySQL
print_status "Configuring MySQL..."
sudo mysql_secure_installation <<EOF

n
n
n
n
n
n
EOF

# Create database and user
print_status "Creating database and user..."
sudo mysql -e "CREATE DATABASE IF NOT EXISTS samschool_main CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'samschool'@'localhost' IDENTIFIED BY 'samschool_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON samschool_main.* TO 'samschool'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Configure Redis
print_status "Configuring Redis..."
sudo systemctl enable redis-server
sudo systemctl start redis-server

# Create project directory
print_status "Creating project directory..."
sudo mkdir -p $PROJECT_DIR
sudo chown -R $USER:$USER $PROJECT_DIR

# Clone repository (if not already cloned)
if [ ! -d "$PROJECT_DIR/.git" ]; then
    print_status "Cloning repository..."
    git clone https://github.com/your-username/samschool-backend.git $PROJECT_DIR
fi

# Install PHP dependencies
print_status "Installing PHP dependencies..."
cd $PROJECT_DIR
composer install --no-dev --optimize-autoloader --no-interaction

# Install Node dependencies
print_status "Installing Node dependencies..."
npm ci --only=production

# Build assets
print_status "Building assets..."
npm run build

# Set up environment file
print_status "Setting up environment file..."
if [ ! -f "$PROJECT_DIR/.env" ]; then
    cp $PROJECT_DIR/.env.example $PROJECT_DIR/.env
    print_warning "Please update .env file with your configuration"
fi

# Generate application key
print_status "Generating application key..."
php artisan key:generate

# Set permissions
print_status "Setting permissions..."
sudo chown -R $SERVICE_USER:$SERVICE_USER $PROJECT_DIR
sudo chmod -R 755 $PROJECT_DIR
sudo chmod -R 775 $PROJECT_DIR/storage
sudo chmod -R 775 $PROJECT_DIR/bootstrap/cache

# Create storage link
print_status "Creating storage link..."
php artisan storage:link

# Run migrations
print_status "Running migrations..."
php artisan migrate --force

# Seed database
print_status "Seeding database..."
php artisan db:seed --force

# Configure Nginx
print_status "Configuring Nginx..."
sudo tee $NGINX_CONFIG > /dev/null <<EOF
server {
    listen 80;
    server_name your-domain.com www.your-domain.com;
    root $PROJECT_DIR/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Security headers
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "no-referrer-when-downgrade";
    add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'";

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied expired no-cache no-store private must-revalidate auth;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss application/javascript;
}
EOF

# Enable Nginx site
sudo ln -sf $NGINX_CONFIG /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx

# Configure Supervisor for queue workers
print_status "Configuring Supervisor..."
sudo tee $SUPERVISOR_CONFIG > /dev/null <<EOF
[program:samschool-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_DIR/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=$SERVICE_USER
numprocs=4
redirect_stderr=true
stdout_logfile=$PROJECT_DIR/storage/logs/worker.log
stopwaitsecs=3600

[program:samschool-horizon]
process_name=%(program_name)s
command=php $PROJECT_DIR/artisan horizon
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=$SERVICE_USER
redirect_stderr=true
stdout_logfile=$PROJECT_DIR/storage/logs/horizon.log
stopwaitsecs=3600
EOF

# Reload Supervisor configuration
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all

# Configure systemd services
print_status "Configuring systemd services..."

# Create systemd service for the application
sudo tee /etc/systemd/system/samschool.service > /dev/null <<EOF
[Unit]
Description=SamSchool Management System
After=network.target mysql.service redis.service

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_USER
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/bin/php $PROJECT_DIR/artisan serve --host=0.0.0.0 --port=8000
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Enable and start services
sudo systemctl daemon-reload
sudo systemctl enable samschool
sudo systemctl start samschool

# Configure log rotation
print_status "Configuring log rotation..."
sudo tee /etc/logrotate.d/samschool > /dev/null <<EOF
$PROJECT_DIR/storage/logs/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    create 644 $SERVICE_USER $SERVICE_USER
    postrotate
        sudo systemctl reload samschool
    endscript
}
EOF

# Set up SSL certificate (Let's Encrypt)
print_status "Setting up SSL certificate..."
if command -v certbot &> /dev/null; then
    sudo certbot --nginx -d your-domain.com -d www.your-domain.com --non-interactive --agree-tos --email your-email@example.com
else
    print_warning "Certbot not found. Please install and configure SSL manually."
fi

# Configure firewall
print_status "Configuring firewall..."
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable

# Set up monitoring
print_status "Setting up monitoring..."

# Create health check script
sudo tee /usr/local/bin/samschool-health-check.sh > /dev/null <<EOF
#!/bin/bash

# SamSchool Health Check Script
PROJECT_DIR="$PROJECT_DIR"
LOG_FILE="/var/log/samschool-health.log"

# Check if application is running
if ! curl -f http://localhost:8000/api/health > /dev/null 2>&1; then
    echo "\$(date): Application health check failed" >> \$LOG_FILE
    sudo systemctl restart samschool
    exit 1
fi

# Check if queue workers are running
if ! sudo supervisorctl status samschool-worker:* | grep -q RUNNING; then
    echo "\$(date): Queue workers not running" >> \$LOG_FILE
    sudo supervisorctl restart samschool-worker:*
fi

# Check if Horizon is running
if ! sudo supervisorctl status samschool-horizon | grep -q RUNNING; then
    echo "\$(date): Horizon not running" >> \$LOG_FILE
    sudo supervisorctl restart samschool-horizon
fi

echo "\$(date): All health checks passed" >> \$LOG_FILE
EOF

sudo chmod +x /usr/local/bin/samschool-health-check.sh

# Add health check to crontab
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/samschool-health-check.sh") | crontab -

# Create backup script
print_status "Creating backup script..."
sudo tee /usr/local/bin/samschool-backup.sh > /dev/null <<EOF
#!/bin/bash

# SamSchool Backup Script
BACKUP_DIR="/var/backups/samschool"
PROJECT_DIR="$PROJECT_DIR"
DATE=\$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p \$BACKUP_DIR

# Database backup
mysqldump -u samschool -psamschool_password samschool_main > \$BACKUP_DIR/database_\$DATE.sql

# Application backup
tar -czf \$BACKUP_DIR/application_\$DATE.tar.gz -C \$PROJECT_DIR .

# Keep only last 7 days of backups
find \$BACKUP_DIR -name "*.sql" -mtime +7 -delete
find \$BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete

echo "\$(date): Backup completed" >> /var/log/samschool-backup.log
EOF

sudo chmod +x /usr/local/bin/samschool-backup.sh

# Add backup to crontab (daily at 2 AM)
(crontab -l 2>/dev/null; echo "0 2 * * * /usr/local/bin/samschool-backup.sh") | crontab -

# Final system check
print_status "Running final system check..."

# Check services
sudo systemctl status nginx --no-pager
sudo systemctl status php8.2-fpm --no-pager
sudo systemctl status mysql --no-pager
sudo systemctl status redis-server --no-pager
sudo systemctl status samschool --no-pager

# Check queue workers
sudo supervisorctl status

# Test application
print_status "Testing application..."
sleep 5
curl -f http://localhost:8000/api/health || print_error "Application health check failed"

# Run comprehensive test
print_status "Running comprehensive system test..."
cd $PROJECT_DIR
php complete-system-test.php

print_header "Setup Complete! 🎉"

echo -e "${GREEN}✅ SamSchool Management System has been successfully set up!${NC}"
echo ""
echo "📋 System Information:"
echo "• Application URL: http://your-domain.com"
echo "• API Endpoints: http://your-domain.com/api/v1"
echo "• Horizon Dashboard: http://your-domain.com/horizon"
echo "• Project Directory: $PROJECT_DIR"
echo "• Logs: $PROJECT_DIR/storage/logs"
echo ""
echo "🔧 Management Commands:"
echo "• Check status: sudo systemctl status samschool"
echo "• Restart app: sudo systemctl restart samschool"
echo "• Check queues: sudo supervisorctl status"
echo "• Restart queues: sudo supervisorctl restart all"
echo "• View logs: tail -f $PROJECT_DIR/storage/logs/laravel.log"
echo "• Run tests: cd $PROJECT_DIR && php complete-system-test.php"
echo ""
echo "📊 Monitoring:"
echo "• Health check runs every 5 minutes"
echo "• Backups run daily at 2 AM"
echo "• Logs are rotated daily"
echo ""
echo "🚀 Your system is ready for production!"
echo ""
print_warning "Don't forget to:"
echo "1. Update your domain name in Nginx configuration"
echo "2. Configure SSL certificate"
echo "3. Set up New Relic monitoring"
echo "4. Configure your .env file with production values"
echo "5. Set up database backups"
