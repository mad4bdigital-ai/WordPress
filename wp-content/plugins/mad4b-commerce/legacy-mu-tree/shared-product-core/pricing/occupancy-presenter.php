<?php
/** Occupancy-specific display backed by the audience-aware quote resolver. */
if (!defined('ABSPATH')) return;
function pricing_occupancy_price($rate_id,$occupancy,$currency=null,$audience='general'){
 if(!function_exists('pricing_audience_quote'))return '';
 $quote=pricing_audience_quote($rate_id,$audience,$occupancy);
 if(($quote['status']??'')!=='available')return '<span class="price-unavailable">'.esc_html__('Price on request','woocommerce').'</span>';
 return pricing_currency_markup($quote['regular'],$quote['sale'],$quote['source_currency'],$currency?:pricing_display_currency());
}
add_shortcode('pricing_occupancy_price',static function($atts){
 $a=shortcode_atts(['rate_id'=>0,'occupancy'=>'double','audience'=>'general'],$atts);
 return pricing_occupancy_price(absint($a['rate_id'])?:get_the_ID(),sanitize_key($a['occupancy']),null,sanitize_key($a['audience']));
});
