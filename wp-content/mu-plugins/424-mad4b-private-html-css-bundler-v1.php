<?php
/**
 * Plugin Name: MAD4B Private HTML CSS Bundle Delivery
 * Version: 1.2.1
 * Description: Optimize original CSS assets on anonymous public-page PHP responses while preserving private/no-store HTML and original JavaScript.
 */
defined('ABSPATH') || exit;
final class MAD4B_Private_HTML_CSS_Bundler_V1 {
    private static bool $active=false;
    private static function trusted_signed_preferences(string $raw): bool {
        if ($raw==='' || strlen($raw)>5000 || preg_match('/[\x00-\x1f\x7f]/',$raw))return false;
        $seen=[];$signed=false;
        foreach (explode(';',$raw) as $rawPair) {
            $pair=trim($rawPair);
            if ($pair==='' || !str_contains($pair,'='))return false;
            [$name,$value]=explode('=',$pair,2);
            if ($name==='' || isset($seen[$name]) || strlen($value)>1600)return false;
            $seen[$name]=true;
            if ($name==='mad4b_country_selected_v1') {
                if (!class_exists('MAD4B_Country_Switcher',false)
                    || !MAD4B_Country_Switcher::saved_country())return false;
                $signed=true;continue;
            }
            if ($name==='travel_booking_nationality_preference_v1') {
                if (!class_exists('Travel_Booking_Traveller_Nationality_Switcher',false)
                    || !Travel_Booking_Traveller_Nationality_Switcher::selected_manual())return false;
                $signed=true;continue;
            }
            if ($name==='wp-wpml_current_language') {
                if ($value!==(string)apply_filters('wpml_current_language',''))return false;
                continue;
            }
            if ($name==='mad4b_locale_seen') {
                if ($value!=='1')return false;
                continue;
            }
            if (preg_match('/\A(?:sbjs_(?:migrations|current_add|first_add|current|first|udata|session)|_ga(?:_[A-Za-z0-9]+)?|_gid|_gat(?:_[A-Za-z0-9]+)?|_gcl_(?:au|aw|dc|gb)|_fbp|_fbc|_clck|_clsk)\z/D',$name))
                continue;
            // Woo session, logged-in, payment, unknown and duplicate cookies
            // can never authorize this publicly retrievable CSS optimization.
            return false;
        }
        if (!$signed)return false;
        if (function_exists('WC') && WC() && WC()->session
            && method_exists(WC()->session,'has_session') && WC()->session->has_session())
            return false;
        return true;
    }
    public static function eligible(): bool {
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $environment=(string)wp_get_environment_type();
        if (!(($host==='tours.dev.mad4b.com' && $environment==='staging')
              || ($host==='wayfatours.com' && $environment==='production'))
            || get_option('mad4b_private_html_css_bundle_v1','on')!=='on'
            || !defined('LSCWP_V')
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || !empty($_SERVER['QUERY_STRING'])
            || ($_SERVER['REQUEST_METHOD']??'')!=='GET'
            || is_admin() || wp_doing_ajax() || is_user_logged_in()
            || is_preview() || is_feed() || is_search() || is_404()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (string)get_option('litespeed.conf.optm-css_comb','0')!=='1'
            || (string)get_option('litespeed.conf.optm-css_min','0')!=='1')
            return false;
        $raw=(string)($_SERVER['HTTP_COOKIE']??'');
        // Wayfa anonymous already uses its certified public CSS path, and
        // its signed mobile Home is independently certified by original 423.
        if ($host==='wayfatours.com' && ($raw==='' || is_front_page() || is_home()))
            return false;
        if ($raw!=='' && !self::trusted_signed_preferences($raw))return false;
        if ((function_exists('is_checkout')&&is_checkout())
            || (function_exists('is_cart')&&is_cart())
            || (function_exists('is_account_page')&&is_account_page())
            || (function_exists('is_wc_endpoint_url')&&is_wc_endpoint_url()))
            return false;
        if (is_front_page() || is_home()) return true;
        if (is_singular(['page','post','tours-and-activities','properties'])) {
            $post=get_queried_object();
            return $post instanceof WP_Post && $post->post_status==='publish'
                && (string)$post->post_password==='';
        }
        if (is_tax(['destinations','tour-types','property-types','tour-categories'])
            || is_category()) return true;
        return is_post_type_archive(['tours-and-activities','properties']);
    }
    public static function admit(): void {
        if (!self::eligible())return;
        // LiteSpeed's normal optimizer refuses to combine CSS on a
        // no-cache PHP response. This original internal switch allows CSS
        // compilation without reclassifying the HTML response as public.
        // Native JavaScript must stay original, because first-party forms
        // contain dynamic signed per-request state and payment actions.
        self::$active=true;
        if (!defined('LITESPEED_GUEST_OPTM'))define('LITESPEED_GUEST_OPTM',true);
    }
    public static function preserve_js($excludes) {
        if (!self::$active)return $excludes;
        $excludes=is_array($excludes)?$excludes:[];
        // Native LiteSpeed Utility::str_hit_array() treats '^' as the
        // all-prefix match, excluding every JS src/inline script from
        // asset generation. This is intentional CSS-only optimization.
        if (!in_array('^',$excludes,true))$excludes[]='^';
        return $excludes;
    }
    /**
     * Keep volatile JetEngine per-render CSS next to its own live markup,
     * NOT in the content-addressed 3MB LiteSpeed CSS bundle. This preserves
     * Elementor class-to-rule parity and lets the immutable static bundle be
     * shared across viewport widths. Proven on staging Tour AR: four
     * independent no-cookie requests produced one identical CSS SHA.
     *
     * This does not relax private HTML or modify JS; it is scoped to a
     * public, published source Tour admitted by eligible()/admit().
     */
    public static function preserve_volatile_tour_css($excludes) {
        if (!self::$active
            || (string)wp_parse_url(home_url('/'),PHP_URL_HOST)!=='tours.dev.mad4b.com'
            || !is_singular('tours-and-activities'))
            return $excludes;
        $excludes=is_array($excludes)?$excludes:[];
        if (!in_array('elementor-dcss-',$excludes,true))
            $excludes[]='elementor-dcss-';
        return $excludes;
    }
    public static function protect_headers(): void {
        if (!self::$active || headers_sent())return;
        // This CSS-only fallback intentionally retains PRIVATE HTML. The
        // independent public-page optimizer can have announced eligibility
        // earlier at template_redirect:9000; it cannot certify the final
        // CSS/HTML body and must never override our private decision.
        // Remove *only* conflicting public admission claims, not the CSS
        // certificate or the independently verified visitor market state.
        header_remove('X-MAD4B-Cache-Eligibility');
        header_remove('X-MAD4B-Browser-HTML');
        header_remove('X-MAD4B-Browser-HTML-TTL');
        header('Cache-Control: private, no-store, max-age=0',true);
        header('X-MAD4B-Private-CSS-Delivery: css-bundle-only-v1',true);
    }
    public static function readback(): array {
        return ['contract'=>'mad4b.private-html.css-only-bundle.v1',
            'environment'=>wp_get_environment_type(),
            'signed_preference_nonhome_css_only'=>true,
            'enabled'=>get_option('mad4b_private_html_css_bundle_v1','on'),
            'private_html_no_store'=>true,
            'javascript_exclusion'=>'all',
            'uses_existing_litespeed_css_combine'=>true,
            'source_tour_dynamic_css_excluded_from_bundle'=>true];
    }
}
add_action('wp',['MAD4B_Private_HTML_CSS_Bundler_V1','admit'],105);
add_filter('litespeed_optimize_js_excludes',
    ['MAD4B_Private_HTML_CSS_Bundler_V1','preserve_js'],PHP_INT_MAX);
add_filter('litespeed_optimize_css_excludes',
    ['MAD4B_Private_HTML_CSS_Bundler_V1','preserve_volatile_tour_css'],PHP_INT_MAX);
// send_headers occurs before the query is resolved, so wp-admitted
// pages must reinforce private HTML headers at template_redirect too.
add_action('template_redirect',
    ['MAD4B_Private_HTML_CSS_Bundler_V1','protect_headers'],9999);
add_action('send_headers',
    ['MAD4B_Private_HTML_CSS_Bundler_V1','protect_headers'],PHP_INT_MAX);
