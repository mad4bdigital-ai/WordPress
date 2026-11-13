#!/bin/sh
# First-party source-only native publisher acceptance in an isolated PHP
# container. No live WordPress/Coolify container, permissions or source files
# are changed. All fixtures reside in an ephemeral tmpfs inside the sandbox.
set -eu
REPO=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
docker run --rm --network none --read-only \
  --tmpfs /tmp:rw,nosuid,nodev \
  --tmpfs /var/www/html:rw,nosuid,nodev,mode=0700 \
  -v "$REPO":/work:ro \
  --entrypoint sh mad4b-wordpress-php:8.5-redis6.3-source -ceu '
    mkdir -p /var/www/html/wp-content/plugins/mad4b-commerce/modules/presentation/assets
    mkdir -p /var/www/html/wp-content/plugins/mad4b-site-control-plane
    printf "/* offline fixture, not deployed */\n" \
      > /var/www/html/wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js
    cp /work/wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php \
      /var/www/html/wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php
    {
      cat /work/tests/native-source-central-review/bootstrap.php
      cat /work/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-source-release-review.php
      printf "\n?>\n"
      cat /work/tests/native-source-central-review/acceptance.php
    } | php
  '
