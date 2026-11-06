<?php
/**
 * Plugin Name: MAD4B Shared Renderer Query Governor
 * Description: Capability-aware, versioned JetEngine data-query caching, independent of tenant IDs and domains.
 * Version: 1.2.2
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mad4b_render_query_private_context() {
    if ( is_admin() || wp_doing_ajax() || is_user_logged_in() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return true; }
    if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'MAD4B_RENDER_QUERY_QA' ) ) { return true; }
    foreach ( array_keys( (array) $_GET ) as $key ) {
        if ( ! preg_match( '/^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i', (string) $key ) ) { return true; }
    }
    foreach ( array_keys( (array) $_COOKIE ) as $key ) {
        if ( preg_match( '/(?:currency|market|passport|country|booking|checkout|order|cart|customer|session|woocommerce|nonce|token|jet_smart_filters|jet-engine-query)/i', (string) $key ) ) { return true; }
    }
    return false;
}

function mad4b_render_query_policy( $query ) {
    if ( ! is_object( $query ) || empty( $query->id ) || ! isset( $query->query_type ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'unidentified' ]; }
    if ( ! wp_using_ext_object_cache() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'no_persistent_backend' ]; }
    if ( 'off' === get_option( 'mad4b_render_query_governor', 'safe' ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'policy_off' ]; }
    if ( mad4b_render_query_private_context() ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'private_context' ]; }
    $type = (string) $query->query_type;
    $args = is_array( $query->final_query ?? null ) ? $query->final_query : [];
    $deny = '/(?:^|[_-])(?:orders?|bookings?|checkout|quotes?|payments?|customers?|users?|sessions?|suppliers?|invoices?|rates?|pricing|prices?|costs?|reservations?)(?:[_-]|$)/i';
    if ( 'posts' === $type ) {
        $types = (array) ( $args['post_type'] ?? [] );
        $statuses = array_values( array_unique( (array) ( $args['post_status'] ?? [ 'publish' ] ) ) );
        if ( ! $types || count( $statuses ) !== 1 || 'publish' !== $statuses[0] ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'non_public_status' ]; }
        foreach ( $types as $post_type ) {
            if ( ! is_string( $post_type ) || preg_match( $deny, $post_type ) || 'product' === $post_type ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'sensitive_type' ]; }
            $object = get_post_type_object( $post_type );
            if ( ! $object || empty( $object->public ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'not_public_type' ]; }
        }
        // General public lists keep a short fallback; stable tour relations/properties have long TTLs.
        // Defaults are shared across all companies, while each site can adjust its own TTL profile.
        $kind = 'general';
        $taxes = (array) ( $args['tax_query'] ?? [] );
        foreach ( $taxes as $tax ) {
            if ( is_array( $tax ) && isset( $tax['taxonomy'] ) && preg_match( '/(?:destination|location|region|city)/i', (string) $tax['taxonomy'] ) ) { $kind = 'related_tours'; break; }
        }
        foreach ( $types as $post_type ) {
            if ( preg_match( '/(?:property|properties|hotel|accommodation)/i', $post_type ) ) { $kind = 'properties'; break; }
        }
        $defaults = [ 'general' => 900, 'related_tours' => 7 * DAY_IN_SECONDS, 'properties' => 3 * DAY_IN_SECONDS ];
        $overrides = get_option( 'mad4b_render_query_ttls', [] );
        $value = is_array( $overrides ) && isset( $overrides[ $kind ] ) && is_numeric( $overrides[ $kind ] )
            ? (int) $overrides[ $kind ] : $defaults[ $kind ];
        // Bound persistent cache memory and ensure entries expire even if a bulk import skips WP hooks.
        $ttl = max( 300, min( 30 * DAY_IN_SECONDS, $value ) );
        return [ 'cache' => true, 'ttl' => $ttl, 'reason' => 'public_posts_' . $kind ];
    }
    if ( 'terms' === $type ) {
        $taxes = (array) ( $args['taxonomy'] ?? [] );
        if ( ! $taxes ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'unscoped_terms' ]; }
        foreach ( $taxes as $tax ) {
            $obj = is_string( $tax ) ? get_taxonomy( $tax ) : null;
            if ( ! $obj || empty( $obj->public ) || preg_match( $deny, (string) $tax ) ) { return [ 'cache' => false, 'ttl' => 0, 'reason' => 'sensitive_terms' ]; }
        }
        return [ 'cache' => true, 'ttl' => 900, 'reason' => 'public_terms' ];
    }
    if ( 'repeater' === $type ) {
        $field = (string) ( $args['jet_engine_field'] ?? '' );
        // Discover content-only repeater fields from JetEngine metadata; never cache prices/extras/quotes.
        if ( 'jet_engine' === ( $args['source'] ?? '' )
            && preg_match( '/(?:^|::|_)(?:faq(?:_items?)?|itinerary(?:_items?)?|includes?|excludes?|amenities)(?:$|_item|_items)/i', $field )
            && ! preg_match( '/(?:price|rate|cost|extra|booking|checkout|quote|supplier|customer)/i', $field ) ) {
            return [ 'cache' => true, 'ttl' => 900, 'reason' => 'public_content_repeater' ];
        }
    }
    // SQL/users/orders/pricing and other dynamic sources must run against current data.
    return [ 'cache' => false, 'ttl' => 0, 'reason' => 'dynamic_or_unclassified' ];
}

add_action( 'jet-engine/query-builder/query/after-query-setup', static function ( $query ) {
    if ( ! is_object( $query ) || ! property_exists( $query, 'cache_query' ) ) { return; }
    $decision = mad4b_render_query_policy( $query );
    $query->cache_query = $decision['cache'];
    if ( ! $decision['cache'] ) { $query->cache_expires = 0; return; }
    $site = substr( md5( home_url( '/' ) ), 0, 10 );
    $language = (string) apply_filters( 'wpml_current_language', null );
    if ( '' === $language ) { $language = determine_locale(); }
    $language = sanitize_key( $language );
    $version = (string) get_option( 'mad4b_render_query_data_generation', 'initial' );
    $query->cache_query = true;
    $query->cache_expires = $decision['ttl'];
    $query->cache_group = 'mad4b-rg-' . $site . '-' . $language . '-' . (int) $query->id . '-' . substr( md5( $version ), 0, 10 );
}, 100, 1 );

function mad4b_render_query_invalidate() {
    static $bumped = false;
    if ( $bumped ) { return; }
    $bumped = true;
    update_option( 'mad4b_render_query_data_generation', sprintf( '%d-%d', time(), wp_rand( 100000, 999999 ) ), false );
}
function mad4b_render_query_changed_post( $post_id ) {
    if ( ! $post_id || wp_is_post_revision( $post_id ) ) { return; }
    $type = get_post_type( $post_id );
    if ( $type && preg_match( '/(?:order|booking|checkout|payment|customer|reservation|invoice)/i', $type ) ) { return; }
    $obj = $type ? get_post_type_object( $type ) : null;
    if ( $obj && ! empty( $obj->public ) ) { mad4b_render_query_invalidate(); }
}
add_action( 'save_post', 'mad4b_render_query_changed_post', 40, 1 );
add_action( 'before_delete_post', 'mad4b_render_query_changed_post', 40, 1 );
add_action( 'trashed_post', 'mad4b_render_query_changed_post', 40, 1 );
foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
    add_action( $hook, static function ( $meta_id, $post_id ) { mad4b_render_query_changed_post( (int) $post_id ); }, 40, 2 );
}
add_action( 'set_object_terms', static function ( $id, $terms, $tt_ids, $taxonomy ) {
    $type = get_post_type( $id );
    $tax = get_taxonomy( $taxonomy );
    if ( $tax && ! empty( $tax->public ) && ! ( $type && preg_match( '/(?:order|booking|checkout|payment|customer|reservation|invoice)/i', $type ) ) ) {
        mad4b_render_query_invalidate();
    }
}, 40, 4 );
add_action( 'edited_term', static function () { mad4b_render_query_invalidate(); }, 40 );
add_action( 'deleted_term', static function () { mad4b_render_query_invalidate(); }, 40 );
add_action( 'updated_option', static function ( $name ) {
    if ( preg_match( '/(?:^|[_-])(?:rates?|pricing|suppliers?|markets?|currenc(?:y|ies)|seasons?|occupancy|wcml)(?:[_-]|$)/i', (string) $name ) ) { mad4b_render_query_invalidate(); }
}, 40, 1 );
// Query Builder emits this after successful edits/creation; invalidate definitions too.
add_action( 'jet-engine/query-builder/after-query-update', 'mad4b_render_query_invalidate', 40 );
// Bulk imports that update SQL directly can explicitly call this action.
add_action( 'mad4b/render_query/invalidate', 'mad4b_render_query_invalidate' );

/**
 * Selective public listing fragment memo (v1). Shares the query governor's
 * data-generation invalidation; no extra database tables, services or broker.
 * The tenant's audited templates are declared in a separate JSON policy.
 * Default OFF; new companies without a matching local policy render natively.
 */
