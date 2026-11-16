<?php
/** Original first-party LiteSpeed adapter: no WordPress cache purge, no network. */
define('ABSPATH',__DIR__.'/');
define('LSCWP_V','7.1');
class WP_Error {
 private $code;
 function __construct($code,$message=''){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function esc_url_raw($url){return $url;}
function home_url(){return 'https://wayfatours.com/';}
function wp_parse_url($url,$part=-1){return $part===-1?parse_url($url):parse_url($url,$part);}
function has_filter($name){return array_key_exists('filter_available',$GLOBALS)?$GLOBALS['filter_available']:true;}
function apply_filters($hook,$slug){
 if(!empty($GLOBALS['filter_throw']))throw new RuntimeException('Native LiteSpeed settings read failed');
 return $GLOBALS['filter_values'][$slug]??false;
}
function wp_json_encode($v){return json_encode($v);}
function wp_scripts(){return (object)['registered'=>[]];}
function do_action($name,$value){$GLOBALS['purge_actions'][]=['event'=>$name,'value'=>$value];}
class MAD4B_SCP_Audit {
 static function record($event,$data){$GLOBALS['audit'][]=['event'=>$event,'data'=>$data];}
}
class MAD4B_Safe_Public_HTML_Purge_V1 {
 static $result=['status'=>'public_html_purge_queued'];
 static function purge_public_html($scope){return self::$result;}
}
abstract class MAD4B_SCP_Adapter_Base {
 abstract public function id();
 abstract public function label();
 abstract public function is_available();
 abstract public function ability_names();
 abstract public function register_abilities();
 protected function runtime_version(){return '7.1';}
 protected function unavailable_error(){return new WP_Error('provider_unavailable');}
 protected function add_ability(...$args){$GLOBALS['registered'][]=$args;}
 protected function schema($fields,$required=[]){return compact('fields','required');}
}
function ls_check($ok,$why){
 if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
$GLOBALS['purge_actions']=[];$GLOBALS['audit']=[];$GLOBALS['registered']=[];
$GLOBALS['filter_values']=[];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-litespeed-adapter.php';
$a=new MAD4B_SCP_LiteSpeed_Adapter;
ls_check($a->id()==='litespeed'&&$a->is_available()===true
 &&count($a->ability_names()['admin'])===2,
 'Real first-party LiteSpeed adapter retains exact local cache purge ability names');
$a->register_abilities();
ls_check(count($GLOBALS['registered'])===7,
 'Native read-only diagnostics and separately permitted admin purge are mounted unchanged');
$status=$a->get_optimization_settings();
ls_check($status['state']==='NATIVE_SETTINGS_READ_OBSERVED'
 &&$status['write_contract_certified']===false
 &&$status['mutation_performed']===false
 &&strlen($status['snapshot_sha256'])===64,
 'Native LiteSpeed official settings hook readback does not certify writer or browser execution');
foreach([new WP_Error('native_hook_unknown'),1,'true',null,false] as $untrusted){
 $GLOBALS['filter_available']=$untrusted;
 $scan=$a->get_optimization_settings();
 ls_check(is_array($scan)&&$scan['setting_values_read']===false
  &&$scan['state']==='CAPABILITY_GAP',
  'Unknown native LiteSpeed settings getter cannot claim settings observation');
}
$GLOBALS['filter_available']=true;
$GLOBALS['filter_values']['optm-js_comb']=new WP_Error('vendor_value_unknown');
ls_check(is_wp_error($a->get_optimization_settings()),
 'Error-shaped native LiteSpeed setting must not be hashed as a verified snapshot');
unset($GLOBALS['filter_values']['optm-js_comb']);
$GLOBALS['filter_throw']=true;
ls_check(is_wp_error($a->get_optimization_settings()),
 'Original LiteSpeed filter exception must return fail-closed evidence rather than PHP fatal');
$GLOBALS['filter_throw']=false;
$plan=$a->settings_change_plan(['error_code'=>'script_order']);
ls_check($plan['automatic_write_allowed']===false
 &&$plan['owner_approval_required']===true
 &&$plan['restore_contract_implemented']===false,
 'Original native settings plan remains nonexecuting without a certified rollback');
ls_check($a->verify_optimization()['browser_test_performed']===false,
 'A WordPress PHP request cannot invent completed independent browser acceptance');
$before=count($GLOBALS['purge_actions']);
foreach([
 'http://wayfatours.com/tours/',
 'https://wayfatours.com:8443/tours/',
 'https://wayfatours.com:443/tours/',
 'https://user:token@wayfatours.com/tours/',
 'https://wayfatours.com/tours/#fragment',
 'https://wayfatours.com./tours/',
 'https://evil.test/tours/',
 'javascript:alert(1)'
] as $url){
 $r=$a->purge_url(['url'=>$url]);
 ls_check(is_wp_error($r)&&count($GLOBALS['purge_actions'])===$before,
  'Exact WordPress same-origin purge cannot accept URL downgrade/port/userinfo/fragment/other host');
}
foreach([new WP_Error('bad_url'),[],null,1] as $bad_url){
 ls_check(is_wp_error($a->purge_url(['url'=>$bad_url]))
  &&count($GLOBALS['purge_actions'])===$before,
  'Unknown direct cache purge URL cannot invoke original LiteSpeed action');
}
$valid=$a->purge_url(['url'=>'https://wayfatours.com/tours/']);
ls_check($valid['purged']===true&&count($GLOBALS['purge_actions'])===$before+1
 &&$GLOBALS['purge_actions'][$before]['event']==='litespeed_purge_url',
 'Exact same-site HTTPS path keeps original LiteSpeed URL purge action and audit');
foreach([new WP_Error('queue_unknown'),false,1,'true',null] as $untrusted){
 MAD4B_Safe_Public_HTML_Purge_V1::$result=$untrusted;
 $before_audit=count($GLOBALS['audit']);
 $r=$a->purge_all();
 ls_check(is_wp_error($r)&&count($GLOBALS['audit'])===$before_audit,
  'Unknown original public HTML purge receipt cannot claim queued cache invalidation or success');
}
MAD4B_Safe_Public_HTML_Purge_V1::$result=['status'=>'public_html_purge_queued'];
$queued=$a->purge_all();
ls_check($queued['purged']===false&&$queued['queued']===true
 &&$queued['redis_flushed']===false&&$queued['fastcgi_host_receipt']==='pending',
 'Safe HTML purge is queued only and never claims FastCGI invalidation or Redis flush');
echo 'PASS: LITESPEED_ADAPTER_ORIGINAL '.($GLOBALS['checks']??0).
 ' original provider settings, bounded same-origin purge and queue cases'.PHP_EOL;
