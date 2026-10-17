<?php
/** Hermetic transport boundary: owner intent only, never a generic Write bypass. */
define('ABSPATH',__DIR__);
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $code;function __construct($c,$m='',$d=null){$this->code=$c;}function get_error_code(){return $this->code;}}
class MAD4B_SCP_Site_Profile{
 static function configured(){return true;}
 static function current_environment(){return $GLOBALS['environment'];}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
}
class MAD4B_SCP_Staging_Write_Owner_Intent{const ATTEST_ABILITY='mad4b/staging-write-owner-attest';}
class MAD4B_SCP_Servers{
 static function expected_server_ids(){return array('mad4b-chatgpt','mad4b-enrollment','mad4b-write');}
 static function is_chatgpt_direct_step_up_tool($n){return $GLOBALS['allow']&&$n===MAD4B_SCP_Staging_Write_Owner_Intent::ATTEST_ABILITY;}
 static function ability_is_mounted($server,$n){return $GLOBALS['mounted']&&'mad4b-enrollment'===$server&&$n===MAD4B_SCP_Staging_Write_Owner_Intent::ATTEST_ABILITY;}
 static function is_external_write_candidate($n){return true;}
}
class MAD4B_SCP_Staging_Write_Authority {
 static function effective(){return false;}
 static function candidate_bootstrap_allowed($n,$v=null){return false;}
 static function candidate_bootstrap_status($n,$v=null){return array();}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-transport-context.php';
function ok($v,$l){if(!$v){fwrite(STDERR,"FAIL: $l\n");exit(1);}}
$GLOBALS['environment']='staging';$GLOBALS['allow']=true;$GLOBALS['mounted']=true;
$request=new class {function get_route(){return '/mcp/mad4b-chatgpt';}};
ok(true===MAD4B_SCP_Transport_Context::bind('mad4b-chatgpt',$request),'transport bind');
$owner=MAD4B_SCP_Staging_Write_Owner_Intent::ATTEST_ABILITY;
$allowed=MAD4B_SCP_Transport_Context::resolve_server_for_ability('mad4b-enrollment',$owner,array());
ok('mad4b-enrollment'===$allowed,'only Staging owner attestation delegated');
$denied=MAD4B_SCP_Transport_Context::resolve_server_for_ability('mad4b-write','mad4b/arbitrary-write',array());
ok(is_wp_error($denied),'general write still denied on stale candidate');
$GLOBALS['allow']=false;
ok(is_wp_error(MAD4B_SCP_Transport_Context::resolve_server_for_ability('mad4b-enrollment',$owner,array())),'not direct-stepup denied');
$GLOBALS['allow']=true;$GLOBALS['mounted']=false;
ok(is_wp_error(MAD4B_SCP_Transport_Context::resolve_server_for_ability('mad4b-enrollment',$owner,array())),'unmounted owner ability denied');
$GLOBALS['mounted']=true;$GLOBALS['environment']='production';
ok(is_wp_error(MAD4B_SCP_Transport_Context::resolve_server_for_ability('mad4b-enrollment',$owner,array())),'Production denied');
MAD4B_SCP_Transport_Context::clear();
echo "STAGING_OWNER_TRANSPORT: PASS (hermetic)\n";
