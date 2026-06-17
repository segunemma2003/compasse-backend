#!/usr/bin/env bash
# Remote production deploy script — executed over SSH from GitHub Actions.
# Required env: PROJECT_DIR
# Optional env: REPO_URL, CERTBOT_EMAIL, CF_Token

set -e
set -x

echo "========================================="
echo "🚀 Starting Deployment Process"
echo "========================================="

PROJECT_DIR="${PROJECT_DIR:-/var/www/compasse-backend}"
REPO_URL="${REPO_URL:-}"
if [ -z "$REPO_URL" ]; then
  REPO_URL="git@github-backend:segunemma2003/compasse-backend.git"
fi

echo "📁 Project Directory: $PROJECT_DIR"
echo "🔗 Repository URL: $REPO_URL"

if [ ! -d "$PROJECT_DIR" ]; then
  echo "📂 Project directory does not exist. Creating directory..."
  sudo mkdir -p "$PROJECT_DIR"
  sudo chown -R "$USER:$USER" "$PROJECT_DIR"
  echo "✅ Directory created: $PROJECT_DIR"
else
  echo "✅ Project directory exists: $PROJECT_DIR"
fi

sudo chown -R "$USER:$USER" "$PROJECT_DIR"

mkdir -p ~/.ssh
ssh-keyscan -H github.com >> ~/.ssh/known_hosts 2>/dev/null || true
chmod 600 ~/.ssh/known_hosts

cd "$PROJECT_DIR"
echo "📍 Current directory: $(pwd)"

if [ ! -d ".git" ]; then
  echo "📥 Not a git repository. Cloning repository..."
  if [[ "$REPO_URL" == *"@"* ]] || [[ "$REPO_URL" == *"git@"* ]]; then
    git clone "$REPO_URL" .
  else
    [[ "$REPO_URL" != *".git" ]] && REPO_URL="${REPO_URL}.git"
    git clone "$REPO_URL" .
  fi
  echo "✅ Repository cloned successfully"
else
  echo "📥 Existing git repository found. Pulling latest changes..."
  git config --global --add safe.directory "$PROJECT_DIR"
  git remote set-url origin git@github-backend:segunemma2003/compasse-backend.git
  git pull origin main || git pull origin master || echo "⚠️  Git pull had issues, continuing..."
  echo "✅ Code updated"
fi

echo "========================================="
echo "📦 Installing Dependencies"
echo "========================================="
composer install --no-dev --optimize-autoloader --no-interaction
npm ci

echo "========================================="
echo "🔨 Building Assets"
echo "========================================="
npm run build

sudo chown -R "$USER:www-data" storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

echo "========================================="
echo "🧹 Clearing Caches"
echo "========================================="
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "========================================="
echo "💾 Caching for Production"
echo "========================================="
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "========================================="
echo "🗄️  Running Database Migrations"
echo "========================================="
php artisan migrate --force
php artisan db:seed --class=SuperAdminSeeder --force
php artisan storage:link

echo "========================================="
echo "🏢 Running Tenant Migrations"
echo "========================================="
php artisan tenants:migrate --force

echo "========================================="
echo "🏢 Setting Up Multi-Tenant Databases"
echo "========================================="
php artisan tinker --execute="
\$tenants = App\Models\Tenant::all();
foreach (\$tenants as \$tenant) {
    try {
        \$databaseName = \$tenant->database_name;
        \$databaseExists = DB::select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [\$databaseName]);
        if (empty(\$databaseExists)) {
            DB::statement('CREATE DATABASE ' . \$databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            echo 'Created new database: ' . \$databaseName;
        } else {
            echo 'Database already exists: ' . \$databaseName;
        }
        config(['database.connections.tenant' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => \$databaseName,
            'username' => \$tenant->database_username,
            'password' => \$tenant->database_password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]]);
        try {
            Artisan::call('migrate', ['--database' => 'tenant', '--force' => true, '--path' => 'database/migrations/tenant']);
            echo ' - Migrations completed for ' . \$databaseName;
        } catch (Exception \$migrationError) {
            echo ' - Migration error for ' . \$databaseName . ': ' . \$migrationError->getMessage();
        }
        echo ' - Tenant database ' . \$databaseName . ' setup completed';
    } catch (Exception \$e) {
        echo 'Error setting up tenant ' . \$tenant->name . ': ' . \$e->getMessage();
    }
}
"

