<?php
global $wpdb;
$mode=shared_product_mode_policies();
$priceSource=function_exists('shared_product_price_source_policy')
    ?shared_product_price_source_policy('tour'):null;
$ids=$wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p
 JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='related_tour_id'
 WHERE p.post_type='tour-rates' AND p.post_status='publish'
 ORDER BY p.ID DESC LIMIT 8");
$rows=[];
foreach(array_slice($ids,0,5) as $id){
 $parent=(int)get_post_meta((int)$id,'related_tour_id',true);
 $strategy=function_exists('shared_product_resolve_strategy')?shared_product_resolve_strategy((int)$id):null;
 $financial=function_exists('shared_product_financial_policy_resolve')?shared_product_financial_policy_resolve((int)$id):null;
 $display=function_exists('pricing_rate_presentation')?pricing_rate_presentation((int)$id):null;
 $normal=static function($x) {
    if(is_wp_error($x))return ['error'=>$x->get_error_code()];
    return $x;
 };
 $results=['rate'=>(int)$id,'tour'=>$parent,'strategy'=>$normal($strategy),
   'financial'=>$normal($financial),'presentation'=>$normal($display)];
 $rows[]=['rate'=>(int)$id,'tour'=>$parent,'sha256'=>hash('sha256',wp_json_encode($results))];
}
$out=['contract'=>'mad4b.pricing-cache-native-parity.v1',
 'modes_sha256'=>hash('sha256',wp_json_encode($mode)),
 'price_source_sha256'=>hash('sha256',wp_json_encode(is_wp_error($priceSource)?['error'=>$priceSource->get_error_code()]:$priceSource)),
 'modes'=>is_array($mode)?count($mode):null,'rates'=>$rows,
 'has_price_info'=>function_exists('pricing_rate_presentation')];
echo 'MAD4B_NATIVE_PRICE_PARITY='.wp_json_encode($out,JSON_UNESCAPED_SLASHES)."\n";
