<?php
/** Explicit composite booking configuration for already integrated tour-rates. Lab only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_booking_configuration_quote($rate_id,$audience,$occupancy,$selection){
 if(get_post_type($rate_id)!=='tour-rates'||get_post_status($rate_id)!=='publish'||!is_array($selection))return new WP_Error('booking_invalid_rate_or_options');
 $allowed=['persons','rooms','cabins','vehicles','days','trips','tables'];
 $quantities=$selection['quantities']??[];
 if(!is_array($quantities)||array_diff(array_keys($quantities),$allowed))return new WP_Error('booking_invalid_unit_quantities');
 foreach($quantities as $v)if(!filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]))return new WP_Error('booking_invalid_unit_quantity');
 $age_receipt=null;
 if(array_key_exists('age_context',$selection)){
  if(!class_exists('MAD4B_Age_Occupancy_Registry'))return new WP_Error('booking_age_registry_unavailable');
  $tour=absint(get_post_meta($rate_id,'related_tour_id',true));
  $age_receipt=MAD4B_Age_Occupancy_Registry::booking_evidence($selection['age_context'],
   ['domain'=>'tour','product_id'=>$tour,'rate_id'=>(int)$rate_id],(int)($quantities['persons']??0));
  if(is_wp_error($age_receipt))return $age_receipt;
 }
 $unit=shared_product_resolve_price_unit($rate_id);if(is_wp_error($unit))return $unit;
 $base=shared_product_quote_snapshot($rate_id,$audience,$occupancy);if(is_wp_error($base))return $base;
 $extended=shared_product_unit_extended_amount($base['display_amount'],$unit['unit'],$quantities);if(is_wp_error($extended))return $extended;
 $options=$selection['options']??[];
 if(!is_array($options)||array_diff(array_keys($options),['travelers','optional_rate_ids','guide_language','transportation','visual_selection']))return new WP_Error('booking_invalid_options');
 $visual=$options['visual_selection']??[];
 if(!is_array($visual)||array_diff(array_keys($visual),['property_id','transport_id','extra_tour_id','package_term_id']))return new WP_Error('invalid_visual_selection');
 foreach($visual as $key=>$value){if(!is_scalar($value)||!ctype_digit((string)$value)||absint($value)<1)return new WP_Error('invalid_visual_selection_id');}
 if($visual){
  $related=shared_product_rate_related_options($rate_id);if(is_wp_error($related))return $related;
  $available=['property_id'=>array_column($related['properties'],'id'),'extra_tour_id'=>array_column($related['optional_tours'],'id'),'transport_id'=>[]];
  foreach($related['transportation'] as $group)foreach($group as $entry)$available['transport_id'][]=$entry['id'];
  foreach($visual as $key=>$value){if($key==='package_term_id')continue;if(!in_array(absint($value),$available[$key],true))return new WP_Error('visual_option_not_linked_to_rate');}
  if(isset($visual['property_id'])){
   $choice=shared_product_select_package_accommodation($rate_id,$visual['property_id'],$visual['package_term_id']??null);
   if(is_wp_error($choice))return $choice;
  }elseif(isset($visual['package_term_id'])){
   $tier=shared_product_package_accommodation_options($rate_id,$visual['package_term_id']);
   if(is_wp_error($tier))return $tier;
  }
 }
 $composite=shared_product_composite_quote($rate_id,$audience,$occupancy,$options);if(is_wp_error($composite))return $composite;
 // Composite quote base represents one pricing unit. Extend only the base; child options are quoted independently.
 $total=round($extended['amount']+(float)$composite['optional_total']+(float)$composite['modifiers_total'],2);
 if($total<=0)return new WP_Error('booking_invalid_total');
 return ['schema'=>1,'rate_id'=>(int)$rate_id,'audience'=>$audience,'occupancy'=>$occupancy,'unit'=>$unit['unit'],
  'unit_label'=>$unit['label'],'unit_quantity'=>$extended['quantity'],'quantities'=>$quantities,'options'=>$options,'currency'=>$base['display_currency'],
  'base_unit_amount'=>$base['display_amount'],'base_extended_amount'=>$extended['amount'],
  'optional_rate_lines'=>$composite['optional_rate_lines'],'modifier_lines'=>$composite['modifier_lines'],
  'total'=>$total,'quoted_at_utc'=>gmdate('c'),'status'=>'lab_cart_quote_not_financial_ledger','age_policy_receipt'=>$age_receipt];
}
/** Server recalculation; never use a browser-supplied total. */
function shared_product_booking_configuration_matches($saved){
 if(!is_array($saved)||($saved['schema']??null)!==1)return new WP_Error('booking_configuration_missing');
 $selection=['quantities'=>$saved['quantities'],'options'=>$saved['options']];
 $receipt=$saved['age_policy_receipt']??null;
 if($receipt!==null){
  $tour=absint(get_post_meta((int)$saved['rate_id'],'related_tour_id',true));
  $context=['domain'=>'tour','product_id'=>$tour,'rate_id'=>(int)$saved['rate_id']];
  if(!MAD4B_Age_Occupancy_Registry::verify_booking_evidence($receipt,$context,(int)($saved['quantities']['persons']??0)))
   return new WP_Error('booking_age_evidence_stale_or_invalid');
  $selection['age_context']=array_intersect_key($receipt,array_flip(['children','infants','stay_children','child_ages','infant_ages']));
 }
 $fresh=shared_product_booking_configuration_quote((int)$saved['rate_id'],$saved['audience'],$saved['occupancy'],$selection);
 if(is_wp_error($fresh))return $fresh;
 if($fresh['currency']!==$saved['currency']||abs($fresh['total']-$saved['total'])>0.009)return new WP_Error('booking_configuration_price_changed');
 return $fresh;
}
add_filter('woocommerce_add_cart_item_data',static function($data,$product_id){
 if(get_post_type($product_id)!=='tour-rates'||empty($_REQUEST['shared_booking_configuration']))return $data;
 $raw=wp_unslash($_REQUEST['shared_booking_configuration']);
 $selection=is_string($raw)?json_decode($raw,true):null;
 $a=sanitize_key(wp_unslash($_REQUEST['shared_audience']??''));$o=sanitize_key(wp_unslash($_REQUEST['shared_occupancy']??''));
 $quote=shared_product_booking_configuration_quote($product_id,$a,$o,$selection);
 if(is_wp_error($quote)){wc_add_notice('Invalid booking configuration: '.$quote->get_error_code(),'error');return $data;}
 $data['shared_booking_configuration']=$quote;
 return $data;
},30,2);
add_action('woocommerce_before_calculate_totals',static function($cart){
 if(!$cart instanceof WC_Cart)return;
 foreach($cart->get_cart() as $line){
  // Do not overwrite the preceding quote hook when both authorities are present.
  if (!empty($line['shared_booking_configuration']) && !empty($line['shared_product_quote'])) {
   wc_add_notice('Conflicting booking pricing authorities: reselect this booking.', 'error');
   continue;
  }
  $config=$line['shared_booking_configuration']??null;if(!$config)continue;
  $fresh=shared_product_booking_configuration_matches($config);
  if(is_wp_error($fresh)){wc_add_notice('Booking options must be refreshed.','error');continue;}
  if((int)($line['quantity']??0)!==1){wc_add_notice('Composite bookings require cart quantity 1; change unit quantities within booking options.','error');continue;}
  if(($line['data']??null) instanceof WC_Product){$line['data']->set_price(wc_format_decimal($fresh['total']));$line['data']->set_tax_status('none');}
 }
},100000);
add_action('woocommerce_check_cart_items',static function(){
 if(!WC()->cart)return;
 foreach(WC()->cart->get_cart() as $line){
  $config=$line['shared_booking_configuration']??null;if(!$config)continue;
  // The legacy validator refuses dual-authority lines before this handler.
  if(!empty($line['shared_product_quote']))continue;
  if(!is_array($config)||get_post_type((int)($line['product_id']??0))!=='tour-rates'||
     (int)($config['rate_id']??0)!==(int)($line['product_id']??0)){
   wc_add_notice('Booking configuration is not bound to this Tour Rate.','error');
   continue;
  }
  $fresh=shared_product_booking_configuration_matches($config);
  if(is_wp_error($fresh))wc_add_notice('Booking configuration is outdated: '.$fresh->get_error_code(),'error');
  if(!is_wp_error($fresh)&&!empty($fresh['age_policy_receipt']['stay_children']))
   wc_add_notice('Age-based commercial checkout is not certified for this booking source.','error');
  if((int)($line['quantity']??0)!==1)wc_add_notice('Booking cart quantity must be one.','error');
 }
},30);
add_action('woocommerce_checkout_create_order_line_item',static function($item,$key,$values){
 if(empty($values['shared_booking_configuration']))return;
 $config=$values['shared_booking_configuration'];
 $fresh=shared_product_booking_configuration_matches($config);
 if(is_wp_error($fresh))return; // Never persist unverified policy evidence.
 $item->update_meta_data('_shared_booking_configuration',$config);
 if(!empty($fresh['age_policy_receipt']))
  $item->update_meta_data('_shared_age_policy_receipt',$fresh['age_policy_receipt']);
},30,3);
/** Resolve presentation labels at render time; immutable pricing keys remain in the quote. */
function shared_product_booking_unit_display($configuration){
 $unit=shared_product_resolve_price_unit((int)($configuration['rate_id']??0));
 if(is_wp_error($unit))return (string)($configuration['unit_label']??$configuration['unit']??'');
 return (string)$unit['label'];
}
/** Render cart details once via WooCommerce's native item-data pipeline. */
add_filter('woocommerce_get_item_data',static function($data,$line){
 $c=$line['shared_booking_configuration']??null;if(!is_array($c))return $data;
 $quantity=absint($c['unit_quantity']??0);
 $data[]=['key'=>__('Pricing unit','woocommerce'),'value'=>esc_html(shared_product_booking_unit_display($c).' × '.$quantity)];
 $occupancy=(string)($c['occupancy']??'');
 $occupancy_labels=['single'=>__('Single','woocommerce'),'double'=>__('Double','woocommerce'),'triple'=>__('Triple','woocommerce')];
 $data[]=['key'=>__('Occupancy','woocommerce'),'value'=>esc_html($occupancy_labels[$occupancy]??$occupancy)];
 $start=(int)get_post_meta((int)$c['rate_id'],'tour_rate_start_date',true);
 $end=(int)get_post_meta((int)$c['rate_id'],'tour_rate_end_date',true);
 if($start>0 && $end >= $start){
  $data[]=['key'=>__('Available from','woocommerce'),'value'=>esc_html(wp_date(get_option('date_format'),$start))];
  $data[]=['key'=>__('Available until','woocommerce'),'value'=>esc_html(wp_date(get_option('date_format'),$end))];
 }
 $context=shared_product_tour_context((int)$c['rate_id']);
 if(!is_wp_error($context)){
  $tour_id=(int)$context['tour_id'];
  $term_id=absint(get_post_meta($tour_id,'tour_origin_location_term',true));
  if($term_id){$term=get_term($term_id,'destinations');if($term && !is_wp_error($term))$data[]=['key'=>__('Departure point','woocommerce'),'value'=>esc_html($term->name)];}
  $place=get_post_meta($tour_id,'tour_origin_place',true);
  if(is_string($place) && trim($place)!=='')$data[]=['key'=>__('Meeting place','woocommerce'),'value'=>esc_html($place)];
 }
 foreach((array)($c['modifier_lines']??[]) as $modifier){
  $code=(string)($modifier['code']??'');
  if(($modifier['type']??'')==='guide_language'){
   $term=get_term_by('slug',$code,'guide-languages');
   if($term && !is_wp_error($term))$data[]=['key'=>__('Guide language','woocommerce'),'value'=>esc_html($term->name)];
  }elseif(($modifier['type']??'')==='transportation'){
   $labels=['vehicle'=>__('Vehicle','woocommerce'),'train'=>__('Train','woocommerce'),'air'=>__('Flight','woocommerce'),'transfer'=>__('Transfer','woocommerce')];
   if(isset($labels[$code]))$data[]=['key'=>__('Transportation','woocommerce'),'value'=>esc_html($labels[$code])];
  }
 }
 $travelers=$c['options']['travelers']??$c['quantities']['persons']??null;
 if(is_numeric($travelers))$data[]=['key'=>__('Travelers','woocommerce'),'value'=>esc_html((string)$travelers)];
 foreach((array)($c['optional_rate_lines']??[]) as $extra){
  $id=absint($extra['tour_id']??0);
  if($id && get_post_status($id)==='publish')$data[]=['key'=>__('Optional activity','woocommerce'),'value'=>esc_html(get_the_title($id))];
 }
 return $data;
},20,2);
/** Update a booking only from validated selections; never accept a client price. */
function shared_product_booking_cart_update($cart_key,$selection){
 if(!WC()->cart||!is_string($cart_key)||!isset(WC()->cart->cart_contents[$cart_key]))return new WP_Error('booking_cart_line_missing');
 $line=WC()->cart->cart_contents[$cart_key];$old=$line['shared_booking_configuration']??null;
 if(!is_array($old))return new WP_Error('booking_cart_line_not_configurable');
 $fresh=shared_product_booking_configuration_quote((int)$old['rate_id'],$old['audience'],$old['occupancy'],$selection);
 if(is_wp_error($fresh))return $fresh;
 WC()->cart->cart_contents[$cart_key]['shared_booking_configuration']=$fresh;
 WC()->cart->calculate_totals();WC()->cart->set_session();
 return ['cart_key'=>$cart_key,'currency'=>$fresh['currency'],'total'=>$fresh['total'],'status'=>'updated_server_quoted_cart_only'];
}