echo "========================================="
echo "🔒 Setting up SSL / Nginx"
echo "========================================="
if ! command -v certbot &> /dev/null; then
  sudo apt-get update -qq
  sudo apt-get install -y certbot python3-certbot-nginx
fi

if [ -f "nginx-api-compasse.conf" ]; then
  sudo cp /etc/nginx/sites-available/compasse-backend "/etc/nginx/sites-available/compasse-backend.bak.$(date +%Y%m%d%H%M%S)" || true
  sudo cp nginx-api-compasse.conf /etc/nginx/sites-available/compasse-backend
  sudo ln -sf /etc/nginx/sites-available/compasse-backend /etc/nginx/sites-enabled/compasse-backend
fi

if [ -f "nginx-compasse-frontend.conf" ]; then
  sudo cp nginx-compasse-frontend.conf /etc/nginx/sites-available/compasse-frontend
  sudo ln -sf /etc/nginx/sites-available/compasse-frontend /etc/nginx/sites-enabled/compasse-frontend
fi

if [ -f "nginx-tenants.conf" ]; then
  sudo cp nginx-tenants.conf /etc/nginx/sites-available/compasse-tenants
  sudo ln -sf /etc/nginx/sites-available/compasse-tenants /etc/nginx/sites-enabled/compasse-tenants
fi

if [ ! -f "/etc/letsencrypt/live/compasse-wildcard/fullchain.pem" ]; then
  if [ -f "scripts/setup-wildcard-ssl.sh" ] && [ -n "${CF_Token:-}" ]; then
    sudo -E bash scripts/setup-wildcard-ssl.sh || echo "⚠️  Wildcard SSL setup failed"
  else
    echo "⚠️  Wildcard cert missing — set CF_Token secret to auto-provision"
  fi
else
  sudo -u deploy /home/deploy/.acme.sh/acme.sh --cron --home /home/deploy/.acme.sh > /dev/null 2>&1 || true
fi

sudo nginx -t
sudo systemctl reload nginx

if [ ! -d "/etc/letsencrypt/live/api.compasse.net" ]; then
  sudo certbot --nginx -d api.compasse.net --non-interactive --agree-tos --email "${CERTBOT_EMAIL:-admin@compasse.net}" || true
else
  sudo certbot renew --quiet || true
fi

echo "========================================="
echo "🔄 Restarting Services"
echo "========================================="
sudo systemctl restart nginx
sudo systemctl restart php8.4-fpm

for CONF in compasse-horizon compasse-reverb compasse-bulk-worker; do
  if [ -f "$PROJECT_DIR/supervisor/${CONF}.conf" ]; then
    sudo cp "$PROJECT_DIR/supervisor/${CONF}.conf" "/etc/supervisor/conf.d/${CONF}.conf"
  fi
done

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart compasse-horizon || sudo supervisorctl start compasse-horizon || true
php artisan horizon:status || true
sudo supervisorctl restart compasse-reverb || sudo supervisorctl start compasse-reverb || true
sudo supervisorctl restart compasse-bulk-worker:* || sudo supervisorctl start compasse-bulk-worker:* || true

php artisan cache:clear
php artisan queue:restart

CRON_JOB="* * * * * cd $PROJECT_DIR && php artisan schedule:run >> /dev/null 2>&1"
( crontab -l 2>/dev/null | grep -v "artisan schedule:run"; echo "$CRON_JOB" ) | crontab -

echo "========================================="
echo "✅ Deployment completed successfully!"
echo "========================================="
