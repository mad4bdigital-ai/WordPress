<?php
/** Hermetic structural partition and fail-closed contract for Tour snapshots. */
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['cfg']=[
 'mad4b_compiled_tour_family_v1'=>[
  'contract'=>'mad4b.template-family-public-snapshot.v1',
  'site_host'=>'example.test','environment'=>'staging','status'=>'approved',
  'family_document'=>24784,'widget_id'=>'6190bab','content_document'=>48366,
  'family_sha256'=>str_repeat('a',64),'content_sha256'=>str_repeat('b',64)
 ],
 'mad4b_compiled_tour_family_mode_v1'=>'off'
];
function add_action(...$args): void{}
function get_option($key,$default=null){return $GLOBALS['cfg'][$key]??$default;}
function home_url($path=''): string{return 'https://example.test'.$path;}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function wp_get_environment_type(){return $GLOBALS['environment']??'staging';}
function absint($id){return abs((int)$id);}
function get_post_status($id){return (int)$id===45929?'publish':'draft';}
function get_post_type($id){return (int)$id===45929?'jet-engine':null;}
function get_post_meta($id,$key,$single=true){
 if((int)$id===45929 && $key==='_listing_data')return ['source'=>'terms','tax'=>'destinations'];
 return '';
}
function check(bool $test,string $msg):void{if(!$test)throw new RuntimeException('FAIL '.$msg);echo 'PASS '.$msg.PHP_EOL;}
$_SERVER['REQUEST_METHOD']='GET';
require $argv[1]??'';
$class='MAD4B_Compiled_Tour_Family_V1';
check($class::mode()==='off','default disabled');
$GLOBALS['cfg']['mad4b_compiled_tour_family_mode_v1']='canary';
check($class::mode()==='off','canary denied without exact header');
$_SERVER['HTTP_X_MAD4B_COMPILED_TOUR_CANARY']='invalid-token';
check($class::mode()==='off','incorrect token denied');
$_SERVER['HTTP_X_MAD4B_COMPILED_TOUR_CANARY']='family-20261124';
check($class::mode()==='canary','staging exact canary enabled');
$GLOBALS['environment']='invalid';
check($class::mode()==='off','unregistered environment denied');
$GLOBALS['environment']='staging';
check($class::config()['content_document']===48366,'manifest recognized');
$base='<div class="elementor-template">'.str_repeat('<div class="jet-listing-dynamic-post-122">Public Cairo / Luxor</div>',100).'</div>';
check($class::safe_html($base),'noncommercial public listing accepted');
foreach(['payment terms','Price comparison guide','Checkout advice','Shipping information','billing information','USD and EUR currency information','How to book a tour'] as $phrase) {
 check($class::safe_html($base.$phrase),'static copy accepted: '.$phrase);
}
foreach([
 '<script>alert(1)</script>','<form></form>','<input name="price">',
 '<iframe src="about:blank"></iframe>','<div data-price="999"></div>',
 '<div data-nonce="secret"></div>','<div data-booking="live"></div>',
 '<div data-rate-id="57562"></div>','<button onclick="pay()"></button>',
 '<a href="/checkout/?_wpnonce=123">Live Checkout</a>',
 '<div class="fluentform"></div>','<span class="woocommerce-Price-amount"></span>',
 ' 123.23 USD','From $123','€ 2999','E£ 499',
 '<div id="personal" data-session="123"></div>'
] as $bad) {
 $classification=$class::classify_html($base.$bad);
 check($classification['kind']==='native_dynamic','live markup stays native: '.substr($bad,0,45));
}
check(!$class::safe_html('<div class="elementor-template">Only text</div>'),'missing listing cannot snapshot');
check(!$class::safe_html(str_repeat($base,70)),'oversized HTML native');
$public=[[
 'id'=>'parent1','elType'=>'container','elements'=>[
  ['id'=>'heading1','elType'=>'widget','widgetType'=>'text-editor',
   'settings'=>['editor'=>'Price, Payment and Checkout information are public page text']],
  ['id'=>'list001','elType'=>'widget','widgetType'=>'jet-listing-grid',
   'settings'=>['lisitng_id'=>'45929']]
 ]
]];
check($class::public_template_structure(json_encode($public)),'approved public destination listing with payment-themed text allowed');
$nonpublic=$public;
$nonpublic[0]['elements'][1]['widgetType']='fluent-form-widget';
check(!$class::public_template_structure(json_encode($nonpublic)),'Fluent Form widget remains separate live island');
$nonpublic=$public;
$nonpublic[0]['elements'][1]['settings']['lisitng_id']='98765';
check(!$class::public_template_structure(json_encode($nonpublic)),'unapproved listing cannot be public snapshot');
$nonpublic=$public;
$nonpublic[0]['elements'][1]['widgetType']='shortcode';
check(!$class::public_template_structure(json_encode($nonpublic)),'unknown WordPress shortcode stays live');
check(!$class::eligible(),'no snapshot without captured published canonical Tour');
$GLOBALS['cfg']['mad4b_compiled_tour_family_mode_v1']='on';
check($class::mode()==='on' && !$class::eligible(),'mode on cannot bypass route');
class DummyWidgetManager {
 public array $ops=[];
 function unregister($name){$this->ops[]='remove '.$name;}
 function register($obj){$this->ops[]='add '.get_class($obj);}
}
$m=new DummyWidgetManager();
$class::register_widget($m);
check($m->ops===[],'global Elementor registry untouched outside qualified Tour');
echo 'ALL_STRUCTURAL_PUBLIC_SNAPSHOT_TESTS_PASS'.PHP_EOL;
