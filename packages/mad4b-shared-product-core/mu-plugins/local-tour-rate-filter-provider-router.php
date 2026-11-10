<?php
/** Native JetSmartFilters provider routing for original Grid/Accordion modes. */
if(!defined('ABSPATH'))return;
add_action('elementor/frontend/widget/before_render',static function($widget){
 if(!function_exists('shared_presentation_primary_tour_post_v1'))return;
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return;
 if(!is_object($widget)||!method_exists($widget,'get_name')||!method_exists($widget,'get_settings')||!method_exists($widget,'set_settings'))return;
 if(!str_starts_with((string)$widget->get_name(),'jet-smart-filters-'))return;
 if((string)$widget->get_settings('query_id')!=='tour_rates')return;
 $tour_id=(int)$tour_post->ID;
 if(!$tour_id||get_post_type($tour_id)!=='tours-and-activities')return;
 if((class_exists('MAD4B_Tour_Rate_View_Inheritance_V1') ? MAD4B_Tour_Rate_View_Inheritance_V1::resolve($tour_id)['style']==='accordion' : (string)get_post_meta($tour_id,'tour_rates_display_style',true)==='Accordion')
  && class_exists('Jet_Engine\\Query_Builder\\Manager')
  && \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id('tour_rates_accordion'))
  $widget->set_settings('query_id','tour_rates_accordion');
},12);
