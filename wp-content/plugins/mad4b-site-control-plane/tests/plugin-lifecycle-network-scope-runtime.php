<?php
define( 'ABSPATH', __DIR__ . '/fixtures/' );
define( 'MAD4B_SCP_FILE', __FILE__ );
define( 'MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED', true );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/mad4b-plugin-lifecycle-network-fixture' );
@mkdir( WP_PLUGIN_DIR . '/example', 0777, true );
file_put_contents( WP_PLUGIN_DIR . '/example/example.php', "<?php\n/* Plugin Name: Example */\n" );

class WP_Error { private $code; public function __construct($c,$m='',$d=null){$this->code=(string)$c;} public function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
function wp_normalize_path($v){return str_replace('\\\\','/',(string)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function plugin_basename($v){return basename(dirname($v)).'/'.basename($v);}
function is_multisite(){return true;}
function get_plugins(){return array('example/example.php'=>array('Name'=>'Example','Version'=>'1.2.3','RequiresPlugins'=>'','TextDomain'=>'example'));}
function get_option($k,$d=array()){return 'active_plugins'===$k?$GLOBALS['site_active']:$d;}
function is_plugin_active_for_network($p){return !empty($GLOBALS['network_active'][$p]);}
function is_plugin_active($p){return in_array($p,$GLOBALS['site_active'],true)||!empty($GLOBALS['network_active'][$p]);}
function wp_register_ability($n,$a){return true;}
function wp_has_ability($n){return false;}
class MAD4B_SCP_Policy {
 public static function plugin_lifecycle_allowed($plugin,$operation){return true;}
 public static function plugin_lifecycle_protected($plugin){return false;}
 public static function can_read(){return true;}
}
$GLOBALS['site_active']=array();
$GLOBALS['network_active']=array();

require dirname(__DIR__).'/includes/class-mad4b-scp-plugin-lifecycle.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL plugin-lifecycle-network-scope-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$plugin='example/example.php';

$plan=MAD4B_SCP_Plugin_Lifecycle::plan(array('plugin'=>$plugin,'desired_active'=>true,'activation_scope'=>'network','reason'=>'ci'));
$check(is_array($plan)&&!empty($plan['eligible'])&&'network'===$plan['activation_scope']&&!empty($plan['desired_active']),'Network activation plan was not independently eligible.',$plan);

$GLOBALS['network_active'][$plugin]=true;
$verified=MAD4B_SCP_Plugin_Lifecycle::verify_state($plugin,true,'network');
$check(is_array($verified)&&!empty($verified['network_active']),'Network activation readback did not recognize network scope.',$verified);

$sitePlan=MAD4B_SCP_Plugin_Lifecycle::plan(array('plugin'=>$plugin,'desired_active'=>false,'activation_scope'=>'site','reason'=>'ci'));
$check(is_array($sitePlan)&&in_array('network_activation_controls_site_state',$sitePlan['blockers'],true),'Site lifecycle plan ignored controlling network activation.',$sitePlan);

$networkDeactivate=MAD4B_SCP_Plugin_Lifecycle::plan(array('plugin'=>$plugin,'desired_active'=>false,'activation_scope'=>'network','reason'=>'ci'));
$check(is_array($networkDeactivate)&&!empty($networkDeactivate['eligible']),'Network deactivation plan was not eligible after network activation.',$networkDeactivate);

@unlink(WP_PLUGIN_DIR.'/example/example.php'); @rmdir(WP_PLUGIN_DIR.'/example'); @rmdir(WP_PLUGIN_DIR);
echo "mad4b.plugin-lifecycle-network-scope.runtime.v1: PASS\n";
