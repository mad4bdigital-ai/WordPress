<?php
/** Native signed JSF Tour identity; no extra pricing/query engine.
 * Elementor is the source of the Tour on render; the JetSmartFilters
 * provider remains responsible for signing and transporting settings.
 */
defined('ABSPATH') || exit;
if (function_exists('wp_get_environment_type') && wp_get_environment_type()==='local') {
add_action('elementor/frontend/widget/after_render',static function($widget):void {
 if(wp_get_environment_type()!=='local'||wp_doing_ajax()
  ||!function_exists('shared_presentation_primary_tour_post_v1')
  ||!is_object($widget)||!method_exists($widget,'get_name')
  ||$widget->get_name()!=='jet-listing-grid'||!function_exists('jet_smart_filters'))return;
 $tour_post=shared_presentation_primary_tour_post_v1();
 if(!($tour_post instanceof WP_Post))return;
 $tour=(int)$tour_post->ID;
 if(!$tour||get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')return;
 $s=$widget->get_settings();
 $mode=get_post_meta($tour,'tour_rates_display_style',true)==='Accordion'?'accordion':'grid';
 $provider=$mode==='accordion'?'tour_rates_accordion':'tour_rates';
 $listing=$mode==='accordion'?56197:46193;
 if(($s['_element_id']??'')!==$provider
  ||(int)($s['lisitng_id']??0)!==$listing
  ||(int)($s['custom_query_id']??0)!==310)return;
 $manager=jet_smart_filters()->providers;
 $stored=$manager->get_provider_settings();
 if((int)($stored['jet-engine'][$provider]['lisitng_id']??0)!==$listing)return;
 $manager->add_provider_settings('jet-engine',['queried_id'=>$tour],$provider);
},20);
}
