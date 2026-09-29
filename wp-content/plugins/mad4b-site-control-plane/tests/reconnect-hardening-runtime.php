<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', rtrim( dirname( __DIR__ ), '/\\' ) . '/' );
$GLOBALS['opts']=array(); $GLOBALS['env']='staging'; $GLOBALS['home']='https://staging.example.test';
$GLOBALS['transients']=array(); $GLOBALS['user_meta_rows']=array(); $GLOBALS['abilities']=array(); $GLOBALS['after_user_meta_update']=null;
class WP_Error { private $c; private $d; public function __construct($c,$m='',$d=null){$this->c=$c;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_data(){return $this->d;} }
function is_wp_error($v){return $v instanceof WP_Error;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim((string)$v);} function absint($v){return abs((int)$v);} function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function wp_json_encode($v,$f=0){return json_encode($v,$f);} function trailingslashit($v){return rtrim((string)$v,'/\\').'/';} function untrailingslashit($v){return rtrim((string)$v,'/\\');}
function home_url($p=''){return rtrim($GLOBALS['home'],'/').(''===$p?'':'/'.ltrim($p,'/'));} function rest_url($p=''){return rtrim($GLOBALS['home'],'/').'/wp-json/'.ltrim($p,'/');} function wp_get_environment_type(){return $GLOBALS['env'];} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$d;} function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;} function delete_option($k){unset($GLOBALS['opts'][$k]);return true;} function get_userdata($id){return (int)$id===7?(object)array('ID'=>7):false;} function user_can($u,$c){return is_object($u)&&$u->ID===7&&$c==='manage_options';} function current_user_can($c){return true;} function get_current_user_id(){return 7;} function wp_generate_uuid4(){return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';} function get_bloginfo($k){return 'Test';}
function add_action(){return true;} function remove_action(){return true;} function add_filter(){return true;} function apply_filters($hook,$value){return $value;} function is_admin(){return false;} function wp_unslash($v){return $v;} function esc_html($v){return $v;} function esc_html__($v){return $v;} function wp_register_ability_category(){} function wp_register_ability(){}
function wp_has_ability($name){return isset($GLOBALS['abilities'][$name]);} function wp_get_ability($name){return $GLOBALS['abilities'][$name]??null;}
function is_multisite(){return false;} function get_current_blog_id(){return 1;} function wp_cache_delete(){return true;}
function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;return true;} function get_transient($k){return array_key_exists($k,$GLOBALS['transients'])?$GLOBALS['transients'][$k]:false;} function delete_transient($k){unset($GLOBALS['transients'][$k]);return true;}
function get_user_meta($uid,$key,$single=false){$rows=$GLOBALS['user_meta_rows'][(int)$uid][$key]??array();if($single)return empty($rows)?'':reset($rows);return array_values($rows);}
function update_user_meta($uid,$key,$value,$prev=null){$uid=(int)$uid;$rows=$GLOBALS['user_meta_rows'][$uid][$key]??array();$matched=false;if(func_num_args()>=4){foreach($rows as $i=>$row){if($row===$prev){$rows[$i]=$value;$matched=true;}}if(!$matched)return false;}else{if(empty($rows))$rows[]=$value;else{$rows[0]=$value;$matched=true;}}$GLOBALS['user_meta_rows'][$uid][$key]=array_values($rows);if(is_callable($GLOBALS['after_user_meta_update'])){$cb=$GLOBALS['after_user_meta_update'];$GLOBALS['after_user_meta_update']=null;$cb();}return true;}
function delete_user_meta($uid,$key,$value=null){$uid=(int)$uid;if(!isset($GLOBALS['user_meta_rows'][$uid][$key]))return false;if(func_num_args()<3){unset($GLOBALS['user_meta_rows'][$uid][$key]);return true;}$GLOBALS['user_meta_rows'][$uid][$key]=array_values(array_filter($GLOBALS['user_meta_rows'][$uid][$key],static function($row)use($value){return $row!==$value;}));return true;}
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
final class MAD4B_SCP_OAuth_Resource_Bridge { public static $effective=true; public static function status(){return array('effective'=>self::$effective);} public static function resource_identifier(){return rest_url('mcp/mad4b-chatgpt');} public static function verified_bearer_active(){return true;} public static function verified_bearer_client_is($id){return $id==='https://chatgpt.com/oauth/client.json';} }
final class MAD4B_SCP_MCP_Registration_Bridge { public static $status=array(); public static function status(){return self::$status;} }
final class MAD4B_SCP_Servers { public static $status=array(); public static function registration_status(){return self::$status;} public static function chatgpt_direct_read_transport_tools(){return array('mad4b/site-info');} }
eval('namespace WP\\MCP\\Core; final class McpAdapter { const VERSION = "0.6.1"; }');
eval('namespace WP\\MCP\\Transport\\Infrastructure; final class SessionManager {}');
eval('namespace WP\\MCP\\Domain\\Utils; final class McpNameSanitizer { public static function sanitize_name($name){ return str_replace("/","-",trim((string)$name)); } }');
require dirname(__DIR__).'/includes/class-mad4b-scp-site-profile.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-upgrade-continuity.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-reconnect-hardening.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-plugin.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function priv($name,$args=array()){ $m=new ReflectionMethod('MAD4B_SCP_Reconnect_Hardening',$name); $m->setAccessible(true); return $m->invokeArgs(null,$args); }
function set_priv($name,$value){ $p=new ReflectionProperty('MAD4B_SCP_Reconnect_Hardening',$name); $p->setAccessible(true); $p->setValue(null,$value); }
function sid($n){return sprintf('00000000-0000-4000-8000-%012x',$n);}
function srec($t=1000){return array('created_at'=>$t,'last_activity'=>$t,'client_params'=>array('protocolVersion'=>'2025-06-18'));}

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
MAD4B_SCP_Local_OAuth_Server::$effective=false; MAD4B_SCP_OAuth_Resource_Bridge::$effective=true; $s=MAD4B_SCP_Reconnect_Hardening::reconnect_status();
ok(!empty($s['ready'])&&empty($s['local_oauth_required_for_reconnect']),'effective OAuth resource bridge must not require local OAuth authority');
MAD4B_SCP_Local_OAuth_Server::$effective=true;
MAD4B_SCP_Servers::$status['mad4b-chatgpt']=array('registered'=>false,'error'=>'chatgpt_registration_failed'); $s=MAD4B_SCP_Reconnect_Hardening::reconnect_status();
ok(empty($s['ready'])&&in_array('chatgpt_registration_failed',$s['blockers'],true),'exact ChatGPT registration error blocks reconnect'); ok(!in_array('targeted_mad4b_route_count_incomplete',$s['blockers'],true),'global route blocker remains diagnostic context only');
ok(MAD4B_SCP_Reconnect_Hardening::is_resource_request_path('/mcp/mad4b-chatgpt'),'REST route form recognized'); ok(MAD4B_SCP_Reconnect_Hardening::is_resource_request_path('/wp-json/mcp/mad4b-chatgpt'),'wp-json resource form recognized');
$request=new class { public function get_route(){return '/mcp/mad4b-chatgpt';} }; $guard=MAD4B_SCP_Reconnect_Hardening::guard_mcp_rest_dispatch(null,null,$request); ok(is_wp_error($guard)&&'mad4b_mcp_reconnect_not_ready'===$guard->get_error_code(),'missing route becomes deterministic reconnect error'); ok(503===($guard->get_error_data()['status']??0),'reconnect error is 503');
// Nested request isolation: inner REST lifecycle cannot clear the outer initialize state.
$outer_request=new class {};
$inner_request=new class {};
$outer_key=priv('request_scope_key',array($outer_request));
$inner_key=priv('request_scope_key',array($inner_request));
set_priv('initialize_empty_requests',array($outer_key=>true));
MAD4B_SCP_Reconnect_Hardening::reset_session_policy_scope(null,null,$inner_request);
$rp=new ReflectionProperty('MAD4B_SCP_Reconnect_Hardening','initialize_empty_requests'); $rp->setAccessible(true);
$scope=$rp->getValue();
ok(!empty($scope[$outer_key])&&!isset($scope[$inner_key]),'nested REST reset preserves exact outer initialize scope');
MAD4B_SCP_Reconnect_Hardening::clear_session_policy_scope(null,null,$inner_request);
$scope=$rp->getValue();
ok(!empty($scope[$outer_key]),'nested REST clear cannot erase outer initialize scope');
MAD4B_SCP_Reconnect_Hardening::clear_session_policy_scope(null,null,$outer_request);
ok(empty($rp->getValue()),'outer REST clear removes only its exact initialize scope');
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