final class MAD4B_Render_Query_Public_Fragments {
    private const GROUP = 'mad4b-rq-public-fragments-v1';
    private const VERSION = '1.0.0';
    private static array $templates = [];
    private static array $metrics = ['hit' => 0, 'miss' => 0, 'reject' => 0, 'bytes' => 0];
    private static array $audited = [];
    private static bool $qa = false;

    private static function policy(): array {
        static $policy = null;
        if ($policy !== null) return $policy;
        $policy = [];
        $file = WPMU_PLUGIN_DIR . '/mad4b-selective-render-policy.json';
        if (!is_readable($file) || is_link($file)) return [];
        $cfg = json_decode((string) @file_get_contents($file), true);
        if (!is_array($cfg) || ($cfg['contract'] ?? '') !== 'mad4b.render-query.public-fragments.v1'
            || ($cfg['environment'] ?? '') !== wp_get_environment_type()
            || ($cfg['site_host'] ?? '') !== wp_parse_url(home_url('/'), PHP_URL_HOST)
            || !is_array($cfg['templates'] ?? null)
            || !in_array(($cfg['status'] ?? ''), ['canary', 'active'], true)) return [];
        $ttl = $cfg['ttl_seconds'] ?? 0;
        if (!is_int($ttl) || $ttl < 15 || $ttl > 120) return [];
        return $policy = $cfg;
    }

