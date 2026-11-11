<?php
/** Source-only WPML transport categories, not a selector or booking engine. */
define('ABSPATH','/offline');
define('MAD4B_COMMERCE_RUNTIME_ENABLED',true);
$GLOBALS['lang']='es';
$GLOBALS['translated']=true;
$GLOBALS['fakeTermOnPost']=[1598=>true,3001=>true,578=>false,2001=>false];
$GLOBALS['fakeTerms']=[
 1598=>(object)['term_id'=>1598,'parent'=>0],
 578=>(object)['term_id'=>578,'parent'=>0],
 3001=>(object)['term_id'=>3001,'parent'=>1598],
 2001=>(object)['term_id'=>2001,'parent'=>578]];
class WP_Term {
 public function __construct(public int $term_id,public int $parent){}
}
function wp_get_environment_type(){return 'staging';}
function get_post_type($id){return $id===702?'transportations':'';}
function get_post_status($id){return $id===702?'publish':'draft';}
function get_post_meta($id,$key,$single=true){
 if($id!==702)return '';
 return ['trans_available_status'=>'1','trans_mode'=>578,'trans_type'=>2001][$key]??'';
}
function has_term($id,$taxonomy,$post){
 return $taxonomy==='transportation-types'&&$post===702
    &&!empty($GLOBALS['fakeTermOnPost'][$id]);
}
function get_term($id,$tax) {
 $row=$GLOBALS['fakeTerms'][$id]??null;
 return $row ? new WP_Term($row->term_id,$row->parent):null;
}
function apply_filters($hook,$value,...$args) {
 if($hook==='wpml_current_language')return $GLOBALS['lang'];
 if($hook==='wpml_object_id'){
  if(!$GLOBALS['translated'])return null;
  return [578=>1598,2001=>3001][$value]??null;
 }
 return $value;
}
function absint($x){return abs((int)$x);}
function check($passed,$label) {
 if(!$passed){fwrite(STDERR,"FAIL ".$label.PHP_EOL);exit(2);}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/adapters/presentation-transport-context-v1.php';
$C=Shared_Product_Presentation_Transport_Context_v1::class;
$r=$C::candidate(702,'train');
check(($r['ok']??false)===true,'translated term mode and subtype resolve');
check(($r['booking_authorized']??true)===false,'translated category cannot authorize booking');
check(($r['price_verified']??true)===false,'translated category cannot authorize rate');
check(($r['inventory_verified']??true)===false,'translated category cannot authorize stock');
check(($r['mode_term_id']??0)===578,'canonical mode identity kept for source policy');
$r=$C::candidate(702,'air');
check(($r['ok']??false)===false,'different transport category does not inherit train');
$GLOBALS['fakeTermOnPost'][3001]=false;
$r=$C::candidate(702,'train');
check(($r['ok']??false)===false,'subtype not present on post rejected');
$GLOBALS['fakeTermOnPost'][3001]=true;
$GLOBALS['fakeTerms'][3001]=(object)['term_id'=>3001,'parent'=>9999];
$r=$C::candidate(702,'train');
check(($r['ok']??false)===false,'wrong subtype hierarchy denied');
$GLOBALS['fakeTerms'][3001]=(object)['term_id'=>3001,'parent'=>1598];
$GLOBALS['translated']=false;
$GLOBALS['fakeTermOnPost'][578]=true;
$GLOBALS['fakeTermOnPost'][2001]=true;
$r=$C::candidate(702,'train');
check(($r['ok']??false)===true,'translate-or-fallback canonical meta taxonomy accepted');
$GLOBALS['fakeTermOnPost'][578]=false;
$r=$C::candidate(702,'train');
check(($r['ok']??false)===false,'untranslated mode must exist on actual post');
echo json_encode(['state'=>'PASS','cases'=>10,'pricing_authorized'=>false,
 'booking_authorized'=>false,'site_modified'=>false,'live_browser_verified'=>false]).PHP_EOL;
