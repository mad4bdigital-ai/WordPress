<?php
/**
 * Company Performance Preset: short-lived public, per-term JetEngine card cache.
 * Injected alternate elements (Fluent Forms), pricing and booking remain dynamic.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging' || PHP_SAPI === 'cli') return;

add_action('init', static function() {
    if (!function_exists('mad4b_company_fragment_preset_enabled')
        || !mad4b_company_fragment_preset_enabled('individual_attractions_cards')
        || ($_SERVER['HTTP_X_MAD4B_CARD_AB'] ?? 'enabled') === 'control') return;

    $cfg = mad4b_company_fragment_preset_config();
    $targets = $cfg['targets'];
    $doc_id = $targets['attractions_document_id'];
    $widget_id = $targets['attractions_widget_id'];
    $listing_id = $targets['attractions_listing_id'];
    $taxonomy = $targets['attractions_taxonomy'];
    $ttl = (int) $cfg['fragment_ttl_seconds'];
    $within_target = false;
    $root = sys_get_temp_dir().'/mad4b-company-card-fragments-'.
        hash('sha256', strtolower($cfg['site_host']));
    if (!is_dir($root) && !@mkdir($root, 0700, true)) return;
    if (!is_writable($root)) return;

    $key_for = static function($object, $source_id) use($root, $doc_id, $listing_id, $taxonomy) {
        if ((int)$source_id !== $listing_id || !($object instanceof \WP_Term)
            || $object->taxonomy !== $taxonomy) return null;
        $doc = get_post($doc_id);
        $item = get_post($listing_id);
        if (!$doc || $doc->post_status !== 'publish'
            || !$item || $item->post_status !== 'publish') return null;
        $lang = (string) apply_filters('wpml_current_language', '');
        $key = home_url('/').'|'.get_locale().'|'.$lang.'|'.
            get_woocommerce_currency().'|'.(wp_is_mobile() ? 'mobile' : 'desktop').'|'.
            $taxonomy.'|'.$object->term_id.'|'.$listing_id.'|'.
            $doc->post_modified_gmt.'|'.$item->post_modified_gmt;
        return $root.'/'.hash('sha256', $key).'.json';
    };

    $cacheable = static function($html): bool {
        return is_string($html) && strlen($html) > 80 && strlen($html) < 75000 &&
            !preg_match('/nonce|data-price|wcml|woocommerce-cart|wc_fragments|add-to-cart|checkout|fluentform|<form\b|wp-admin|logged_in|customer|cart_hash|client_secret/i', $html);
    };

    add_action('elementor/frontend/widget/before_render', static function($widget)
        use(&$within_target, $widget_id, $doc_id) {
        if (!is_object($widget) || !method_exists($widget, 'get_id')
            || (string)$widget->get_id() !== $widget_id) return;
        $doc = \Elementor\Plugin::$instance->documents->get_current();
        $within_target = $doc && (int)$doc->get_main_id() === $doc_id;
    }, 99999);

    add_action('elementor/frontend/widget/after_render', static function($widget)
        use(&$within_target, $widget_id) {
        if (is_object($widget) && method_exists($widget, 'get_id')
            && (string)$widget->get_id() === $widget_id) $within_target = false;
    }, 99999);

    add_filter('jet-engine/listing/pre-get-item-content', static function($current, $object, $index, $grid, $query)
        use(&$within_target, $key_for, $listing_id, $cacheable) {
        if (!$within_target || $current) return $current;
        $file = $key_for($object, $listing_id);
        if (!$file || !is_file($file) || filesize($file) > 76000) return $current;
        $entry = json_decode((string) @file_get_contents($file), true);
        if (!is_array($entry) || ($entry['expires'] ?? 0) <= time()
            || !$cacheable($entry['html'] ?? null)) return $current;

        // Preserve JetEngine's per-term identity and lifecycle callbacks on cache HIT.
        if (!function_exists('jet_engine') || !is_object(jet_engine()->frontend)
            || !method_exists(jet_engine()->frontend, 'setup_data')) return $current;
        jet_engine()->frontend->setup_data($object);
        do_action('jet-engine/listings/frontend/object-done', $object, $listing_id);
        jet_engine()->frontend->ensure_listing_item_assets($listing_id, $object, true);
        return $entry['html'];
    }, 100, 5);

    add_filter('jet-engine/listings/frontend/listing-item-content', static function($html, $source_id, $term)
        use(&$within_target, $key_for, $cacheable, $ttl) {
        if (!$within_target || !$cacheable($html)) return $html;
        $file = $key_for($term, $source_id);
        if (!$file) return $html;
        $payload = json_encode(['expires'=>time()+$ttl,'html'=>$html],
            JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) return $html;
        $tmp = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
        return $html;
    }, 9999, 3);
}, 20);
