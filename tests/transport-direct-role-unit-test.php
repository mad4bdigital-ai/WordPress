<?php
defined('ABSPATH')||exit;
require_once WP_CONTENT_DIR.'/mu-plugins/mad4b-transport-start-price-roles-v1.php';
$ids=[54552,54553];$out=['site'=>home_url(),'tests'=>[]];
foreach($ids as $id){
    $base=MAD4B_Transport_Start_Price_Roles_V1::inspect($id);
    if(($base['mode']??'')!=='indicative_only' || $base['planning_cost_source']!==null)
        throw new RuntimeException('legacy_no_cost_not_preserved_'.$id);
    $overrides=[
        MAD4B_Transport_Start_Price_Roles_V1::KEY=>'both',
        'trans_start_price'=>'120','trans_base_currency'=>'egp','trans_unit_type'=>'per_person'
    ];
    $mock=static function($value,$post,$key,$single)use($id,$overrides){
        if($post===$id && array_key_exists($key,$overrides))return $overrides[$key];
        return $value;
    };
    add_filter('get_post_metadata',$mock,10,4);
    $both=MAD4B_Transport_Start_Price_Roles_V1::inspect($id);
    remove_filter('get_post_metadata',$mock,10);
    if($both['mode']!=='both'||$both['direct']['show_as_indicative']!==true||
        $both['direct']['offer_as_planning_cost']!==true||
        $both['planning_cost_source']!=='direct'||
        $both['planning_cost_unit']!=='per_person'||
        $both['planning_cost_currency']!=='EGP'||
        $both['planning_cost_amount']!==120.0||
        $both['tour_rate_generation_authorized']!==false ||
        $both['commercial_price_or_checkout_authorized']!==false)
        throw new RuntimeException('both_mode_wrong_'.$id.':'.wp_json_encode($both));
    $out['tests'][]=['train'=>$id,'mode'=>'both','price'=>120,'source'=>$both['planning_cost_source'],
        'authorized'=>false,'passed'=>true];
    $role=static function($value,$post,$key,$single)use($id){
        if($post!==$id)return $value;
        if($key===MAD4B_Transport_Start_Price_Roles_V1::KEY)return 'cost_reference_only';
        if($key==='trans_start_price')return '120';
        return $value;
    };
    add_filter('get_post_metadata',$role,10,4);
    $cost=MAD4B_Transport_Start_Price_Roles_V1::inspect($id);
    remove_filter('get_post_metadata',$role,10);
    if($cost['mode']!=='cost_reference_only'||
      $cost['direct']['show_as_indicative']!==false ||
      $cost['planning_cost_source']!=='direct')
        throw new RuntimeException('cost_mode_wrong_'.$id);
    $out['tests'][]=['train'=>$id,'mode'=>'cost_reference_only','source'=>$cost['planning_cost_source'],
        'authorized'=>false,'passed'=>true];
    $end=MAD4B_Transport_Start_Price_Roles_V1::inspect($id);
    if($end!==$base)throw new RuntimeException('mock_leaked_or_DB_was_mutated_'.$id);
}
$source=shared_product_resolve_price_sources([
 'direct'=>['amount'=>120,'currency'=>'EGP'],
 'rate'=>['amount'=>95,'currency'=>'EGP']],'transport');
$direct=shared_product_resolve_price_sources([
 'direct'=>['amount'=>120,'currency'=>'EGP']],'transport');
if(is_wp_error($source)||$source['source']!=='rate'||
   is_wp_error($direct)||$direct['source']!=='direct')
    throw new RuntimeException('weight_then_fallback_not_effective');
$out['tests'][]=['mode'=>'rate_and_direct','winner'=>$source['source'],'authorized'=>false,'passed'=>true];
$out['tests'][]=['mode'=>'direct_fallback_only','winner'=>$direct['source'],'authorized'=>false,'passed'=>true];
$out['all_pass']=true;$out['test_count']=count($out['tests']);
$out['live_post_price_meta_modified']=false;$out['checkout_authorized']=false;
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;