    private static function test_request(): bool {
        // Query-based canary access is never permitted in the active public mode.
        return (self::policy()['status'] ?? '') === 'canary'
            && isset($_GET['mad4b_public_fragment_qa'])
            && count($_GET) === 1
            && $_GET['mad4b_public_fragment_qa'] === '1';
    }

    private static function context(): bool {
        // Both Staging and Production are eligible only when this company's
        // own environment-matched manifest and explicit on-switch are present.
        if (!in_array(wp_get_environment_type(), ['staging', 'production'], true)
            || !wp_using_ext_object_cache()
            || (defined('WP_CLI') && WP_CLI) || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || is_preview() || !is_front_page()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['PHP_AUTH_USER'])) return false;
        // Read only the on-wire cookie header. Accept a single WPML locale
        // cookie when it matches the already resolved server-side language;
        // reject analytics/session/cart/market/currency or ambiguous cookies.
        $wire = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if ($wire !== '') {
            if (strlen($wire) > 256) return false;
            $pairs = explode(';', $wire);
            if (count($pairs) !== 1) return false;
            $pair = explode('=', trim($pairs[0]), 2);
            if (count($pair) !== 2 || $pair[0] !== 'wp-wpml_current_language'
                || rawurldecode($pair[1]) !== (string)apply_filters('wpml_current_language', '')) return false;
        }
        // WPML may populate this language state during bootstrap without
        // receiving a Cookie header. No arbitrary or visitor-derived cookie
        // values are permitted in a shared fragment cache context.
        foreach ((array) $_COOKIE as $cookie_name => $cookie_value) {
            if ($cookie_name !== 'wp-wpml_current_language'
                || !is_string($cookie_value)
                || $cookie_value !== (string)apply_filters('wpml_current_language', '')) return false;
        }
        if ($_GET && !self::test_request()) return false;
        foreach (['HTTP_X_MAD4B_CURRENCY','HTTP_X_MAD4B_MARKET_CONTEXT',
            'HTTP_X_GEO_COUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY','HTTP_CF_IPCOUNTRY',
            'HTTP_X_COUNTRY_CODE','HTTP_X_GEOIP_COUNTRY','HTTP_X_WP_NONCE',
            'HTTP_X_REQUESTED_WITH','HTTP_X_MAD4B_BY_PASS'] as $key) {
            if (!empty($_SERVER[$key])) return false;
        }
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host === '' || strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== $host) return false;
        if (!class_exists('MAD4B_Stable_Listing_CSS_Identity', false)
            || !MAD4B_Stable_Listing_CSS_Identity::cache_ready()) return false;
        $base_currency = (string)get_option('woocommerce_currency', '');
        if (get_option('woocommerce_default_customer_address', '') !== 'base'
            || get_option('woocommerce_calc_taxes', '') !== 'no'
            || !preg_match('/^[A-Z]{3}$/D', $base_currency)
            || (function_exists('get_woocommerce_currency')
                && get_woocommerce_currency() !== $base_currency)) return false;
        return true;
    }

    private static function template_allowed(int $id, array $cfg): bool {
        if (array_key_exists($id, self::$audited)) return self::$audited[$id];
        $doc = get_post($id);
        $raw = get_post_meta($id, '_elementor_data', true);
        $hash = $cfg['elementor_sha256'] ?? '';
        if (!$doc || $doc->post_status !== 'publish' || $doc->post_type !== 'jet-engine'
            || !is_string($raw) || strlen($raw) > 50000 || strlen($raw) < 200
            || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
            || !hash_equals($hash, hash('sha256', $raw))) return self::$audited[$id] = false;
        // The SHA pins a manually reviewed, public term-only Elementor document.
        // A changed/translated template is disabled until its dependencies are audited.
        return self::$audited[$id] = true;
    }

    private static function safe_html($html): bool {
        return is_string($html) && strlen($html) >= 60 && strlen($html) <= 200000
            && !preg_match('~<\s*(?:script|form|iframe|input)\b|(?:wp-admin|wp-login|woocommerce-cart|wc_fragments|cart_hash|checkout|customer|billing|shipping|add-to-cart|nonce|data-price|data-rate|data-amount|payment|booking|(?:\b(?:USD|EUR|EGP|GBP)\b)|(?:\$\s*\d))~i', $html);
    }

    private static function term_key(WP_Term $term, int $template_id, array $cfg, string $lang): string {
        $post = get_post($template_id);
        $term_url = get_term_link($term);
        if (!is_string($term_url) || !str_starts_with($term_url, 'https://')) return '';
        // JetEngine's get_current_object_unique_id() uses hrtime(); never put
        // that request-volatile selector in a persistent fragment key.
        // 414 normalizes the matching HTML/CSS class with this stable identity.
        $unique = substr(hash('sha256', get_class($term) . ':' . (string)$term->term_id), 0, 16);
        $version = (string)get_option('mad4b_render_query_data_generation', 'initial');
        $site = get_option('mad4b_scp_brand_context_profile_v1', []);
        $kit = absint(get_option('elementor_active_kit'));
        static $template_css = [];
        if (!isset($template_css[$template_id])) {
            $stored_css = get_post_meta($template_id, '_elementor_css', true);
            $serialized_css = maybe_serialize($stored_css);
            $template_css[$template_id] = hash('sha256', is_string($serialized_css) ? $serialized_css : '');
        }
        $payload = [
            'contract' => self::VERSION, 'site' => home_url('/'),
            'tenant' => is_array($site) ? ($site['site_uuid'] ?? '') : '',
            'route' => (string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            'lang' => $lang, 'locale' => get_locale(), 'device' => wp_is_mobile() ? 'mobile' : 'desktop',
            'base_currency' => (string)get_option('woocommerce_currency', ''),
            'template' => $template_id, 'template_sha' => $cfg['elementor_sha256'],
            'template_modified' => $post->post_modified_gmt,
            'template_css' => $template_css[$template_id],
            'term' => [$term->term_id, $term->taxonomy, $term->term_taxonomy_id, $unique,
                $term->name, $term->slug, $term->description, $term->parent, $term->count,
                get_term_meta($term->term_id), $term_url],
            'queried_page' => get_queried_object_id(),
            'generation' => $version,
            'assets' => [get_option('elementor_css_print_method'), get_option('elementor_experiment-e_element_cache'),
                $kit, $kit ? get_post_modified_time('U', true, $kit) : 0,
                defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
                defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : '',
                defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : '',
                (string)wp_get_theme()->get('Version')],
        ];
        $encoded = wp_json_encode($payload);
        return is_string($encoded) ? hash('sha256', $encoded) : '';
    }

    public static function render($input, $listing_id, callable $native, string $lang) {
        $id = absint($listing_id);
        $cfg = self::$templates[$id] ?? null;
        if (!is_array($cfg) || !self::template_allowed($id, $cfg)) return $native($input, $listing_id);
        $term = jet_engine()->listings->data->get_current_object();
        if (!($term instanceof WP_Term) || !in_array($term->taxonomy, (array)($cfg['taxonomies'] ?? []), true)) {
            self::$metrics['reject']++; return $native($input, $listing_id);
        }
        $key = self::term_key($term, $id, $cfg, $lang);
        if (!$key) return $native($input, $listing_id);
        $force_native = self::$qa && ($_SERVER['HTTP_X_MAD4B_FRAGMENT_CONTROL'] ?? '') === 'native-v1';
        $cache = $force_native ? false : wp_cache_get($key, self::GROUP);
        if (self::safe_html($cache) && class_exists('Elementor\Core\Files\CSS\Post')) {
            $css = \Elementor\Core\Files\CSS\Post::create($id);
            if ($css) {
                $css->enqueue();
                self::$metrics['hit']++;
                self::$metrics['bytes'] += strlen($cache);
                return $cache;
            }
        }
        $html = $native($input, $listing_id);
        self::$metrics['miss']++;
        if (!$force_native && self::safe_html($html)) {
            $ttl = (int)(self::policy()['ttl_seconds'] ?? 45);
            wp_cache_set($key, $html, self::GROUP, $ttl);
        } else if (!self::safe_html($html)) self::$metrics['reject']++;
        return $html;
    }

    public static function activate(): void {
        $policy = self::policy();
        if (!$policy || !self::context()) return;
        self::$qa = self::test_request();
        if (($policy['status'] ?? '') !== 'active' && !self::$qa) return;
        if (!self::$qa && (string)get_option('mad4b_render_public_fragments_enabled', '0') !== '1') return;
        if (empty($policy['templates']) || !is_array($policy['templates'])) return;
        foreach ($policy['templates'] as $k => $t) {
            $id = absint($k);
            if ($id && is_array($t) && !empty($t['taxonomies'])) self::$templates[$id] = $t;
        }
        if (!self::$templates) return;
        global $wp_filter;
        $hook = $wp_filter['jet-engine/listing/content/elementor'] ?? null;
        if (!($hook instanceof WP_Hook) || count($hook->callbacks[10] ?? []) !== 1) return;
        $native = null;
        foreach ($hook->callbacks[10] as $entry) {
            $cb = $entry['function'];
            if (is_array($cb) && is_object($cb[0])
                && get_class($cb[0]) === 'Jet_Engine_Elementor_Frontend'
                && $cb[1] === 'get_listing_content_cb') $native = $cb;
        }
        if (!$native) return;
        remove_filter('jet-engine/listing/content/elementor', $native, 10);
        $lang = (string)apply_filters('wpml_current_language', '');
        add_filter('jet-engine/listing/content/elementor',
            static fn($html, $id) => self::render($html, $id, $native, $lang), 10, 2);
        // QA telemetry was kept only during staging acceptance; no public diagnostic writes.

    }
}
add_action('wp', ['MAD4B_Render_Query_Public_Fragments', 'activate'], 125);

