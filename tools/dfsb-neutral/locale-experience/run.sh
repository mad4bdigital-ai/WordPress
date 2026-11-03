#!/usr/bin/env bash
# Configured company-site runner. Defaults grant no environment or WP authority.
set -Eeuo pipefail
: "${MAD4B_WP_CONTAINER:?must configure original WordPress container}"
: "${MAD4B_SITE_BASE:?must configure exact site URL}"
: "${MAD4B_SITE_UUID:?must configure current Site Profile identity}"
: "${MAD4B_SITE_ENVIRONMENT:?must configure original WordPress environment}"
: "${MAD4B_WP_OPERATOR:?must configure authorized original WordPress user}"
: "${MAD4B_ACTIVE_LANGUAGES:?must configure current WPML languages}"
: "${MAD4B_DEFAULT_LANGUAGE:?must configure WPML default language}"
: "${MAD4B_ARCHIVE_PATH:?must configure site-specific archive path}"
case "$MAD4B_WP_CONTAINER" in
  *[!a-zA-Z0-9_.-]*|'') echo 'invalid container identifier';exit 5;;
esac
case "$MAD4B_WP_OPERATOR" in
  *[!0-9]*|'') echo 'invalid WordPress principal';exit 5;;
esac
case "$MAD4B_SITE_ENVIRONMENT" in
  production|staging|development|local) ;;
  *) echo 'invalid WordPress environment';exit 5;;
esac
exec 9>"/run/lock/mad4b-locale-experience-${MAD4B_WP_CONTAINER}.lock"
/usr/bin/flock -n 9 || { echo 'SKIP_OVERLAPPING_LOCALE_SCAN'; exit 0; }
wp=(/usr/bin/docker exec
  -e MAD4B_SITE_UUID
  -e MAD4B_SITE_BASE
  -e MAD4B_SITE_ENVIRONMENT
  --workdir /var/www/html "$MAD4B_WP_CONTAINER" wp)
proof="$("${wp[@]}" eval '
  $site=getenv("MAD4B_SITE_UUID");
  $url=rtrim((string)getenv("MAD4B_SITE_BASE"),"/");
  $environment=getenv("MAD4B_SITE_ENVIRONMENT");
  $ok=(wp_get_environment_type()===$environment
       && rtrim(home_url(),"/")===$url
       && MAD4B_SCP_Site_Profile::site_uuid()===$site
       && current_user_can("manage_options")
       && MAD4B_SCP_Policy::can_mutate());
  echo $ok?"EXACT_SITE_AND_OWNER":"DENIED";
' --allow-root --user="$MAD4B_WP_OPERATOR")"
[ "$proof" = 'EXACT_SITE_AND_OWNER' ] || { echo 'DENIED_SITE_PROFILE_OR_OPERATOR'; exit 5; }
tmp="$(/usr/bin/mktemp /tmp/mad4b-locale-observation.XXXXXXXX.json)"
trap 'rm -f "$tmp"' EXIT
export MAD4B_OBSERVATION_OUT="$tmp"
/usr/bin/timeout --signal=TERM --kill-after=10s 300s /usr/bin/python3 /opt/mad4b/locale-experience/collector.py
/usr/bin/docker cp "$tmp" "$MAD4B_WP_CONTAINER":/tmp/mad4b-locale-browser-current.json
"${wp[@]}" eval '
  $data=json_decode((string)file_get_contents("/tmp/mad4b-locale-browser-current.json"),true);
  $result=MAD4B_SCP_Locale_Experience_Alerts::import_observations($data);
  if(is_wp_error($result)||empty($result["readback_verified"]))
    throw new RuntimeException(is_wp_error($result)?$result->get_error_code():"browser_readback_unverified");
  $scan=MAD4B_SCP_Content_Readiness_Alerts::discover();
  if(empty($scan["complete"]))throw new RuntimeException("original_readiness_scan_incomplete");
  $ledger=MAD4B_SCP_Content_Readiness_Alerts::reconcile($scan);
  if(is_wp_error($ledger))throw new RuntimeException($ledger->get_error_code());
  echo wp_json_encode(["contract"=>"mad4b.locale-experience-scheduled.v1",
      "source_evidence_sha256"=>$result["sha256"],"screens"=>$result["screens"],
      "priority_alert_count"=>count(MAD4B_SCP_Locale_Experience_Alerts::discover()["issues"]),
      "only_intake_requests"=>true,"publication_authorized"=>false]),PHP_EOL;
' --allow-root --user="$MAD4B_WP_OPERATOR"

# Opt-in, company-scoped original ContentJob intake. Never grants publication.
# Existing WordPress original actor and Site Profile proof remain mandatory.
if [ "${MAD4B_LOCALE_NATIVE_INTAKE_ENABLED:-0}" = '1' ]; then
  source_dir="$(cd -- "$(dirname -- "$0")" && pwd -P)"
  native="$source_dir/native-intake.php"
  [ -f "$native" ] && [ ! -L "$native" ] || {
    echo 'DENIED_MISSING_OR_SYMLINK_NATIVE_INTAKE_SOURCE'; exit 5;
  }
  export MAD4B_LOCALE_NATIVE_INTAKE_ENABLED=1
  /usr/bin/docker cp "$native" "$MAD4B_WP_CONTAINER":/tmp/mad4b-locale-native-intake.php
  /usr/bin/docker exec -e MAD4B_SITE_UUID -e MAD4B_SITE_BASE \
    -e MAD4B_SITE_ENVIRONMENT -e MAD4B_LOCALE_NATIVE_INTAKE_ENABLED \
    --workdir /var/www/html "$MAD4B_WP_CONTAINER" wp eval \
    'define("MAD4B_LOCALE_NATIVE_INTAKE_EXECUTE",true);require "/tmp/mad4b-locale-native-intake.php";' \
    --allow-root --user="$MAD4B_WP_OPERATOR"
fi
