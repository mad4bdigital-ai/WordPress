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

# Refresh public front-end evidence dynamically; never infer invisibility
# from a failed crawl. A verified snapshot survives refresh failures.
EVIDENCE_DIR=/opt/staging/mad4b-wpml-content-jobs-bridge-20261102/priority
EVIDENCE_FILE="$EVIDENCE_DIR/frontend-visibility.json"
CRAWLER="$EVIDENCE_DIR/live-frontend-probe.py"
age=999999
if [[ -f "$EVIDENCE_FILE" ]]; then
  mtime=$(/usr/bin/stat -c %Y "$EVIDENCE_FILE")
  age=$(( $(/usr/bin/date +%s) - mtime ))
fi
forced=$(/usr/bin/printenv MAD4B_FORCE_PRIORITY_REFRESH 2>/dev/null || true)
if [[ "$forced" == "yes" || "$age" -ge 1200 ]]; then
  if /usr/bin/python3 "$CRAWLER" \
    && /usr/bin/docker cp "$EVIDENCE_FILE" "$CONTAINER:/tmp/mad4b-wpml-frontend-visibility.json" \
    && /usr/bin/docker exec -u www-data "$CONTAINER" wp mad4b-wpml-priority import --user="$OPERATOR" --path=/var/www/html --skip-themes; then
    echo "MAD4B WPML Priority: accepted fresh public HTML evidence"
  else
    echo "MAD4B WPML Priority: refresh failed; retaining last verified snapshot" >&2
  fi
fi

/usr/bin/docker exec -u www-data "$CONTAINER" wp mad4b-wpml-jobs run   --limit=5 --user="$OPERATOR" --path=/var/www/html --skip-themes

# Receipt reconciliation runs only under the same authorized service identity.
# Never completes a job or publishes content without independently verified evidence.
/usr/bin/docker exec -u www-data "$CONTAINER" wp mad4b-wpml-jobs reconcile --limit=12 --user="$OPERATOR" --path=/var/www/html --skip-themes
