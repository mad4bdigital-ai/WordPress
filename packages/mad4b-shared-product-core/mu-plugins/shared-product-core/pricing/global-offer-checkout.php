<?php
/** Lab-only Global Offer checkout discount. Applies an auditable non-taxable fee once per totals pass. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_global_offer_cart_discount($cart){
 if(!$cart instanceof WC_Cart || !function_exists('global_offer_product_eligible'))return new WP_Error('cart_or_eligibility_missing');
 $other_coupons=array_filter($cart->get_applied_coupons(),static fn($code)=>$code!=='shared-global-offer-lab');
 if($other_coupons)return new WP_Error('cannot_stack_with_coupon');
 foreach($cart->get_fees() as $fee)if((float)($fee->amount??0)<0)return new WP_Error('cannot_stack_with_existing_discount_fee');
 $settings=get_option('global-offers',[]);
 if(!is_array($settings)||!in_array($settings['offers_enabled']??false,[true,1,'1','true','on'],true))return new WP_Error('offer_disabled');
 $campaign=get_post(absint($settings['active_offer_id']??0));
 if(!$campaign||$campaign->post_type!=='offer-campaign'||$campaign->post_status!=='publish')return new WP_Error('campaign_unavailable');
 $today=wp_date('Y-m-d');foreach(['starts_at','ends_at'] as $key){$d=get_post_meta($campaign->ID,$key,true);if($d!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$d)||($key==='starts_at'&&$today<$d)||($key==='ends_at'&&$today>$d)))return new WP_Error('campaign_outside_dates');}
 $rows=function_exists('shared_product_structured_policy_rows')?shared_product_structured_policy_rows('shared_promotion_rows'):new WP_Error('rules_unavailable');if(is_wp_error($rows)||!is_array($rows))return new WP_Error('rules_unavailable');
 $selected=[];$rule_id=(string)get_post_meta($campaign->ID,'promotion_rule_id',true);if($rule_id==='')return new WP_Error('rule_missing');
 foreach($rows as $r)if(is_array($r)&&(string)($r['id']??'')===$rule_id)$selected[]=$r;
 if(count($selected)!==1)return new WP_Error('rule_missing_or_duplicate');$rule=$selected[0];
 if(!in_array($rule['enabled']??false,[true,1,'1','true','on'],true)||!is_numeric($rule['percent']??null))return new WP_Error('rule_invalid');
 $percent=(float)$rule['percent'];if(!is_finite($percent)||$percent<=0||$percent>=100)return new WP_Error('percent_invalid');
 foreach(['start','end'] as $key){$d=(string)($rule[$key]??'');if($d!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d)||($key==='start'&&$today<$d)||($key==='end'&&$today>$d)))return new WP_Error('rule_outside_dates');}
 foreach(['payment_methods','country_ids','nationalities'] as $field){$v=$rule[$field]??[];if(is_string($v))$v=array_filter(array_map('trim',explode(',',$v)));if(!empty($v))return new WP_Error('context_restricted_rule');}
 $ids=array_values(array_unique(array_filter(array_map('absint',explode(',',(string)get_post_meta($campaign->ID,'eligible_product_ids',true))))));if(!$ids||count($ids)>100)return new WP_Error('invalid_campaign_products');
 $allowed=array_fill_keys($ids,true);$services=array_filter(array_map('trim',explode(',',(string)get_post_meta($campaign->ID,'eligible_services',true))));if($services&&!in_array('tours',$services,true))return new WP_Error('tours_outside_campaign_services');
 $total=0.;$seen=[];$lines=[];
 foreach($cart->get_cart() as $line){
  $rate_id=absint($line['product_id']??0);if(get_post_type($rate_id)!=='tour-rates')continue;
  $tour_id=absint(get_post_meta($rate_id,'related_tour_id',true));if(!$tour_id||!isset($allowed[$tour_id])||!global_offer_product_eligible($tour_id))continue;
  if(!empty($line['shared_booking_configuration']))return new WP_Error('booking_configuration_discount_not_certified');
  $q=$line['shared_product_quote']??null;if(!is_array($q)||($q['schema']??null)!==1||absint($q['rate_id']??0)!==$rate_id)return new WP_Error('missing_rate_quote');
  $fresh=shared_product_quote_snapshot($rate_id,$q['audience']??'',$q['occupancy']??'',$q['display_currency']??'');if(is_wp_error($fresh))return new WP_Error('fresh_quote_unavailable');
  if($fresh['display_currency']!==get_woocommerce_currency()||abs($fresh['display_amount']-(float)($q['display_amount']??-1))>0.009)return new WP_Error('quote_currency_or_price_changed');
  // Existing sale is not stacked with a campaign reduction without a dedicated stacking policy.
  if(!empty($fresh['discounted']))return new WP_Error('existing_sale_cannot_stack');
  $qty=filter_var($line['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);if($qty===false)return new WP_Error('quantity_invalid');
  $price=(float)$fresh['display_amount'];$product=$line['data']??null;if(!$product instanceof WC_Product||abs((float)$product->get_price()-$price)>0.009)return new WP_Error('cart_price_mismatch');
  $total+=$price*$qty;$seen[$tour_id]=true;$lines[]=['tour_id'=>$tour_id,'rate_id'=>$rate_id,'amount'=>$price,'quantity'=>$qty];
 }
 if(!$lines)return new WP_Error('no_eligible_cart_lines');
 $discount=round($total*$percent/100,wc_get_price_decimals());if($discount<=0||$discount>=$total)return new WP_Error('discount_out_of_range');
 return ['campaign_id'=>$campaign->ID,'rule_id'=>$rule_id,'currency'=>get_woocommerce_currency(),'subtotal'=>$total,'discount'=>$discount,'lines'=>$lines,'status'=>'checkout_cart_calculation'];
}
// Native coupon adapter owns discount application. The earlier negative-fee path is retired.
