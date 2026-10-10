<?php
/** Native policy, dynamic-delegation and stale-plan denial; no WordPress boot. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code;
 public function __construct($code,$message=''){ $this->code=$code; }
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_current_user_id(){return 4;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
class MAD4B_SCP_WordPress_Native_Opt_In {
 const OPTION='mad4b_scp_wp_native_candidate_opt_in_v1';
 public static $enabled=false;
 public static function site_policy(array $s) {
  $ok=($s['environment']??'')==='staging' &&
   ($s['configured_environment']??'')==='staging' &&
   ($s['wordpress_environment']??'')==='staging' &&
   !empty($s['authority_ready']) && ($s['current_origin']??'')===($s['canonical_origin']??'');
  return array('eligible'=>$ok,'blockers'=>$ok?array():array('exact_staging_required'));
 }
 public static function enabled(){return self::$enabled;}
}
class MAD4B_SCP_Site_Profile {
 public static $site=array();
 public static function status(){return self::$site;}
}
class MAD4B_SCP_Self_Update {
 public static function installed_candidate_identity(){return array('source_commit_sha'=>str_repeat('a',40));}
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-manual-workflow-bridge.php';
function assert_bridge($ok,$msg) {if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$cls='MAD4B_SCP_Manual_Workflow_Bridge';
$site=array('environment'=>'staging','configured_environment'=>'staging','authority_ready'=>true,
 'site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb','revision'=>3,
 'profile_digest'=>str_repeat('b',64),'canonical_origin'=>'https://example.test',
 'current_origin'=>'https://example.test','wordpress_environment'=>'staging');
MAD4B_SCP_Site_Profile::$site=$site;
assert_bridge($cls::decision($site,false,$cls::ENABLE)['eligible'],'explicit Staging opt-in can be planned');
assert_bridge($cls::decision($site,true,$cls::ENABLE)['already_current'],'repeat enable idempotent');
assert_bridge($cls::decision($site,true,$cls::DISABLE)['eligible'],'disable can be planned');
assert_bridge($cls::decision($site,false,$cls::DISABLE)['already_current'],'repeat disable idempotent');
foreach(array(
 array('environment','production'),array('configured_environment','production'),
 array('wordpress_environment','production'),array('authority_ready',false),
 array('current_origin','https://other.invalid')
) as $case) {
 $x=$site;$x[$case[0]]=$case[1];$result=$cls::decision($x,false,$cls::ENABLE);
 assert_bridge(!$result['eligible']&&!$result['production_allowed'],'deny '.$case[0]);
}
$unknown=$cls::decision($site,false,'wordpress.plugin.activate');
assert_bridge(!$unknown['eligible']&&$unknown['delegated'],'no generic arbitrary mutation');
$input=array('operation_id'=>$cls::ENABLE,'reason'=>'Explicitly approved Staging consent');
$plan=$cls::plan($input);
assert_bridge($plan['eligible']&&preg_match('/^[a-f0-9]{64}$/D',$plan['plan_sha256']),'bound plan');
assert_bridge($plan['required_confirmation']===$cls::CONFIRM_ENABLE,'exact confirmation');
assert_bridge($cls::plan($input)['plan_sha256']===$plan['plan_sha256'],'deterministic plan');
MAD4B_SCP_Site_Profile::$site['revision']=4;
assert_bridge($cls::plan($input)['plan_sha256']!==$plan['plan_sha256'],'stale profile plan');
MAD4B_SCP_Site_Profile::$site=$site;
MAD4B_SCP_WordPress_Native_Opt_In::$enabled=true;
assert_bridge($cls::plan($input)['plan_sha256']!==$plan['plan_sha256'],'stale option plan');
assert_bridge(is_wp_error($cls::apply(array('operation_id'=>'wordpress.plugin.activate'))),'reject unknown effectful executor');
assert_bridge(is_wp_error($cls::apply(array('operation_id'=>$cls::ENABLE,'reason'=>'abc','confirmation'=>'wrong','expected_plan_sha256'=>str_repeat('a',64)))),'reject invalid confirmation');
echo "PASS: 15 manual-workflow source-neutral admission, denial, idempotency and stale-plan cases\n";
