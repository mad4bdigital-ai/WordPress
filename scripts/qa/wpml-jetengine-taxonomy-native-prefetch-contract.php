<?php
/**
 * Read-only live acceptance of the exact MAD4B WPML/JetEngine native
 * taxonomy-options batch prefetch. No term rewrites, checkout, price, or
 * content writes; no activation or cache clearing.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) {
    return;
}
$owner = 'MAD4B_Elementor_WPML_Schema_Guard_V1';
$source_sha = 'b87ee6eb43ea9a61e305d41c247558a2e32e7785251f9df825454333e6d22296';
$vendor_sha = 'e242bcba4053d418ae4de26bd42d4922280829fc11f3a278db09ed630927d6fe';
$option = 'mad4b_wpml_jetengine_taxonomy_prefetch_mode';
$hook = 'jet-engine/meta-boxes/taxonomy-options/terms-args';
$callback = [$owner, 'jetengine_taxonomy_prefetch'];

if (!class_exists($owner, false)) {
    WP_CLI::error('MAD4B WPML owner is not loaded');
}
$source_file = (new ReflectionClass($owner))->getFileName();
if (!is_string($source_file) || hash_file('sha256', $source_file) !== $source_sha) {
    WP_CLI::error('MAD4B WPML owner drift: no release-source parity');
}
if (get_option($option, 'missing') !== 'active') {
    WP_CLI::error('Native prefetch is not active for the current company');
}
if (has_filter($hook, $callback) !== 8) {
    WP_CLI::error('Native JetEngine taxonomy-options boundary not registered at priority 8');
}
global $wpml_term_translations, $wpdb;
if (!($wpml_term_translations instanceof WPML_Term_Translation)) {
    WP_CLI::error('Original WPML Term Translation authority missing');
}
$native = new ReflectionMethod($wpml_term_translations, 'prefetch_ids');
$native_file = $native->getFileName();
if ($native->getNumberOfRequiredParameters() !== 1 ||
    !is_string($native_file) ||
    !hash_equals($vendor_sha, (string) hash_file('sha256', $native_file))) {
    WP_CLI::error('Original WPML native prefetch signature drift');
}
$raw = get_option('jet_engine_meta_boxes', []);
if (!is_array($raw) || !$raw) {
    WP_CLI::error('No native JetEngine Meta Boxes to test');
}
$taxonomies = [];
foreach ($raw as $box) {
    $stack = $box['meta_fields'] ?? [];
    while ($stack) {
        $field = array_pop($stack);
        if (!is_array($field)) {
            continue;
        }
        if (!empty($field['taxonomy']) && is_string($field['taxonomy'])) {
            $name = sanitize_key($field['taxonomy']);
            if (taxonomy_exists($name)) {
                $taxonomies[$name] = true;
            }
        }
        foreach (['repeater-fields', 'repeater_fields', 'fields'] as $key) {
            if (isset($field[$key]) && is_array($field[$key])) {
                foreach ($field[$key] as $child) {
                    $stack[] = $child;
                }
            }
        }
    }
}
if (!$taxonomies) {
    WP_CLI::error('JetEngine native taxonomy-backed Meta Boxes unavailable');
}
WP_CLI::success(wp_json_encode([
    'contract' => 'mad4b.wpml-jetengine.taxonomy-options-native-prefetch.v1',
    'source_sha256' => $source_sha,
    'vendor_sha256' => $vendor_sha,
    'mode' => get_option($option),
    'hook_priority' => has_filter($hook, $callback),
    'meta_boxes' => count($raw),
    'taxonomy_sources' => count($taxonomies),
    'read_only' => true,
    'no_wpml_locale_switch' => true,
    'no_price_or_booking_data' => true,
], JSON_UNESCAPED_SLASHES));
