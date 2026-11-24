<?php
/** Hermetic fail-closed contract for compiled Elementor public Tour snapshots. */
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
$valid='<div class="elementor-template">'.str_repeat('<div class="jet-listing-dynamic-post-122">Public Cairo / Luxor</div>',100).'</div>';
check(strlen($valid)>5000 && $class::safe_html($valid),'noncommercial term listing content qualified');
foreach(['<script>','<form>','<input>',' nonce',' data-price=',' 123.23 USD','checkout','payment','billing','woocommerce-cart'] as $unsafe) {
 check(!$class::safe_html($valid.$unsafe),'unsafe output denied: '.trim($unsafe));
}
check(!$class::safe_html('<div class="elementor-template">Only text</div>'),'missing public term items denied');
check(!$class::safe_html(str_repeat($valid,70)),'oversized HTML fails closed');
check(!$class::eligible(),'cannot cache without captured public canonical Tour');
$GLOBALS['cfg']['mad4b_compiled_tour_family_mode_v1']='on';
check($class::mode()==='on' && !$class::eligible(),'mode on still cannot bypass missing route');
class DummyWidgetManager {
 public array $ops=[];
 function unregister($name){$this->ops[]='remove '.$name;}
 function register($obj){$this->ops[]='add '.get_class($obj);}
}
$m=new DummyWidgetManager();
$class::register_widget($m);
check($m->ops===[],'global Elementor registry untouched on non-Tour request');
echo 'ALL_COMPILED_TOUR_FAMILY_GATES_PASS'.PHP_EOL;
