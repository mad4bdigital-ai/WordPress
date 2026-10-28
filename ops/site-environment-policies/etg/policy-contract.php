<?php
/** Hermetic contract: no WordPress bootstrap, no runtime HTTP/cron/mail. */
define('ABSPATH','/virtual/');
$env=getenv('TEST_ENV') ?: 'staging';
define('SHARED_PRODUCT_LAB_ONLY', $env !== 'production');
function wp_get_environment_type(){return getenv('TEST_ENV');}
$GLOBALS['hooks']=[];
function add_filter($name,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$name][]=[$priority,$fn];return true;}
require __DIR__.'/000-sandbox-guard.php';
require __DIR__.'/woo-lab-isolation.php';
require __DIR__.'/005-mad4b-staging-mail-admission.php';
$counts=[];foreach(['pre_http_request','pre_wp_mail','cron_request','woocommerce_is_purchasable','woocommerce_available_payment_gateways'] as $key)$counts[$key]=count($GLOBALS['hooks'][$key]??[]);
if($env==='staging')$expected=['pre_http_request'=>0,'pre_wp_mail'=>1,'cron_request'=>0,'woocommerce_is_purchasable'=>1,'woocommerce_available_payment_gateways'=>1];
elseif($env==='production')$expected=['pre_http_request'=>0,'pre_wp_mail'=>0,'cron_request'=>0,'woocommerce_is_purchasable'=>0,'woocommerce_available_payment_gateways'=>0];
else $expected=['pre_http_request'=>1,'pre_wp_mail'=>2,'cron_request'=>2,'woocommerce_is_purchasable'=>1,'woocommerce_available_payment_gateways'=>1];
if($counts!==$expected){fwrite(STDERR,'FAILED '.json_encode(['environment'=>$env,'actual'=>$counts,'expected'=>$expected])."\n");exit(7);}
echo json_encode(['status'=>'PASS','environment'=>$env,'hooks'=>$counts],JSON_UNESCAPED_SLASHES)."\n";
