<?php
/**
 * Plugin Name: MAD4B Staging Empty JetEngine CSS Fastpath
 * Description: Avoid confirmed empty per-card dynamic CSS only on a pinned, anonymous staging tours archive.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Empty_Jet_CSS_Fastpath_V1 {
 private const HOST = 'tours.dev.mad4b.com';
 private const SITE_UUID = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const LISTING_ID = 67125;
 private const LISTING_JSON_BYTES = 5478;
 private const LISTING_JSON_SHA256 = '079e0deb501c27ccbd92f74418e03f69716d6372b6da104b4a853a57a0b892f6';

 private static function allowed(): bool {
  if (PHP_SAPI === 'cli' || wp_get_environment_type() !== 'staging'
   || is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
   || !is_post_type_archive('tours-and-activities')) return false;
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
   || !empty($_SERVER['QUERY_STRING']) || !empty($_SERVER['HTTP_COOKIE'])
   || !empty($_SERVER['HTTP_AUTHORIZATION'])
   || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
   || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
   || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
   || !empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])) return false;

  // Native behavior remains directly testable without changing any stored settings.
  if (($_SERVER['HTTP_X_MAD4B_DCSS_CONTROL'] ?? '') === 'native-v1') return false;
  if ((string)wp_parse_url(home_url(), PHP_URL_HOST) !== self::HOST
   || ($_SERVER['HTTP_HOST'] ?? '') !== self::HOST) return false;
  $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
  if (!is_array($profile) || ($profile['site_uuid'] ?? '') !== self::SITE_UUID) return false;
  if (!function_exists('get_woocommerce_currency') || get_woocommerce_currency() !== 'USD') return false;

  $path = (string)wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  $locales = [
   '/tours-and-activities/' => 'en',
   '/fr/tours-and-activities/' => 'fr',
   '/de/tours-and-activities/' => 'de',
   '/ar/tours-and-activities/' => 'ar',
   '/es/tours-and-activities/' => 'es',
   '/it/tours-and-activities/' => 'it',
  ];
  return isset($locales[$path]) && apply_filters('wpml_current_language', null) === $locales[$path];
 }

 private static function pinned_listing_is_unchanged(): bool {
  $post = get_post(self::LISTING_ID);
  if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'jet-engine') return false;
  $json = get_post_meta(self::LISTING_ID, '_elementor_data', true);
  return is_string($json) && strlen($json) === self::LISTING_JSON_BYTES
   && hash_equals(self::LISTING_JSON_SHA256, hash('sha256', $json));
 }

 public static function render_listing($html, $listing_id, $inner_templates, callable $native) {
  // Verified template uses dynamic tags only for content image, link and title.
  // It added zero dynamic CSS bytes in all six locales in the 2026-11-01 cold-path proof.
  if ((int)$listing_id === self::LISTING_ID && empty($inner_templates)) return $html;
  return $native($html, $listing_id, $inner_templates);
 }

 public static function boot(): void {
  if (!self::allowed() || !self::pinned_listing_is_unchanged()) return;
  global $wp_filter;
  $hook = $wp_filter['jet-engine/elementor-views/frontend/listing-content'] ?? null;
  if (!($hook instanceof WP_Hook) || count($hook->callbacks[10] ?? []) !== 1) return;

  $native = null;
  foreach ($hook->callbacks[10] as $entry) {
   $candidate = $entry['function'];
   if (is_array($candidate) && is_object($candidate[0])
    && get_class($candidate[0]) === 'Jet_Engine_Dynamic_Tags_Manager'
    && $candidate[1] === 'add_listing_item_dynamic_css') {
    $native = $candidate;
   }
  }
  if (!$native) return;
  remove_filter('jet-engine/elementor-views/frontend/listing-content', $native, 10);
  add_filter('jet-engine/elementor-views/frontend/listing-content',
   static function($html, $listing_id, $inner_templates) use ($native) {
    return self::render_listing($html, $listing_id, $inner_templates, $native);
   }, 10, 3);
 }
}
add_action('wp', ['MAD4B_Empty_Jet_CSS_Fastpath_V1', 'boot'], 99);
