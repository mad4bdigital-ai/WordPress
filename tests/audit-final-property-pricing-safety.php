<?php
defined('ABSPATH')||exit;
$ids=[36084,36246,36282,36127,36226,36107,36186];
$rows=[];$tierAssign=0;
foreach($ids as $id){
 $terms=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
 $levels=wp_get_object_terms($id,'package_levels',['fields'=>'ids']);
 $ref=get_post_meta($id,'_mad4b_resort_public_offer_reference_v1',true);
 if(!in_array('resort',$terms,true)||!in_array('hotel',$terms,true)||
    !is_array($ref)||($ref['contract']??'')!=='mad4b.resort-public-web-offer.planning-reference.v1'||
    ($ref['supplier_agreement_approved']??true)!==false||
    ($ref['accepted_for_checkout']??true)!==false||
    get_post_meta($id,'prop_start_price',true)!==''||
    get_post_meta($id,'prop_use_in_tour_rate_generation',true)!=='')
     throw new RuntimeException('resort_web_offer_native_invariant_failed_'.$id);
 if($levels)$tierAssign++;
 $rows[]=['id'=>$id,'tiers'=>$levels,'retail_offer'=>$ref['amount_usd'],'checked'=>true];
}
if($tierAssign!==1)throw new RuntimeException('unexpected_tier_mutation');
$guard=[];
foreach([57562,60623,60629] as $tour){
 $t=MAD4B_Company_Package_Levels_V1::resolve($tour);
 $tier=$t['effective_tier_ids'][0]??0;
 $report=MAD4B_Package_Stage_Rate_Guard_V1::review($tour,$tier);
 $guard[$tour]=['tier'=>$tier,'commercial_ready'=>$report['ready'],
    'checkout_ready'=>$report['checkout_ready'],
    'blockers'=>count($report['blockers'])];
 if($report['checkout_ready']!==false)
    throw new RuntimeException('checkout_open_'.$tour);
}
$margin=class_exists('MAD4B_Company_Global_Profit_V1',false)
 ?MAD4B_Company_Global_Profit_V1::current()['percent']??null:null;
$out=['site'=>home_url(),'resorts'=>$rows,'resort_count'=>count($rows),
 'tier_assigned_resort_count'=>$tierAssign,'native_guard'=>$guard,
 'margin_percent'=>$margin,
 'booking_enabled'=>get_option('etg-dynamic-pricing-controls_booking_enabled'),
 'tour_rate_posts_modified_by_this_migration'=>0,
 'woo_order_posts_modified_by_this_migration'=>0,
 'new_supplier_costs_confirmed'=>false
];
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;

