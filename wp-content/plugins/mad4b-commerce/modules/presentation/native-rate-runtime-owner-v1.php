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

/**
 * Dependency-addressed recovery of cached Elementor Tour/Extra price cards.
 * Once per SOURCE CODE fingerprint, never per visitor or per booking attempt.
 * Do not clear Redis, WooCommerce, customer quotes, or a global cache epoch.
 */
if (!function_exists('mad4b_commerce_native_card_price_cache_reconcile_v1')) {
 function mad4b_commerce_native_card_price_cache_reconcile_v1($manager=null): void {
  $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')
    && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
  $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
    && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
  if(!$presentation || $commerce || !function_exists('tour_component_structured_price_value'))return;
  $provider=dirname(__DIR__).'/commerce/tour-component-structured-price-tags.php';
  if(!is_file($provider) || !is_readable($provider) || is_link($provider))return;
  $fingerprint=hash('sha256',hash_file('sha256',$provider).'|'.hash_file('sha256',__FILE__));
  $key='mad4b_tour_card_price_source_fingerprint_v1';
  if(get_option($key,'')===$fingerprint)return;
  // A failing Host socket must not cause every subsequent visitor to repeat
  // SQL template scans and cache invalidation. The existing Redis-backed
  // runtime group bounds retries; success alone pins the source fingerprint.
  $retry_key='mad4b_tour_card_price_retry_'.substr($fingerprint,0,20);
  if(wp_cache_get($retry_key,'mad4b-runtime'))return;
  // Do not invalidate Elementor render data until a scoped Host cache owner
  // is actually available. No global or Redis flush fallback is permitted.
  if(!class_exists('MAD4B_Cache_Control_V1',false)){
   wp_cache_set($retry_key,1,'mad4b-runtime',300);
   do_action('mad4b/tour_card_price/hold',array('reason'=>'scoped_public_cache_owner_missing'));
   return;
  }
  // Do not mark the dependency certified when Elementor cannot resolve its
  // actual registered tag, even if the PHP formatting function exists.
  if(!is_object($manager) || !method_exists($manager,'get_tags'))return;
  $registered=$manager->get_tags();
  if(!isset($registered['tour-component-price-text'])){
   do_action('mad4b/tour_card_price/hold',array('reason'=>'elementor_tag_not_registered'));
   return;
  }
  $lock_key='mad4b_tour_card_price_reconcile_v1';
  if(!wp_cache_add($lock_key,1,'mad4b-runtime',60))return;
  try {
   global $wpdb;
   // Discover actual published template dependencies. Never assume numeric
   // Elementor/JetEngine template IDs are shared between companies or WPML.
   $pattern='%'.$wpdb->esc_like('tour-component-price-').'%';
   $ids=$wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
     WHERE p.post_type IN ('jet-engine','elementor_library') AND p.post_status='publish'
     AND m.meta_key='_elementor_data' AND m.meta_value LIKE %s LIMIT 65",$pattern));
   if(!is_array($ids) || count($ids)>64){
    wp_cache_set($retry_key,1,'mad4b-runtime',300);
    do_action('mad4b/tour_card_price/hold',array('reason'=>'dependent_templates_exceed_safe_limit'));
    return;
   }
   foreach($ids as $id)delete_post_meta((int)$id,'_elementor_element_cache');
   // The site-local authority versions ONLY safe public paths; checkout,
   // customer data and object cache remain untouched.
   $paths=array('/','/tours-and-activities/');
   $languages=apply_filters('wpml_active_languages',null,array('skip_missing'=>0));
   $default=(string)apply_filters('wpml_default_language',null);
   if(is_array($languages))foreach(array_keys($languages) as $lang){
    if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',(string)$lang) || $lang===$default)continue;
    $paths[]='/'.$lang.'/';$paths[]='/'.$lang.'/tours-and-activities/';
   }
   $paths=array_values(array_unique($paths));
   $purge=MAD4B_Cache_Control_V1::socket_purge($paths);
   if(($purge['status']??'')!=='ok'){
    wp_cache_set($retry_key,1,'mad4b-runtime',300);
    do_action('mad4b/tour_card_price/hold',array('reason'=>'public_cache_reconcile_failed'));
    return;
   }
   if(!update_option($key,$fingerprint,false)){
    wp_cache_set($retry_key,1,'mad4b-runtime',300);
    do_action('mad4b/tour_card_price/hold',array('reason'=>'fingerprint_commit_failed'));
   }
  } finally {
   wp_cache_delete($lock_key,'mad4b-runtime');
  }
 }
}
add_action('elementor/dynamic_tags/register','mad4b_commerce_native_card_price_cache_reconcile_v1',90,1);
