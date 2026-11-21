#!/usr/bin/env bash
set -euo pipefail
exec 9>/run/lock/mad4b-css-rum-install-wayfa.lock
flock -n 9 || { echo WAYFA_RUM_ANOTHER_INSTALLER_ACTIVE;exit 75; }
exec 8>/run/lock/mad4b-css-wpcron-worker.lock
flock -n 8 || { echo WAYFA_RUM_CSS_WORKER_ACTIVE;exit 75; }
python3 /opt/wayfa-rebuild-20261102/operations/css-cron-v1/renderer/public-route-cpu-guard.py || exit 75
nice -n 15 timeout -k 3s 40s docker exec php-guxhp9y6vgh7oouuw8plxrlr wp --allow-root --skip-themes eval '
 $host=(string)wp_parse_url(home_url("/"),PHP_URL_HOST);
 if($host!=="wayfatours.com"||wp_get_environment_type()!=="production")throw new Exception("WAYFA_PRODUCTION_ORIGIN_MISMATCH");
 $c="MAD4B_Adaptive_CSS_RUM_V1";
 if(!class_exists($c,false)||$c::site()!=="wayfa")throw new Exception("SIGNED_COMPANY_SITE_PROFILE_MISSING");
 $p=get_option($c::OPTION,false);
 if($p!==false&&$p!==["mode"=>"off","sample_bps"=>0]&&
    $p!==["mode"=>"observe","sample_bps"=>100])throw new Exception("POLICY_ALREADY_MODIFIED");
 if(!$c::install_schema())throw new Exception("RUM_SCHEMA_CREATE_DENIED");
 global $wpdb;$table=$c::table();
 if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table)throw new Exception("RUM_SCHEMA_READBACK_FAILED");
 if($p!==["mode"=>"observe","sample_bps"=>100])
   update_option($c::OPTION,["mode"=>"observe","sample_bps"=>100],false);
 if($c::policy()!==["mode"=>"observe","sample_bps"=>100])throw new Exception("POLICY_NOT_PERSISTED");
 echo wp_json_encode(["contract"=>"mad4b.adaptive-css.rum.activation.v1",
    "site"=>$c::site(),"mode"=>"observe","sample_bps"=>100,
    "schema_present"=>true,"table"=>$table,
    "css_certificates_changed"=>false],JSON_UNESCAPED_SLASHES),"\n";
'
