#!/usr/bin/env bash
set -euo pipefail
ROOT=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/operations/css-cron-v1
exec 9>/run/lock/mad4b-css-rum-install-source.lock
flock -n 9 || { echo 'RUM_SCHEMA_ANOTHER_INSTALLER_ACTIVE';exit 75; }
exec 8>/run/lock/mad4b-css-wpcron-worker.lock
flock -n 8 || { echo 'RUM_SCHEMA_HOST_CSS_WORKER_ACTIVE';exit 75; }
python3 "$ROOT/renderer/public-route-cpu-guard.py" || exit 75
echo 'SOURCE_OBSERVE_WPCLI_ADMITTED'
nice -n 15 timeout -k 3s 36s docker exec php-wkgtkzta35wkkgouwt6ruljc wp --allow-root --skip-themes eval '
 $host=(string)wp_parse_url(home_url("/"),PHP_URL_HOST);
 if($host!=="tours.dev.mad4b.com"||wp_get_environment_type()!=="staging")throw new Exception("SOURCE_ORIGIN_MISMATCH");
 $c="MAD4B_Adaptive_CSS_RUM_V1";
 if(!class_exists($c,false)||$c::site()!=="source")throw new Exception("REGISTERED_SITE_PROFILE_MISSING");
 $p=get_option($c::OPTION,false);
 if($p!==false&&$p!==["mode"=>"off","sample_bps"=>0]&&
    $p!==["mode"=>"observe","sample_bps"=>100])throw new Exception("EXISTING_POLICY_CONFLICT");
 if(!$c::install_schema())throw new Exception("SCHEMA_CREATE_DENIED");
 global $wpdb;
 $table=$c::table();
 if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table)throw new Exception("TABLE_NOT_READABLE");
 if($p!==["mode"=>"observe","sample_bps"=>100])
  update_option($c::OPTION,["mode"=>"observe","sample_bps"=>100],false);
 if($c::policy()!==["mode"=>"observe","sample_bps"=>100])throw new Exception("READBACK_MISMATCH");
 echo wp_json_encode(["site"=>$c::site(),"mode"=>"observe",
 "sample_bps"=>100,"table"=>$table,"schema_present"=>true,
 "css_certificates_modified"=>false],JSON_UNESCAPED_SLASHES),"\n";
'
