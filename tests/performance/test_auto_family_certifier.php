<?php
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['opts']=['mad4b_auto_public_family_mode_v1'=>'off'];
$GLOBALS['posts']=[];
function get_option($k,$fallback=null){return $GLOBALS['opts'][$k]??$fallback;}
function home_url($p=''){return 'https://example.test'.$p;}
function wp_parse_url($v,$c){return parse_url($v,$c);}
function wp_get_environment_type(){return 'staging';}
function absint($x){return abs((int)$x);}
function get_post_meta($id,$name,$single=true){
 if($name==='_elementor_data')return $GLOBALS['posts'][(int)$id]['data']??'';
 if($name==='_listing_data')return $GLOBALS['posts'][(int)$id]['listing']??[];
 return '';
}
function get_post_status($id){return $GLOBALS['posts'][(int)$id]['status']??'draft';}
function get_post_type($id){return $GLOBALS['posts'][(int)$id]['type']??'post';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function assertTrue(bool $x,string $name){if(!$x)throw new RuntimeException('FAILED '.$name);echo 'PASS '.$name.PHP_EOL;}
require $argv[1];
$c='MAD4B_Auto_Public_Family_V1';
$inside=[
 ['id'=>'abc1234','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Payment policy is flexible']],
 ['id'=>'f987aaa','elType'=>'widget','widgetType'=>'jet-listing-dynamic-field',
    'settings'=>['dynamic_field_source'=>'meta','dynamic_field_post_meta'=>'_description']]
];
$public=['id'=>'abcdef0','elType'=>'container','elements'=>$inside];
$tree=[$public,['id'=>'a0b1c2d','elType'=>'container','elements'=>[
 ['id'=>'1234567','elType'=>'widget','widgetType'=>'jet-smart-filters-select']
]]];
$source=wp_json_encode($tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$GLOBALS['posts'][24784]=['data'=>$source,'status'=>'publish','type'=>'elementor_library'];
$GLOBALS['opts']['mad4b_auto_public_family_v1']=[
 'contract'=>'mad4b.elementor-auto-public-family.v1','site_host'=>'example.test',
 'environment'=>'staging','status'=>'approved',
 'families'=>[['id'=>24784,'sha256'=>hash('sha256',$source)]]
];
assertTrue($c::mode()==='off','Off by default');
$GLOBALS['opts']['mad4b_auto_public_family_mode_v1']='canary';
assertTrue($c::root_for(24784,'abcdef0')===null,'Denied outside canary');
$_SERVER['HTTP_X_MAD4B_AUTO_FAMILY_CANARY']='auto-family-20261124';
assertTrue($c::mode()==='canary','Exact canary');
$found=$c::root_for(24784,'abcdef0');
assertTrue(is_array($found)&&($found['type']??'')==='auto_public_v1','Discovers public root without manually listing its ID');
assertTrue($c::root_for(24784,'a0b1c2d')===null,'Live rate filter is not certified');
assertTrue($c::validate($public,$found),'Replay validates exact discovered public subtree');
$bad=$public;
$bad['elements'][]=['id'=>'ededede','elType'=>'widget','widgetType'=>'fluent-form-widget'];
assertTrue(!$c::analyze_node($bad)['eligible'],'Reject nested Form6');
$bad=$public;
$bad['elements'][1]['settings']['dynamic_field_post_meta']='user_email';
assertTrue(!$c::analyze_node($bad)['eligible'],'Reject user/sensitive dynamic metadata');
$bad=$public;
$bad['elements'][0]['settings']['__dynamic__']=['title'=>'[elementor-tag name="jet-query-count"]'];
assertTrue(!$c::analyze_node($bad)['eligible'],'Reject uncontrolled query-count tags');
$bad=$public;
$bad['settings']['jedv_enabled']='yes';
assertTrue(!$c::analyze_node($bad)['eligible'],'Reject stateful descendant display conditions');
$basic='<div class="elementor-widget-container">'.str_repeat('Description text, payment info and attractions. ',9).'</div>';
assertTrue($c::safe_html($basic),'Allows ordinary public prose');
foreach(['<form>', '<script>', ' data-nonce="x"',' data-user="3"','123 USD','€ 500','class="fluentform"'] as $s)
 assertTrue(!$c::safe_html($basic.$s),'Reject actual live DOM/state '.substr($s,0,22));
assertTrue(!in_array(0,array_keys($c::status()),true),'Only specified family audited');
echo 'ALL_AUTO_FAMILY_CERTIFIER_TESTS_PASS'.PHP_EOL;
