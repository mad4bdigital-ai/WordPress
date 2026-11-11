<?php
/** Site-scoped JetEngine signed lazy settings for dynamically projected destination grids. */
defined('ABSPATH') || exit;

// JetEngine already signs the complete settings at render time, but its JS
// sends only the signature. Projected Elementor grids have no saved element ID.
// Remember exactly the server-rendered settings for the lifetime of that HMAC.
add_filter('jet-engine/listing/grid/lazy-load/options', static function($options, $settings) {
    if (is_admin() || wp_doing_ajax()
        || !is_array($settings) || !is_array($options)
        || empty($settings['lazy_load'])
        || !str_contains((string)($settings['_css_classes'] ?? ''), 'navigation-editor-grid')
        || !ctype_digit((string)($settings['terms_parent'] ?? ''))
        || (int)($settings['lisitng_id'] ?? $settings['listing_id'] ?? 0) !== 67125)
        return $options;
    $signature = $options['settings_signature'] ?? '';
    if (!is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) return $options;
    $key = 'mad4b_signed_nav_'.substr($signature, 0, 40);
    // Only signed, public display configuration. No user, order or price data.
    set_transient($key, [
        'signature' => $signature,
        'settings' => $settings,
        'language' => (string)apply_filters('wpml_current_language', null),
    ], 14 * DAY_IN_SECONDS);
    return $options;
}, 30, 2);

// Run before JetEngine's registered handler; its normal HMAC and post-context
// checks still decide whether the reconstructed configuration is accepted.
$recover = static function() {
    if (($_REQUEST['handler'] ?? '') !== 'get_listing') return;
    $incoming = $_REQUEST['widget_settings'] ?? null;
    if (!is_array($incoming) || count($incoming) !== 1) return;
    $signature = $incoming['_jet_engine_settings_signature'] ?? '';
    if (!is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) return;
    $data = get_transient('mad4b_signed_nav_'.substr($signature, 0, 40));
    if (!is_array($data) || !hash_equals((string)($data['signature'] ?? ''), $signature)) return;
    $settings = $data['settings'] ?? null;
    if (!is_array($settings) || !str_contains((string)($settings['_css_classes'] ?? ''), 'navigation-editor-grid')
        || (int)($settings['lisitng_id'] ?? $settings['listing_id'] ?? 0) !== 67125
        || !ctype_digit((string)($settings['terms_parent'] ?? ''))) return;
    // WordPress unslashes request settings before JetEngine's verification.
    $_REQUEST['widget_settings'] = wp_slash(array_merge($settings, [
        '_jet_engine_settings_signature' => $signature,
    ]));
};
add_action('wp_ajax_jet_engine_ajax', $recover, 1);
add_action('wp_ajax_nopriv_jet_engine_ajax', $recover, 1);
