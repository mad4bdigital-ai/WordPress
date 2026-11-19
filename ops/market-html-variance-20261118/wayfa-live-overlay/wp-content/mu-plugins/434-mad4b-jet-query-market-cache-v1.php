<?php
/**
 * Plugin Name: MAD4B Wayfa JetEngine Verified Market Query Cohorts
 * Version: 1.0.1
 * Description: Scope read-only JetEngine post/term query results by verified country, WPML and currency; never cache commercial/session-dependent selections.
 */
defined('ABSPATH') || exit;

final class MAD4B_Wayfa_Jet_Query_Market_Cache_V1 {
    const CONTRACT='mad4b.wayfa.jet-market-query-cohort.v1';
    const MAX_SECONDS=90;
    const FILTER_SECONDS=30;

    private static function unsafe_request():bool {
        if (is_user_logged_in())return true;
        foreach ((array)($_COOKIE??[]) as $name=>$value) {
            if (!preg_match('/\A(?:mad4b_country_selected_v1|travel_booking_nationality_preference_v1|wp-wpml_current_language|mad4b_locale_seen|_ga(?:_[A-Za-z0-9]+)?|_gid|_gat(?:_[A-Za-z0-9]+)?|_gcl_(?:au|aw|dc|gb)|_fbp|_fbc|_clck|_clsk|sbjs_(?:migrations|current_add|first_add|current|first|udata|session))\z/D',(string)$name))return true;
            if (preg_match('/\A(?:wordpress_logged_in_|wordpress_sec_|wp_woocommerce_session_|woocommerce_cart_hash|woocommerce_items_in_cart)/i',(string)$name))
                return true;
        }
        if (function_exists('WC') && WC() && isset(WC()->session)
            && is_object(WC()->session) && method_exists(WC()->session,'has_session')
            && WC()->session->has_session())return true;
        $path=(string)($_SERVER['REQUEST_URI']??'');
        if (preg_match('~(?:/|^)(?:checkout|cart|order-pay|order-received|payment|booking|inquiry|contact|my-account)(?:/|[?]|$)~i',$path))
            return true;
        $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
        if (!in_array($method,['GET','POST'],true))return true;
        $ajax=function_exists('wp_doing_ajax')&&wp_doing_ajax();
        if ($ajax && (string)($_REQUEST['action']??'')!=='jet_smart_filters')return true;
        // A POST not owned by JetSmartFilters can submit guest forms/rates
        // even if its URL resembles a public archive.
        if ($method==='POST' && !$ajax)return true;
        if ((defined('REST_REQUEST')&&REST_REQUEST) && !$ajax)return true;
        if (is_admin() && !$ajax)return true;
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])
            ||!empty($_SERVER['HTTP_X_WP_NONCE'])
            ||!empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            ||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            ||!empty($_SERVER['HTTP_X_MAD4B_PASSPORT']))return true;
        return false;
    }
    private static function request_fingerprint():string {
        $request=(array)($_REQUEST??[]);
        // WooCommerce can initialize public login/reset-password nonce fields
        // even on a read-only archive GET. These do not alter query output.
        // Other request keys remain part of the scope or fail closed.
        foreach (['_wpnonce','woocommerce-login-nonce','woocommerce-reset-password-nonce'] as $nonce)
            unset($request[$nonce]);
        if (!$request)return 'none';
        // Never store, log or reuse a raw visitor filter payload. The short
        // digest partitions inputs that JetEngine macros might interpret.
        $raw=wp_json_encode($request);
        if (!is_string($raw) || strlen($raw)>8192)return '';
        return hash('sha256',$raw);
    }
    public static function configure($query):void {
        if (!is_object($query) || !property_exists($query,'cache_query')
            || !$query->cache_query || !property_exists($query,'cache_group')
            || !property_exists($query,'cache_expires')
            || !method_exists($query,'get_query_type')
            || !in_array((string)$query->get_query_type(),['posts','terms'],true))
            return;
        if (strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))!=='wayfatours.com')
            return;
        if (self::unsafe_request()) {
            $query->cache_query=false;return;
        }
        // Do not admit unverified or absent visitor-country cookies into the
        // market-specific namespace. Existing anonymous-query behavior is
        // left to JetEngine and the public FastCGI policy.
        if (empty($_COOKIE['mad4b_country_selected_v1']))return;
        if (!class_exists('MAD4B_Country_Switcher',false)) {
            $query->cache_query=false;return;
        }
        $country=(string)MAD4B_Country_Switcher::saved_country();
        if (!preg_match('/^[A-Z]{2}$/D',$country)) {
            $query->cache_query=false;return;
        }
        $lang=(string)apply_filters('wpml_current_language',null);
        if (!preg_match('/^[a-z]{2}$/D',$lang)) {
            $query->cache_query=false;return;
        }
        $currency=function_exists('get_woocommerce_currency')?(string)get_woocommerce_currency():'';
        if (!preg_match('/^[A-Z]{3}$/D',$currency)) {
            $query->cache_query=false;return;
        }
        $nationality='none';
        if (isset($_COOKIE['travel_booking_nationality_preference_v1'])) {
            if (!class_exists('Travel_Booking_Traveller_Nationality_Switcher',false)) {
                $query->cache_query=false;return;
            }
            $nationality=(string)Travel_Booking_Traveller_Nationality_Switcher::selected_manual();
            if (!preg_match('/^[A-Z]{2}$/D',$nationality)) {
                $query->cache_query=false;return;
            }
        }
        $final=property_exists($query,'final_query')?$query->final_query:null;
        $serialized=wp_json_encode($final);
        if (!is_string($serialized) || strlen($serialized)>20000) {
            $query->cache_query=false;return;
        }
        // Financially sensitive selection is always evaluated live. This
        // filter does not bypass Woo/FluentForms quote or checkout validation.
        if (preg_match('/(?:price|rate|stock|booking|payment|availability|capacity|supplier|discount|deposit|checkout|date|season|pickup|accommodation)/i',$serialized)) {
            $query->cache_query=false;return;
        }
        // Never cache JetSmartFilters' date/price/availability context:
        // price and booking sources must be refreshed from the origin.
        $requestRaw=wp_json_encode((array)($_REQUEST??[]));
        if (!is_string($requestRaw) || strlen($requestRaw)>8192
            || preg_match('/(?:price|rate|stock|booking|payment|availability|capacity|supplier|discount|deposit|checkout|date|season|pickup|accommodation)/i',$requestRaw)) {
            $query->cache_query=false;return;
        }
        $requestSha=self::request_fingerprint();
        if ($requestSha==='') {
            $query->cache_query=false;return;
        }
        $scope=hash('sha256',wp_json_encode([
            'contract'=>self::CONTRACT,'site'=>home_url('/'),
            'locale'=>$lang,'country'=>$country,'passport'=>$nationality,
            'currency'=>$currency,'request'=>$requestSha,
            'dependency_guard'=>(string)get_option('mad4b_page_dependency_guard_epoch_v1','initial'),
            'query_type'=>$query->get_query_type(),
        ]));
        // The existing JetEngine query hash still covers exact query args.
        // Namespace its *public records only* by verified visitor context.
        $query->cache_group='mad4b-jet-public-'.substr($scope,0,32);
        $ttl=(int)$query->cache_expires;
        $cap=(function_exists('wp_doing_ajax')&&wp_doing_ajax())
            ?self::FILTER_SECONDS:self::MAX_SECONDS;
        $query->cache_expires=$ttl>0?min($ttl,$cap):$cap;
    }
    public static function boot():void {
        add_action('jet-engine/query-builder/query/after-query-setup',
            [__CLASS__,'configure'],120,1);
    }
}
MAD4B_Wayfa_Jet_Query_Market_Cache_V1::boot();
