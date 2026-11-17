<?php
/**
 * Source/tenant-native Rate display, compatible with the Original MU router.
 *
 * Only Presentation-only companies need the fallback: the central Source
 * already loads these functions from the Original ordered modular map.
 * Do not start a second pricing provider, booking engine or checkout writer.
 */
defined('ABSPATH') || exit;

if (!function_exists('mad4b_commerce_native_rate_runtime_status_v1')) {
 function mad4b_commerce_native_rate_runtime_status_v1(): array {
  $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
  $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
  $required=array(
   'price_tag_provider'=>'tour_rate_native_card_price_value',
   'rate_selector'=>'shared_product_rate_selection_actions_markup',
   'rate_heading_provider'=>'tk_tour_rates_dynamic_function_cb',
  );
  $functions=[];
  foreach($required as $kind=>$function)$functions[$kind]=function_exists($function);
  $missing=[];
  foreach($functions as $kind=>$ok)if(!$ok)$missing[]=$kind;
  return array(
   'contract'=>'mad4b.commerce.native-rate-runtime.readonly.v1',
   'mode'=>$commerce?'commerce':($presentation?'presentation':'off'),
   'functional_readiness'=>$presentation||$commerce ? (!$missing?'ready':'hold') : 'not_applicable',
   'functions'=>$functions,
   'missing_functions'=>$missing,
   'source_of_truth'=>'existing_pricing_registry_and_original_rate_actions',
   'new_price_written'=>false,
   'checkout_ready_granted'=>false,
  );
 }
}

if (!function_exists('mad4b_commerce_native_rate_runtime_ensure_v1')) {
 function mad4b_commerce_native_rate_runtime_ensure_v1(): void {
  $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
  $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
  // The full original Commerce runtime belongs to the original ordered map.
  // This is strictly a *read-only presentation* fallback for site companies.
  if(!$presentation || $commerce)return;
  if(function_exists('tour_rate_native_card_price_value') &&
     function_exists('shared_product_rate_selection_actions_markup') &&
     function_exists('tk_tour_rates_dynamic_function_cb'))return;

  $plugin=dirname(__DIR__,2);
  $native=$plugin.'/modules/compat/tour-rate-native-display-tags.php';
  $action=$plugin.'/legacy-mu-tree/shared-product-core/adapters/rate-selection-actions.php';
  // Previous company deployments may still have the exact original adapter
  // under MU. Do not invent an adapter, and don't autoload unrelated modules.
  if(!is_file($action) && defined('WPMU_PLUGIN_DIR'))
   $action=WPMU_PLUGIN_DIR.'/shared-product-core/adapters/rate-selection-actions.php';
  $files=array(
   'actions'=>$action,
   'native_tags'=>$native,
   'rate_label_function'=>$plugin.'/modules/commerce/tour-rates-dynamic-function.php',
   'structured_tags'=>$plugin.'/modules/compat/assets/tour-rate-structured-tags.inc.php',
   'package_disclosure'=>$plugin.'/modules/compat/assets/tour-rate-package-disclosure.inc.php',
   'price_svg'=>$plugin.'/modules/compat/assets/tour-rate-original-price-svg.inc.php',
   'occupancy_label'=>$plugin.'/modules/compat/assets/tour-rate-occupancy-label.inc.php',
   'rate_css'=>defined('WPMU_PLUGIN_DIR')?WPMU_PLUGIN_DIR.'/assets/tour-rate-native-display.css':'',
   'popover_js'=>$plugin.'/assets/tour-rate-centered-popover.js',
  );
  $missing=[];
  foreach($files as $kind=>$path)
   if(!is_string($path)||$path===''||!is_file($path)||!is_readable($path)||is_link($path))$missing[]=$kind;
  if($missing){
   // Never load a partial UI that will show an empty price or a dead button.
   do_action('mad4b/native_rate_display/hold',array(
    'contract'=>'mad4b.rate-display.missing-dependency.v1',
    'missing_dependencies'=>$missing,'site_data_mutated'=>false));
   return;
  }
  // Existing presentation functions are read-only. Actions own neither a
  // Rate's numeric price nor supplier inventory, payment or booking status.
  if(!function_exists('tk_tour_rates_dynamic_function_cb'))require_once $files['rate_label_function'];
  if(!function_exists('shared_product_rate_selection_actions_markup'))require_once $action;
  if(!function_exists('tour_rate_native_card_price_value'))require_once $native;
  $status=mad4b_commerce_native_rate_runtime_status_v1();
  if($status['functional_readiness']!=='ready')
   do_action('mad4b/native_rate_display/hold',array(
    'contract'=>'mad4b.rate-display.provider-not-registered.v1',
    'missing_dependencies'=>$status['missing_functions'],'site_data_mutated'=>false));
 }
}
/** Tell content editors when the Rate UI is incomplete before publishing. */
if (!function_exists('mad4b_commerce_native_rate_admin_warning_v1')) {
 function mad4b_commerce_native_rate_admin_warning_v1(): void {
  if(!function_exists('get_current_screen') || !current_user_can('edit_posts'))return;
  $screen=get_current_screen();
  if(!is_object($screen) ||
     !in_array((string)($screen->post_type??''),
       ['tours-and-activities','tour-rates','elementor_library','jet-engine'],true))return;
  $status=mad4b_commerce_native_rate_runtime_status_v1();
  if($status['functional_readiness']!=='hold')return;
  $missing=implode(', ',array_map('sanitize_text_field',$status['missing_functions']));
  echo '<div class="notice notice-error" role="alert"><p><strong>';
  echo esc_html__('MAD4B Rate presentation is incomplete','mad4b-commerce');
  echo '</strong> — ';
  echo esc_html__('Source Rate price/selection providers are not loaded. This editor may save content, but release acceptance must remain on HOLD until independent frontend validation succeeds.','mad4b-commerce');
  if($missing!=='')echo ' <code>'.esc_html($missing).'</code>';
  echo '</p></div>';
 }
}
// Run after regular plugins are loaded, before Elementor widget/tag discovery.
add_action('plugins_loaded','mad4b_commerce_native_rate_runtime_ensure_v1',16);
add_action('admin_notices','mad4b_commerce_native_rate_admin_warning_v1',10);

