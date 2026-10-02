<?php
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_VERSION', 'ci-request-generation' );

class WP_Error {
	private $code; private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_current_user_id() { return $GLOBALS['user']; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][$key] : $default; }
function wp_get_current_user() { return (object) array( 'allcaps' => $GLOBALS['caps'] ); }

class MAD4B_SCP_Site_Profile { const OPTION='profile'; static function reset_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_ChatGPT_Tool_Projection { const OPTION='projection'; }
class MAD4B_SCP_Schema { static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_Agent_Registry { static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_Operation_Registry { static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_Policy_Resolution { static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_MCP_Client_Profile_Registry { static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_Servers { static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_Staging_Write_Authority {
	static $reconciling=false;
	static function request_scope_state(){ return array('reconciling'=>self::$reconciling); }
	static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; }
}
class MAD4B_SCP_Authorization {
	static $active=false;
	static function request_scope_state(){ return array('active_execution_observations'=>self::$active?array('fixture'):array()); }
	static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; }
}
class MAD4B_SCP_Abilities { static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; } }
class MAD4B_SCP_Identity_Context {
	static $approval=false; static $override=false;
	static function request_scope_state(){ return array('approval_ticket_bound'=>self::$approval,'subject_override_active'=>self::$override); }
	static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; }
}
class MAD4B_SCP_MCP_Request_Scope {
	static $unsafe=false;
	static function request_scope_transition_safe(){ return self::$unsafe ? new WP_Error('mad4b_request_scope_worker_recycle_required') : true; }
	static function reset_request_cache(){ $GLOBALS['resets'][]=__CLASS__; return true; }
}
class MAD4B_SCP_Database_Transaction_Guard { static $state=0; static function transaction_state(){ return self::$state; } }

$GLOBALS['blog']=1;
$GLOBALS['user']=1;
$GLOBALS['environment']='staging';
$GLOBALS['caps']=array('read'=>true);
$GLOBALS['options']=array('home'=>'https://ci.test','siteurl'=>'https://ci.test','profile'=>array('revision'=>1),'projection'=>array('revision'=>1));
$GLOBALS['resets']=array();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-request-generation.php';

$fail=static function($m){fwrite(STDERR,"FAIL request-generation-runtime: {$m}\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

$a=MAD4B_SCP_Request_Generation::begin_long_lived_request('request-a','fixture');
$check(is_array($a)&&1===$a['generation']&&!$a['reset_performed'],'first generation did not initialize');
$a2=MAD4B_SCP_Request_Generation::admit('fixture');
$check(is_array($a2)&&1===$a2['generation'],'same logical request was not idempotent');

$GLOBALS['options']['projection']=array('revision'=>2);
$presentation=MAD4B_SCP_Request_Generation::admit('fixture');
$check(is_array($presentation)&&!empty($presentation['presentation_changed'])&&1===$presentation['generation'],'non-authorizing projection generation disrupted authoritative request context');

$GLOBALS['user']=2;
$GLOBALS['caps']=array('read'=>true,'edit_posts'=>true);
$drift=MAD4B_SCP_Request_Generation::admit('fixture');
$check('mad4b_request_scope_context_drift'===$code($drift),'same-request user/capability drift failed open');

$b=MAD4B_SCP_Request_Generation::begin_long_lived_request('request-b','fixture');
$check(is_array($b)&&2===$b['generation']&&$b['reset_performed'],'next logical request did not reset request-local owners');
$check(in_array('MAD4B_SCP_Agent_Registry',$GLOBALS['resets'],true)&&in_array('MAD4B_SCP_Servers',$GLOBALS['resets'],true),'authority/catalog caches were not reset');

MAD4B_SCP_Database_Transaction_Guard::$state=1;
$tx=MAD4B_SCP_Request_Generation::begin_long_lived_request('request-c','fixture');
$check('mad4b_request_scope_transaction_active'===$code($tx),'active transaction crossed request boundary');
MAD4B_SCP_Database_Transaction_Guard::$state=0;

MAD4B_SCP_Identity_Context::$approval=true;
$id=MAD4B_SCP_Request_Generation::begin_long_lived_request('request-d','fixture');
$check('mad4b_request_scope_identity_active'===$code($id),'approval identity overlay crossed request boundary');
MAD4B_SCP_Identity_Context::$approval=false;

MAD4B_SCP_MCP_Request_Scope::$unsafe=true;
$mcp=MAD4B_SCP_Request_Generation::begin_long_lived_request('request-e','fixture');
$check('mad4b_request_scope_worker_recycle_required'===$code($mcp),'mutated MCP hook lifecycle was reset unsafely');
MAD4B_SCP_MCP_Request_Scope::$unsafe=false;

$GLOBALS['blog']=2;
$site=MAD4B_SCP_Request_Generation::begin_long_lived_request('request-f','fixture');
$check('mad4b_request_scope_worker_recycle_required'===$code($site),'cross-site worker reuse was admitted');

echo "mad4b.request-scope-generation.runtime.v1: PASS\n";