// ChatGPT recovery must not modify Adapter-global session capacity or timeout.
$policy=MAD4B_SCP_Reconnect_Hardening::session_continuity_policy();
ok(empty($policy['adapter_session_max_modified'])&&empty($policy['adapter_inactivity_timeout_modified'])&&empty($policy['activity_update_interval_modified']),'ChatGPT repair leaves Adapter-global session policy unchanged');

// Recovery admission exists only for the proven first empty->non-empty race.
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array();
ok(priv('session_store_is_empty_for_first_initialize',array(7)),'empty canonical store is eligible for first-initialize race observation');
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array(array());
ok(priv('session_store_is_empty_for_first_initialize',array(7)),'empty duplicate meta rows remain an empty first-initialize state');
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array(array(sid(90)=>srec(time())));
ok(!priv('session_store_is_empty_for_first_initialize',array(7)),'existing non-empty session state disables first-initialize recovery admission');
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array();

// Initialize shadow keeps only the negotiated protocol revision.
$minimal=priv('minimal_initialize_params',array(array(
    'protocolVersion'=>'2025-06-18',
    'clientInfo'=>array('name'=>'ChatGPT','version'=>'1.0','secret'=>'must-not-survive'),
    'capabilities'=>array('tools'=>array('token'=>'must-not-survive')),
)));
ok(isset($minimal['protocolVersion'])&&!isset($minimal['capabilities']),'initialize capabilities are not persisted in recovery shadow');
ok(!isset($minimal['clientInfo'])&&count($minimal)===1,'recovery shadow retains protocolVersion only');

