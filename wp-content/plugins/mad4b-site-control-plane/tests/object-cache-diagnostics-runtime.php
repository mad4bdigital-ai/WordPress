<?php
define('ABSPATH',__DIR__);define('WP_CLI',true);
$GLOBALS['memtest_persistent']=false;$GLOBALS['memtest_env']='staging';
$GLOBALS['memtest_admin']=true;$GLOBALS['memtest_data']=array();$GLOBALS['memtest_fail_incr']=false;
function wp_using_ext_object_cache(){return $GLOBALS['memtest_persistent'];}
function wp_get_environment_type(){return $GLOBALS['memtest_env'];}
function current_user_can($c){return 'manage_options'===$c&&$GLOBALS['memtest_admin'];}
function wp_cache_add($k,$v,$g,$ttl){$id="$g:$k";if(isset($GLOBALS['memtest_data'][$id]))return false;$GLOBALS['memtest_data'][$id]=$v;return true;}
function wp_cache_get($k,$g,$force=false,&$found=null){$id="$g:$k";$found=array_key_exists($id,$GLOBALS['memtest_data']);return $found?$GLOBALS['memtest_data'][$id]:false;}
function wp_cache_incr($k,$i,$g){$id="$g:$k";if(!isset($GLOBALS['memtest_data'][$id])||$GLOBALS['memtest_fail_incr'])return false;return $GLOBALS['memtest_data'][$id]+=$i;}
function wp_cache_delete($k,$g){unset($GLOBALS['memtest_data']["$g:$k"]);return true;}
class WP_Error {private $code;public function __construct($code,$description){$this->code=$code;}public function get_error_code(){return $this->code;}}
function check_cache($c,$s){if(!$c)throw new RuntimeException($s);}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-object-cache-diagnostics.php';
$cls='MAD4B_SCP_Object_Cache_Diagnostics';
define('ETG_DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED',true);
$passive=$cls::status();
check_cache(!$passive['persistent_object_cache_reported']&&'rate_limit_backend_unavailable'===$passive['ajax_rate_limit_mode'],'missing backend fail closed');
check_cache(empty($GLOBALS['memtest_data'])&&!$passive['cross_request_proven']&&!$passive['atomic_increment_proven'],'passive read does not write or certify');
$GLOBALS['memtest_persistent']=true;
check_cache('persistent_object_cache'===$cls::status()['ajax_rate_limit_mode'],'Object Cache WordPress interface detected');
$seed=$cls::cli_probe('seed');check_cache(is_array($seed)&&2===$seed['counter']&&32===strlen($seed['token']),'seed add/incr');
$token=$seed['token'];$read=$cls::cli_probe('read',$token);
check_cache($read['cross_request_proven']&&!$read['atomic_increment_proven'],'read verification not concurrency proof');
check_cache(3===$cls::cli_probe('increment',$token)['counter'],'increment count');
$GLOBALS['memtest_fail_incr']=true;$fail=$cls::cli_probe('increment',$token);
check_cache($fail instanceof WP_Error&&'mad4b_cache_probe_increment_failed'===$fail->get_error_code(),'failed incr blocked');
$GLOBALS['memtest_fail_incr']=false;$GLOBALS['memtest_env']='production';
check_cache($cls::cli_probe('seed') instanceof WP_Error,'production denied');
$GLOBALS['memtest_env']='staging';$GLOBALS['memtest_admin']=false;
check_cache($cls::cli_probe('seed') instanceof WP_Error,'non-admin denied');
$GLOBALS['memtest_admin']=true;
check_cache($cls::cli_probe('read','invalid') instanceof WP_Error,'invalid token denied');
$GLOBALS['memtest_data']=array();
$expiry=$cls::cli_probe('expiry',$token);
check_cache($expiry['expired_observed'] && !$expiry['ttl_expiration_proven']
    && !$expiry['elapsed_ttl_proven'] && $expiry['expiry_missing_key_is_not_ttl_proof'],
    'deleted key can only prove absence, not passage of TTL or genuine expiry');
$GLOBALS['memtest_persistent']=false;
check_cache('rate_limit_backend_unavailable'===$cls::status()['ajax_rate_limit_mode'],'site-specific addon constant cannot determine core WAF evidence');
define('MAD4B_SCP_EXTERNAL_WAF_RATE_LIMIT_VERIFIED',true);
$status=$cls::status();
check_cache('external_waf_attested'===$status['ajax_rate_limit_mode']&&!$status['atomic_increment_proven'],'site-neutral WAF configuration remains passive evidence');
echo "PASS Memcached/LSMCD passive diagnostics and staging-only ephemeral acceptance\n";
