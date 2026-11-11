<?php
/** Offline native query-310 date AND test; fake WordPress, no database writes. */
define('ABSPATH','/offline');
define('DAY_IN_SECONDS',86400);
class WP_Error {
 private string $code;
 public function __construct($code,$message=''){ $this->code=$code; }
 public function get_error_code():string { return $this->code; }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_doing_ajax(){return true;}
function wp_get_referer(){return false;}
function getSitePress(){return (object)[];}
class NativeDisplayCurrency {
 public array $calls=[];
 public function set_client_currency($currency){$this->calls[]=$currency;}
}
$GLOBALS['woocommerce_wpml']=(object)['multi_currency'=>new NativeDisplayCurrency()];
function is_admin(){return false;}
function get_option($key,$default=null){
 if($key==='mad4b_unified_date_enabled_v1')return 'on';
 if($key==='_wcml_settings')return ['currency_options'=>[
   'USD'=>[], 'EUR'=>[], 'EGP'=>[], 'GBP'=>[]]];
 return $default;
}
$filters=[];
function add_filter($name,$callback,$priority=10,$count=1){global $filters;$filters[$name][$priority][]=$callback;return true;}
function add_action($name,$callback,$priority=10,$count=1){return true;}
function absint($v){return abs((int)$v);}
function wp_unslash($v){return stripslashes($v);}
function sanitize_text_field($v){return trim((string)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_post_type($id){return $id===99?'tours-and-activities':(in_array($id,[101,102],true)?'tour-rates':'not-a-tour');}
function get_post_status($id){return in_array($id,[101,102],true)?'publish':'draft';}
function get_post_meta($id,$key,$single=true){
 $dates=[
  101=>['tour_rate_start_date'=>strtotime('2027-01-01 00:00:00 UTC'),'tour_rate_end_date'=>strtotime('2027-03-01 23:59:59 UTC')],
  102=>['tour_rate_start_date'=>strtotime('2028-01-01 00:00:00 UTC'),'tour_rate_end_date'=>strtotime('2028-03-01 23:59:59 UTC')]
 ];
 return $dates[$id][$key]??null;
}
function shared_product_eligible_tour_rate_ids($tourId){return $tourId===99?[101,102]:[];}
$tour=99;
function shared_product_verified_presentation_tour_id(){global $tour;return $tour;}
function check($ok,$label){if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/presentation/unified-date-presentation-v1.php';
$and='mad4b_unified_date_intersect_post_ids_v1';
check($and([101,102],[])===[101,102],'unconstrained native list can narrow normally');
check($and([101,102],['post__in'=>[]])===[101,102],'empty native list does not deny');
check($and([101,102],['post__in'=>[0]])===[0],'native deny-all remains deny-all');
check($and([101,102],['post__in'=>['0']])===[0],'string zero also remains deny-all');
check($and([101,102],['post__in'=>['-1']])===[0],'negative native id not converted to positive');
check($and([101,102],['post__in'=>[102,700]])===[102],'date narrows existing native IDs');
check($and([101,102],['post__in'=>[700]])===[0],'no intersection fails closed');
$callbacks=$filters['jet-engine/query-builder/types/posts-query/args'][85]??[];
check(count($callbacks)===1,'exact tour Rates query contract registered');
$rateFilter=$callbacks[0];
$_GET=['mad4b_date_mode'=>'fixed','mad4b_date_from'=>'2027-02-18'];
$query=(object)['id'=>310,'query_id'=>'tour_rates'];
check($rateFilter([], $query)['post__in']===[101],'eligible published rate matches travel date');
check($rateFilter(['post__in'=>[102],'tax_query'=>['existing']],$query)['post__in']===[0],
    'native Rate restriction AND the selected travel date');
check($rateFilter(['post__in'=>[0]],$query)['post__in']===[0],
    'upstream native deny remains denied');
check($rateFilter(['post__in'=>[101,102]],(object)['id'=>311,'query_id'=>'tour_rates'])['post__in']===[101,102],
    'unrelated query is not touched');
check($rateFilter(['post__in'=>[101,102]],(object)['id'=>310,'query_id'=>'other'])['post__in']===[101,102],
    'unrelated query_id is not touched');
$tour=0;
check($rateFilter([], $query)['post__in']===[0],'no verified tour identity cannot fetch any Rates');
$tour=99;
$_GET=['mad4b_date_mode'=>'invalid','mad4b_date_from'=>'2027-02-18'];
check($rateFilter([], $query)['post__in']===[0],'malformed date fails closed');
$_GET=[];
check($rateFilter(['post__in'=>[102]],$query)['post__in']===[102],'no selected travel date preserves old Rates');
$_GET=['mad4b_date_mode'=>'fixed','mad4b_date_from'=>'2027-02-18'];
$_POST=['query'=>['mad4b_date_clear'=>'1']];
check(mad4b_unified_date_request_v1()['status']==='absent','explicit native Ajax clear takes priority over stale GET');
check($rateFilter(['post__in'=>[102]],$query)['post__in']===[102],'clear never re-inherits stale referrer date');
$_GET=[];$_POST=['query'=>['mad4b_date_mode'=>'fixed','mad4b_date_from'=>'2027-02-18']];
check($rateFilter([],$query)['post__in']===[101],'date can be passed in native JetSmartFilters query payload');
$_POST=['query'=>['mad4b_date_mode'=>['invalid_shape'],'mad4b_date_from'=>'2027-02-18']];
check($rateFilter([],$query)['post__in']===[0],'malformed nested AJAX payload cannot broaden rates');
$_GET=['mad4b_date_mode'=>'fixed','mad4b_date_from'=>'2028-02-18'];
$_POST=['query'=>['mad4b_date_mode'=>'fixed','mad4b_date_from'=>'2027-02-18']];
check($rateFilter([],$query)['post__in']===[101],
  'current AJAX date beats stale GET and returns only matching Rates');
$_POST=['query'=>['mad4b_date_mode'=>['untrusted_shape'],'mad4b_date_from'=>'2027-02-18']];
check($rateFilter([],$query)['post__in']===[0],
  'invalid AJAX date fails closed instead of falling back to valid stale GET');
unset($_POST['query']);
$_POST=['provider'=>'jet-engine/tour_rates_accordion',
  'query'=>['mad4b_currency'=>'EUR']];
mad4b_unified_date_currency_context_ajax_v1();
check($GLOBALS['woocommerce_wpml']->multi_currency->calls===['EUR'],
  'verified configured EUR is restored as native WCML AJAX display context');
$_POST['provider']='jet-engine/unrelated';
mad4b_unified_date_currency_context_ajax_v1();
check(count($GLOBALS['woocommerce_wpml']->multi_currency->calls)===1,
  'other providers cannot affect display currency');
$_POST['provider']='jet-engine/tour_rates_accordion';
$_POST['query']['mad4b_currency']='BTC';
mad4b_unified_date_currency_context_ajax_v1();
check(count($GLOBALS['woocommerce_wpml']->multi_currency->calls)===1,
  'non-enrolled currencies cannot affect display context');
$_POST['query']['mad4b_currency']=['EUR'];
mad4b_unified_date_currency_context_ajax_v1();
check(count($GLOBALS['woocommerce_wpml']->multi_currency->calls)===1,
  'nested invalid currency type ignored before WCML setter');
$_POST['query']['mad4b_currency']='GBP';
mad4b_unified_date_currency_context_ajax_v1();
check($GLOBALS['woocommerce_wpml']->multi_currency->calls===['EUR','GBP'],
  'second configured display currency is supported without changing rate tables');
$_POST['query']=['mad4b_currency'=>'GBP','unknown_price_override'=>99];
$_GET=[];
check($rateFilter(['post__in'=>[101,102]],$query)['post__in']===[101,102],
  'currency-only native AJAX does not alter rate eligibility or price rows');
unset($_POST['query']);
echo json_encode(['state'=>'PASS','cases'=>27,'rate_query_id'=>310,
 'upstream_filters_preserved'=>true,'payment_mutation'=>false,'live_browser_test'=>false,
 'wordpress_runtime_loaded'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
