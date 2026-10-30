#!/usr/bin/env bash
# MAD4B Control MCP -> Content Hub: governed LOCAL fallback (Staging only).
# Caller: explicit, authorized root-side VPS operation. No cron or OAuth impersonation.
set -euo pipefail
umask 077
MODE="${1:---status}"
case "$MODE" in --status|--dry-run|--run) ;; *) echo 'Usage: --status | --dry-run | --run [--request-key KEY]' >&2;exit 79;; esac
STAGING=etg-commerce-staging-php
WORK=/opt/mad4b-company-release-prep/20261030/tourism-cross-ssh-runs
RUNNER=/opt/mad4b/operations/content-hub/cross-ssh/run-tourism-cross-ssh.sh
if test "$(id -u)" != 0;then echo 'Authorized VPS root required' >&2;exit 80;fi
test -x "$RUNNER" || { echo 'Pinned host runner unavailable' >&2;exit 81; }
if test "$MODE" = --run;then
  if test "$#" = 3 && test "$2" = --request-key;then
    KEY="$3"
  elif test "$#" = 1;then
    KEY="vps-fallback-$(python3 -c 'import uuid;print(uuid.uuid4())')"
  else
    echo 'Invalid arguments' >&2;exit 79
  fi
  [[ "$KEY" =~ ^[a-zA-Z0-9_.-]{8,80}$ ]] || { echo 'Invalid idempotency key' >&2;exit 79; }
  mkdir -p "$WORK";chmod 700 "$WORK"
  exec 8>/run/lock/mad4b-hub-source-fallback.lock
  flock -n 8 || { echo 'Fallback dispatcher already running' >&2;exit 82; }
else
  test "$#" = 1 || { echo 'Invalid arguments' >&2;exit 79; }
fi

# Read-only policy/health probe; the PHP class guarantees exact site/tenant.
STATUS=$(docker exec -u www-data "$STAGING" wp --path=/var/www/html --skip-themes eval '
if(home_url("/")!=="https://tours.dev.mad4b.com/" ||
   wp_get_environment_type()!=="staging" ||
   !class_exists("MAD4B_Hub_Source_Local_Fallback_V1"))exit(91);
echo wp_json_encode(MAD4B_Hub_Source_Local_Fallback_V1::status(),JSON_UNESCAPED_SLASHES);
' 2>/dev/null)
printf '%s' "$STATUS" | python3 -c 'import sys,json;s=json.load(sys.stdin);assert s["contract"]=="mad4b.hub.source-fallback.v1";assert s["site"]=="https://tours.dev.mad4b.com/";assert not s["production_allowed"] and not s["publishing_enabled"] and not s["fallback_on_authorization_denial"];print("FALLBACK_POLICY="+s["policy"]);print("PREFERRED_EXECUTION="+s["preferred_execution"]);print("FALLBACK_ELIGIBLE="+str(s["fallback_eligible"]).lower())'
if test "$MODE" = --status;then exit 0;fi
printf '%s' "$STATUS" | python3 -c 'import sys,json;s=json.load(sys.stdin);assert s["default_fallback_enabled"] and s["preferred_execution"]=="vps_wpcli_fallback" and s["fallback_eligible"], "Native MCP preferred, disabled, or source not ready"' || exit 83
if test "$MODE" = --dry-run;then echo FALLBACK_DRY_RUN=ACCEPTED_NO_WRITE;exit 0;fi
REQUEST=$(docker exec -u www-data -e MAD4B_SOURCE_FALLBACK_KEY="$KEY" "$STAGING" wp --path=/var/www/html --skip-themes eval '
if(home_url("/")!=="https://tours.dev.mad4b.com/")exit(90);
$ids=get_users(["role"=>"administrator","number"=>50,"fields"=>"ID"]);
$enrolled=[];foreach($ids as $id)if(MAD4B_SCP_Site_Profile::user_is_enrolled((int)$id))$enrolled[]=(int)$id;
if(count($enrolled)!==1)exit(91);
wp_set_current_user($enrolled[0]);
if(!current_user_can("manage_options"))exit(92);
define("MAD4B_HUB_SOURCE_HOST_RUNNER",true);
$result=MAD4B_Hub_Source_Local_Fallback_V1::request_from_host([
 "request_key"=>(string)getenv("MAD4B_SOURCE_FALLBACK_KEY"),
 "reason"=>"external_client_unbound"
]);
if(is_wp_error($result)){echo "FALLBACK_ERROR:".$result->get_error_code();exit(93);}
echo wp_json_encode($result,JSON_UNESCAPED_SLASHES);
' 2>/dev/null)
JOB=$(printf '%s' "$REQUEST" | python3 -c 'import sys,json,uuid;r=json.load(sys.stdin);j=r["job_id"];assert str(uuid.UUID(j))==j and r["status"]=="queued" and r["path"]=="vps_wpcli_fallback";print(j)')
echo "QUEUED_FALLBACK_JOB=$JOB"
# This worker ONLY claims a pre-authorized, registered Company source job.
bash "$RUNNER" --run-queued
FINAL=$(docker exec -u www-data -e MAD4B_HUB_JOB_ID="$JOB" "$STAGING" wp --path=/var/www/html --skip-themes eval '
$id=(string)getenv("MAD4B_HUB_JOB_ID");
$jobs=MAD4B_Hub_Source_Sync_Jobs_V1::status(["job_id"=>$id])["jobs"];
$job=$jobs?reset($jobs):[];
$receipt=get_option(MAD4B_Hub_Source_Import_V1::RECEIPT,[]);
$ledger=get_option(MAD4B_Hub_Source_Providers_V1::LEDGER,[]);
$last=is_array($ledger)&&$ledger?end($ledger):[];
if(($job["status"]??"")!=="completed" ||
   ($job["snapshot_sha256"]??"")!==($receipt["snapshot_sha256"]??null) ||
   ($job["snapshot_sha256"]??"")!==($last["snapshot_sha256"]??null) ||
   (int)($job["imported"]??-1)!==(int)($receipt["imported"]??-2) ||
   MAD4B_Hub_Auto_Distribute_V1::settings()["enabled"])exit(96);
echo wp_json_encode(["job_id"=>$id,"status"=>$job["status"],
 "imported"=>$job["imported"],"created"=>$job["created"],"updated"=>$job["updated"],
 "receipt_matches_ledger"=>true,"auto_distribute"=>false],JSON_UNESCAPED_SLASHES);
' 2>/dev/null)
printf '%s' "$FINAL" | python3 -c 'import json,sys;s=json.load(sys.stdin);assert s["status"]=="completed" and s["receipt_matches_ledger"] and not s["auto_distribute"];print("FALLBACK_VERIFIED="+json.dumps(s,sort_keys=True))'
