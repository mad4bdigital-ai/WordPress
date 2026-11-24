<?php
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['values']=['mad4b_mixed_family_public_islands_mode_v1'=>'off'];
function add_action(...$args){}
function get_option($key,$default=null){return $GLOBALS['values'][$key]??$default;}
function get_post_status($id){return in_array($id,[15181,15188],true)?'publish':'draft';}
function get_post_type($id){return in_array($id,[15181,15188],true)?'jet-engine':null;}
function get_post_meta($id,$key,$single=true){if($key==='_listing_data')return [
 'source'=>$id===15181?'posts':'repeater','post_type'=>'tours-and-activities'
];return null;}
function absint($x){return abs((int)$x);}
function fail($good,$name){if(!$good)throw new RuntimeException('FAILED '.$name);echo 'PASS '.$name.PHP_EOL;}
require $argv[1];
$c='MAD4B_Mixed_Family_Public_Islands_V1';
fail($c::mode()==='off','Default off');
$GLOBALS['values']['mad4b_mixed_family_public_islands_mode_v1']='canary';
fail($c::mode()==='off','canary requires exact header');
$_SERVER['HTTP_X_MAD4B_MIXED_FAMILY_CANARY']='mixed-20261124';
fail($c::mode()==='canary','Exact canary');
$tree=['id'=>'409e1bc','elType'=>'container','elements'=>[
 ['id'=>'title001','elType'=>'widget','widgetType'=>'heading','elements'=>[]],
 ['id'=>'sub1111','elType'=>'container','elements'=>[
   ['id'=>'list001','elType'=>'widget','widgetType'=>'jet-listing-grid','settings'=>['lisitng_id'=>'15181']],
   ['id'=>'list002','elType'=>'widget','widgetType'=>'jet-listing-grid','settings'=>['lisitng_id'=>'15188']]]]
]];
$root=['listings'=>[15181,15188]];
fail($c::public_structure($tree,$root),'Tour public Include/Exclude subtree approved structurally');
fail($c::tree_find([$tree],'list002')['id']==='list002','Nested subtree discovery');
$bad=$tree;
$bad['elements'][1]['elements'][1]['widgetType']='fluent-form-widget';
fail(!$c::public_structure($bad,$root),'Fluent Form always live');
$bad=$tree;
$bad['elements'][1]['elements'][]=['id'=>'rate001','elType'=>'widget','widgetType'=>'jet-smart-filters-select'];
fail(!$c::public_structure($bad,$root),'Rate filters always live');
$bad=$tree;
$bad['elements'][1]['elements'][1]['settings']['lisitng_id']=99999;
fail(!$c::public_structure($bad,$root),'Unknown listing cannot become public island');
$html='<div class="jet-listing-grid">'.str_repeat('<div class="jet-listing-grid__item">Includes airport transfer and tours. Our payment policy is flexible.</div>',14).'</div>';
fail($c::safe_html($html),'Ordinary payment language allowed');
foreach(['<input name="email">','<form>','<script>','<div data-price="199">','<div data-session="X">','<span class="woocommerce-Price-amount">','123 USD'] as $s)fail(!$c::safe_html($html.$s),'Live data not snapshotted '.substr($s,0,30));
fail(!$c::safe_html('<div>Not a public listing</div>'),'Unknown presentation stays native');
echo 'ALL_MIXED_PUBLIC_FAMILY_TESTS_PASS'.PHP_EOL;
