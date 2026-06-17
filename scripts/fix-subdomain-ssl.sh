#!/usr/bin/env bash
# Fix "site not secured" on *.compasse.net subdomains.
#
# Root cause: compasse-frontend nginx claims *.compasse.net but uses a cert
# that only covers compasse.net + www — browsers reject it for school subdomains.
# compasse-tenants already has the correct wildcard cert; this script removes
# the conflicting server_name entries and reloads nginx.
#
# Usage: sudo ./scripts/fix-subdomain-ssl.sh

set -euo pipefail

FRONTEND_CONF="/etc/nginx/sites-enabled/compasse-frontend"
TENANTS_AVAILABLE="/etc/nginx/sites-available/compasse-tenants"
TENANTS_ENABLED="/etc/nginx/sites-enabled/compasse-tenants"
WILDCARD_CERT="/etc/letsencrypt/live/compasse-wildcard/fullchain.pem"

echo "🔧 Fixing subdomain SSL routing..."

if [[ "$(id -u)" -ne 0 ]]; then
    echo "❌ Run as root: sudo $0"
    exit 1
fi

if [[ -f "${FRONTEND_CONF}" ]]; then
    if grep -q '\*\.compasse\.net' "${FRONTEND_CONF}"; then
        sed -i 's/server_name compasse.net www.compasse.net \*.compasse.net;/server_name compasse.net www.compasse.net;/g' "${FRONTEND_CONF}"
        echo "✅ Removed *.compasse.net from ${FRONTEND_CONF}"
    else
        echo "ℹ️  ${FRONTEND_CONF} already scoped to root domain only"
    fi
fi

if [[ -f "nginx-compasse-frontend.conf" ]]; then
    cp nginx-compasse-frontend.conf /etc/nginx/sites-available/compasse-frontend
    ln -sf /etc/nginx/sites-available/compasse-frontend "${FRONTEND_CONF}"
    echo "✅ Installed nginx-compasse-frontend.conf"
fi

if [[ -f "nginx-tenants.conf" ]]; then
    cp nginx-tenants.conf "${TENANTS_AVAILABLE}"
    ln -sf "${TENANTS_AVAILABLE}" "${TENANTS_ENABLED}"
    echo "✅ Ensured compasse-tenants config is active"
fi

if [[ ! -f "${WILDCARD_CERT}" ]]; then
    echo "⚠️  Wildcard cert missing at ${WILDCARD_CERT}"
    echo "   Run: sudo -E ./scripts/setup-wildcard-ssl.sh"
    echo "   (Set CF_Token or your DNS API credentials first)"
else
    echo "✅ Wildcard cert present: $(openssl x509 -in ${WILDCARD_CERT} -noout -subject -dates)"
fi

nginx -t
systemctl reload nginx
echo "✅ Nginx reloaded"

echo ""
echo "🧪 Quick check (should show CN=*.compasse.net):"
echo | openssl s_client -connect 127.0.0.1:443 -servername test.compasse.net 2>/dev/null \
    | openssl x509 -noout -subject 2>/dev/null || echo "   (could not verify locally — try in browser)"
