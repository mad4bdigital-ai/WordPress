<?php
/** Plugin Name: Shared Listing Empty-State Compatibility (Lab) */
/** Description: JetEngine listing empty-state normalization for JetSmartFilters without modifying vendors or security. */
if (!defined('ABSPATH')) return;
/** All JetSmartFilters filter types share the provider response: select, checkbox, radio, search, range, pagination, reset. */
add_filter('jet-smart-filters/render/ajax/data', static function ($response) {
    if (!wp_doing_ajax() || !is_array($response) || !array_key_exists('content', $response)) return $response;
    if (trim((string)$response['content']) !== '' || !is_array($response['pagination'] ?? null)) return $response;
    if (!array_key_exists('found_posts', $response['pagination']) || (int)$response['pagination']['found_posts'] !== 0) return $response;
    $provider = isset($_REQUEST['provider']) ? sanitize_text_field(wp_unslash($_REQUEST['provider'])) : '';
    if (!preg_match('~^jet-engine(?:/[a-zA-Z0-9_-]+)?$~D', $provider)) return $response;
    $settings = isset($_REQUEST['settings']) && is_array($_REQUEST['settings']) ? wp_unslash($_REQUEST['settings']) : [];
    // JetEngine's own key intentionally uses the spelling "lisitng_id".
    $listing_id = absint($settings['lisitng_id'] ?? $settings['listing_id'] ?? 0);
    if (!$listing_id || get_post_status($listing_id) !== 'publish' || get_post_type($listing_id) !== 'jet-engine') return $response;
    $message = isset($settings['not_found_message']) && is_string($settings['not_found_message'])
        ? sanitize_text_field($settings['not_found_message']) : '';
    if ($message === '') $message = __('No matching items found.', 'jet-engine');
    $response['content'] = '<div class="jet-listing-grid jet-listing"><div class="jet-listing-grid__items"><div class="jet-listing-not-found shared-listing-empty-state" role="status">' . esc_html($message) . '</div></div></div>';
    return $response;
}, 99);
