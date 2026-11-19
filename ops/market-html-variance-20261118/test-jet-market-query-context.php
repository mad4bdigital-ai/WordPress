<?php
/** Independent price-safe JetEngine query grouping fixture. */
define('ABSPATH','/');
function add_action($hook,$callback,$priority=10,$argc=1){return true;}
function home_url($path='/'){return 'https://wayfatours.com'.$path;}
function wp_parse_url($url,$component=null){return parse_url($url,$component);}
function apply_filters($key,$value){return $GLOBALS['locale'];}
function wp_json_encode($data){return json_encode($data);}
function get_woocommerce_currency(){return $GLOBALS['currency'];}
function get_option($key,$default=null){return $GLOBALS['guard']??$default;}
function is_user_logged_in(){return $GLOBALS['logged_in'];}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
class MAD4B_Country_Switcher {
 public static function saved_country(){return $GLOBALS['valid_market']?$GLOBALS['market']:'';}
}
class Travel_Booking_Traveller_Nationality_Switcher {
 public static function selected_manual(){return $GLOBALS['passport'];}
}
class QueryMock {
 public $cache_query=true,$cache_group='jet-engine',$cache_expires=0;
 public $final_query=['post_type'=>'tours-and-activities','post_status'=>'publish'];
 public function get_query_type(){return 'posts';}
}
require_once '/tmp/mad4b-market-query-lint.php';
$GLOBALS['locale']='en';$GLOBALS['currency']='USD';$GLOBALS['valid_market']=true;
$GLOBALS['market']='US';$GLOBALS['passport']='EG';
$GLOBALS['logged_in']=false;$GLOBALS['admin']=false;$GLOBALS['ajax']=false;$GLOBALS['guard']='gen1';
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/tours-and-activities/'];
$_REQUEST=[];$_COOKIE=['mad4b_country_selected_v1'=>'first-party-hmac-verified'];
function test_case($name,$yes){if(!$yes){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(3);}echo 'PASS '.$name.PHP_EOL;}
function check_query(){ $q=new QueryMock();MAD4B_Wayfa_Jet_Query_Market_Cache_V1::configure($q);return $q; }
$baseline=check_query();
test_case('signed post queries reuse bounded object cache',str_starts_with($baseline->cache_group,'mad4b-jet-public-') && $baseline->cache_expires===90);
test_case('same country language currency produces stable cache scope',
    check_query()->cache_group===$baseline->cache_group);
$GLOBALS['market']='DE';$country=check_query();
test_case('different verified country cannot share results',$country->cache_group!==$baseline->cache_group);
$GLOBALS['market']='US';$GLOBALS['locale']='es';$es=check_query();
test_case('different WPML language isolated',$es->cache_group!==$baseline->cache_group);
$GLOBALS['locale']='en';$GLOBALS['currency']='EUR';$eur=check_query();
test_case('different WCML currency isolated',$eur->cache_group!==$baseline->cache_group);
$GLOBALS['currency']='USD';
$_COOKIE['travel_booking_nationality_preference_v1']='separate-first-party-hmac';
$passport=check_query();
test_case('passport-specific cache isolated',$passport->cache_group!==$baseline->cache_group);
unset($_COOKIE['travel_booking_nationality_preference_v1']);
$_REQUEST=['taxonomy'=>'destination-luxor'];$filter=check_query();
test_case('read-only filter state scoped',$filter->cache_group!==$baseline->cache_group);
$_REQUEST=['tour_date'=>'2027-01-10'];
test_case('date selections are never query-cached',check_query()->cache_query===false);
$_REQUEST=['price_range'=>'100-200'];
test_case('financial filters always live',check_query()->cache_query===false);
$_REQUEST=['action'=>'jet_smart_filters','region'=>'luxor'];
$GLOBALS['ajax']=true;$ajax=check_query();
test_case('safe anonymous JetSmartFilters query scoped 30s',
    $ajax->cache_query && $ajax->cache_expires===30);
$GLOBALS['ajax']=false;$_REQUEST=[];
$GLOBALS['valid_market']=false;
test_case('invalid signed market disables object cache',check_query()->cache_query===false);
$GLOBALS['valid_market']=true;
$_COOKIE['wp_woocommerce_session_mock']='cart';
test_case('WooCommerce session never receives public query cache',check_query()->cache_query===false);
unset($_COOKIE['wp_woocommerce_session_mock']);
$_COOKIE['unknown_personal_cookie']='opaque';
test_case('unknown visitor cookie fails closed',check_query()->cache_query===false);
unset($_COOKIE['unknown_personal_cookie']);
$GLOBALS['logged_in']=true;
test_case('logged-in WordPress request fails closed',check_query()->cache_query===false);
$GLOBALS['logged_in']=false;
$_REQUEST=[];
$_SERVER['REQUEST_METHOD']='POST';
test_case('guest form POST is never query-cached',check_query()->cache_query===false);
$_SERVER['REQUEST_METHOD']='GET';
$GLOBALS['guard']='gen2';
test_case('content dependency guard invalidates old query scope',check_query()->cache_group!==$baseline->cache_group);
$GLOBALS['guard']='gen1';
$_REQUEST=['_wpnonce'=>'nonce-user-one','woocommerce-login-nonce'=>'public'];
test_case('public Woo nonces do not fragment shared query scope',
    check_query()->cache_group===$baseline->cache_group);
$_REQUEST=[];
$_SERVER['REQUEST_URI']='/checkout/';
test_case('payment route never receives cached public query',check_query()->cache_query===false);
$_SERVER['REQUEST_URI']='/tours-and-activities/';
$q=new QueryMock();$q->final_query=['meta_query'=>[['key'=>'tour_rate','value'=>200]]];
MAD4B_Wayfa_Jet_Query_Market_Cache_V1::configure($q);
test_case('rate-dependent query is never object cached',$q->cache_query===false);
echo 'JET_VERIFIED_MARKET_QUERY_REGRESSIONS 19'.PHP_EOL;
