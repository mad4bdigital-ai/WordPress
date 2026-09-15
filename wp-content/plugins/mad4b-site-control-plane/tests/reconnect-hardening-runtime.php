<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', rtrim( dirname( __DIR__ ), '/\\' ) . '/' );
$GLOBALS['opts']=array(); $GLOBALS['env']='staging'; $GLOBALS['home']='https://staging.example.test';
class WP_Error { private $c; private $d; public function __construct($c,$m='',$d=null){$this->c=$c;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_data(){return $this->d;} }
function is_wp_error($v){return $v instanceof WP_Error;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim((string)$v);} function absint($v){return abs((int)$v);} function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function wp_json_encode($v,$f=0){return json_encode($v,$f);} function trailingslashit($v){return rtrim((string)$v,'/\\').'/';} function untrailingslashit($v){return rtrim((string)$v,'/\\');}
function home_url($p=''){return rtrim($GLOBALS['home'],'/').(''===$p?'':'/'.ltrim($p,'/'));} function rest_url($p=''){return rtrim($GLOBALS['home'],'/').'/wp-json/'.ltrim($p,'/');} function wp_get_environment_type(){return $GLOBALS['env'];} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$d;} function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;} function delete_option($k){unset($GLOBALS['opts'][$k]);return true;} function get_userdata($id){return (int)$id===7?(object)array('ID'=>7):false;} function user_can($u,$c){return is_object($u)&&$u->ID===7&&$c==='manage_options';} function current_user_can($c){return true;} function get_current_user_id(){return 7;} function wp_generate_uuid4(){return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';} function get_bloginfo($k){return 'Test';}
function add_action(){return true;} function remove_action(){return true;} function add_filter(){return true;} function is_admin(){return false;} function wp_unslash($v){return $v;} function esc_html($v){return $v;} function esc_html__($v){return $v;} function wp_register_ability_category(){} function wp_register_ability(){} function wp_has_ability(){return false;}
final class MAD4B_SCP_Audit {
    public static $storage=array('ready'=>true);
    public static function storage_status(){return self::$storage;}
    public static function record(){return true;}
}
final class MAD4B_SCP_Schema {
    public static $status=array('ready'=>true,'physical_integrity'=>array('ready'=>true));
    public static function status($deep=false){return self::$status;}
}
final class MAD4B_SCP_Local_OAuth_Server { public static $effective=true; public static function status(){return array('configured'=>true,'effective'=>self::$effective,'issuer_configuration_valid'=>true,'private_key_present'=>true,'oauth_store_ready'=>true,'runtime_error'=>'');} }
final class MAD4B_SCP_OAuth_Resource_Bridge { public static $effective=true; public static function status(){return array('effective'=>self::$effective);} public static function resource_identifier(){return rest_url('mcp/mad4b-chatgpt');} }
final class MAD4B_SCP_MCP_Registration_Bridge { public static $status=array(); public static function status(){return self::$status;} }
final class MAD4B_SCP_Servers { public static $status=array(); public static function registration_status(){return self::$status;} }
require dirname(__DIR__).'/includes/class-mad4b-scp-site-profile.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-upgrade-continuity.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-reconnect-hardening.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-plugin.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function legacy(){return array('contract'=>MAD4B_SCP_Site_Profile::LEGACY_CONTRACT,'version'=>MAD4B_SCP_Site_Profile::LEGACY_VERSION,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','revision'=>4,'environment'=>'staging','canonical_origin'=>'https://staging.example.test','display_name'=>'Legacy','oauth_user_ids'=>array(7),'features'=>array('oauth'=>true,'skills'=>true,'write'=>true,'production_write_confirmed'=>true,'provider_isolation'=>true,'managed_runtime'=>true,'acceptance'=>true));}
function prior(){return array('environment'=>'staging','site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','profile_revision'=>4,'canonical_origin'=>'https://staging.example.test','wp_user_id'=>7,'primary_owner_user_id'=>7,'oauth_user_ids'=>array(7),'issuer'=>'https://staging.example.test/oauth/mcp');}

