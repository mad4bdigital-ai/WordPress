<?php
/** Lab-only JetWooBuilder/Elementor badge; no synthetic sale prices. */
if(!defined('ABSPATH'))return;
function shared_global_offer_product_badge($atts=[]){
 $atts=shortcode_atts(['product_id'=>0],$atts,'global_offer_product_badge');
 $id=absint($atts['product_id']);if(!$id){$id=absint($GLOBALS['shared_global_offer_render_product_id']??0)?:get_the_ID();}
 if(!$id||!function_exists('global_offer_resolved')||!function_exists('global_offer_product_eligible'))return '';
 $offer=global_offer_resolved();if(!$offer)return '';
 $campaign=$offer['campaign'];
 $ids=array_values(array_unique(array_filter(array_map('absint',explode(',',(string)get_post_meta($campaign->ID,'eligible_product_ids',true))))));
 $product=get_post($id);if(!$product)return '';
 if($product->post_type==='tour-rates')$id=absint(get_post_meta($id,'related_tour_id',true));
 if(!$id||!in_array($id,$ids,true)||!global_offer_product_eligible($id))return '';
 return '<span class="shared-global-offer-badge" data-campaign="'.esc_attr((string)$campaign->ID).'">'.esc_html($campaign->post_title).'</span>';
}
add_shortcode('global_offer_product_badge','shared_global_offer_product_badge');
