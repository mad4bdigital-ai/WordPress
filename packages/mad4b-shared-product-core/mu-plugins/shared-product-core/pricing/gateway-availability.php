<?php
/** Lab-only gateway eligibility: existing Woo gateways are filtered, never enabled. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_gateway_availability_rules(){
 $raw=shared_product_jet_policy_raw('shared_gateway_availability_rows');
 if(is_wp_error($raw))return $raw;
 if(!is_array($raw))return new WP_Error('invalid_gateway_rules');
 $rules=[];
 foreach($raw as $row){
  if(!is_array($row))return new WP_Error('invalid_gateway_rule');
  $id=$row['method']??'';
  if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,80}$/D',$id)||isset($rules[$id]))return new WP_Error('invalid_or_duplicate_gateway_rule');
  $basis=$row['country_basis']??'';
  if(!in_array($basis,['billing','shipping','destination'],true))return new WP_Error('invalid_country_basis');
  $rule=['enabled'=>in_array($row['enabled']??false,[true,1,'1','true','on'],true),'country_basis'=>$basis];
  foreach(['allowed_countries','excluded_countries','allowed_currencies'] as $key){
   $values=$row[$key]??[];if(is_string($values))$values=explode(',',$values);
   if(!is_array($values))return new WP_Error('invalid_gateway_scope');
   $values=array_values(array_unique(array_filter(array_map('trim',$values),static fn($x)=>$x!=='')));
   foreach($values as $value){
    if($key==='allowed_currencies'&&!preg_match('/^[A-Z]{3}$/D',$value))return new WP_Error('invalid_gateway_currency');
    if($key!=='allowed_currencies' && ($basis==='destination'?(!ctype_digit($value)||is_wp_error(shared_product_destination_country((int)$value))):!preg_match('/^[A-Z]{2}$/D',$value)))return new WP_Error('invalid_gateway_country');
   }
   $rule[$key]=$values;
  }
  $rules[$id]=$rule;
 }
 return $rules;
}
function shared_product_gateway_is_eligible($gateway_id,$billing_country,$shipping_country,$destination_term_id,$currency){
 $rules=shared_product_gateway_availability_rules();
 if(is_wp_error($rules))return $rules->get_error_code()==='policy_not_configured'?true:$rules;
 if(!isset($rules[$gateway_id]))return true; // No policy means existing WC behavior.
 $rule=$rules[$gateway_id];if(!$rule['enabled'])return false;
 $basis=$rule['country_basis'];
 $country=$basis==='billing'?$billing_country:($basis==='shipping'?$shipping_country:(string)$destination_term_id);
 if(!is_string($country)||$country==='')return false;
 if($basis==='destination'&&is_wp_error(shared_product_destination_country((int)$country)))return false;
 if($rule['allowed_countries']&&!in_array($country,$rule['allowed_countries'],true))return false;
 if(in_array($country,$rule['excluded_countries'],true))return false;
 if($rule['allowed_currencies']&&!in_array($currency,$rule['allowed_currencies'],true))return false;
 return true;
}
add_filter('woocommerce_available_payment_gateways',static function($gateways){
 if(!is_array($gateways)||!function_exists('WC')||!WC()->customer)return $gateways;
 $billing=WC()->customer->get_billing_country();$shipping=WC()->customer->get_shipping_country();
 $cart=WC()->cart??null;
 foreach($gateways as $id=>$gateway){
  $allowed=shared_product_gateway_cart_eligibility($id,$billing,$shipping,get_woocommerce_currency(),$cart);
  if($allowed===false||is_wp_error($allowed))unset($gateways[$id]);
 }
 return $gateways;
},99);

/** Map any nested destination term to its canonical country ancestor. */
function shared_product_country_from_destination($term_id){
 $seen=[];$term=get_term(absint($term_id),'destinations');
 while($term&&!is_wp_error($term)&&!isset($seen[$term->term_id])){
  $seen[$term->term_id]=true;
  $country=shared_product_destination_country($term->term_id);
  if(!is_wp_error($country))return (int)$term->term_id;
  if(!$term->parent)break;
  $term=get_term((int)$term->parent,'destinations');
 }
 return new WP_Error('destination_country_unresolved');
}
function shared_product_cart_destination_countries($cart){
 if(!$cart||!method_exists($cart,'get_cart'))return new WP_Error('cart_unavailable');
 $countries=[];
 foreach($cart->get_cart() as $line){
  $id=(int)($line['product_id']??0);$type=get_post_type($id);
  if($type==='tour-rates'){
   $context=shared_product_tour_context($id);if(is_wp_error($context))return $context;
   $id=(int)$context['tour_id'];
  } elseif($type!=='travel-visa'&&$type!=='tours-and-activities'){
   return new WP_Error('cart_destination_unresolved');
  }
  $terms=wp_get_post_terms($id,'destinations',['fields'=>'ids']);
  if(is_wp_error($terms)||!$terms)return new WP_Error('cart_destination_missing');
  $per_item=[];
  foreach($terms as $term){
   $country=shared_product_country_from_destination($term);
   if(!is_wp_error($country))$per_item[$country]=$country;
  }
  if(count($per_item)!==1)return new WP_Error('cart_destination_ambiguous');
  $countries+= $per_item;
 }
 if(!$countries)return new WP_Error('cart_destination_missing');
 return array_values($countries);
}
function shared_product_gateway_cart_eligibility($gateway_id,$billing,$shipping,$currency,$cart){
 $rules=shared_product_gateway_availability_rules();
 if(is_wp_error($rules))return $rules->get_error_code()==='policy_not_configured'?true:$rules;
 if(!isset($rules[$gateway_id]))return true;
 $rule=$rules[$gateway_id];
 if($rule['country_basis']!=='destination')return shared_product_gateway_is_eligible($gateway_id,$billing,$shipping,null,$currency);
 $countries=shared_product_cart_destination_countries($cart);
 if(is_wp_error($countries))return $countries;
 foreach($countries as $country){
  $eligible=shared_product_gateway_is_eligible($gateway_id,$billing,$shipping,$country,$currency);
  if($eligible!==true)return $eligible;
 }
 return true;
}
add_action('woocommerce_checkout_process',static function(){
 if(!function_exists('WC')||!WC()->cart||!WC()->session)return;
 $id=WC()->session->get('chosen_payment_method');
 if(!is_string($id)||$id==='')return;
 $billing=WC()->customer?WC()->customer->get_billing_country():'';
 $shipping=WC()->customer?WC()->customer->get_shipping_country():'';
 $allowed=shared_product_gateway_cart_eligibility($id,$billing,$shipping,get_woocommerce_currency(),WC()->cart);
 if($allowed!==true)wc_add_notice(__('The chosen payment method is unavailable for this booking.','woocommerce'),'error');
},50);
