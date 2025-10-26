#!/bin/bash

# VPS Setup Script for SamSchool Management System - Port 8078
# Run this script on your VPS to prepare it for deployment

echo "🚀 Setting up VPS for SamSchool Management System on Port 8078..."

# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y nginx mysql-server redis-server supervisor git curl wget unzip

# Install PHP 8.2 and extensions
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.2-fpm php8.2-mysql php8.2-redis php8.2-gd php8.2-curl php8.2-zip php8.2-xml php8.2-mbstring php8.2-bcmath php8.2-intl php8.2-soap php8.2-readline php8.2-sqlite3 php8.2-pdo php8.2-tokenizer php8.2-xmlwriter php8.2-xmlreader php8.2-sodium php8.2-iconv php8.2-json php8.2-filter php8.2-hash php8.2-session php8.2-standard php8.2-mysqlnd php8.2-pcre php8.2-spl php8.2-zlib php8.2-calendar php8.2-ctype php8.2-exif php8.2-ffi php8.2-ftp php8.2-gettext php8.2-gmp php8.2-imap php8.2-ldap php8.2-odbc php8.2-pcntl php8.2-pdo-odbc php8.2-pdo-pgsql php8.2-pgsql php8.2-shmop php8.2-snmp php8.2-sockets php8.2-sysvmsg php8.2-sysvsem php8.2-sysvshm php8.2-tidy php8.2-xmlrpc php8.2-xsl php8.2-zip php8.2-zlib

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Install Node.js 18
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Configure MySQL
sudo mysql_secure_installation

# Create MySQL databases
sudo mysql -e "CREATE DATABASE IF NOT EXISTS samschool_main CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE DATABASE IF NOT EXISTS samschool_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'samschool'@'localhost' IDENTIFIED BY 'your_secure_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON samschool_main.* TO 'samschool'@'localhost';"
sudo mysql -e "GRANT ALL PRIVILEGES ON samschool_test.* TO 'samschool'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Configure Redis
sudo systemctl enable redis-server
sudo systemctl start redis-server

# Configure Nginx for Port 8078
sudo tee /etc/nginx/sites-available/samschool << 'EOF'
server {
    listen 8078;
    server_name your-domain.com www.your-domain.com;
    root /var/www/samschool-backend/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

# Enable the site
sudo ln -s /etc/nginx/sites-available/samschool /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart nginx

# Configure PHP-FPM
sudo tee /etc/php/8.2/fpm/pool.d/samschool.conf << 'EOF'
[samschool]
user = www-data
group = www-data
listen = /var/run/php/php8.2-fpm-samschool.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500
EOF

sudo systemctl restart php8.2-fpm

# Create project directory
sudo mkdir -p /var/www/samschool-backend
sudo chown -R $USER:www-data /var/www/samschool-backend
sudo chmod -R 775 /var/www/samschool-backend

# Configure Supervisor for queue workers
sudo tee /etc/supervisor/conf.d/samschool-worker.conf << 'EOF'
[program:samschool-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/samschool-backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/samschool-backend/storage/logs/worker.log
stopwaitsecs=3600
EOF

# Configure Supervisor for Horizon
sudo tee /etc/supervisor/conf.d/samschool-horizon.conf << 'EOF'
[program:samschool-horizon]
process_name=%(program_name)s
command=php /var/www/samschool-backend/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/samschool-backend/storage/logs/horizon.log
stopwaitsecs=3600
EOF

# Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update

# Configure firewall for port 8078
sudo ufw allow 8078
sudo ufw allow 22
sudo ufw --force enable

echo "✅ VPS setup completed on port 8078!"
echo "📝 Next steps:"
echo "1. Update your domain in /etc/nginx/sites-available/samschool"
echo "2. Set up SSL certificate with: sudo certbot --nginx -d your-domain.com"
echo "3. Configure GitHub Secrets for deployment"
echo "4. Deploy your application using GitHub Actions"
echo "5. Access your application at: http://your-domain.com:8078"
echo "6. Health check endpoint: http://your-domain.com:8078/api/health"
