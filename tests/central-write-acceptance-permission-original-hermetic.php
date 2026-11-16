<?php
/** First-party Central Write Acceptance source proof only, without mutation/claims. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
function cwa_assert($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
class MAD4B_SCP_Site_Profile {
 static $overrides=[];
 static function status(){return array_replace([
  'site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'origin_match'=>true,'configured'=>true,'authority_ready'=>true,
  'deployment_binding_match'=>true,'same_origin_clone_protection'=>true,
  'environment_match'=>true,'mutation_pending_audit'=>false,
  'environment'=>'staging','configured_environment'=>'staging',
 ],self::$overrides);}
}
class MAD4B_SCP_Policy {
 static $can=true;
 static function can_mutate(){return self::$can;}
}
function current_user_can($cap){return $GLOBALS['can_manage'];}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-central-write-acceptance.php';
$c='MAD4B_SCP_Central_Write_Acceptance';
$GLOBALS['can_manage']=true;
cwa_assert($c::permitted()===true,'Exact authorized native WordPress admin and enrolled source remain eligible');
foreach([new WP_Error('permission_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['can_manage']=$bad;
 cwa_assert($c::permitted()===false,
  'Error-shaped manage_options result cannot become original CWA write permission');
}
$GLOBALS['can_manage']=true;
foreach(['origin_match','configured','authority_ready','deployment_binding_match',
         'same_origin_clone_protection','environment_match'] as $field){
 foreach([new WP_Error('site_flag_unknown'),1,'true',null,false] as $bad){
  MAD4B_SCP_Site_Profile::$overrides=[$field=>$bad];
  cwa_assert($c::site_scope()===[] &&$c::permitted()===false,
   'Unverified original Site Profile source cannot admit CWA durable write');
 }
}
MAD4B_SCP_Site_Profile::$overrides=[];
foreach([new WP_Error('policy_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Policy::$can=$bad;
 cwa_assert($c::permitted()===false,
  'Only strict true from registered original native mutation policy');
}
echo 'PASS: CENTRAL_WRITE_ACCEPTANCE_PERMISSION_ORIGINAL '.($GLOBALS['checks']??0).
 ' original Site/WordPress actor preflight checks'.PHP_EOL;
