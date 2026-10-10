<?php
/** Offline governed consent executor simulation. No WordPress site or secrets. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code;
 public function __construct($code,$message=''){ $this->code=$code; }
 public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function current_user_can($cap){return in_array($cap,array('manage_options','update_plugins'),true);}
function get_current_user_id(){return 4;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000000004';}
$GLOBALS['test_options']=array();
function get_option($key,$default=false){return $GLOBALS['test_options'][$key]??$default;}
function add_option($key,$value,$deprecated='',$autoload=false) {
 if(array_key_exists($key,$GLOBALS['test_options']))return false;
 $GLOBALS['test_options'][$key]=$value;return true;
}
function update_option($key,$value,$autoload=false){$GLOBALS['test_options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['test_options'][$key]);return true;}
class MAD4B_SCP_Site_Profile {
 public static $site=array();
 public static function status(){return self::$site;}
 public static function user_is_enrolled($user){return $user===4;}
}
class MAD4B_SCP_Policy {public static function can_mutate(){return true;}}
class MAD4B_SCP_OAuth_Resource_Bridge {
 const AUTHORITY_STEP_UP_SCOPE='mad4b:authority:step-up';
 public static $authorized=true;
 public static function verified_bearer_active(){return self::$authorized;}
 public static function verified_bearer_has_scope($scope){return self::$authorized&&$scope===self::AUTHORITY_STEP_UP_SCOPE;}
 public static function verified_bearer_client_is($client){return self::$authorized&&$client===MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID;}
}
class MAD4B_SCP_Local_OAuth_Server {const CHATGPT_CIMD_CLIENT_ID='chatgpt-client';}
class MAD4B_SCP_Authorization {
 public static $authorized=true;
 public static function authorize_mutation($name,$category,$provider,$input){return self::$authorized?true:new WP_Error('not_authorized');}
}
class MAD4B_SCP_Audit {
 public static $fail=false;
 public static $events=array();
 public static function record($name,$data,$status){
  if(self::$fail)return new WP_Error('audit_failed');
  self::$events[]=array($name,$data,$status);return true;
 }
}
class MAD4B_SCP_WordPress_Native_Opt_In {
 const OPTION='mad4b_scp_wp_native_candidate_opt_in_v1';
 public static function site_policy(array $s){
  $ok=($s['environment']??'')==='staging'&&($s['configured_environment']??'')==='staging'
   &&($s['wordpress_environment']??'')==='staging'&&($s['canonical_origin']??'')===($s['current_origin']??'')
   &&!empty($s['authority_ready']);
  return array('eligible'=>$ok,'blockers'=>$ok?array():array('staging_required'));
 }
 public static function enabled(){
  $row=get_option(self::OPTION,array());
  return !empty($row['enabled']) && ($row['profile_digest']??'')===MAD4B_SCP_Site_Profile::$site['profile_digest'];
 }
}
class MAD4B_SCP_Self_Update {
 public static $sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
 public static function installed_candidate_identity(){return array('source_commit_sha'=>self::$sha);}
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-manual-workflow-bridge.php';
function demand($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$c='MAD4B_SCP_Manual_Workflow_Bridge';
MAD4B_SCP_Site_Profile::$site=array('configured_environment'=>'staging','environment'=>'staging',
 'authority_ready'=>true,'wordpress_environment'=>'staging',
 'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb','revision'=>3,
 'profile_digest'=>str_repeat('b',64),'canonical_origin'=>'https://example.test',
 'current_origin'=>'https://example.test');
$reason='Owner-approved staging update channel';
$enable=$c::plan(array('operation_id'=>$c::ENABLE,'reason'=>$reason));
$input=array('operation_id'=>$c::ENABLE,'reason'=>$reason,
 'expected_plan_sha256'=>$enable['plan_sha256'],'confirmation'=>$c::CONFIRM_ENABLE);
demand($enable['eligible'],'preflight eligible');
$receipt=$c::apply($input);
demand(!is_wp_error($receipt)&&$receipt['readback_verified']&&$receipt['enabled'],'approved enable verified');
demand(count(MAD4B_SCP_Audit::$events)===1,'audit recorded');
demand(!array_key_exists($c::LOCK,$GLOBALS['test_options']),'success lock released');
demand(!$c::plan(array('operation_id'=>$c::ENABLE,'reason'=>$reason))['eligible'],'idempotent follow-up');
$retry=$c::apply($input);
demand(is_wp_error($retry)&&$retry->get_error_code()==='mad4b_manual_replan_required','stale plan rejected');
MAD4B_SCP_OAuth_Resource_Bridge::$authorized=false;
$disable=$c::plan(array('operation_id'=>$c::DISABLE,'reason'=>$reason));
$in2=array('operation_id'=>$c::DISABLE,'reason'=>$reason,
 'expected_plan_sha256'=>$disable['plan_sha256'],'confirmation'=>$c::CONFIRM_DISABLE);
demand(is_wp_error($c::apply($in2)),'OAuth step-up denial');
MAD4B_SCP_OAuth_Resource_Bridge::$authorized=true;
MAD4B_SCP_Authorization::$authorized=false;
demand(is_wp_error($c::apply($in2)),'central grant denial');
MAD4B_SCP_Authorization::$authorized=true;
MAD4B_SCP_Audit::$fail=true;
$fail=$c::apply($in2);
demand(is_wp_error($fail)&&$fail->get_error_code()==='mad4b_manual_audit_failed','audit failure denied');
demand(MAD4B_SCP_WordPress_Native_Opt_In::enabled(),'failed audit reverted original option');
demand(!array_key_exists($c::LOCK,$GLOBALS['test_options']),'failure lock released');
MAD4B_SCP_Audit::$fail=false;
add_option($c::LOCK,array('token'=>'other','created'=>time()),'',false);
demand(is_wp_error($c::apply($in2)),'concurrent operation denied');
delete_option($c::LOCK);
$receipt2=$c::apply($in2);
demand(!is_wp_error($receipt2)&&!$receipt2['enabled'],'approved disable verified');
demand(count(MAD4B_SCP_Audit::$events)===2,'both committed operations audited');
demand(!array_key_exists($c::LOCK,$GLOBALS['test_options']),'second lock released');
echo "PASS: owner authorization, enable/disable, audit rollback, stale plans and lock contention\n";
