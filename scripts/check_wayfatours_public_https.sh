#!/usr/bin/env bash
# Read-only public promotion check. This MUST PASS before a public "LIVE" claim.
set -euo pipefail
HOST=wayfatours.mad4b.com
ROOT=/opt/hostinger-migrations/hosting-3/wayfatours-production
if [ "$(id -u)" -ne 0 ] || [ ! -f "$ROOT/site-copy/wp-config.php" ]; then
 echo 'WAYFATOURS_NOT_PROVISIONED' >&2; exit 2
fi
if ! getent ahostsv4 "$HOST" >/dev/null; then
 echo "PUBLIC_PROMOTION_BLOCKED: DNS_MISSING for $HOST" >&2; exit 3
fi
# No -k. The Coolify/Traefik ORIGIN certificate itself must be valid.
if ! curl --noproxy '*' --fail --silent --show-error --max-time 25 \
 --resolve "$HOST:443:127.0.0.1" "https://$HOST/" | grep -q 'Discover Wayfatours'; then
 echo 'PUBLIC_PROMOTION_BLOCKED: TRUSTED_ORIGIN_TLS_FAILED' >&2; exit 4
fi
# Also check public DNS-facing routing, not just the origin.
if ! curl --noproxy '*' --fail --silent --show-error --max-time 25 \
 "https://$HOST/" | grep -q 'Discover Wayfatours'; then
 echo 'PUBLIC_PROMOTION_BLOCKED: PUBLIC_HTTPS_FAILED' >&2; exit 5
fi
docker exec -u 33:33 \
 -e MAD4B_EXPECTED_COMPANY=wayfatours \
 -e MAD4B_EXPECTED_HOST="$HOST" \
 -e MAD4B_EXPECTED_DB=wayfatours_production \
 wayfatours-production-php wp eval-file \
 /tmp/gate_new_company_production.php --path=/var/www/html
echo 'WAYFATOURS_PUBLIC_ROUTING_TLS_AND_TENANT_ADMISSION_VERIFIED'
