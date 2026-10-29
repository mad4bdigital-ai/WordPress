<?php
/**
 * Local Tour Rate listing: hide Rate records unless they can produce a
 * currently eligible general-audience price through the original pricing
 * engine. No price, policy or order mutation.
 */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

/**
 * Source of truth for the native Tour Rates query and its filter facets.
 * Fail closed for missing price, date period, status, currency, or FX.
 * A Rate with at least one valid occupancy option remains selectable.
 */
function shared_product_eligible_tour_rate_ids($tour_id): array {
 static $cache=[];
 $tour_id=absint($tour_id);
 if(!$tour_id || get_post_type($tour_id)!=='tours-and-activities' || get_post_status($tour_id)!=='publish')
  return [];
 $currency=function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency();
 $cache_key=$tour_id.'|'.$currency.'|'.(string)apply_filters('wpml_current_language',null);
 if(isset($cache[$cache_key]))return $cache[$cache_key];
 if(!function_exists('pricing_audience_quote') || !function_exists('pricing_audience_display'))
  return $cache[$cache_key]=[];
 // Filter by the original parent Tour, never by an unverified request payload.
 $candidate_ids=get_posts([
  'post_type'=>'tour-rates','post_status'=>'publish','fields'=>'ids',
  'posts_per_page'=>-1,'nopaging'=>true,'suppress_filters'=>false,
  'meta_query'=>[
    ['key'=>'related_tour_id','value'=>(string)$tour_id,'compare'=>'='],
    ['key'=>'avilabile_status','value'=>'1','compare'=>'=']
  ]
 ]);
 $eligible=[];
 foreach($candidate_ids as $rate_id){
  $rate_id=absint($rate_id);
  if(!$rate_id || (string)get_post_meta($rate_id,'stop_sale',true)==='1')continue;
  foreach(['single','double','triple'] as $occupancy){
   $quote=pricing_audience_quote($rate_id,'general',$occupancy);
   if(($quote['status']??null)!=='available')continue;
   if(pricing_audience_display($quote,'final',$currency)==='')continue;
   $eligible[]=$rate_id;
   break;
  }
 }
 return $cache[$cache_key]=array_values(array_unique($eligible));
}

add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
 if(!in_array($query->query_id??'',['tour_rates','tour_rate_single_tour'],true))return $args;
 if(is_admin() && !wp_doing_ajax())return $args;
 $tour=absint($args['queried_object_id']??0);
 if(get_post_type($tour)!=='tours-and-activities')$tour=absint(get_queried_object_id());
 if(get_post_type($tour)!=='tours-and-activities' || get_post_status($tour)!=='publish'){
  // An unbound public/AJAX query may not list rates from other Tours.
  $args['post__in']=[0];
  return $args;
 }
 $eligible=shared_product_eligible_tour_rate_ids($tour);
 $prior=$args['post__in']??null;
 if(is_array($prior) && count($prior)>0){
  $prior=array_values(array_filter(array_map('absint',$prior)));
  $eligible=array_values(array_intersect($eligible,$prior));
 }
 $args['post__in']=$eligible?:[0];
 return $args;
},40,2);
