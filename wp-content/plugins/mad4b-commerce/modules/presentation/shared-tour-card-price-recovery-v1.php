<?php
/**
 * Shared, read-only Elementor Tour/Extra card price cache reconciliation.
 * Owned by the original price evidence provider; active in Commerce and
 * Presentation runtimes, without duplicating pricing or enabling payment.
 * Never broad-flush Redis, orders, or private page caches.
 */
defined('ABSPATH')||exit;

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
  if((!$presentation && !$commerce) || !function_exists('tour_component_structured_price_value'))return;
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
