#!/usr/bin/env bash
# Local staging guard. Fail closed on Nginx redeploy or broken canary bypass.
set -Eeuo pipefail
Q=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/css-priority-auto-v1
C=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf
WP=(docker exec -u www-data -w /var/www/html etg-commerce-staging-php wp)
disable() { "${WP[@]}" eval 'update_option("mad4b_css_ai_cache_proof_v1",0,false);' --quiet >/dev/null 2>&1 || :; python3 "$Q/notification-bridge.py" / failed "Cache isolation safety gate $1" >/dev/null 2>&1 || :; echo "FAIL_CLOSED: $1" >&2; exit 12; }
python3 "$Q/policy-engine.py" status >/dev/null || disable wrong_staging_identity
grep -Fq '$http_x_mad4b_dynamic_css' "$C" || disable missing_context_cache_bypass
grep -Fq 'fastcgi_cache_bypass $company_perf_skip' "$C" || disable missing_fastcgi_cache_bypass
grep -Fq 'fastcgi_no_cache $company_perf_skip' "$C" || disable missing_fastcgi_no_cache
docker exec etg-commerce-staging-nginx nginx -t >/dev/null 2>&1 || disable invalid_nginx_config
HEADERS=$(curl -ksS -D - -o /dev/null --max-time 21 -H 'X-MAD4B-DYNAMIC-CSS: canary-v1' -H 'X-MAD4B-BYPASS-CACHE: 1' https://tours.dev.mad4b.com/) || disable canary_endpoint_error
printf '%s\n' "$HEADERS" | grep -iqE '^x-mad4b-preset-cache: BYPASS' || disable fastcgi_cache_not_bypassed
printf '%s\n' "$HEADERS" | grep -iqE '^cache-control: private, no-store' || disable cacheable_canary_response
"${WP[@]}" eval 'update_option("mad4b_css_ai_cache_proof_v1",time(),false);echo "cache_proof=".get_option("mad4b_css_ai_cache_proof_v1")."\n";' --quiet
