<?php
/**
 * Plugin Name: MAD4B Safe Destination Navigation Fragment Cache (Staging)
 * Description: Short-lived server-rendered JetEngine term-card cache with source, locale, term and visitor guards.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Nav_Term_Fragment_Cache_V1 {
 private const HOST = 'tours.dev.mad4b.com';
 private const SITE_UUID = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const TEMPLATE = 67125;
 private const JSON_SIZE = 5478;
 private const JSON_SHA256 = '079e0deb501c27ccbd92f74418e03f69716d6372b6da104b4a853a57a0b892f6';
 private const TTL = 45;
 private const GROUP = 'mad4b_nav_term_cards_v1';

 private static function eligible(): bool {
  if (PHP_SAPI === 'cli' || wp_get_environment_type() !== 'staging' ||
   is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview() ||
   !is_post_type_archive('tours-and-activities')) return false;
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' ||
   !empty($_SERVER['QUERY_STRING']) || !empty($_SERVER['HTTP_COOKIE']) ||
   !empty($_SERVER['HTTP_AUTHORIZATION']) ||
   !empty($_SERVER['HTTP_X_MAD4B_CURRENCY']) ||
   !empty($_SERVER['HTTP_X_GEO_COUNTRY']) ||
   !empty($_SERVER['HTTP_CF_IPCOUNTRY']) ||
   !empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])) return false;
  if (($_SERVER['HTTP_X_MAD4B_NAV_CACHE_CONTROL'] ?? '') === 'native-v1') return false;
  if (($_SERVER['HTTP_HOST'] ?? '') !== self::HOST ||
   (string)wp_parse_url(home_url(), PHP_URL_HOST) !== self::HOST) return false;
  $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
  if (!is_array($profile) || ($profile['site_uuid'] ?? '') !== self::SITE_UUID) return false;
  if (!function_exists('get_woocommerce_currency') || get_woocommerce_currency() !== 'USD') return false;

  $paths = [
   '/tours-and-activities/' => 'en',
   '/fr/tours-and-activities/' => 'fr',
   '/de/tours-and-activities/' => 'de',
   '/ar/tours-and-activities/' => 'ar',
   '/es/tours-and-activities/' => 'es',
   '/it/tours-and-activities/' => 'it',
  ];
  $path = (string)wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  return isset($paths[$path]) &&
   apply_filters('wpml_current_language', null) === $paths[$path];
 }

 private static function source_matches(): bool {
  $post = get_post(self::TEMPLATE);
  if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'jet-engine') return false;
  $json = get_post_meta(self::TEMPLATE, '_elementor_data', true);
  return is_string($json) && strlen($json) === self::JSON_SIZE &&
   hash_equals(self::JSON_SHA256, hash('sha256', $json));
 }

 public static function render_term($content, $listing_id, callable $native, string $locale) {
  if ((int)$listing_id !== self::TEMPLATE) {
   return $native($content, $listing_id);
  }
  $term = jet_engine()->listings->data->get_current_object();
  if (!($term instanceof WP_Term) || $term->taxonomy !== 'destinations' || $term->term_id < 1) {
   return $native($content, $listing_id);
  }

  $term_info = [
   (int)$term->term_id, (string)$term->taxonomy, (int)$term->term_taxonomy_id,
   (string)$term->name, (string)$term->slug, (string)$term->description,
   (int)$term->parent, (int)$term->count, get_term_meta($term->term_id),
  ];
  $key = 'term-' . hash('sha256', wp_json_encode([
   self::JSON_SHA256, $locale, (bool)wp_is_mobile(), $term_info,
  ]));
  $cached = wp_cache_get($key, self::GROUP);
  if (is_string($cached) && $cached !== '' &&
   class_exists('Elementor\\Core\\Files\\CSS\\Post')) {
   $css_file = \Elementor\Core\Files\CSS\Post::create(self::TEMPLATE);
   if ($css_file) {
    $css_file->enqueue(); // Preserve Elementor stylesheet on fragment-only renders.
    return $cached;
   }
  }
  $html = $native($content, $listing_id);
  if (is_string($html) && $html !== '') {
   wp_cache_set($key, $html, self::GROUP, self::TTL);
  }
  return $html;
 }

 public static function activate(): void {
  if (!self::eligible() || !self::source_matches()) return;
  global $wp_filter;
  $hook = $wp_filter['jet-engine/listing/content/elementor'] ?? null;
  if (!($hook instanceof WP_Hook) || count($hook->callbacks[10] ?? []) !== 1) return;
  $native = null;
  foreach ($hook->callbacks[10] as $entry) {
   $cb = $entry['function'];
   if (is_array($cb) && is_object($cb[0]) &&
    get_class($cb[0]) === 'Jet_Engine_Elementor_Frontend' &&
    $cb[1] === 'get_listing_content_cb') $native = $cb;
  }
  if (!$native) return;
  remove_filter('jet-engine/listing/content/elementor', $native, 10);
  $locale = apply_filters('wpml_current_language', null);
  add_filter('jet-engine/listing/content/elementor',
   static function($content, $listing_id) use ($native, $locale) {
    return self::render_term($content, $listing_id, $native, (string)$locale);
   }, 10, 2);
 }
}
add_action('wp', ['MAD4B_Nav_Term_Fragment_Cache_V1', 'activate'], 101);
