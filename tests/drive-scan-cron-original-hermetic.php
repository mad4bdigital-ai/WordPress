<?php
/** Original first-party Drive Scan Cron admission only; no Google requests or live options. */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
class WP_Error {private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_user_by($field,$id){return (object)['ID'=>$id];}
function wp_set_current_user($id){$GLOBALS['selected_user']=$id;}
function current_user_can($cap){return $GLOBALS['can_manage'];}
function get_option($name,$default=[]){
 if($name==='mad4b_drive_scan_cron_policy_v1'){$GLOBALS['policy_reads']++;return [];}
 return $default;
}
function update_option($name,$value,$autoload=false){
 $GLOBALS['reports'][]=$value;return true;
}
function drive_assert($ok,$why){
 if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Brand_Context_Builder {
 static $calls=0;
 static function source_scan_plan($input){self::$calls++;return new WP_Error('scan_must_not_execute');}
}
class MAD4B_SCP_Site_Profile {
 static $overrides=[];
 static function status(){return array_replace([
  'site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'configured'=>true,'authority_ready'=>true,
  'origin_match'=>true,'environment_match'=>true,
  'deployment_binding_match'=>true,'configured_environment'=>'staging',
  'environment'=>'staging'
 ],self::$overrides);}
}
class MAD4B_SCP_Context_Authority {
 static function source($id){return [
  'site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'provider'=>'google_drive','mode'=>'governed'];}
 static function ai_review_policy_status(){return ['mode'=>'human_only','ready'=>false];}
 static function assets(){return [];}
}
putenv('MAD4B_SCAN_SITE_UUID=11111111-1111-4111-8111-111111111111');
putenv('MAD4B_SCAN_SOURCE_ID='.str_repeat('a',64));
putenv('MAD4B_SCAN_ACTOR_ID=17');
$GLOBALS['can_manage']=true;
$GLOBALS['policy_reads']=0;
$GLOBALS['reports']=[];
ob_start();
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-drive-scan-cron.php';
ob_end_clean();
$c='MAD4B_SCP_Drive_Scan_Cron_V1';
drive_assert(end($GLOBALS['reports'])['state']==='disabled'
 &&$GLOBALS['policy_reads']===1
 &&MAD4B_SCP_Brand_Context_Builder::$calls===0,
 'Verified site with absent scheduler policy remains disabled and cannot scan');
foreach([new WP_Error('cap_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['can_manage']=$bad;
 $GLOBALS['policy_reads']=0;
 ob_start();$c::run();ob_end_clean();
 drive_assert(end($GLOBALS['reports'])['error_code']==='scheduler_operator_denied'
  &&$GLOBALS['policy_reads']===0,
  'Unknown WordPress operator proof cannot read scheduled inventory policy');
}
$GLOBALS['can_manage']=true;
foreach(['configured','authority_ready','origin_match','environment_match','deployment_binding_match'] as $flag){
 foreach([new WP_Error('site_unknown'),1,'true',null,false] as $bad){
  MAD4B_SCP_Site_Profile::$overrides=[$flag=>$bad];
  $GLOBALS['policy_reads']=0;
  ob_start();$c::run();ob_end_clean();
  drive_assert(end($GLOBALS['reports'])['error_code']==='scheduler_site_or_source_drift'
   &&$GLOBALS['policy_reads']===0,
   'Unverified original site enrollment cannot proceed to policy/Google Scan');
 }
}
MAD4B_SCP_Site_Profile::$overrides=[];
echo 'PASS: DRIVE_SCAN_CRON_ORIGINAL '.($GLOBALS['checks']??0).
 ' source site/actor read-only cron and Google scan denial cases'.PHP_EOL;
