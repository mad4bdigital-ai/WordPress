<?php
define( 'ABSPATH', sys_get_temp_dir() . '/mad4b-compat-root/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/mad4b-compat-content' );
define( 'WP_CLI', true );
if ( ! is_dir( ABSPATH ) ) mkdir( ABSPATH, 0700, true );
if ( ! is_dir( WP_CONTENT_DIR ) ) mkdir( WP_CONTENT_DIR, 0700, true );

class WP_Error { private $code; private $data; public function __construct($c,$m='',$d=array()){$this->code=$c;$this->data=$d;} public function get_error_code(){return $this->code;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function trailingslashit($v){return rtrim((string)$v,"/\\").'/';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_using_ext_object_cache(){return !empty($GLOBALS['ext_cache']);}
function wp_cache_set($k,$v,$g='',$e=0){if(!empty($GLOBALS['cache_fail']))return false;$GLOBALS['cache'][$g][$k]=$v;return true;}
function wp_cache_get($k,$g='',$force=false,&$found=null){$found=isset($GLOBALS['cache'][$g])&&array_key_exists($k,$GLOBALS['cache'][$g]);return $found?$GLOBALS['cache'][$g][$k]:false;}
function wp_cache_delete($k,$g=''){if(!empty($GLOBALS['cache_fail']))return false;unset($GLOBALS['cache'][$g][$k]);return true;}
function get_option($k,$d=false){return $k==='active_plugins'?($GLOBALS['active_plugins']??array()):$d;}
function is_multisite(){return false;}
function wp_doing_cron(){return false;}

class MAD4B_SCP_Transport_Context { public static $server=''; public static function current_server_id(){return self::$server;} }
class MAD4B_SCP_Database_Topology {
	public static $dropin=false;
	public static function status($refresh=false){
		$dropin=self::$dropin||is_file(WP_CONTENT_DIR.'/db.php');
		return array('ready'=>!$dropin,'read_your_writes'=>!$dropin,'database_dropin_present'=>$dropin,'mode'=>$dropin?'uncertified_router':'single_wpdb_writer_session','server_fingerprint'=>str_repeat('a',64),'blockers'=>$dropin?array('uncertified_database_router_dropin'):array());
	}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-compatibility-profile.php';

$fail=static function($m){fwrite(STDERR,"FAIL runtime-compatibility-profile-runtime: {$m}\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$blockers=static function($v){$d=is_wp_error($v)?$v->get_error_data():array();return is_array($d)&&isset($d['blockers'])?$d['blockers']:array();};

$GLOBALS['ext_cache']=false;$GLOBALS['cache_fail']=false;$GLOBALS['cache']=array();$GLOBALS['active_plugins']=array();
$base=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check(is_array($base)&&'wp_cli'===$base['execution_context']&&!empty($base['ready']),'local WP-CLI baseline was not certified');

$GLOBALS['ext_cache']=true;
$cache=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check(is_array($cache)&&!empty($cache['object_cache']['contract_verified']),'working external object cache contract was rejected');
$GLOBALS['cache_fail']=true;
$cache_bad=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($cache_bad)&&in_array('persistent_object_cache_contract_unverified',$blockers($cache_bad),true),'broken persistent object cache failed open');
$GLOBALS['cache_fail']=false;$GLOBALS['ext_cache']=false;

file_put_contents(WP_CONTENT_DIR.'/db.php',"<?php\n");
$db=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($db)&&in_array('database_router_uncertified',$blockers($db),true),'uncertified db.php router failed open');
unlink(WP_CONTENT_DIR.'/db.php');

file_put_contents(ABSPATH.'.maintenance',"<?php $upgrading = time();\n");
$maint=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($maint)&&in_array('wordpress_maintenance_mode_active',$blockers($maint),true),'maintenance mode failed open');
unlink(ABSPATH.'.maintenance');

$GLOBALS['active_plugins']=array('wordfence/wordfence.php');
$security=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($security)&&in_array('security_firewall_plugin_requires_certification',$blockers($security),true),'security/firewall conflict failed open');
$GLOBALS['active_plugins']=array();

MAD4B_SCP_Transport_Context::$server='mad4b-chatgpt';
$remote=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($remote)&&in_array('remote_transport_wp_cli_context_conflict',$blockers($remote),true),'remote transport inside WP-CLI failed open');

echo "mad4b.runtime-compatibility-profile.runtime.v1: PASS\n";
