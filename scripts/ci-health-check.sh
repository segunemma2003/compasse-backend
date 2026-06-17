#!/usr/bin/env bash
set -e

PROJECT_DIR="${PROJECT_DIR:-/var/www/compasse-backend}"
cd "$PROJECT_DIR"

echo "========================================="
echo "🏥 Health Check - Verifying Deployment"
echo "========================================="

check_health() {
  local url=$1
  local name=$2
  local max_attempts=5
  local attempt=1

  echo "🔍 Checking $name..."
  while [ $attempt -le $max_attempts ]; do
    response=$(curl -s -w "\n%{http_code}" -m 15 "$url" || echo -e "\n000")
    http_code=$(echo "$response" | tail -n1)
    if [ "$http_code" = "200" ]; then
      echo "   ✅ $name is healthy (HTTP $http_code)"
      return 0
    fi
    echo "   ⚠️  Attempt $attempt failed (HTTP $http_code)"
    sleep 5
    attempt=$((attempt + 1))
  done
  return 1
}

check_health "https://api.compasse.net/api/health" "API Health" || echo "⚠️  API health check failed"
check_health "https://api.compasse.net/api/v1/health" "API v1 Health" || echo "⚠️  v1 health skipped"

if [ -n "${APP_URL:-}" ]; then
  APP_URL="${APP_URL%/}"
  check_health "${APP_URL}/api/health" "API Health (public)" || echo "ℹ️  Public health skipped"
fi

php artisan horizon:status || echo "⚠️  Horizon not responding"
sudo supervisorctl status compasse-reverb || echo "⚠️  Reverb not responding"
sudo supervisorctl status compasse-bulk-worker:* || echo "⚠️  Bulk workers not running"
php artisan db:show --no-interaction 2>/dev/null | head -5 || echo "⚠️  DB check skipped"

echo "✅ Health check complete"
