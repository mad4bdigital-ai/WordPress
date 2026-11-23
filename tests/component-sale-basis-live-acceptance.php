<?php
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Component_Selling_Basis_V1',false))
 throw new RuntimeException('component_policy_not_loaded');
$c=MAD4B_Component_Selling_Basis_V1::class;
$base=(string)get_option('woocommerce_currency','');
$reported=[];
foreach([57562,60623,60629] as $tour){
 $r=$c::resolve($tour);
 if(is_wp_error($r))throw new RuntimeException('tour_policy_'.$r->get_error_code());
 $explicit=(string)get_post_meta($tour,'tour_base_currency',true);
 $price=get_post_meta($tour,'start_price',true);
 if($r['currency']!==($explicit!==''?$explicit:$base)||$r['unit']!=='per_person'||
   $r['checkout_authorized']!==false||$r['mutation_performed']!==false)
  throw new RuntimeException('tour_currency_unit_regressed_'.$tour);
 $reported[$tour]=[
   'id'=>$tour,'name'=>$r['name'],'type'=>'tours-and-activities',
   'sale_currency'=>$r['currency'],'currency_origin'=>$r['currency_source'],
   'unit'=>$r['unit'],'unit_origin'=>$r['unit_source'],
   'amount_present'=>is_numeric($price)&&(float)$price>0,
   'direct_source_candidate'=>class_exists('\Commerce\Composition\SingleFluentPaymentRouter')
       ?\Commerce\Composition\SingleFluentPaymentRouter::directSourceCandidate($tour):null
 ];
}
if($reported[57562]['amount_present']!==false||
   $reported[57562]['direct_source_candidate']!==false)
 throw new RuntimeException('missing_price_became_payable');
$accommodation=$c::resolve(36084);
$cabin=$c::resolve(78050);
if(is_wp_error($accommodation)||$accommodation['unit']!=='per_room_night'||
   is_wp_error($cabin)||$cabin['unit']!=='per_cabin_night')
 throw new RuntimeException('hotel_cruise_units_regressed');
$rate=get_posts(['post_type'=>'property-rates','fields'=>'ids',
 'post_status'=>['publish','draft'],'posts_per_page'=>1,'suppress_filters'=>true])[0]??0;
if(!$rate)throw new RuntimeException('no_existing_rate');
$rateBasis=$c::resolve((int)$rate);
if(is_wp_error($rateBasis)||$rateBasis['unit']!==(string)get_post_meta($rate,'prop_price_unit',true))
 throw new RuntimeException('existing_rate_override_lost');
$vehicle=$c::resolve_external('transport',['id'=>455,
   'currency'=>'EUR','unit'=>'per_vehicle','mode'=>'transport']);
$unknown=$c::resolve_external('transport',['id'=>456]);
$originalCost=$c::resolve_external('guide',['unit'=>'per_guide_day'],'supplier_cost');
if(is_wp_error($vehicle)||$vehicle['unit']!=='per_vehicle'||$vehicle['currency']!=='EUR'||
   is_wp_error($unknown)||$unknown['unit']!==null||
   !is_array($originalCost)||$originalCost['currency']!==null||
   $originalCost['checkout_authorized']!==false)
 throw new RuntimeException('transport_or_supplier_inheritance_invalid');
$terms=get_terms(['taxonomy'=>'guide-languages','hide_empty'=>false,'number'=>1]);
$guide=null;
if(!is_wp_error($terms)&&$terms){
 $guide=$c::guide_language((int)$terms[0]->term_id);
 if(is_wp_error($guide)||$guide['unit']!=='per_guide_day'||$guide['cost_approval_inferred']!==false)
  throw new RuntimeException('guide_day_cost_authorization_regressed');
}
$registered=has_filter('jet-engine/options-pages/raw-fields',[$c,'admin_fields'])!==false;
$editor=has_action('add_meta_boxes',[$c,'tour_editor_box'])!==false;
if(!$registered||!$editor)throw new RuntimeException('dynamic_pricing_controls_not_connected');
$admin=$c::admin_fields([],(object)['slug'=>'etg-dynamic-pricing-controls']);
if(count($admin)!==8)throw new RuntimeException('policy_fields_not_renderable');
$invalidUnit=static function($value,$id,$key) {
 if((int)$id===60629 && $key==='tour_price_unit')return 'per_cabin_night';
 return $value;
};
add_filter('get_post_metadata',$invalidUnit,9999,3);
$invalid=$c::resolve(60629);
remove_filter('get_post_metadata',$invalidUnit,9999);
if(!is_wp_error($invalid)||$invalid->get_error_code()!=='component_basis_unit_invalid_for_kind')
 throw new RuntimeException('invalid_explicit_unit_silently_inherited');
$guards=[];
if(class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false)){
 foreach([57562,60623,60629] as $id){
  $result=MAD4B_Package_Stage_Rate_Guard_V1::review($id,2354);
  $guards[$id]=$result['checkout_ready'];
  if($result['checkout_ready']!==false)throw new RuntimeException('commercial_gate_unexpectedly_open_'.$id);
 }
}
$booking=get_option('etg-dynamic-pricing-controls_booking_enabled');
if($booking!=='false')throw new RuntimeException('global_booking_flag_changed');
echo wp_json_encode([
 'contract'=>'mad4b.component-basis.live-acceptance.v1',
 'site'=>home_url(),'woocommerce_store_currency'=>$base,
 'wcml_current_display_currency'=>get_woocommerce_currency(),
 'tours'=>$reported,'hotel'=>['id'=>36084,'name'=>$accommodation['name'],'unit'=>$accommodation['unit']],
 'cabin'=>['id'=>78050,'name'=>$cabin['name'],'unit'=>$cabin['unit']],
 'rate_id'=>(int)$rate,'rate_unit_preserved'=>$rateBasis['unit'],
 'vehicle_unit'=>$vehicle['unit'],'unknown_transport_unit'=>$unknown['unit'],
 'guide_cost_per_day'=>$guide['unit']??null,
 'guide_supplier_original_currency_not_inferred'=>true,
 'policy_fields'=>count($admin),'policy_hook_active'=>$registered,
 'editor_box_hook_active'=>$editor,
 'invalid_explicit_unit_blocked'=>true,
 'package_checkout_guards'=>$guards,
 'booking_flag'=>$booking,
 'read_only_test'=>true,'orders_created'=>0,'rates_created'=>0
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