// Model the exact main bootstrap order for the first request after a v1-only upgrade.
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy(); $GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=prior();
MAD4B_SCP_Site_Profile::bootstrap(); $r=MAD4B_SCP_Upgrade_Continuity::pre_boot(); ok(!empty($r['recovered']),'first-upgrade continuity recovers verified read/OAuth evidence');
if(!empty($r['recovered'])){MAD4B_SCP_Site_Profile::reset_cache();MAD4B_SCP_Site_Profile::bootstrap();}
ok(MAD4B_SCP_Site_Profile::oauth_enabled(),'OAuth is effective after cache refresh'); ok(!MAD4B_SCP_Site_Profile::write_enabled(),'write remains disabled');

MAD4B_SCP_Servers::$status=array('mad4b-chatgpt'=>array('registered'=>true,'error'=>''),'mad4b-admin'=>array('registered'=>false,'error'=>'admin_registration_failed'));
MAD4B_SCP_MCP_Registration_Bridge::$status=array('missed_rest_recovery_state'=>'blocked','missed_rest_recovery_blocker'=>'targeted_mad4b_route_count_incomplete');
$s=MAD4B_SCP_Reconnect_Hardening::reconnect_status(); ok(!empty($s['ready']),'unrelated server/global recovery failure must not block exact ChatGPT reconnect');
MAD4B_SCP_Servers::$status['mad4b-chatgpt']=array('registered'=>false,'error'=>'chatgpt_registration_failed'); $s=MAD4B_SCP_Reconnect_Hardening::reconnect_status();
ok(empty($s['ready'])&&in_array('chatgpt_registration_failed',$s['blockers'],true),'exact ChatGPT registration error blocks reconnect'); ok(!in_array('targeted_mad4b_route_count_incomplete',$s['blockers'],true),'global route blocker remains diagnostic context only');
ok(MAD4B_SCP_Reconnect_Hardening::is_resource_request_path('/mcp/mad4b-chatgpt'),'REST route form recognized'); ok(MAD4B_SCP_Reconnect_Hardening::is_resource_request_path('/wp-json/mcp/mad4b-chatgpt'),'wp-json resource form recognized');
$request=new class { public function get_route(){return '/mcp/mad4b-chatgpt';} }; $guard=MAD4B_SCP_Reconnect_Hardening::guard_mcp_rest_dispatch(null,null,$request); ok(is_wp_error($guard)&&'mad4b_mcp_reconnect_not_ready'===$guard->get_error_code(),'missing route becomes deterministic reconnect error'); ok(503===($guard->get_error_data()['status']??0),'reconnect error is 503');
// Preserve the exact bootstrap failure when runtime initialization knows more than a derived status snapshot.
MAD4B_SCP_Audit::$storage=array('ready'=>false,'schema_ready'=>true,'schema_physical_ready'=>true,'tables_ready'=>true,'transactional'=>true,'legacy_chain_valid'=>true,'head_initialized'=>false,'head_consistent'=>false,'legacy_anchor_match'=>true);
$bootstrap_error=new ReflectionProperty('MAD4B_SCP_Plugin','schema_error');
$bootstrap_error->setAccessible(true);
$bootstrap_error->setValue(null,new WP_Error('mad4b_audit_head_create_failed','fixture'));
$g=MAD4B_SCP_Upgrade_Continuity::governance_status();
ok(empty($g['ready'])&&'audit'===$g['blocker_kind']&&'mad4b_audit_head_create_failed'===$g['blocker_code'],'exact audit bootstrap error code must be preserved');
$bootstrap_error->setValue(null,null);
$g=MAD4B_SCP_Upgrade_Continuity::governance_status();
ok('mad4b_audit_head_missing'===$g['blocker_code'],'derived audit blocker remains the fallback when no exact bootstrap error exists');
MAD4B_SCP_Audit::$storage=array('ready'=>true);

fwrite(STDOUT,"mad4b.reconnect-hardening.runtime.v1: PASS\n");
