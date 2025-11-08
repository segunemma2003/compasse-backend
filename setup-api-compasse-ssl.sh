#!/bin/bash

# Setup script for api.compasse.net with SSL
# This script configures Nginx for api.compasse.net and sets up SSL certificate

set -e

echo "========================================="
echo "🔧 Setting up api.compasse.net with SSL"
echo "========================================="

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Please run as root (use sudo)"
    exit 1
fi

# Variables
DOMAIN="api.compasse.net"
PROJECT_DIR="/var/www/samschool-backend"
NGINX_SITES="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
NGINX_CONFIG="$NGINX_SITES/samschool"
NGINX_SYMLINK="$NGINX_ENABLED/samschool"

echo ""
echo "📋 Configuration:"
echo "   Domain: $DOMAIN"
echo "   Project: $PROJECT_DIR"
echo ""

# Step 1: Install Certbot if not installed
echo "🔍 Checking Certbot installation..."
if ! command -v certbot &> /dev/null; then
    echo "📦 Installing Certbot..."
    apt-get update
    apt-get install -y certbot python3-certbot-nginx
    echo "✅ Certbot installed"
else
    echo "✅ Certbot already installed"
fi

# Step 2: Copy Nginx configuration
echo ""
echo "📝 Setting up Nginx configuration..."

# Backup existing configuration
if [ -f "$NGINX_CONFIG" ]; then
    cp "$NGINX_CONFIG" "${NGINX_CONFIG}.bak.$(date +%Y%m%d%H%M%S)"
    echo "💾 Existing Nginx configuration backed up"
fi

# Copy the configuration file
if [ -f "$PROJECT_DIR/nginx-api-compasse.conf" ]; then
    cp "$PROJECT_DIR/nginx-api-compasse.conf" "$NGINX_CONFIG"
    echo "✅ Nginx configuration updated"
else
    echo "⚠️  Configuration file not found at $PROJECT_DIR/nginx-api-compasse.conf"
    echo "   Creating basic configuration..."

    # Create basic configuration
    cat > "$NGINX_CONFIG" << 'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name api.compasse.net;

    root /var/www/samschool-backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
    echo "✅ Basic configuration created"
fi

# Step 3: Ensure site is enabled
echo ""
echo "🔗 Ensuring Nginx site is enabled..."
ln -sf "$NGINX_CONFIG" "$NGINX_SYMLINK"
echo "✅ Nginx site link updated: $NGINX_SYMLINK"

# Step 4: Test Nginx configuration
echo ""
echo "🧪 Testing Nginx configuration..."
nginx -t
if [ $? -eq 0 ]; then
    echo "✅ Nginx configuration is valid"
else
    echo "❌ Nginx configuration test failed"
    exit 1
fi

# Step 5: Reload Nginx
echo ""
echo "🔄 Reloading Nginx..."
systemctl reload nginx
echo "✅ Nginx reloaded"

# Step 6: Obtain SSL Certificate
echo ""
echo "🔒 Obtaining SSL certificate for $DOMAIN..."
echo "   (This will prompt for email and agreement to terms)"

# Check if certificate already exists
if [ -d "/etc/letsencrypt/live/$DOMAIN" ]; then
    echo "ℹ️  SSL certificate already exists for $DOMAIN"
    read -p "   Do you want to renew it? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        certbot renew --nginx -d $DOMAIN --non-interactive
        echo "✅ Certificate renewed"
    else
        echo "⏭️  Skipping certificate renewal"
    fi
else
    # Obtain new certificate
    certbot --nginx -d $DOMAIN --non-interactive --agree-tos --email admin@compasse.net
    echo "✅ SSL certificate obtained"
fi

# Step 7: Update Nginx configuration with SSL settings
echo ""
echo "📝 Updating Nginx configuration with SSL settings..."
# The certbot command should have already updated the config, but let's verify
if grep -q "ssl_certificate" "$NGINX_CONFIG"; then
    echo "✅ SSL configuration detected in Nginx config"
else
    echo "⚠️  SSL configuration not found. Please run certbot manually:"
    echo "   sudo certbot --nginx -d $DOMAIN"
fi

# Step 8: Test Nginx again
echo ""
echo "🧪 Testing Nginx configuration after SSL setup..."
nginx -t
if [ $? -eq 0 ]; then
    echo "✅ Nginx configuration is valid"
else
    echo "❌ Nginx configuration test failed"
    exit 1
fi

# Step 9: Reload Nginx
echo ""
echo "🔄 Reloading Nginx with SSL configuration..."
systemctl reload nginx
echo "✅ Nginx reloaded"

# Step 10: Set up auto-renewal
echo ""
echo "⏰ Setting up SSL certificate auto-renewal..."
systemctl enable certbot.timer
systemctl start certbot.timer
echo "✅ Auto-renewal configured"

# Step 11: Configure firewall
echo ""
echo "🔥 Configuring firewall..."
if command -v ufw &> /dev/null; then
    ufw allow 'Nginx Full'
    ufw allow 80/tcp
    ufw allow 443/tcp
    echo "✅ Firewall configured"
else
    echo "⚠️  UFW not found, skipping firewall configuration"
fi

# Step 12: Verify setup
echo ""
echo "========================================="
echo "✅ Setup Complete!"
echo "========================================="
echo ""
echo "📋 Summary:"
echo "   Domain: $DOMAIN"
echo "   SSL: Enabled"
echo "   Configuration: $NGINX_CONFIG"
echo "   Project: $PROJECT_DIR"
echo ""
echo "🌐 Test your API:"
echo "   HTTPS: https://$DOMAIN/api/health"
echo "   HTTP:  http://$DOMAIN/api/health (should redirect to HTTPS)"
echo ""
echo "📝 Next steps:"
echo "   1. Update .env file: APP_URL=https://$DOMAIN"
echo "   2. Update DNS: Point $DOMAIN to your server IP"
echo "   3. Test API endpoints"
echo ""

