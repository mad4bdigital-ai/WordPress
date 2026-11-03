<?php
/** Lab-only native WooCommerce virtual coupon for integrated CPT tour rates. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_global_offer_coupon_code(){return 'shared-global-offer-lab';}
function shared_product_global_offer_coupon_context($cart){
 if(!$cart instanceof WC_Cart)return new WP_Error('cart_unavailable');
 $c=shared_product_global_offer_cart_discount($cart);if(is_wp_error($c))return $c;
 $rules=shared_product_structured_policy_rows('shared_promotion_rows');if(is_wp_error($rules))return $rules;
 foreach($rules as $r)if(is_array($r)&&(string)($r['id']??'')===$c['rule_id']){
  return ['discount_percent'=>(float)$r['percent'],'rate_ids'=>array_values(array_unique(array_map(static fn($l)=>(int)$l['rate_id'],$c['lines']))),'result'=>$c];
 }
 return new WP_Error('rule_not_found');
}
add_filter('woocommerce_get_shop_coupon_data',static function($data,$code){
 if($code!==shared_product_global_offer_coupon_code())return $data;
 // A real coupon with the reserved code always wins; never shadow merchant coupons.
 if(wc_get_coupon_id_by_code($code))return $data;
 if(!function_exists('WC')||!WC()->cart)return false;
 $c=shared_product_global_offer_coupon_context(WC()->cart);if(is_wp_error($c))return false;
 return ['discount_type'=>'percent','amount'=>$c['discount_percent'],'individual_use'=>true,'product_ids'=>$c['rate_ids'],'exclude_sale_items'=>true,'usage_limit'=>0,'usage_limit_per_user'=>0,'free_shipping'=>false,'virtual'=>true];
},10,2);
add_action('woocommerce_before_calculate_totals',static function($cart){
 if(!$cart instanceof WC_Cart||!function_exists('WC'))return;
 static $busy=false;if($busy)return;$busy=true;
 try{
  $code=shared_product_global_offer_coupon_code();$applied=$cart->has_discount($code);
  if(wc_get_coupon_id_by_code($code))return;
  $other=array_filter($cart->get_applied_coupons(),static fn($x)=>$x!==$code);
  $valid=!$other?shared_product_global_offer_coupon_context($cart):new WP_Error('other_coupon_present');
  if(is_wp_error($valid)){if($applied)$cart->remove_coupon($code);}
  elseif(!$applied)$cart->apply_coupon($code);
 }finally{$busy=false;}
},100001);

// Reject a coupon retained in a session when its campaign or quoted lines are no longer valid.
add_filter('woocommerce_coupon_is_valid',static function($valid,$coupon,$discounts){
 if(!$valid||!$coupon instanceof WC_Coupon||$coupon->get_code()!==shared_product_global_offer_coupon_code())return $valid;
 if(wc_get_coupon_id_by_code($coupon->get_code()))return $valid;
 if(!function_exists('WC')||!WC()->cart)return false;
 return !is_wp_error(shared_product_global_offer_coupon_context(WC()->cart));
},50,3);
// Persist campaign provenance in WooCommerce order metadata, without altering order item prices.
add_action('woocommerce_checkout_create_order',static function($order,$data){
 if(!$order instanceof WC_Order||!function_exists('WC')||!WC()->cart||!WC()->cart->has_discount(shared_product_global_offer_coupon_code()))return;
 $context=shared_product_global_offer_coupon_context(WC()->cart);
 if(is_wp_error($context))return;
 $offer=$context['result'];
 $order->update_meta_data('_shared_global_offer_campaign_id',(int)$offer['campaign_id']);
 $order->update_meta_data('_shared_global_offer_rule_id',(string)$offer['rule_id']);
 $order->update_meta_data('_shared_global_offer_discount_currency',(string)$offer['currency']);
},90,2);

// Checkout guard: an attached offer coupon must be valid at final cart validation.
add_action('woocommerce_check_cart_items',static function(){
 if(!function_exists('WC')||!WC()->cart)return;
 if(!WC()->cart->has_discount(shared_product_global_offer_coupon_code()))return;
 if(wc_get_coupon_id_by_code(shared_product_global_offer_coupon_code()))return;
 $check=shared_product_global_offer_coupon_context(WC()->cart);
 if(is_wp_error($check))wc_add_notice(__('The promotional offer changed. Refresh the cart before checkout.','woocommerce'),'error');
},1000);
