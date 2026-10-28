<?php
/** Read-only pricing evidence for a specific linked tour rate. Not checkout authorization. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_global_offer_rate_evidence($tour_id,$rate_id,$audience,$occupancy){
 $tour_id=absint($tour_id);$rate_id=absint($rate_id);
 if(get_post_type($tour_id)!=='tours-and-activities'||get_post_status($tour_id)!=='publish')return new WP_Error('invalid_offer_tour');
 if(!function_exists('global_offer_product_eligible')||!global_offer_product_eligible($tour_id))return new WP_Error('offer_product_excluded');
 if(get_post_type($rate_id)!=='tour-rates'||get_post_status($rate_id)!=='publish'||absint(get_post_meta($rate_id,'related_tour_id',true))!==$tour_id)return new WP_Error('unlinked_offer_rate');
 if(!function_exists('pricing_audience_quote'))return new WP_Error('quote_resolver_unavailable');
 $quote=pricing_audience_quote($rate_id,$audience,$occupancy);
 if(!is_array($quote)||($quote['status']??'')!=='available')return new WP_Error('offer_rate_unavailable');
 if(!is_numeric($quote['regular']??null)||!is_finite((float)$quote['regular'])||(float)$quote['regular']<=0)return new WP_Error('invalid_regular_price');
 return ['product_id'=>$tour_id,'rate_id'=>$rate_id,'audience'=>$audience,'occupancy'=>$occupancy,'currency'=>$quote['source_currency'],'regular_amount'=>(float)$quote['regular'],'current_amount'=>(float)$quote['final'],'has_existing_sale'=>!empty($quote['discounted']),'status'=>'preview_only_not_checkout_authority'];
}
