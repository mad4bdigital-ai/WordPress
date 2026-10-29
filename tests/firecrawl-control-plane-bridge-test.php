<?php
// Fail-closed isolated test for Firecrawl Control Plane MU bridge.
define( 'ABSPATH', '/tmp' );
$GLOBALS['caps']=false; $GLOBALS['registrations']=array(); $GLOBALS['request_count']=0;
class WP_Error { public $code; function __construct($c){$this->code=$c;} }
class MAD4B_SCP_Policy { static function can_read(){return true;} }
function current_user_can($c){return $GLOBALS['caps']&&$c==='manage_options';}
function add_action($h,$callback,$p){$GLOBALS['boot_hook']=$h;$GLOBALS['boot_callback']=$callback;}
function wp_register_ability($k,$v){$GLOBALS['registrations'][$k]=$v;}
function wp_has_ability($k){return isset($GLOBALS['registrations'][$k]);}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_remote_get($url,$args){$GLOBALS['request_count']++;return array('code'=>200);}
function wp_remote_post($url,$args){$GLOBALS['request_count']++;return array('code'=>200,'body'=>'{"success":true,"data":{"markdown":"Example Domain"}}');}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function wp_json_encode($x){return json_encode($x);}
if(!function_exists('mb_substr')){function mb_substr($s,$start,$len,$e){return substr($s,$start,$len);}}
if(!function_exists('mb_strlen')){function mb_strlen($s,$e){return strlen($s);}}
$tmp=sys_get_temp_dir().'/mad4b-test-'.bin2hex(random_bytes(8));
file_put_contents($tmp,"username=mad4b-woo-lab-control-plane\npassword=".str_repeat('a',60)."\n");chmod($tmp,0600);
define('MAD4B_FIRECRAWL_SECRET_FILE',$tmp);
register_shutdown_function(function()use($tmp){@unlink($tmp);});
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-research-firecrawl.php';
call_user_func($GLOBALS['boot_callback']);
$bridge='MAD4B_Research_Firecrawl_Bridge';
assert(count($GLOBALS['registrations'])===2);
assert($bridge::preview(array('url'=>'https://example.com')) instanceof WP_Error);
assert($GLOBALS['request_count']===0);
$GLOBALS['caps']=true;
assert($bridge::preview(array('url'=>'https://127.0.0.1')) instanceof WP_Error);
assert($bridge::preview(array('url'=>'http://example.com')) instanceof WP_Error);
assert($bridge::preview(array('url'=>'https://example.com:8443')) instanceof WP_Error);
assert($bridge::preview(array('url'=>'https://other.com')) instanceof WP_Error);
assert($GLOBALS['request_count']===0);
$r=$bridge::preview(array('url'=>'https://example.com'));
assert(is_array($r)&&$r['preview']==='Example Domain'&&$GLOBALS['request_count']===1);
assert($bridge::status()['healthy']===true);
echo "firecrawl-woo-lab-bridge-tests: PASS\n";