/**
 * Exact Source multilingual Tour presentation in company Presentation mode.
 * Keep the original Source modular router authoritative when Commerce owns it.
 * This is localization and frontend-layout presentation, never a booking write.
 */
if (!function_exists('mad4b_commerce_native_tour_locale_ensure_v1')) {
 function mad4b_commerce_native_tour_locale_ensure_v1(): void {
  $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
    && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
  $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
    && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
  if(!$presentation || $commerce || class_exists('MAD4B_Tour_Multilingual_Interface_V1',false))return;
  $source=__DIR__.'/mad4b-tour-multilingual-interface-v1.php';
  if(!is_file($source) || !is_readable($source) || is_link($source)){
   do_action('mad4b/tour_locale/hold',array('reason'=>'original_multilingual_module_missing'));
   return;
  }
  require_once $source;
 }
}
add_action('plugins_loaded','mad4b_commerce_native_tour_locale_ensure_v1',18);

/**
 * Original read-only Elementor price tags for the Tour/Extra card presenters.
 * This is independent of the Rate accordion: direct-priced day tours also
 * depend on this tag in Featured Tours Listing and related JetEngine cards.
 * The original full Commerce router is authoritative where enabled.
 */
if (!function_exists('mad4b_commerce_native_tour_card_price_tags_ensure_v1')) {
 function mad4b_commerce_native_tour_card_price_tags_ensure_v1(): void {
  $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
    && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
  $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
    && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
  if(!$presentation || $commerce || function_exists('tour_component_structured_price_value'))return;
  $required=array('tour_component_price_evidence','tour_component_price_current_id');
  $missing=array_values(array_filter($required,static fn($fn)=>!function_exists($fn)));
  $file=dirname(__DIR__).'/commerce/tour-component-structured-price-tags.php';
  if(!is_file($file) || !is_readable($file) || is_link($file))$missing[]='native_source_price_tags';
  if($missing){
   do_action('mad4b/tour_card_price/hold',array(
    'contract'=>'mad4b.tour-card-price.original-tags-missing.v1',
    'missing_dependencies'=>$missing,'price_mutated'=>false));
   return;
  }
  // Register the Source Elementor Dynamic Tags at its original hook/priority.
  // Do not define another amount, conversion, audience or rate calculator.
  require_once $file;
 }
}
add_action('plugins_loaded','mad4b_commerce_native_tour_card_price_tags_ensure_v1',17);
