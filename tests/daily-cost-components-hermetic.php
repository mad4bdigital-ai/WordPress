<?php
define('ABSPATH','/');
class WP_Error{function __construct(public $code){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(...$args){}
function sanitize_text_field($v){return trim(strip_tags($v));}
function get_post_type($id){return $id===11?'property-rates':($id===31?'attractions':'tours-and-activities');}
function get_post_status($id){return 'publish';}
function get_post_meta($id,$key,$single){return $id===41?'activity':'tour';}
function current_user_can(...$args){return true;}
function term_exists($id,$tax){return $id===23?['term_id'=>23]:null;}
class MAD4B_Cost_Source_Registry_V1{static function mappings(){return ['cost'=>['kind'=>'post_type','slug'=>'property-rates']];}}
require __DIR__.'/../wp-content/mu-plugins/mad4b-daily-cost-components-v1.php';
$c='MAD4B_Daily_Cost_Components_V1';$s=['days'=>['item-0'=>['key'=>'item-0'],'item-1'=>['key'=>'item-1']]];
$r=['day_key'=>'item-0','component_key'=>'road1','component_type'=>'transportation','label'=>'Airport transfer'];
$n=0;function ck($v,$why){global $n;if(!$v)throw new RuntimeException($why);$n++;}
$v=$c::normalize($r,$s);ck(!is_wp_error($v)&&$v['inclusion']==='included','native requirement');
ck(!is_wp_error($c::normalize(array_replace($r,['component_key'=>'road2']),$s)),'multiple services per day');
foreach(['day_key'=>'fake','component_key'=>'bad key','component_type'=>'unknown','quantity_override'=>0,'tier_id'=>99,'source_ref'=>11,'requirement'=>'approved','selection_mode'=>'publish','label'=>''] as $k=>$bad)ck(is_wp_error($c::normalize(array_replace($r,[$k=>$bad]),$s)),'reject '.$k);
ck(!is_wp_error($c::normalize(array_replace($r,['component_type'=>'registry:cost','source_ref'=>11,'tier_id'=>23]),$s)),'registered source and tier');
ck(is_wp_error($c::normalize(array_replace($r,['component_type'=>'registry:cost','source_ref'=>22]),$s)),'wrong source type');
ck($c::normalize(array_replace($r,['source_row_key'=>'fee-1']),$s)['source_row_key']==='fee-1','row key');
$ticket=array_replace($r,['component_type'=>'tickets','source_type'=>'attraction','source_ref'=>31]);
ck(!is_wp_error($c::normalize($ticket,$s)),'direct attraction');
ck(!is_wp_error($c::normalize(array_replace($ticket,['source_type'=>'activity','source_ref'=>41]),$s)),'activity only');
ck(is_wp_error($c::normalize(array_replace($ticket,['source_type'=>'activity','source_ref'=>42]),$s)),'nonactivity rejected');
ck(is_wp_error($c::normalize(array_replace($ticket,['source_ref'=>41]),$s)),'wrong attraction type rejected');
echo "PASS $n/$n daily component validation\n";
