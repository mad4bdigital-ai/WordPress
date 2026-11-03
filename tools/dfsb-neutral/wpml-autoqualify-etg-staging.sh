#!/bin/sh
# Exact ETG Staging only. The MU runtime checks the site UUID,
# environment, approved operator and publication policy before any write.
set -eu
command -v docker >/dev/null
command -v flock >/dev/null
exec /usr/bin/flock -n /run/lock/mad4b-wpml-autoqualify-etg-staging.lock \
  /usr/bin/docker exec -u www-data etg-commerce-staging-php \
  wp mad4b wpml-auto-qualify run \
  --user=26 --path=/var/www/html --skip-themes
