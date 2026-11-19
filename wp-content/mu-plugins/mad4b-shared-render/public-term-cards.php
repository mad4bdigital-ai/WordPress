<?php
/** Shared JetEngine term-card lifecycle. Callers supply site policy and storage. */
defined('ABSPATH') || exit;

/**
 * The *rendered card markup*, not the presence of a pricing engine, decides
 * whether a public destination term card is market-invariant. Reject any
 * potentially dynamic amounts, country/state/session selectors or forms.
 * A price-bearing tour/listing stays in its original market-aware renderer.
 */
function mad4b_public_term_market_invariant_html($html): bool {
    if (!is_string($html) || strlen($html)<80 || strlen($html)>75000) return false;
    return preg_match(
        '~(?:nonce|<script\b|<form\b|<input\b|<select\b|'
        .'data-(?:price|currency|market|passport|nationality|rate|quote|availability|country)|'
        .'woocommerce[-_]?(?:price|cart)|wcml|tour-component-price|price-current|'
        .'(?:^|[^a-z])(?:booking|checkout|customer|session|cart_hash|client_secret)(?:[^a-z]|$)|'
        .'(?:\$|€|£|¥|&#0?36;|&#x0?24;|&#8364;|&dollar;)\s*[0-9]|'
        .'[0-9][\s,]*(?:USD|EUR|GBP|EGP)(?:\b|$)|'
        .'(?:[?&]|&amp;)(?:country|market|currency|price|rate|passport|nationality)=)~i',
        $html
    ) === 0;
}

function mad4b_register_public_term_cards(int $doc_id, string $widget_id, int $listing_id,
    string $taxonomy, callable $read, callable $write, bool $market_invariant_public_terms=false): void {
    if ($market_invariant_public_terms && $taxonomy!=='destinations') return;
    foreach (['HTTP_X_MAD4B_CURRENCY', 'HTTP_X_MAD4B_MARKET_CONTEXT', 'HTTP_X_MAD4B_PASSPORT',
              'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY', 'HTTP_X_GEO_COUNTRY',
              'HTTP_CF_IPCOUNTRY', 'HTTP_X_FORWARDED_GEO_COUNTRY'] as $header) {
        if (isset($_SERVER[$header]) && $_SERVER[$header] !== '') return;
    }
    static $registered = [];
    $target = $doc_id . ':' . $widget_id;
    if (isset($registered[$target])) return;
    $registered[$target] = true;
    $within_target = false;
    $context = null;
    $build_context = static function() use($doc_id, $listing_id, $taxonomy, $market_invariant_public_terms) {
        $doc = get_post($doc_id);
        $item = get_post($listing_id);
        if (!$doc || $doc->post_status !== 'publish' || !$item || $item->post_status !== 'publish'
            || !function_exists('get_woocommerce_currency')) return null;

        // WordPress advances this generation on term edits and term-meta changes.
        // Without persistent object caching the generation would change every request.
        $generation = wp_using_ext_object_cache() ? wp_cache_get_last_changed('terms') : '';
        return serialize([
            home_url('/'), get_locale(), apply_filters('wpml_current_language', ''),
            ($market_invariant_public_terms?'public-static-no-money-v1':get_woocommerce_currency()),
            wp_is_mobile(), $taxonomy, $doc_id, $listing_id,
            $doc->post_modified_gmt, $item->post_modified_gmt, $generation,
        ]);
    };
    $key_for = static function($object, $source_id) use(&$context, $listing_id, $taxonomy) {
        if ($context === null || (int)$source_id !== $listing_id || !($object instanceof WP_Term)
            || $object->taxonomy !== $taxonomy) return null;
        return hash('sha256', $context . serialize([
            $object->term_id, $object->name, $object->slug, $object->parent, $object->count,
        ]));
    };

    $cacheable = static function($html) use($market_invariant_public_terms): bool {
        return (!$market_invariant_public_terms ||
                mad4b_public_term_market_invariant_html($html))
            && is_string($html) && strlen($html) > 80 && strlen($html) < 75000
            && !preg_match('/nonce|data-price|wcml|woocommerce-cart|wc_fragments|add-to-cart|checkout|fluentform|<form\b|wp-admin|logged_in|customer|cart_hash|client_secret/i', $html);
    };

    add_action('elementor/frontend/widget/before_render', static function($widget)
        use(&$within_target, &$context, $build_context, $widget_id, $doc_id) {
        if (!is_object($widget) || !method_exists($widget, 'get_id')
            || (string)$widget->get_id() !== $widget_id) return;
        $doc = \Elementor\Plugin::$instance->documents->get_current();
        $within_target = $doc && (int)$doc->get_main_id() === $doc_id;
        $context = $within_target ? $build_context() : null;
    }, 99999);

    add_action('elementor/frontend/widget/after_render', static function($widget)
        use(&$within_target, $widget_id) {
        if (is_object($widget) && method_exists($widget, 'get_id')
            && (string)$widget->get_id() === $widget_id) $within_target = false;
    }, 99999);

    add_filter('jet-engine/listing/pre-get-item-content', static function($current, $object, $index, $grid, $query)
        use(&$within_target, $key_for, $listing_id, $cacheable, $read) {
        if (!$within_target || $current || !is_object($grid) || !method_exists($grid, 'get_settings')
            || absint($grid->get_settings('lisitng_id')) !== $listing_id) return $current;
        $key = $key_for($object, $listing_id);
        if (!$key) return $current;
        $html = $read($key);
        if (!$cacheable($html)) return $current;

        // Preserve JetEngine's per-term identity and lifecycle callbacks on cache HIT.
        if (!function_exists('jet_engine') || !is_object(jet_engine()->frontend)
            || !method_exists(jet_engine()->frontend, 'setup_data')) return $current;
        jet_engine()->frontend->setup_data($object);
        do_action('jet-engine/listings/frontend/object-done', $object, $listing_id);
        jet_engine()->frontend->ensure_listing_item_assets($listing_id, $object, true);
        return $html;
    }, 100, 5);

    add_filter('jet-engine/listings/frontend/listing-item-content', static function($html, $source_id, $term)
        use(&$within_target, $key_for, $cacheable, $write) {
        // JetEngine renders a second copy with visibility disabled to discover assets.
        // Only visitor-visible output belongs in the card cache.
        if (false !== has_filter('jet-engine/modules/dynamic-visibility/condition/prevent-check', '__return_true')) return $html;
        if (!$within_target || !$cacheable($html)) return $html;
        $key = $key_for($term, $source_id);
        if ($key) $write($key, $html);
        return $html;
    }, 9999, 3);
}
