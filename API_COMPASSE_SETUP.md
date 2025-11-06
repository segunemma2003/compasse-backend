# API Domain Setup: api.compasse.net

## Overview

`api.compasse.net` is the main API domain that is **excluded from tenant resolution**. This means:

-   ✅ All requests to `api.compasse.net` use the **main database** (not tenant databases)
-   ✅ No tenant middleware is applied
-   ✅ Perfect for backend administration, super admin operations, and tenant management
-   ✅ SSL/HTTPS enabled for secure communication

## Configuration

### 1. Domain Exclusion

The domain is automatically excluded in:

-   **TenantMiddleware**: Checks `config('tenant.excluded_domains')`
-   **Config**: `config/tenant.php` includes `api.compasse.net` in excluded domains

### 2. Environment Variables

Update your `.env` file:

```env
# API Domain
APP_URL=https://api.compasse.net
API_DOMAIN=api.compasse.net

# Database (main database, not tenant)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=samschool_main
DB_USERNAME=samschool
DB_PASSWORD=your_secure_password
```

### 3. Nginx Configuration

The Nginx configuration file `nginx-api-compasse.conf` is included in the repository.

**Features:**

-   ✅ HTTP to HTTPS redirect
-   ✅ SSL/TLS encryption
-   ✅ Security headers (HSTS, X-Frame-Options, etc.)
-   ✅ Optimized for API requests
-   ✅ Static asset caching

### 4. SSL Certificate Setup

#### Option A: Automated Setup (Recommended)

```bash
# Run the setup script
chmod +x setup-api-compasse-ssl.sh
sudo ./setup-api-compasse-ssl.sh
```

This script will:

1. Install Certbot (if needed)
2. Configure Nginx
3. Obtain SSL certificate
4. Set up auto-renewal
5. Configure firewall

#### Option B: Manual Setup

```bash
# 1. Install Certbot
sudo apt-get update
sudo apt-get install -y certbot python3-certbot-nginx

# 2. Copy Nginx configuration
sudo cp nginx-api-compasse.conf /etc/nginx/sites-available/api-compasse
sudo ln -s /etc/nginx/sites-available/api-compasse /etc/nginx/sites-enabled/

# 3. Test Nginx configuration
sudo nginx -t

# 4. Reload Nginx
sudo systemctl reload nginx

# 5. Obtain SSL certificate
sudo certbot --nginx -d api.compasse.net

# 6. Set up auto-renewal
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer
```

### 5. DNS Configuration

Point your DNS A record:

```
Type: A
Name: api
Value: YOUR_SERVER_IP
TTL: 3600
```

### 6. Verify Setup

Test the API:

```bash
# Test HTTP (should redirect to HTTPS)
curl -I http://api.compasse.net/api/health

# Test HTTPS
curl https://api.compasse.net/api/health

# Expected response:
# {
#   "status": "ok",
#   "timestamp": "2025-01-26T10:00:00Z",
#   "version": "1.0.0"
# }
```

## Routes Available on api.compasse.net

### Public Routes (No Authentication)

-   `GET /api/health` - Health check
-   `GET /api/v1/schools/subdomain/{subdomain}` - Get school by subdomain
-   `POST /api/v1/auth/login` - User login
-   `POST /api/v1/auth/register` - User registration

### Super Admin Routes (Require Authentication)

-   `GET /api/v1/tenants` - List all tenants
-   `POST /api/v1/tenants` - Create new tenant/school
-   `GET /api/v1/tenants/{id}` - Get tenant details
-   `PUT /api/v1/tenants/{id}` - Update tenant
-   `DELETE /api/v1/tenants/{id}` - Delete tenant
-   `GET /api/v1/tenants/{id}/stats` - Get tenant statistics

## Deployment

The GitHub Actions workflow automatically:

1. ✅ Sets up Nginx configuration for `api.compasse.net`
2. ✅ Configures SSL certificate (if not already present)
3. ✅ Renews certificates when needed
4. ✅ Restarts services

## Troubleshooting

### SSL Certificate Not Working

```bash
# Check certificate status
sudo certbot certificates

# Renew certificate manually
sudo certbot renew --force-renewal -d api.compasse.net

# Check Nginx configuration
sudo nginx -t

# Check SSL connection
openssl s_client -connect api.compasse.net:443 -servername api.compasse.net
```

### Nginx Not Serving Requests

```bash
# Check Nginx status
sudo systemctl status nginx

# Check Nginx error logs
sudo tail -f /var/log/nginx/api-compasse-error.log

# Reload Nginx
sudo systemctl reload nginx
```

### Domain Resolution Issues

```bash
# Check DNS resolution
nslookup api.compasse.net
dig api.compasse.net

# Test from server
curl -I http://localhost/api/health
```

## Security Considerations

1. **Firewall**: Ensure ports 80 and 443 are open

    ```bash
    sudo ufw allow 'Nginx Full'
    ```

2. **SSL/TLS**: Uses modern TLS 1.2 and 1.3 protocols

3. **Security Headers**: Configured in Nginx for:

    - HSTS (HTTP Strict Transport Security)
    - X-Frame-Options
    - X-Content-Type-Options
    - X-XSS-Protection

4. **Rate Limiting**: Consider adding rate limiting in Nginx or Laravel

## Maintenance

### Certificate Renewal

Certificates auto-renew, but you can manually renew:

```bash
sudo certbot renew
sudo systemctl reload nginx
```

### Update Nginx Configuration

After updating `nginx-api-compasse.conf`:

```bash
sudo cp nginx-api-compasse.conf /etc/nginx/sites-available/api-compasse
sudo nginx -t
sudo systemctl reload nginx
```

## API Base URL

Once configured, your API base URL will be:

```
https://api.compasse.net
```

All API endpoints are accessible at:

```
https://api.compasse.net/api/v1/...
```

## Notes

-   `api.compasse.net` is **always excluded** from tenant resolution
-   All tenant-specific routes should use tenant subdomains or headers
-   Super admin operations should use `api.compasse.net`
-   Tenant databases are only accessed when using tenant subdomains or `X-Tenant-ID` header
