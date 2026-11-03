<?php
/** Native JetSmartFilters provider routing for original Grid/Accordion modes. */
if(!defined('ABSPATH'))return;
add_action('elementor/frontend/widget/before_render',static function($widget){
 if(wp_get_environment_type()!=='local')return;
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return;
 if(!is_object($widget)||!method_exists($widget,'get_name')||!method_exists($widget,'get_settings')||!method_exists($widget,'set_settings'))return;
 if(!str_starts_with((string)$widget->get_name(),'jet-smart-filters-'))return;
 if((string)$widget->get_settings('query_id')!=='tour_rates')return;
 $tour_id=(int)$tour_post->ID;
 if(!$tour_id||get_post_type($tour_id)!=='tours-and-activities')return;
 if((string)get_post_meta($tour_id,'tour_rates_display_style',true)==='Accordion')
  $widget->set_settings('query_id','tour_rates_accordion');
},12);
