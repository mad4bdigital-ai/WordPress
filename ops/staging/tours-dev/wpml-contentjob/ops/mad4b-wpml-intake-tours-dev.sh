#!/usr/bin/env bash
set -euo pipefail
PATH=/usr/sbin:/usr/bin:/sbin:/bin
CONTAINER=etg-commerce-staging-php
OPERATOR=26
LOCK=/run/lock/mad4b-wpml-tours-dev.lock
exec 9>"$LOCK"
flock -n 9 || exit 0

# Fail closed if this VPS/container no longer belongs to the enrolled Staging site.
check=$(/usr/bin/docker exec -u www-data "$CONTAINER" wp eval '
  $scope=MAD4B_SCP_Operational_Integrity::capture();
  if(is_wp_error($scope))exit;
  $site=$scope["scope"]["site_uuid"]??"";
  $actor=wp_get_current_user();
  $correct=$site==="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
    && home_url()==="https://tours.dev.mad4b.com"
    && wp_get_environment_type()==="staging"
    && (int)get_current_user_id()===26
    && $actor->user_login==="mad4b_connection_cc0e557e5804"
    && true===MAD4B_SCP_Policy::can_mutate();
  echo $correct?"AUTHORIZED_STAGING_OPERATOR":"BLOCKED";
' --user="$OPERATOR" --path=/var/www/html --skip-themes 2>/dev/null)
if [[ "$check" != "AUTHORIZED_STAGING_OPERATOR" ]]; then
  echo "MAD4B WPML intake stopped: site or executor identity did not pass the live authority check" >&2
  exit 79
fi

/usr/bin/docker exec -u www-data "$CONTAINER" wp mad4b-wpml-jobs run   --limit=5 --user="$OPERATOR" --path=/var/www/html --skip-themes
