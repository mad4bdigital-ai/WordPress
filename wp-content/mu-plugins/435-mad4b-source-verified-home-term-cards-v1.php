<?php
/**
 * Plugin Name: MAD4B Verified Source Home Destination Cards
 * Description: Optional staging-only safe term-card cache; no prices, commerce or complete HTML.
 * Version: 1.0.3
 */
defined('ABSPATH') || exit;

final class MAD4B_Verified_Source_Home_Term_Cards_V1 {
    private const OPTION = 'mad4b_source_home_top_cities_cache_v1';
    private const GROUP = 'mad4b-source-verified-terms-v1';
    private const MAX_TTL = 300;

    public static function settings(): array {
        $cfg = get_option(self::OPTION, []);
        if (!is_array($cfg) || !in_array(($cfg['mode'] ?? ''), ['canary','active'], true)
            || ($cfg['contract'] ?? '') !== 'mad4b.source.term-card-cache.v1'
            || !preg_match('/^[a-z0-9.-]{3,160}$/D', (string)($cfg['host'] ?? ''))
            || !preg_match('/^[a-z]{2}$/D', (string)($cfg['language'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($cfg['elementor_sha256'] ?? ''))
            || !preg_match('/^[a-f0-9]{7}$/D', (string)($cfg['widget_id'] ?? ''))
            || ($cfg['device'] ?? '') !== 'mobile'
            || !is_int($cfg['document_id'] ?? null) || $cfg['document_id'] < 1
            || !is_int($cfg['listing_id'] ?? null) || $cfg['listing_id'] < 1
            || (int)($cfg['ttl'] ?? 0) !== self::MAX_TTL) return [];
        return $cfg;
    }

    private static function exact_graph(array $cfg): bool {
        $doc = get_post($cfg['document_id']);
        $item = get_post($cfg['listing_id']);
        if (!($doc instanceof WP_Post) || $doc->post_type !== 'elementor_library'
            || $doc->post_status !== 'publish'
            || !($item instanceof WP_Post) || $item->post_type !== 'jet-engine'
            || $item->post_status !== 'publish') return false;
        $listing = get_post_meta($cfg['listing_id'], '_listing_data', true);
        if (!is_array($listing) || ($listing['source'] ?? '') !== 'terms'
            || ($listing['tax'] ?? '') !== 'destinations') return false;
        $raw = get_post_meta($cfg['document_id'], '_elementor_data', true);
        if (!is_string($raw) || !hash_equals($cfg['elementor_sha256'], hash('sha256', $raw))) return false;
        $data = json_decode($raw, true);
        if (!is_array($data)) return false;
        $matches = 0;
        $visit = static function($nodes) use (&$visit, &$matches, $cfg) {
            foreach ((array)$nodes as $node) {
                if (!is_array($node)) continue;
                if (($node['id'] ?? '') === $cfg['widget_id']
                    && ($node['widgetType'] ?? '') === 'jet-listing-grid'
                    && (int)($node['settings']['lisitng_id'] ?? 0) === $cfg['listing_id']) $matches++;
                $visit($node['elements'] ?? []);
            }
        };
        $visit($data);
        return $matches === 1;
    }

    /** WPML may synthesize its resolved locale in PHP without a Cookie header.
     * Allow only that one exact locale; never allow transmitted or session cookies.
     */
    public static function synthetic_language_cookie_only(array $cookies, string $language): bool {
        if (!$cookies) return true;
        return count($cookies) === 1
            && array_key_exists('wp-wpml_current_language', $cookies)
            && is_string($cookies['wp-wpml_current_language'])
            && hash_equals($language, $cookies['wp-wpml_current_language']);
    }

    public static function admission(): ?string {
        $cfg = self::settings();
        if (!$cfg || wp_get_environment_type() !== 'staging'
            || !class_exists('MAD4B_Experience_Site_Scope_V1', false)
            || !MAD4B_Experience_Site_Scope_V1::bound()
            || is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || !is_front_page() || !wp_is_mobile() || !wp_using_ext_object_cache()
            || (string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || (string)($_SERVER['REQUEST_URI'] ?? '') !== '/'
            || !empty($_SERVER['QUERY_STRING'])
            || !empty($_SERVER['HTTP_COOKIE'])
            || !self::synthetic_language_cookie_only((array)$_COOKIE, (string)$cfg['language'])
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_MAD4B_PASSPORT'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || !empty($_SERVER['HTTP_X_COUNTRY_CODE'])
            || !empty($_SERVER['HTTP_X_GEOIP_COUNTRY'])
            || !function_exists('mad4b_render_query_market_identity')
            || !function_exists('mad4b_render_query_has_untrusted_market_header')
            || mad4b_render_query_has_untrusted_market_header()
            || !function_exists('get_woocommerce_currency')
            || !function_exists('wcml_is_multi_currency_on')
            || !wcml_is_multi_currency_on()
            || !class_exists('MAD4B_Stable_Listing_CSS_Identity', false)
            || !MAD4B_Stable_Listing_CSS_Identity::cache_ready()
            || !function_exists('mad4b_public_term_market_invariant_html')) return null;
        if (($cfg['mode'] ?? '') === 'canary') {
            if (($_SERVER['HTTP_X_MAD4B_SOURCE_CARDS_QA'] ?? '') !== 'v1'
                || ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE'] ?? '') !== '1') return null;
        } elseif (!empty($_SERVER['HTTP_X_MAD4B_SOURCE_CARDS_QA'])
            || !empty($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE'])) return null;

        $homeHost = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($homeHost === '' || $homeHost !== $cfg['host']
            || strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) !== $homeHost
            || (string)apply_filters('wpml_current_language', null) !== $cfg['language']
            || !self::exact_graph($cfg)) return null;
        if (function_exists('WC') && WC()->session
            && method_exists(WC()->session, 'has_session')
            && WC()->session->has_session()) return null;
        $market = mad4b_render_query_market_identity();
        if (!is_array($market) || !preg_match('/^[A-Z]{2}$/D', (string)($market['country'] ?? ''))
            || !preg_match('/^[A-Z]{3}$/D', (string)($market['currency'] ?? ''))
            || (string)get_woocommerce_currency() !== $market['currency']) return null;
        // Even price-free content must first be rendered within its own
        // server-verified WCML country/currency partition, never a client hint.
        return hash('sha256', wp_json_encode([
            'source-home-public-terms-v1', home_url('/'), get_current_blog_id(),
            $cfg['language'], $market['country'], $market['currency'],
            $cfg['document_id'], $cfg['listing_id'], $cfg['elementor_sha256'],
        ]));
    }

    private static function safe($html): bool {
        return is_string($html)
            && function_exists('mad4b_public_term_market_invariant_html')
            && mad4b_public_term_market_invariant_html($html)
            && !preg_match('/elementor-dcss-[0-9]{10,22}(?![0-9])/i', $html);
    }

    public static function read(string $scope, string $key) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $scope)
            || !preg_match('/^[a-f0-9]{64}$/D', $key)) return false;
        $ref = wp_cache_get(hash('sha256', 'market:'.$scope.'|'.$key), self::GROUP);
        if (!is_string($ref) || !preg_match('/^[a-f0-9]{64}$/D', $ref)) return false;
        $html = wp_cache_get(hash('sha256','body:'.$key.'|'.$ref), self::GROUP);
        return self::safe($html) && hash_equals($ref, hash('sha256', $html)) ? $html : false;
    }

    public static function write(string $scope, string $key, $html): bool {
        if (!preg_match('/^[a-f0-9]{64}$/D', $scope)
            || !preg_match('/^[a-f0-9]{64}$/D', $key)
            || !self::safe($html)) return false;
        $digest = hash('sha256', $html);
        if (!wp_cache_set(hash('sha256','body:'.$key.'|'.$digest), $html,
            self::GROUP, self::MAX_TTL)) return false;
        return (bool)wp_cache_set(hash('sha256','market:'.$scope.'|'.$key),
            $digest, self::GROUP, self::MAX_TTL);
    }

    public static function run(): void {
        $helper = WPMU_PLUGIN_DIR.'/mad4b-shared-render/public-term-cards.php';
        if (!is_readable($helper) || is_link($helper)) return;
        require_once $helper;
        $scope = self::admission();
        if ($scope === null) return;
        $cfg = self::settings();
        $stats = ['reads'=>0, 'hits'=>0, 'writes'=>0];
        $read = static function($key) use ($scope, &$stats) {
            $stats['reads']++;
            $html = self::read($scope, $key);
            if (is_string($html)) $stats['hits']++;
            return $html;
        };
        $write = static function($key, $html) use ($scope, &$stats) {
            $written = self::write($scope, $key, $html);
            if ($written) $stats['writes']++;
            return $written;
        };
        mad4b_register_public_term_cards(
            $cfg['document_id'], $cfg['widget_id'], $cfg['listing_id'],
            'destinations', $read, $write, true);
        if ($cfg['mode'] === 'canary'
            || ($_SERVER['HTTP_X_MAD4B_RENDER_PROFILE'] ?? '') === 'v1') {
            add_action('shutdown', static function() use (&$stats) {
                // Numeric-only diagnostics; no IP, market, cookie, term or HTML.
                $log = '/tmp/mad4b-source-term-cards-canary-20261118.jsonl';
                if (is_file($log) && (int)@filesize($log) > 32768) return;
                @file_put_contents($log, wp_json_encode([
                    'contract'=>'mad4b.source.term-card.readback.v1',
                    'reads'=>$stats['reads'], 'hits'=>$stats['hits'],
                    'writes'=>$stats['writes']
                ])."\n", FILE_APPEND | LOCK_EX);
            }, 9999);
        }
    }
}
add_action('wp', [MAD4B_Verified_Source_Home_Term_Cards_V1::class, 'run'], 20);
