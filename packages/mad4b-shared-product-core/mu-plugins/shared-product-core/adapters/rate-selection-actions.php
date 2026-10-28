<?php
/** Reusable rate selection actions, limited to single-tour context. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
/** Buttons are rendered inside both original listing-item Elementor layouts. */
function shared_product_rate_selection_actions_markup(){
 global $wp_the_query; $rate=get_the_ID(); $tour_page=$wp_the_query instanceof WP_Query && $wp_the_query->is_singular('tours-and-activities') && get_post_type($wp_the_query->get_queried_object_id())==='tours-and-activities'; if (!$tour_page && wp_doing_ajax() && ($_REQUEST['action']??'')==='jet_smart_filters' && ($_REQUEST['provider']??'')==='jet-engine/tour_rates' && function_exists('jet_smart_filters')) {
  $request=jet_smart_filters()->data->get_request(); $settings=$request['settings']??[];
  if (is_array($settings) && !empty($settings['jsf_signature'])) {
   $signature=(string)$settings['jsf_signature']; unset($settings['jsf_signature']);
   $render=jet_smart_filters()->render;
   $expected=$render->create_signature($render->prepare_provider_settings_for_signature($settings,'jet-engine'));
   $tour_id=absint($settings['queried_id']??0); $ref=wp_get_referer();
   $tour_page=hash_equals($expected,$signature) && get_post_type($tour_id)==='tours-and-activities' && get_post_status($tour_id)==='publish' && $ref && (int)url_to_postid($ref)===$tour_id;
  }
 }
 if (!$tour_page || get_post_type($rate)!=='tour-rates') return '';
 // Source of the visual package tier is the attached Tour Rate term, not
 // a frontend text badge. Unknown tiers remain neutral.
 $term_id=absint(get_post_meta($rate,'related_package_term_id',true));
 $term=$term_id?get_term($term_id,'package_levels'):null;
 $tier=($term instanceof WP_Term&&!is_wp_error($term))?sanitize_title($term->name):'';
 $tone=$tier==='gold'?'gold':'default';
 return '<div class="rate-select-actions" data-rate-actions="'.esc_attr($rate).'" data-rate-tier="'.esc_attr($tone).'">'
 .'<button type="button" class="rate-choose-rate-for-preview" data-rate-booking-rate-id="'.esc_attr($rate).'" aria-pressed="false">Select</button>'
 .'<button type="button" class="rate-remove-rate-preview" data-rate-remove-rate-id="'.esc_attr($rate).'" aria-label="Deselect package" title="Deselect package" hidden><span class="rate-accordion-remove-icon" aria-hidden="true">×</span><span class="rate-native-selected-label" aria-hidden="true">Selected</span></button>'
 .'</div>';
}
add_shortcode('rate_select_actions','shared_product_rate_selection_actions_markup');

add_action('wp_footer',static function(){if(!is_singular('tours-and-activities'))return; ?>
<script id="rate-selection-actions-handler">(function(){if(window.rateSelectionActionsReady)return;window.rateSelectionActionsReady=true;document.addEventListener('click',function(e){const button=e.target.closest('[data-rate-booking-rate-id],[data-rate-remove-rate-id]');if(!button)return;const group=button.closest('[data-rate-actions]');if(!group)return;const remove=button.hasAttribute('data-rate-remove-rate-id');const rateId=remove?button.dataset.rateRemoveRateId:button.dataset.rateBookingRateId;document.dispatchEvent(new CustomEvent('rate-selection-request',{detail:{rateId,remove}}));});})();</script>
<?php },40);
