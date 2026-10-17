<?php
/** Native admin browser reports may never mint independent release evidence. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['hybrid_env']='staging';
$GLOBALS['hybrid_access']=true;
$GLOBALS['hybrid_saved']=false;
$GLOBALS['hooks']=array();
function add_action($hook,$cb,$priority=10,$args=1){$GLOBALS['hooks'][$hook]=$cb;}
function current_user_can($cap){return $GLOBALS['hybrid_access'];}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error{private $code;public function __construct($code,$msg=''){$this->code=$code;}public function get_error_code(){return $this->code;}}
class MAD4B_SCP_Native_Cron_Acceptance{public static function staging(){return $GLOBALS['hybrid_env']==='staging';}}
class MAD4B_SCP_Browser_Acceptance_Admin_UI{
 const PAGE_SLUG='mad4b-browser-acceptance';
 public static function public_selection(){return array(
  'preference_valid'=>true,
  'executor'=>'native_hybrid',
  'preference_source'=>$GLOBALS['hybrid_saved']?'operator_saved_or_legacy':'default_observed',
  'configuration_revision'=>$GLOBALS['hybrid_saved']?str_repeat('a',32):'',
 );}
}
function require_hybrid($condition,$reason){
 if(!$condition){fwrite(STDERR,'FAIL '.$reason."\n");exit(1);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-native-hybrid-acceptance.php';
$c='MAD4B_SCP_Native_Hybrid_Acceptance';
require_hybrid(isset($GLOBALS['hooks']['wp_ajax_mad4b_native_hybrid_observe']),'ajax route registered');
require_hybrid(!$c::eligible(),'default view does not execute without saved owner consent');
$GLOBALS['hybrid_saved']=true;
require_hybrid($c::eligible(),'opted Staging owner can request local browser observations');
$GLOBALS['hybrid_env']='production';
require_hybrid(!$c::eligible(),'Production never eligible');
$GLOBALS['hybrid_env']='staging';
$GLOBALS['hybrid_access']=false;
require_hybrid(!$c::eligible(),'non-admin never eligible');
$GLOBALS['hybrid_access']=true;
$sample=array(
 'route_count'=>2,'js_errors'=>0,'dom_ready'=>true,'http_ok'=>true,
 'same_origin'=>true,'canonical_match'=>true,'hreflang_count'=>6,
 'listing_count'=>25,'listing_unique'=>true,'listing_digest'=>str_repeat('a',64),
 'filter_controls'=>5,'filter_attempted'=>true,'filter_ajax_observed'=>true,
 'filter_dataset_changed'=>true,'filter_dataset_digest'=>str_repeat('b',64),
 'duration_ms'=>1800,'blocked_reason'=>'none'
);
$x=$c::normalize($sample);
require_hybrid(!is_wp_error($x) && $x['verdict']==='OBSERVED'
 && $x['filter_parity']==='not_independently_verified'
 && $x['independent_browser_attested']===false
 && $x['release_ready']===false && $x['authorizing']===false,
 'local positive DOM/AJAX observation cannot claim independent browser acceptance');
$bad=$sample;$bad['api_key']='TEST_SECRET';
require_hybrid(is_wp_error($c::normalize($bad)),'secret or arbitrary input rejected');
$bad=$sample;$bad['listing_digest']='unsafe';
require_hybrid(is_wp_error($c::normalize($bad)),'dataset digest exact SHA required');
$bad=$sample;$bad['duration_ms']=36000;
require_hybrid(is_wp_error($c::normalize($bad)),'duration bound enforced');
$bad=$sample;$bad['blocked_reason']='iframe_blocked';
$x=$c::normalize($bad);
require_hybrid(!is_wp_error($x)&&$x['verdict']==='INCOMPLETE','blocked iframe can never be PASS');
$bad=$sample;$bad['js_errors']=2;
$x=$c::normalize($bad);
require_hybrid(!is_wp_error($x)&&$x['verdict']==='INCOMPLETE','observed JS exceptions fail local check');
$bad=$sample;$bad['filter_ajax_observed']=false;
$x=$c::normalize($bad);
require_hybrid($x['filter_parity']==='not_independently_verified','filter flag cannot assert parity');
require_hybrid($x['frontend_functional_ready']===true && $x['filter_functional_ready']===false,'missing AJAX cannot invalidate independent frontend DOM while filter remains incomplete');
$good=$c::normalize($sample);
require_hybrid($good['frontend_functional_ready'] && $good['filter_functional_ready']
 && !$good['staging_acceptance_ready'] && !$good['release_ready'],
 'scoped local subtest can PASS but global decision must check Cron, build and consent');
echo "NATIVE_HYBRID_ACCEPTANCE_RUNTIME_PASS\n";
