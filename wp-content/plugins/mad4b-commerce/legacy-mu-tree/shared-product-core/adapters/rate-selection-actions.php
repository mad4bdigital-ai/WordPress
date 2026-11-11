<?php
/** Reusable rate selection actions, limited to single-tour context. */
if (!defined('ABSPATH') || (!(defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true) && !(defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true))) return;
/** Native action labels shared by both rate listing styles and active WPML locales. */
if(!function_exists('mad4b_rate_action_label_v1')){
 function mad4b_rate_action_label_v1(string $kind):string {
  $labels=['select'=>'Select','remove'=>'Deselect package','selected'=>'Selected'];
  if(!isset($labels[$kind]))return '';
  $base=$labels[$kind];
  $translated=apply_filters('wpml_translate_single_string',$base,'MAD4B Rate Selection UI',$base);
  if(is_string($translated)&&trim($translated)!==''&&$translated!==$base)return $translated;
  $lang=(string)apply_filters('wpml_current_language','');
  $fallback=[
    'ar'=>['select'=>'اختر السعر','remove'=>'إلغاء اختيار الباقة','selected'=>'محدد'],
    'de'=>['select'=>'Tarif wählen','remove'=>'Paket abwählen','selected'=>'Ausgewählt'],
    'fr'=>['select'=>'Choisir le tarif','remove'=>'Désélectionner le forfait','selected'=>'Sélectionné'],
    'es'=>['select'=>'Elegir tarifa','remove'=>'Deseleccionar paquete','selected'=>'Seleccionado'],
    'it'=>['select'=>'Seleziona tariffa','remove'=>'Deseleziona pacchetto','selected'=>'Selezionato'],
  ];
  return $fallback[$lang][$kind]??$base;
 }
}
add_action('init',static function(){
 foreach(['Select','Deselect package','Selected'] as $v)
  do_action('wpml_register_single_string','MAD4B Rate Selection UI',$v,$v);
},22);
/** Resolve visual package tiers from the English canonical term, not WPML display labels. */
if (!function_exists('mad4b_rate_package_tier_code_v1')) {
 function mad4b_rate_package_tier_code_v1(int $rate):string {
  $term_id=absint(get_post_meta($rate,'related_package_term_id',true));
  $term=$term_id?get_term($term_id,'package_levels'):null;
  if (!($term instanceof WP_Term) || is_wp_error($term)) return '';
  $english_id=(int)apply_filters('wpml_object_id',$term_id,'package_levels',false,'en');
  $canonical_id=$english_id>0?$english_id:$term_id;
  static $code_cache=[];
  if (!array_key_exists($canonical_id,$code_cache)) {
   global $wpdb;
   // WPML translates get_term(english_id) into the current locale; fetch the
   // canonical term code without switching visitor language or modifying data.
   $name=$wpdb->get_var($wpdb->prepare(
    "SELECT t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE t.term_id=%d AND tt.taxonomy=%s LIMIT 1",
    $canonical_id,'package_levels'));
   $code_cache[$canonical_id]=is_string($name)?sanitize_title($name):'';
  }
  return $code_cache[$canonical_id];
 }
}
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
 // The native JetEngine AJAX renderer may not populate $wp_the_query with a
 // singular Tour; reuse the independently verified signed rate context.
 if(!$tour_page && get_post_type($rate)==='tour-rates'
   && class_exists('MAD4B_Tour_Rates_Native_Lazy_V1',false)){
   $owner=absint(get_post_meta($rate,'related_tour_id',true));
   $lang=MAD4B_Tour_Rates_Native_Lazy_V1::trusted_ajax_language($owner);
   $details=apply_filters('wpml_post_language_details',null,$rate);
   $tour_page=$lang!=='' && get_post_status($rate)==='publish'
     && is_array($details) && ($details['language_code']??'')===$lang
     && function_exists('shared_product_eligible_tour_rate_ids')
     && in_array((int)$rate,shared_product_eligible_tour_rate_ids($owner),true);
 }
 if (!$tour_page || get_post_type($rate)!=='tour-rates') return '';
 // Source of the visual package tier is the attached Tour Rate term, not
 // a frontend text badge. Unknown tiers remain neutral.
 $tier=mad4b_rate_package_tier_code_v1((int)$rate);
 $tone=$tier==='gold'?'gold':'default';
 return '<div class="rate-select-actions" data-rate-actions="'.esc_attr($rate).'" data-rate-tier="'.esc_attr($tone).'">'
 .'<button type="button" class="rate-choose-rate-for-preview" data-rate-booking-rate-id="'.esc_attr($rate).'" aria-pressed="false">'.esc_html(mad4b_rate_action_label_v1('select')).'</button>'
 .'<button type="button" class="rate-remove-rate-preview" data-rate-remove-rate-id="'.esc_attr($rate).'" aria-label="'.esc_attr(mad4b_rate_action_label_v1('remove')).'" title="'.esc_attr(mad4b_rate_action_label_v1('remove')).'" hidden><span class="rate-accordion-remove-icon" aria-hidden="true">×</span><span class="rate-native-selected-label" aria-hidden="true">'.esc_html(mad4b_rate_action_label_v1('selected')).'</span></button>'
 .'</div>';
}
add_shortcode('rate_select_actions','shared_product_rate_selection_actions_markup');

add_action('wp_footer',static function(){if(!is_singular('tours-and-activities'))return; ?>
<script id="rate-selection-actions-handler">(function(){if(window.rateSelectionActionsReady)return;window.rateSelectionActionsReady=true;document.addEventListener('click',function(e){const button=e.target.closest('[data-rate-booking-rate-id],[data-rate-remove-rate-id]');if(!button)return;const group=button.closest('[data-rate-actions]');if(!group)return;const remove=button.hasAttribute('data-rate-remove-rate-id');const rateId=remove?button.dataset.rateRemoveRateId:button.dataset.rateBookingRateId;document.dispatchEvent(new CustomEvent('rate-selection-request',{detail:{rateId,remove}}));});})();</script>
<?php },40);
