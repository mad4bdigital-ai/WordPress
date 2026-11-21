#!/usr/bin/env bash
set -euo pipefail
exec 9>/run/lock/mad4b-css-rum-production-e2e.lock
flock -n 9 || { echo DEFER_EXISTING_RUM_E2E;exit 75; }
exec 8>/run/lock/mad4b-css-wpcron-worker.lock
flock -n 8 || { echo DEFER_CSS_WORKER_ACTIVE;exit 75; }
python3 /opt/wayfa-rebuild-20261102/operations/css-cron-v1/renderer/public-route-cpu-guard.py || exit 75
nice -n 15 timeout -k 4s 41s docker exec php-guxhp9y6vgh7oouuw8plxrlr wp --allow-root --skip-themes eval '
 $host=(string)wp_parse_url(home_url("/"),PHP_URL_HOST);
 $c="MAD4B_Adaptive_CSS_RUM_V1";
 if($host!=="wayfatours.com"||wp_get_environment_type()!=="production"
    ||$c::site()!=="wayfa"||$c::policy()!==["mode"=>"observe","sample_bps"=>100])
   throw new Exception("WAYFA_RUM_SOURCE_OR_POLICY_MISMATCH");
 $epoch=(int)get_option("mad4b_css_ai_epoch_v1",0);
 $listing=(int)get_option("mad4b_ccss_listing_generation_v1",0);
 $global=(int)get_option("mad4b_ccss_global_generation_v1",0);
 $route=str_repeat("f",64);$variant=str_repeat("e",64);
 $token=$c::signed_proof(["wayfa",$route,$variant,"mobile",
    (string)$epoch,(string)$listing,(string)$global],time());
 global $wpdb;$table=$c::table();
 $before=(int)$wpdb->get_var($wpdb->prepare(
  "SELECT COALESCE(SUM(samples),0) FROM $table WHERE route_sha=%s AND variant_sha=%s",$route,$variant));
 if($before!==0)throw new Exception("QATEST_FIXTURE_COLLISION");
 try {
  $payload=["contract"=>$c::CONTRACT,"proof"=>$token,
   "arm"=>"optimized","metrics"=>["lcp"=>2200,"inp"=>150,"cls"=>40],
   "cssRestored"=>true];
  $res=wp_remote_post(rest_url("mad4b-css/v1/rum"),[
   "timeout"=>17,"redirection"=>0,"sslverify"=>true,
   "headers"=>["Content-Type"=>"application/json","Origin"=>"https://wayfatours.com",
      "Sec-Fetch-Site"=>"same-origin"],
   "body"=>wp_json_encode($payload,JSON_UNESCAPED_SLASHES)]);
  if(is_wp_error($res))throw new Exception("REST_ERROR:".$res->get_error_code());
  $status=(int)wp_remote_retrieve_response_code($res);
  $stored=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COALESCE(SUM(samples),0) FROM $table WHERE route_sha=%s AND variant_sha=%s",$route,$variant));
  if($status!==202||$stored!==4)
    throw new Exception("SOURCE_FRESH_V2_INGESTION_FAIL_".$status."_".$stored);
  echo wp_json_encode(["contract"=>"mad4b.adaptive-css.rum.v2.e2e",
  "site"=>"wayfa","http"=>202,"aggregated_rows"=>4,
  "synthetic_deleted"=>true,"source_gen_verified"=>true,
  "no_public_css_changes"=>true],JSON_UNESCAPED_SLASHES),"\n";
 } finally {
  $wpdb->delete($table,["site_id"=>"wayfa","route_sha"=>$route,"variant_sha"=>$variant],["%s","%s","%s"]);
  $after=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(samples),0) FROM $table WHERE route_sha=%s AND variant_sha=%s",$route,$variant));
  if($after!==0)throw new Exception("SYNTHETIC_DATA_CLEANUP_FAILURE");
 }
'
