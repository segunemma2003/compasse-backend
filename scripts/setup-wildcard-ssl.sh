#!/usr/bin/env bash
# Issue / renew a wildcard SSL cert for *.compasse.net via acme.sh (DNS-01 challenge).
# Requires DNS API credentials for your domain registrar (e.g. Cloudflare).
#
# Usage (first time):
#   export CF_Token="your-cloudflare-api-token"
#   export CF_Account_ID="your-account-id"   # optional for some setups
#   sudo -E ./scripts/setup-wildcard-ssl.sh
#
# acme.sh installs a daily cron job for automatic renewal.

set -euo pipefail

DOMAIN="compasse.net"
WILDCARD="*.${DOMAIN}"
CERT_NAME="compasse-wildcard"
CERT_DIR="/etc/letsencrypt/live/${CERT_NAME}"
ACME_HOME="${ACME_HOME:-/home/deploy/.acme.sh}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
EMAIL="${CERTBOT_EMAIL:-admin@compasse.net}"

# DNS provider for acme.sh — change if not using Cloudflare
DNS_API="${DNS_API:-dns_cf}"

echo "🔒 Wildcard SSL setup for ${WILDCARD}"
echo "   DNS API: ${DNS_API}"
echo "   Cert path: ${CERT_DIR}"

if [[ "$(id -u)" -ne 0 ]]; then
    echo "❌ Run as root: sudo -E $0"
    exit 1
fi

if [[ ! -x "${ACME_HOME}/acme.sh" ]]; then
    echo "📦 Installing acme.sh for ${DEPLOY_USER}..."
    su - "${DEPLOY_USER}" -c 'curl -fsSL https://get.acme.sh | sh -s email='"${EMAIL}"
fi

ACME="${ACME_HOME}/acme.sh"

issue_or_renew() {
    if [[ -f "${CERT_DIR}/fullchain.pem" ]]; then
        echo "ℹ️  Existing cert found — renewing..."
        su - "${DEPLOY_USER}" -c "${ACME} --renew -d ${DOMAIN} -d ${WILDCARD} --force"
    else
        echo "📜 Issuing new wildcard certificate..."
        su - "${DEPLOY_USER}" -c "${ACME} --issue --dns ${DNS_API} -d ${DOMAIN} -d ${WILDCARD}"
    fi
}

install_cert() {
    echo "📁 Installing cert to ${CERT_DIR}..."
    su - "${DEPLOY_USER}" -c "${ACME} --install-cert -d ${DOMAIN} \
        --cert-file ${CERT_DIR}/cert.pem \
        --key-file ${CERT_DIR}/privkey.pem \
        --fullchain-file ${CERT_DIR}/fullchain.pem \
        --reloadcmd 'systemctl reload nginx'"
}

ensure_cron() {
    if su - "${DEPLOY_USER}" -c "crontab -l 2>/dev/null" | grep -q 'acme.sh --cron'; then
        echo "✅ acme.sh auto-renew cron already configured"
    else
        echo "⏰ Adding acme.sh daily renewal cron..."
        (su - "${DEPLOY_USER}" -c "crontab -l 2>/dev/null"; echo "31 6 * * * \"${ACME_HOME}\"/acme.sh --cron --home \"${ACME_HOME}\" > /dev/null") \
            | su - "${DEPLOY_USER}" -c "crontab -"
    fi
}

mkdir -p "${CERT_DIR}"
issue_or_renew
install_cert
ensure_cron

echo ""
echo "✅ Wildcard SSL ready at ${CERT_DIR}"
echo "   Verify: openssl s_client -connect school.compasse.net:443 -servername school.compasse.net </dev/null 2>/dev/null | openssl x509 -noout -subject"
