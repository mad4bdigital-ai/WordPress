<?php
/** Site-neutral financial rules and visa catalog prototype; isolated lab only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_action('init', static function(){
 register_post_type('travel-visa',['label'=>'Visas','public'=>false,'show_ui'=>true,'show_in_rest'=>true,'supports'=>['title','editor','revisions'],'menu_icon'=>'dashicons-id','capability_type'=>'post']);
 register_taxonomy_for_object_type('destinations','travel-visa');
},20);
/** Country means a term directly underneath an existing continent, not a region/city. */
function shared_product_destination_country($term_id){
 $term=get_term(absint($term_id),'destinations');
 if(!$term || is_wp_error($term) || !$term->parent)return new WP_Error('invalid_destination_country');
 $parent=get_term((int)$term->parent,'destinations');
 if(!$parent||is_wp_error($parent)|| (int)$parent->parent!==0)return new WP_Error('destination_not_country_level');
 return ['term_id'=>(int)$term->term_id,'slug'=>$term->slug,'continent'=>(int)$parent->term_id];
}
function shared_product_visa_eligibility($visa_id,$country_id,$nationality){
 if(get_post_type($visa_id)!=='travel-visa'||get_post_status($visa_id)!=='publish')return new WP_Error('visa_not_available');
 $country=shared_product_destination_country($country_id);if(is_wp_error($country))return $country;
 if(!has_term((int)$country_id,'destinations',$visa_id))return new WP_Error('visa_destination_mismatch');
 $nationality=is_string($nationality)?strtoupper($nationality):'';
 if(!preg_match('/^[A-Z]{2}$/D',$nationality))return new WP_Error('invalid_nationality');
 $list=get_post_meta($visa_id,'shared_product_allowed_nationalities',true);
 if(!is_array($list)||!in_array($nationality,$list,true))return new WP_Error('visa_nationality_not_configured_or_ineligible');
 return ['visa_id'=>(int)$visa_id,'destination_country'=>$country,'nationality'=>$nationality,'status'=>'eligible_for_quote_not_approval'];
}
/** Rules are explicit, validated and ordered. Payment fees/discounts are never inferred from gateway IDs. */
function shared_product_payment_offer_preview($subtotal,$currency,$method,$date,$country_id,$nationality){
 if(!is_numeric($subtotal)||!is_finite((float)$subtotal)||(float)$subtotal<0)return new WP_Error('invalid_subtotal');
 if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))return new WP_Error('invalid_currency');
 if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))return new WP_Error('invalid_offer_date');
 if(!is_string($method)||!preg_match('/^[a-z0-9_-]{1,80}$/D',$method))return new WP_Error('invalid_payment_method');
 if(!is_string($nationality)||!preg_match('/^[A-Z]{2}$/D',$nationality))return new WP_Error('invalid_nationality');
 $rules=shared_product_financial_policies();
 if(!is_array($rules)||($rules['schema']??null)!==1||!is_array($rules['offers']??null)||!is_array($rules['payment_fees']??null))return new WP_Error('payment_policies_unconfigured');
 if(count($rules['offers'])>100)return new WP_Error('too_many_offers');
 $country=shared_product_destination_country($country_id);if(is_wp_error($country))return $country;
 // Fee rate requires an explicitly configured method. No presumed fee or discount.
 if(!isset($rules['payment_fees'][$method]))return new WP_Error('payment_fee_not_configured');
 $fee=$rules['payment_fees'][$method];
 if(!is_array($fee)||!isset($fee['percent'],$fee['fixed'])||!is_numeric($fee['percent'])||!is_numeric($fee['fixed'])||$fee['percent']<0||$fee['percent']>100||$fee['fixed']<0)return new WP_Error('invalid_payment_fee');
 $selected=[];$discount=0.;
 foreach($rules['offers'] as $offer){
  if(!is_array($offer)||empty($offer['enabled']))continue;
  if(!isset($offer['id'],$offer['start'],$offer['end'],$offer['percent'])||!is_string($offer['id'])||!preg_match('/^[a-z0-9_-]+$/D',$offer['id'])||!is_numeric($offer['percent'])||$offer['percent']<0||$offer['percent']>100)return new WP_Error('invalid_offer');
  if($date<$offer['start']||$date>$offer['end'])continue;
  if(!empty($offer['payment_methods'])&&!in_array($method,(array)$offer['payment_methods'],true))continue;
  if(!empty($offer['country_ids'])&&!in_array((int)$country_id,array_map('intval',(array)$offer['country_ids']),true))continue;
  if(!empty($offer['nationalities'])&&!in_array($nationality,(array)$offer['nationalities'],true))continue;
  $selected[]=$offer;
 }
 // One offer only, highest percent wins; no stacking without a dedicated approved policy.
 usort($selected,static fn($a,$b)=>(float)$b['percent']<=>(float)$a['percent']);
 if($selected){$discount=round((float)$subtotal*(float)$selected[0]['percent']/100,2);}
 $discounted=round((float)$subtotal-$discount,2);
 $fee_total=round($discounted*(float)$fee['percent']/100+(float)$fee['fixed'],2);
 return ['schema'=>1,'currency'=>$currency,'subtotal'=>(float)$subtotal,'discount'=>$discount,'discounted_subtotal'=>$discounted,'payment_fee'=>$fee_total,'preview_total'=>round($discounted+$fee_total,2),'offer_id'=>$selected[0]['id']??null,'payment_method'=>$method,'status'=>'preview_only_not_checkout_authority'];
}
