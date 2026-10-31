<?php
/** Add reversible, client-side selection to authored Elementor listing items. */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts',static function(){
 if(!is_singular())return;
 $base=content_url('mu-plugins/listing-option-selection-assets/');
 $dir=__DIR__.'/listing-option-selection-assets/';
 wp_enqueue_style('listing-option-selection',$base.'options.css',[],(string)filemtime($dir.'options.css'));
 wp_enqueue_script('listing-option-selection',$base.'options.js',[],(string)filemtime($dir.'options.js'),true);
});

/** Project the canonical accommodation plan onto an authored native hotel grid. */
add_action('elementor/frontend/widget/before_render',static function($widget){
 if($widget->get_name()!=='jet-listing-grid')return;
 $classes=preg_split('/\s+/',trim((string)$widget->get_settings('_css_classes')));
 if(!in_array('booking-choice-list',$classes,true))return;
 $object=function_exists('jet_engine')?jet_engine()->listings->data->get_current_object():null;
 $rate=$object instanceof WP_Post&&$object->post_type==='tour-rates'?(int)$object->ID:0;
 if(!$rate&&get_post_type(get_the_ID())==='tour-rates')$rate=(int)get_the_ID();
 $plan=['mode'=>'unavailable','stages'=>[]];
 if($rate&&function_exists('BookingExperience\\tour_stay_rate_plan')&&
    (string)get_post_meta($rate,'has_properties',true)!=='0'&&
    (!function_exists('service_channel_allowed')||service_channel_allowed('properties','tour_component'))){
  $source=BookingExperience\tour_stay_rate_plan($rate);
  if(!is_wp_error($source)){
   $plan=['mode'=>$source['mode'],'stages'=>[]];
   if($source['mode']==='simple_or')$plan['stages'][]=['key'=>'simple','label'=>__('Hotel alternatives','woocommerce'),'properties'=>array_map('intval',$source['properties'])];
   else foreach($source['stages']as$stage)$plan['stages'][]=['key'=>$stage['key'],'label'=>$stage['label'],'properties'=>array_map('intval',$stage['properties'])];
  }else $plan['error']=$source->get_error_code();
 }
 // A Rate with no eligible Properties has no accommodation selection UI.
 // Evaluate canonical Rate relations, not locale-specific hard-coded sections.
 $eligible=false;
 foreach((array)($plan['stages']??[]) as $stage){
  foreach((array)($stage['properties']??[]) as $property_id){
   if((int)$property_id>0 && get_post_type((int)$property_id)==='properties'
      && get_post_status((int)$property_id)==='publish'){$eligible=true;break 2;}
  }
 }
 if(!$eligible)$plan=['mode'=>'unavailable','stages'=>[],'reason'=>'no_eligible_rate_properties'];
 $widget->add_render_attribute('_wrapper','data-booking-choice-eligible',$eligible?'1':'0');
 $widget->add_render_attribute('_wrapper','data-booking-choice-plan',wp_json_encode($plan));
});

/** JetEngine source for both simple OR and stage-only native hotel listings. */
function listing_option_selection_property_ids($rate){
 if(get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'||
    (string)get_post_meta($rate,'has_properties',true)==='0'||
    !function_exists('BookingExperience\\tour_stay_rate_plan')||
    (function_exists('service_channel_allowed')&&!service_channel_allowed('properties','tour_component')))return [];
 $plan=BookingExperience\tour_stay_rate_plan($rate);if(is_wp_error($plan))return [];
 $ids=$plan['mode']==='simple_or'?$plan['properties']:[];
 foreach($plan['stages']??[]as$stage)$ids=array_merge($ids,$stage['properties']);
 return array_values(array_unique(array_map('intval',$ids)));
}
add_filter('jet-engine/listings/macros-list',static function($macros){
 $macros['tour_rate_accommodation_ids']=['label'=>'Tour Rate eligible accommodation IDs','cb'=>static function(){
  $object=jet_engine()->listings->macros->get_macros_object();
  if(!$object instanceof WP_Post)$object=jet_engine()->listings->data->get_current_object();
  $rate=$object instanceof WP_Post&&$object->post_type==='tour-rates'?(int)$object->ID:0;
  $ids=$rate?listing_option_selection_property_ids($rate):[];
  // A nonempty impossible ID keeps an absent/invalid plan from becoming an unrestricted query.
  return $ids?implode(',',$ids):(string)PHP_INT_MAX;
 }];return $macros;
});
