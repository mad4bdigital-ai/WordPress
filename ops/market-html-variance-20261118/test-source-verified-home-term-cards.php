<?php
declare(strict_types=1);
define('ABSPATH','/');
$store=[];
function add_action($name,$fn,$priority=10){return true;}
$wpOptions=[];
function get_option($name,$default=null) {global $wpOptions;return $wpOptions[$name]??$default;}
function wp_get_environment_type(){return 'staging';}
function wp_using_ext_object_cache(){return true;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function is_preview(){return false;}
function is_front_page(){global $front;return $front;}
function wp_is_mobile(){global $mobile;return $mobile;}
function wp_json_encode($v){return json_encode($v);}
function wp_parse_url($v,$part){return parse_url($v,$part);}
function get_current_blog_id(){return 1;}
function home_url($path='/'){return 'https://tours.dev.mad4b.com'.$path;}
function apply_filters($hook,$value){return $hook==='wpml_current_language'?'en':$value;}
function get_woocommerce_currency(){return 'USD';}
function wcml_is_multi_currency_on(){global $wcmlOn;return $wcmlOn;}
function mad4b_render_query_market_identity(){global $market;return $market;}
function mad4b_render_query_has_untrusted_market_header() {
 foreach($_SERVER as $name=>$value) {
  if(is_string($name)&&str_starts_with($name,'HTTP_')&&is_scalar($value)
     &&(string)$value!==''&&preg_match('/(?:GEO|COUNTRY|CURRENCY|MARKET|PASSPORT)/i',$name))return true;
 }
 return false;
}
class MAD4B_Experience_Site_Scope_V1{public static function bound(){return true;}}
class MAD4B_Stable_Listing_CSS_Identity{public static function cache_ready(){return true;}}
class WP_Post {
 public $ID;public $post_type;public $post_status;
 function __construct($id,$type){$this->ID=$id;$this->post_type=$type;$this->post_status='publish';}
}
function get_post($id) {
 if($id===25909)return new WP_Post($id,'elementor_library');
 if($id===45927)return new WP_Post($id,'jet-engine');
 return null;
}
function get_post_meta($id,$key,$single=true) {
 global $layoutData;
 if($id===25909 && $key==='_elementor_data')return $layoutData;
 if($id===45927 && $key==='_listing_data')return ['source'=>'terms','tax'=>'destinations'];
 return null;
}

function wp_cache_get($key,$group) {
    global $store;
    return $store[$group][$key]??false;
}
function wp_cache_set($key,$value,$group,$ttl=0){
    global $store;
    if($ttl!==300)throw new RuntimeException('unbounded TTL');
    $store[$group][$key]=$value;
    return true;
}
$root=getenv('MAD4B_SOURCE_CARD_REPO_ROOT') ?: dirname(__DIR__,2);
$shared=getenv('MAD4B_SOURCE_CARD_SHARED_HELPER') ?: $root.'/wp-content/mu-plugins/mad4b-shared-render/public-term-cards.php';
$entry=getenv('MAD4B_SOURCE_CARD_PLUGIN') ?: $root.'/wp-content/mu-plugins/435-mad4b-source-verified-home-term-cards-v1.php';
if(!is_file($shared)||!is_file($entry))throw new RuntimeException('source files missing');
require_once $shared;
require_once $entry;
$cache = 'MAD4B_Verified_Source_Home_Term_Cards_V1';
function check($name,bool $result):void{
    if (!$result) { fwrite(STDERR,"FAIL $name
");exit(2);}
    echo "PASS $name
";
}
$us=str_repeat('a',64);$de=str_repeat('b',64);$key=str_repeat('c',64);
$html='<div class="jet-listing-grid__item"><article class="public-destination">Luxor is an exceptional city of ancient Egypt with cultural monuments.</article></div>';
$other='<div class="jet-listing-grid__item"><article class="public-destination">Alexandria is a remarkable Mediterranean city in Egypt.</article></div>';
check('exact source public term fragment cached',$cache::write($us,$key,$html));
check('exact source public fragment reads',$cache::read($us,$key)===$html);
check('unrendered other market returns no HTML',$cache::read($de,$key)===false);
check('another market independently writes different safe content',$cache::write($de,$key,$other));
check('country partition cannot overwrite original HTML',$cache::read($us,$key)===$html && $cache::read($de,$key)===$other);
check('unknown fragment key gives no result',$cache::read($us,str_repeat('e',64))===false);
check('price bearing HTML denied',!$cache::write($us,$key,$html.'<span class="woocommerce-Price-amount">$ 100</span>'));
check('booking form denied',!$cache::write($us,$key,$html.'<form action="/booking"><input name="nonce"></form>'));
check('visitor market selector denied',!$cache::write($us,$key,$html.'<div data-country="US">Market</div>'));
check('JavaScript and embedded selection denied',!$cache::write($us,$key,$html.'<script>const country="US";</script>'));
check('volatile Elementor CSS identity denied',!$cache::write($us,$key,$html.'<style>.elementor-dcss-1234567890123{display:block}</style>'));
check('invalid market digest denied',!$cache::write('US',$key,$html));
check('invalid object digest denied',!$cache::write($us,'card_10',$html));
$store['mad4b-source-verified-terms-v1'][hash('sha256','market:'.$us.'|'.$key)]=str_repeat('f',64);
check('forged pointer cannot bypass content hash',$cache::read($us,$key)===false);
check('hash-addressed market content remains isolated',$cache::read($de,$key)===$other);
check('missing canary option fails closed',$cache::settings()===[]);
check('missing PHP cookie accepted when browser sent none',
      $cache::synthetic_language_cookie_only([], 'en'));
check('exact WPML bootstrap locale accepted',
      $cache::synthetic_language_cookie_only(['wp-wpml_current_language'=>'en'],'en'));
check('mismatched synthesized locale denied',
      !$cache::synthetic_language_cookie_only(['wp-wpml_current_language'=>'es'],'en'));
check('unexpected visitor preference cookie denied',
      !$cache::synthetic_language_cookie_only(['mad4b_country_selected_v1'=>'US'],'en'));
check('extra cookie denied even when WPML locale matches',
      !$cache::synthetic_language_cookie_only(['wp-wpml_current_language'=>'en','_ga'=>'123'],'en'));
check('non-scalar WPML value denied',
      !$cache::synthetic_language_cookie_only(['wp-wpml_current_language'=>['en']],'en'));
$front=true;$mobile=true;$wcmlOn=true;$market=['country'=>'US','currency'=>'USD'];
$layoutData=json_encode([['id'=>'b25dbff','widgetType'=>'jet-listing-grid','settings'=>['lisitng_id'=>45927],'elements'=>[]]]);
$wpOptions['mad4b_source_home_top_cities_cache_v1']=[
  'contract'=>'mad4b.source.term-card-cache.v1','mode'=>'active',
  'host'=>'tours.dev.mad4b.com','language'=>'en','device'=>'mobile',
  'document_id'=>25909,'widget_id'=>'b25dbff','listing_id'=>45927,
  'elementor_sha256'=>hash('sha256',$layoutData),'ttl'=>300
];
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/','HTTP_HOST'=>'tours.dev.mad4b.com'];
$_COOKIE=['wp-wpml_current_language'=>'en'];
$good=$cache::admission();
check('first-party WCML market plus exact layout admits bounded fragment',
   is_string($good)&&preg_match('/^[a-f0-9]{64}$/D',$good));
$market=['country'=>'DE','currency'=>'USD'];
$deScope=$cache::admission();
check('different verified WCML country gets different cache partition',is_string($deScope)&&$good!==$deScope);
$market=['country'=>'US','currency'=>'USD'];
$_SERVER['HTTP_X_CLIENT_CURRENCY']='EGP';
check('new arbitrary client currency header denied',$cache::admission()===null);
unset($_SERVER['HTTP_X_CLIENT_CURRENCY']);
$_SERVER['HTTP_X_GEO_COUNTRY']='DE';
check('untrusted geo country header denied',$cache::admission()===null);
unset($_SERVER['HTTP_X_GEO_COUNTRY']);
$_SERVER['HTTP_COOKIE']='wp-wpml_current_language=en';
check('even matching on-wire cookie denied',$cache::admission()===null);
unset($_SERVER['HTTP_COOKIE']);
$_COOKIE=['wp-wpml_current_language'=>'en','x_visitor'=>'1'];
check('internal unknown extra cookie denied',$cache::admission()===null);
$_COOKIE=['wp-wpml_current_language'=>'en'];
$mobile=false;
check('desktop request denied by mobile-only feature',$cache::admission()===null);
$mobile=true;
$wpOptions['mad4b_source_home_top_cities_cache_v1']['elementor_sha256']=str_repeat('0',64);
check('changed Elementor template SHA denied',$cache::admission()===null);
$wpOptions['mad4b_source_home_top_cities_cache_v1']['elementor_sha256']=hash('sha256',$layoutData);
$wpOptions['mad4b_source_home_top_cities_cache_v1']['mode']='canary';
check('canary without explicit QA headers denied',$cache::admission()===null);
$_SERVER['HTTP_X_MAD4B_SOURCE_CARDS_QA']='v1';
$_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']='1';
check('canary explicit bypass is first-party market scoped',
    is_string($cache::admission()));
unset($_SERVER['HTTP_X_MAD4B_SOURCE_CARDS_QA'],$_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']);
$wpOptions['mad4b_source_home_top_cities_cache_v1']['mode']='active';
$front=false;
check('non-home route denied',$cache::admission()===null);
echo "SOURCE_VERIFIED_TERM_CARD_CACHE_REGRESSION 33
";