// Static read allowlist is insufficient: runtime readonly annotation is mandatory.
$GLOBALS['abilities']['mad4b/site-info']=new class { public function get_meta(){return array('annotations'=>array('readonly'=>false));} };
$call=array('method'=>'tools/call','params'=>array('name'=>'mad4b-site-info'));
ok(!priv('readonly_transport_request',array($call)),'allowlisted tool marked mutation at runtime cannot rehydrate a session');
$GLOBALS['abilities']['mad4b/site-info']=new class { public function get_meta(){return array('annotations'=>array('readonly'=>true));} };
ok(priv('readonly_transport_request',array($call)),'allowlisted runtime-readonly tool may use bounded repair');
unset($GLOBALS['abilities']['mad4b/site-info']);

// Per-session shadows do not overwrite each other; DELETE tombstone blocks resurrection.
$GLOBALS['transients']=array();
$shadow=array('wp_user_id'=>7,'subject_fingerprint'=>str_repeat('a',64),'site_profile_digest'=>str_repeat('b',64),'client_fingerprint'=>str_repeat('c',64),'client_params'=>$minimal,'captured_at'=>time());
ok(priv('store_session_shadow',array(7,sid(1),$shadow)),'first independent shadow stored');
ok(priv('store_session_shadow',array(7,sid(2),$shadow)),'second independent shadow stored');
ok(!empty(priv('get_session_shadow',array(7,sid(1))))&&!empty(priv('get_session_shadow',array(7,sid(2)))),'concurrent-session shadow keys remain independent');
priv('forget_session_shadow',array(7,sid(2)));
ok(empty(priv('get_session_shadow',array(7,sid(2)))),'DELETE tombstone blocks shadow resurrection');
ok(!empty(priv('get_session_shadow',array(7,sid(1)))),'deleting one session shadow does not affect sibling shadow');
$transient_count=count($GLOBALS['transients']);
priv('forget_session_shadow',array(7,'not-a-session'));
ok(count($GLOBALS['transients'])===$transient_count,'invalid session id cannot allocate a DELETE tombstone');

// Duplicate rows: match Adapter single=true visibility instead of unioning hidden state.
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array(
    array(sid(101)=>srec(1000)),
    array(sid(102)=>srec(1001)),
);
ok(priv('session_exists',array(7,sid(101))),'visible first-row session is recognized');
ok(!priv('session_exists',array(7,sid(102))),'hidden duplicate-row session is not falsely treated as Adapter-visible');
ok(priv('ensure_session_record',array(7,sid(102),$minimal)),'hidden raced session is repaired into Adapter-visible row');
$rows=$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions'];
ok(isset($rows[0][sid(101)],$rows[0][sid(102)]),'visible row gains the repaired session');
ok(count($rows)===2 && isset($rows[1][sid(102)]) && !isset($rows[1][sid(101)]),'hidden row is not union-converged or rewritten');

// Explicit termination removes the target from every duplicate row without merging siblings.
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array(
    array(sid(301)=>srec(1000),sid(302)=>srec(1000)),
    array(sid(302)=>srec(1001),sid(303)=>srec(1001)),
);
ok(priv('remove_session_from_all_rows',array(7,sid(302))),'DELETE cleanup removes target across duplicate rows');
$rows=$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions'];
ok(!isset($rows[0][sid(302)])&&!isset($rows[1][sid(302)]),'terminated session cannot survive in a hidden duplicate row');
ok(isset($rows[0][sid(301)])&&isset($rows[1][sid(303)]),'DELETE cleanup does not union or erase sibling sessions');

// Empty-only or corrupt state fails closed.
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array(array());
ok(!priv('ensure_session_record',array(7,sid(104),$minimal)),'empty-only session storage is never invented by repair');
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array(array('not-a-uuid'=>srec(1000)));
$before=$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions'];
ok(!priv('ensure_session_record',array(7,sid(105),$minimal)),'corrupt existing session record blocks repair');
ok($before===$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions'],'corrupt state remains untouched');

// Capacity is fail-closed and never evicts an existing session.
$full=array(); for($i=1;$i<=32;$i++)$full[sid(1000+$i)]=srec(1000+$i);
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array($full);
ok(!priv('ensure_session_record',array(7,sid(5000),$minimal)),'repair refuses when bounded capacity is full');
ok(count($GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions'][0])===32,'capacity refusal does not evict an existing session');

// DELETE racing after CAS forces rollback of the repaired session.
$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions']=array(array(sid(201)=>srec(1000)));
$target=sid(202);
$GLOBALS['after_user_meta_update']=static function()use($target){
    $key=priv('shadow_tombstone_key',array(7,$target));
    set_transient($key,1,300);
};
ok(!priv('ensure_session_record',array(7,$target,$minimal)),'post-CAS DELETE tombstone converts repair to failure');
$merged=$GLOBALS['user_meta_rows'][7]['mcp_adapter_sessions'][0];
ok(!isset($merged[$target]),'post-CAS DELETE race removes the repaired session');

fwrite(STDOUT,"mad4b.reconnect-hardening.runtime.v1: PASS\n");
