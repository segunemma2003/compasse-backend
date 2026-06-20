#!/usr/bin/env bash
# One-time bootstrap: install deploy-trigger.php and DEPLOY_WEBHOOK_TOKEN on the server.
# Run from your Mac (requires SSH access):
#   bash scripts/bootstrap-deploy-webhook.sh
#
# Then add the printed token to GitHub → Settings → Secrets → DEPLOY_WEBHOOK_TOKEN

set -euo pipefail

SERVER="${DEPLOY_SERVER:-deploy@31.97.155.60}"
SSH_KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/compasse_deploy}"
PROJECT_DIR="${DEPLOY_PROJECT_DIR:-/var/www/compasse-backend}"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "→ Copying deploy-trigger.php to $SERVER:$PROJECT_DIR/public/"
scp -i "$SSH_KEY" -o StrictHostKeyChecking=no \
  "$REPO_ROOT/public/deploy-trigger.php" \
  "$SERVER:$PROJECT_DIR/public/deploy-trigger.php"

echo "→ Setting DEPLOY_WEBHOOK_TOKEN on server (if missing)..."
ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no "$SERVER" bash -s <<EOF
set -e
cd "$PROJECT_DIR"
chmod 644 public/deploy-trigger.php
if grep -q '^DEPLOY_WEBHOOK_TOKEN=' .env 2>/dev/null; then
  TOKEN=\$(grep '^DEPLOY_WEBHOOK_TOKEN=' .env | cut -d= -f2- | tr -d '"')
  echo "Token already in .env (unchanged)"
else
  TOKEN=\$(openssl rand -hex 32)
  echo "DEPLOY_WEBHOOK_TOKEN=\$TOKEN" >> .env
  echo "Created new token"
fi
php artisan config:clear 2>/dev/null || true
echo ""
echo "========================================="
echo "Add this to GitHub Secrets as DEPLOY_WEBHOOK_TOKEN:"
echo "\$TOKEN"
echo "========================================="
echo ""
echo "Test:"
echo "curl -X POST https://api.compasse.net/deploy-trigger.php -H \"X-Deploy-Token: \$TOKEN\" -H \"Content-Type: application/json\" -d '{}'"
EOF

echo "✅ Bootstrap complete. Re-run the GitHub Actions deploy workflow."
