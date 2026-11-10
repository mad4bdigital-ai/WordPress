<?php
error_reporting(E_ALL);
define('ABSPATH','/tmp/');
define('WP_CLI',false);
class MAD4B_Central_VPS_Broker_V1 { const PUBKEY='original-broker-public'; }
class MAD4B_Broker_Exact_SSH_Authority_V1 {}
class MAD4B_Broker_Remote_SSH_Receipt_V1 {}
class MAD4B_Native_WPCLI_Receipt_Verifier_V1 {
    public static function supports_dynamic_v4() {
        return ($GLOBALS['original_v4']??true)===true;
    }
}
function get_option($name,$default=false) {
    if($name!==MAD4B_Central_VPS_Broker_V1::PUBKEY)return $default;
    return $GLOBALS['original_broker_public']??$default;
}
$cfg=require __DIR__.'/config.php';
$GLOBALS['original_broker_public']=$cfg['trusted']['independent_verifier_public_key_b64'];
$GLOBALS['original_v4']=true;
require __DIR__.'/class-mad4b-dynamic-313-wordpress.php';
$c='MAD4B_Dynamic_WP_313_Readback_V1';
$a=$c::status();
if($a['original_313_finalizer_connected']!==true ||
   $a['operational_ready']!==true ||
   $a['independent_verifier_ready']!==true) {
    echo json_encode(['phase'=>'valid','status'=>$a]);exit(2);
}
$GLOBALS['original_broker_public']='invalid';
$b=$c::status();
if($b['operational_ready']!==false) {
    echo 'UNPINNED_BROKER_ACCEPTED';exit(3);
}
$GLOBALS['original_broker_public']=$cfg['trusted']['independent_verifier_public_key_b64'];
$GLOBALS['original_v4']=false;
$d=$c::status();
if($d['operational_ready']!==false) {
    echo 'UNREGISTERED_V4_ACCEPTED';exit(4);
}
echo json_encode([
    'original_v4_bridge_live_required'=>true,
    'verified_broker_public_key_required'=>true,
    'dynamic_provider_version'=>$cfg['trusted']['provider']['version'],
    'evidence_schema'=>$cfg['trusted']['evidence_schema']['schema_id'],
    'verified_central_operational_ready'=>true,
    'no_grant_or_job_created'=>true
]);
