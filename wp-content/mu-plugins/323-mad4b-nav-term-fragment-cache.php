<?php
/**
 * Plugin Name: MAD4B Safe Destination Navigation Fragment Cache (Staging)
 * Description: Short-lived server-rendered JetEngine term-card cache with source, locale, term and visitor guards.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Nav_Term_Fragment_Cache_V1 {
 private const HOST = 'tours.dev.mad4b.com';
 private const SITE_UUID = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private const TEMPLATE = 67125;
 private const JSON_SIZE = 5478;
 private const JSON_SHA256 = '079e0deb501c27ccbd92f74418e03f69716d6372b6da104b4a853a57a0b892f6';
 private const TTL = 45;
 private const SNAPSHOT_DIR = '/run/mad4b-oauth/mad4b-public-nav-term-v1';
 private static string $snapshot_dir = self::SNAPSHOT_DIR;
 private const SNAPSHOT_MAX_BYTES = 131072;
 private const SNAPSHOT_MAX_AGE = 604800;
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


 private static function snapshot_key(string $original_key, WP_Term $term): ?string {
  if (!function_exists('get_option') || !function_exists('get_term_link')) return null;
  $url = get_term_link($term);
  if (!is_string($url) || !str_starts_with($url, 'https://')) return null;
  static $context = null;
  if (!is_string($context)) {
   $kit = absint(get_option('elementor_active_kit'));
   $context = hash('sha256', wp_json_encode([
    home_url('/'), get_option('permalink_structure'),
    get_option('icl_sitepress_settings'), get_option('elementor_css_print_method'),
    defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
    defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : '',
    (string)wp_get_theme()->get('Version'),
    $kit, $kit ? get_post_modified_time('U', true, $kit) : null,
    $kit ? get_post_meta($kit, '_elementor_page_settings', true) : null,
   ]));
  }
  $featured = get_term_meta($term->term_id, 'featured_image', true);
  $image_state = null;
  if (is_numeric($featured) && (int)$featured > 0) {
   $id = (int)$featured;
   $image_state = [
    get_post_modified_time('U', true, $id),
    get_post_meta($id, '_wp_attachment_metadata', true),
   ];
  }
  return hash('sha256', wp_json_encode([$original_key, $context, $url, $image_state]));
 }

 private static function snapshot_path(string $key): ?string {
  if (!preg_match('/^[a-f0-9]{64}$/D', $key)) return null;
  $dir = self::$snapshot_dir;
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
  if (is_link($dir) || !is_dir($dir) || !is_writable($dir)
    || realpath($dir) !== $dir) return null;
  return $dir . '/term-' . $key . '.snapshot';
 }

 private static function read_snapshot(string $key): ?string {
  $path = self::snapshot_path($key);
  if (!$path || is_link($path) || !is_file($path)) return null;
  $mtime = @filemtime($path);
  if (!$mtime || $mtime < time() - self::SNAPSHOT_MAX_AGE) return null;
  $blob = @file_get_contents($path, false, null, 0, self::SNAPSHOT_MAX_BYTES + 100);
  if (!is_string($blob) || strlen($blob) > self::SNAPSHOT_MAX_BYTES + 100
    || strpos($blob, "\n") !== 64) return null;
  $html = substr($blob, 65);
  if (strlen($html) < 80 || strlen($html) > self::SNAPSHOT_MAX_BYTES
    || !hash_equals(substr($blob, 0, 64), hash('sha256', $html))) return null;
  // Sliding lifetime: frequently visited public navigation never re-enters the
  // expensive Elementor renderer just because a time window expired.
  if (time() - $mtime > 86400) @touch($path);
  return $html;
 }

 private static function write_snapshot(string $key, string $html): void {
  if (strlen($html) < 80 || strlen($html) > self::SNAPSHOT_MAX_BYTES
    || preg_match('~<(?:script|form)\b~i', $html)) return;
  $path = self::snapshot_path($key);
  if (!$path || is_link($path)) return;
  $tmp = @tempnam(dirname($path), '.m4nav-');
  if (!is_string($tmp) || !str_starts_with($tmp, dirname($path) . '/')) return;
  @chmod($tmp, 0600);
  $blob = hash('sha256', $html) . "\n" . $html;
  if (@file_put_contents($tmp, $blob, LOCK_EX) !== strlen($blob)
    || !@rename($tmp, $path)) @unlink($tmp);
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
  $disk_only = ($_SERVER['HTTP_X_MAD4B_NAV_CACHE_CONTROL'] ?? '') === 'disk-v1';
  $cached = $disk_only ? false : wp_cache_get($key, self::GROUP);
  $snapshot_key = null;
  $from_disk = false;
  if (!is_string($cached) || $cached === '') {
   $snapshot_key = self::snapshot_key($key, $term);
   $cached = $snapshot_key === null ? false : self::read_snapshot($snapshot_key);
   $from_disk = is_string($cached) && $cached !== '';
  }
  if (is_string($cached) && $cached !== '' &&
   class_exists('Elementor\Core\Files\CSS\Post')) {
   $css_file = \Elementor\Core\Files\CSS\Post::create(self::TEMPLATE);
   if ($css_file) {
    $css_file->enqueue();
    if ($from_disk && !$disk_only) wp_cache_set($key, $cached, self::GROUP, self::TTL);
    return $cached;
   }
  }
  $html = $native($content, $listing_id);
  if (is_string($html) && $html !== '') {
   wp_cache_set($key, $html, self::GROUP, self::TTL);
   if ($snapshot_key === null) $snapshot_key = self::snapshot_key($key, $term);
   if ($snapshot_key !== null) self::write_snapshot($snapshot_key, $html);
  }
  return $html;
 }


 public static function schedule_cleanup(): void {
  if (wp_get_environment_type() !== 'staging'
    || (string)wp_parse_url(home_url(), PHP_URL_HOST) !== self::HOST) return;
  $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
  if (!is_array($profile) || ($profile['site_uuid'] ?? '') !== self::SITE_UUID) return;
  if (!wp_next_scheduled('mad4b_nav_private_snapshot_prune_v1')) {
   wp_schedule_event(time() + 3600, 'daily', 'mad4b_nav_private_snapshot_prune_v1');
  }
 }

 public static function prune_old_snapshots(): void {
  if (wp_get_environment_type() !== 'staging'
    || (string)wp_parse_url(home_url(), PHP_URL_HOST) !== self::HOST) return;
  $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
  if (!is_array($profile) || ($profile['site_uuid'] ?? '') !== self::SITE_UUID) return;
  $dir = self::$snapshot_dir;
  if (is_link($dir) || !is_dir($dir) || realpath($dir) !== $dir) return;
  $paths = glob($dir . '/term-*.snapshot');
  if (!is_array($paths)) return;
  $deleted = 0;
  foreach ($paths as $path) {
   if ($deleted >= 250) break;
   if (!preg_match('~/term-[a-f0-9]{64}\\.snapshot$~D', $path)
     || is_link($path) || !is_file($path)) continue;
   $mtime = @filemtime($path);
   if ($mtime && $mtime < time() - 2 * self::SNAPSHOT_MAX_AGE && @unlink($path)) $deleted++;
  }
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
add_action('init', ['MAD4B_Nav_Term_Fragment_Cache_V1', 'schedule_cleanup'], 20);
add_action('mad4b_nav_private_snapshot_prune_v1', ['MAD4B_Nav_Term_Fragment_Cache_V1', 'prune_old_snapshots']);