/**
 * WPML stores taxon permalink results in the persistent
 * icl_tax_permalink_filter group with no TTL. Changing a term, its parent,
 * or its WPML linkage can leave an old-language URL behind even while the
 * term's source language is correct. Clear only this WPML group on
 * destination taxonomy changes; never flush the whole Redis/object cache.
 * This also covers descendants whose path includes the changed ancestor.
 * The explicit action is for governed imports that write relationships in SQL.
 */
function mad4b_render_query_invalidate_wpml_term_links() {
    static $flushed = false;
    if ($flushed || !class_exists('WPML_WP_Cache', false)) return;
    $flushed = true;
    (new WPML_WP_Cache('icl_tax_permalink_filter'))->flush_group_cache(true);
    mad4b_render_query_invalidate();
}
foreach (['created_term','edited_term','delete_term'] as $mad4b_term_hook) {
    add_action($mad4b_term_hook, static function($term_id,$tt_id,$taxonomy) {
        if ((string)$taxonomy === 'destinations') mad4b_render_query_invalidate_wpml_term_links();
    }, 50, 3);
}
add_action('mad4b/wpml/term_permalink/invalidate','mad4b_render_query_invalidate_wpml_term_links',50);
// A central Content Job / controlled SQL import already emits this action.
// It only flushes WPML term permalinks when explicitly marked as a taxonomy migration.
add_action('mad4b/render_query/term_taxonomy_migrated','mad4b_render_query_invalidate_wpml_term_links',50);
