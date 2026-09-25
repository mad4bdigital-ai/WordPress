#!/usr/bin/env bash
set -euo pipefail

ROOT="${GITHUB_WORKSPACE:-$(pwd)}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cat >"$TMP/create.php" <<'PHP'
<?php
$root = getenv('MAD4B_REPO_ROOT');
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-schema.php';
if (!class_exists('MAD4B_SCP_Site_Profile')) {
  final class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
  }
}
if (!class_exists('MAD4B_SCP_Audit')) {
  final class MAD4B_SCP_Audit {
    public static function record($ability,$summary,$status,$mutation){ return array('recorded'=>true); }
    public static function transaction_committed(){}
    public static function transaction_rolled_back(){}
  }
}
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php';
$r = MAD4B_SCP_Content_Jobs::create_job(array(
  'brand_id'=>'brand-concurrency',
  'subject'=>'Concurrent transition fixture',
  'primary_keyword'=>'',
  'language'=>'en',
  'country'=>'eg',
  'content_type'=>'article',
  'writer_profile_id'=>'',
  'writer_profile_version'=>'',
  'research_depth'=>'standard',
  'automation_level'=>'review_gated',
  'target_post_type'=>'post',
  'desired_publish_at'=>'',
  'reason'=>'create concurrency fixture',
));
if (is_wp_error($r)) { fwrite(STDERR, $r->get_error_code()); exit(2); }
echo $r['job']['job_id'];
PHP

cat >"$TMP/transition.php" <<'PHP'
<?php
$root = getenv('MAD4B_REPO_ROOT');
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-schema.php';
if (!class_exists('MAD4B_SCP_Site_Profile')) {
  final class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
  }
}
if (!class_exists('MAD4B_SCP_Audit')) {
  final class MAD4B_SCP_Audit {
    public static function record($ability,$summary,$status,$mutation){ return array('recorded'=>true); }
    public static function transaction_committed(){}
    public static function transaction_rolled_back(){}
  }
}
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php';
$job = getenv('MAD4B_JOB_ID');
$state = getenv('MAD4B_TARGET_STATE');
$r = MAD4B_SCP_Content_Jobs::transition_job(array(
  'job_id'=>$job,
  'expected_revision'=>1,
  'state'=>$state,
  'stage'=>'INTAKE',
  'reason'=>'concurrent competing transition',
  'plan_sha256'=>'',
  'artifact_id'=>'',
));
if (is_wp_error($r)) {
  echo 'ERROR:' . $r->get_error_code();
  exit(0);
}
echo 'SUCCESS:' . $r['job']['state'] . ':' . $r['job']['job_revision'];
PHP

cat >"$TMP/verify.php" <<'PHP'
<?php
$root = getenv('MAD4B_REPO_ROOT');
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-schema.php';
if (!class_exists('MAD4B_SCP_Site_Profile')) {
  final class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
  }
}
if (!class_exists('MAD4B_SCP_Audit')) {
  final class MAD4B_SCP_Audit {
    public static function record($ability,$summary,$status,$mutation){ return array('recorded'=>true); }
    public static function transaction_committed(){}
    public static function transaction_rolled_back(){}
  }
}
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php';
$job = getenv('MAD4B_JOB_ID');
$current = MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job));
$events = MAD4B_SCP_Content_Jobs::get_events(array('job_id'=>$job,'limit'=>100));
if (is_wp_error($current) || is_wp_error($events)) { exit(2); }
echo wp_json_encode(array(
  'state'=>$current['job']['state'],
  'revision'=>(int)$current['job']['job_revision'],
  'event_count'=>(int)$events['count'],
  'sequences'=>array_map(static fn($e)=>(int)$e['sequence'], $events['items']),
));
PHP

export MAD4B_REPO_ROOT="$ROOT"
JOB_ID="$(wp --path="$ROOT" --allow-root --skip-plugins --skip-themes eval-file "$TMP/create.php")"
if [[ ! "$JOB_ID" =~ ^[a-f0-9-]{36}$ ]]; then
  echo "invalid job id: $JOB_ID" >&2
  exit 1
fi
export MAD4B_JOB_ID="$JOB_ID"

(
  export MAD4B_TARGET_STATE="QUEUED"
  wp --path="$ROOT" --allow-root --skip-plugins --skip-themes eval-file "$TMP/transition.php" >"$TMP/a.out" 2>"$TMP/a.err"
) &
PID_A=$!
(
  export MAD4B_TARGET_STATE="CANCELLED"
  wp --path="$ROOT" --allow-root --skip-plugins --skip-themes eval-file "$TMP/transition.php" >"$TMP/b.out" 2>"$TMP/b.err"
) &
PID_B=$!

wait "$PID_A"
wait "$PID_B"

A="$(cat "$TMP/a.out")"
B="$(cat "$TMP/b.out")"
SUCCESS_COUNT=0
CONFLICT_COUNT=0
for VALUE in "$A" "$B"; do
  [[ "$VALUE" == SUCCESS:* ]] && SUCCESS_COUNT=$((SUCCESS_COUNT+1))
  [[ "$VALUE" == "ERROR:mad4b_content_job_revision_conflict" ]] && CONFLICT_COUNT=$((CONFLICT_COUNT+1))
done

if [[ "$SUCCESS_COUNT" -ne 1 || "$CONFLICT_COUNT" -ne 1 ]]; then
  echo "expected one success and one revision conflict" >&2
  echo "A=$A" >&2
  echo "B=$B" >&2
  echo "A.err=$(cat "$TMP/a.err")" >&2
  echo "B.err=$(cat "$TMP/b.err")" >&2
  exit 1
fi

VERIFY="$(wp --path="$ROOT" --allow-root --skip-plugins --skip-themes eval-file "$TMP/verify.php")"
python3 - "$VERIFY" <<'PY'
import json, sys
row=json.loads(sys.argv[1])
assert row["revision"] == 2, row
assert row["event_count"] == 2, row
assert row["sequences"] == [1,2], row
assert row["state"] in {"QUEUED","CANCELLED"}, row
PY

echo "mad4b.content-job.concurrent-cas.v1: PASS"
