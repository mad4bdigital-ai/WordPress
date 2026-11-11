<?php
/**
 * Plugin Name: MAD4B Source-Native Elementor Visual CSS Owner
 * Description: Keep audited Elementor Kit CSS as the single visual owner on every enrolled environment.
 * Version: 1.3.0
 */
defined('ABSPATH') || exit;

function mad4b_elementor_visual_css_owner_active_v1(): bool {
    static $cache = [];
    $kit = (int)get_option('elementor_active_kit');
    if (array_key_exists($kit,$cache)) return $cache[$kit];
    $cache[$kit] = false;
    if (is_admin() || is_feed() || is_embed() || wp_doing_ajax()) return false;
    $front_css = wp_style_is('elementor-frontend','enqueued') || wp_style_is('elementor-frontend','done')
        || wp_style_is('elementor-post-'.$kit,'enqueued') || wp_style_is('elementor-post-'.$kit,'done');
    if (!$front_css) return false;
    if (!class_exists('MAD4B_SCP_Site_Profile') || !$kit
        || (string)get_option('mad4b_elementor_visual_css_active_v1','0') !== '1') return false;
    $profile = MAD4B_SCP_Site_Profile::status();
    if (empty($profile['configured'])
        || empty($profile['origin_match'])
        || empty($profile['environment_match']) || !wp_is_uuid((string)($profile['site_uuid'] ?? ''))) return false;
    $meta = get_post_meta($kit,'_elementor_css',true);
    $css = is_array($meta) ? (string)($meta['css'] ?? '') : '';
    // Do not dequeue a provider stylesheet until the target's own active Kit
    // actually contains the complete required rules.
    $cache[$kit] = strlen($css) > 65000
        && str_contains($css,'START MAD4B RATE / RESPONSIVE PRESENTATION')
        && str_contains($css,'.tour-rate-amount-row')
        && str_contains($css,'.shared-price-first-preview')
        && str_contains($css,'.jet-remove-all-filters__button');
    return $cache[$kit];
}
add_action('wp_enqueue_scripts',static function(): void {
    if (!mad4b_elementor_visual_css_owner_active_v1()) return;
    wp_dequeue_style('tour-rate-native-display');
    wp_dequeue_style('shared-presentation-price-first-qa');
},150);
