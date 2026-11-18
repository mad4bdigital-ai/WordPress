<?php
/**
 * Plugin Name: MAD4B Wayfa Private Signed Home FastCGI Admission
 * Version: 1.0.0
 * Description: Short-lived origin cache only for the EXACT verified country cookie; never cross-visitor HTML sharing.
 */
defined('ABSPATH') || exit;
final class MAD4B_Wayfa_Signed_Home_FastCGI_V1 {
    const CONTRACT='mad4b.wayfa.signed-home-fastcgi.v1';
    const SECONDS=150;

    public static function allowed_cookie_context(string $raw): bool {
        if (strlen($raw)<80 || strlen($raw)>4096
            || preg_match('/[\x00-\x1f\x7f]/',$raw)) return false;
        $seen=[];$country=0;$nationality=0;
        foreach (explode(';',$raw) as $pair) {
            if (trim($pair)!==$pair && !str_starts_with($pair,' '))return false;
            $pair=trim($pair);
            if ($pair==='' || !str_contains($pair,'='))return false;
            [$name,$value]=explode('=',$pair,2);
            if (isset($seen[$name]))return false;
            $seen[$name]=true;
            if ($name==='mad4b_country_selected_v1') {
                if (!preg_match('/\A[A-Za-z0-9_-]{25,1400}\.[a-f0-9]{64}\z/D',$value))return false;
                $country++;continue;
            }
            // Only a WPML locale which matches the path is allowed. The
            // actual language/path check is performed again at admission.
            if ($name==='travel_booking_nationality_preference_v1') {
                if (!preg_match('/\A[A-Za-z0-9_-]{25,1400}\.[a-f0-9]{64}\z/D',$value))return false;
                $nationality++;continue;
            }
            if ($name==='wp-wpml_current_language') {
                if (!in_array($value,['en','es'],true))return false;
                continue;
            }
            if ($name==='mad4b_locale_seen' && $value==='1')continue;
            if (preg_match('/\A(?:_ga(?:_[A-Za-z0-9]+)?|_gid|_gat(?:_[A-Za-z0-9]+)?|_gcl_(?:au|aw|dc|gb)|_fbp|_fbc|_clck|_clsk|sbjs_(?:migrations|current_add|first_add|current|first|udata|session))\z/D',$name)) {
                if (!preg_match('/\A[-A-Za-z0-9._%$=:\/+|@?!~]{1,512}\z/D',$value))return false;
                continue;
            }
            return false;
        }
        return $country===1 && $nationality<=1;
    }
    public static function eligible(): bool {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='GET'
            || !in_array((string)($_SERVER['REQUEST_URI']??''),['/','/es/'],true)
            || (string)($_SERVER['QUERY_STRING']??'')!==''
            || is_admin() || is_user_logged_in() || wp_doing_ajax()
            || is_preview() || is_404() || !is_front_page()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST))!=='wayfatours.com'
            || !class_exists('MAD4B_Country_Switcher',false))return false;
        foreach (['HTTP_AUTHORIZATION','HTTP_X_WP_NONCE','HTTP_X_REQUESTED_WITH',
                  'HTTP_X_MAD4B_CURRENCY','HTTP_X_MAD4B_MARKET_CONTEXT',
                  'HTTP_X_MAD4B_PASSPORT','HTTP_X_MAD4B_BYPASS_CACHE',
                  'HTTP_X_GEO_COUNTRY','HTTP_CF_IPCOUNTRY',
                  'HTTP_X_COUNTRY_CODE','HTTP_X_GEOIP_COUNTRY',
                  'HTTP_X_FORWARDED_GEO_COUNTRY','HTTP_X_MAD4B_GEO_COUNTRY']
                  as $header) {
            if (!empty($_SERVER[$header]))return false;
        }
        $raw=(string)($_SERVER['HTTP_COOKIE']??'');
        if (!self::allowed_cookie_context($raw))return false;
        $country=(string)MAD4B_Country_Switcher::saved_country();
        if (!preg_match('/^[A-Z]{2}$/D',$country))return false;
        if (isset($_COOKIE['travel_booking_nationality_preference_v1'])) {
            if (!class_exists('Travel_Booking_Traveller_Nationality_Switcher',false)
                || !preg_match('/^[A-Z]{2}$/D',
                    (string)Travel_Booking_Traveller_Nationality_Switcher::selected_manual()))
                return false;
        }
        if (function_exists('WC') && WC() && WC()->session
            && method_exists(WC()->session,'has_session') && WC()->session->has_session())
            return false;
        $pathLocale=($_SERVER['REQUEST_URI']==='/es/')?'es':'en';
        if (isset($_COOKIE['wp-wpml_current_language']) &&
            $_COOKIE['wp-wpml_current_language']!==$pathLocale)return false;
        if ((string)apply_filters('wpml_current_language',null)!==$pathLocale)return false;
        return true;
    }
    private static function header_value(array $headers,string $name): ?string {
        foreach (array_reverse($headers) as $entry) {
            if (!is_string($entry))continue;
            if (stripos($entry,$name.':')===0)return trim(substr($entry,strlen($name)+1));
        }
        return null;
    }
    public static function header_admission(): void {
        if (headers_sent() || !self::eligible())return;
        $headers=headers_list();
        if (self::header_value($headers,'X-Wayfa-Public-Page')!=='1')return;
        if (self::header_value($headers,'Set-Cookie')!==null)return;
        $cachePolicy=self::header_value($headers,'X-MAD4B-Cache-Policy');
        if ($cachePolicy!==null && preg_match('/private-context|private-commerce|commerce/i',$cachePolicy))return;
        // No persistent server cache if the private country CSS certificate is
        // missing. Desktop is not using that mobile proof contract.
        if ((string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'')==='mobile'
            && self::header_value($headers,'X-MAD4B-Private-Mobile-CSS-Ready')!=='1')
            return;
        // Only Nginx has the exact-cookie cache key and no-store browser
        // headers. Direct PHP consumers must revalidate and cannot cache
        // without an explicitly enrolled upstream FastCGI contract.
        header('Cache-Control: max-age=0, must-revalidate',true);
        header('X-Accel-Expires: '.self::SECONDS,true);
        header('X-MAD4B-Signed-Home-Cache-Ready: 1',true);
    }
    public static function boot(): void {
        if (!self::eligible())return;
        header_register_callback([__CLASS__,'header_admission']);
    }
}
add_action('template_redirect',['MAD4B_Wayfa_Signed_Home_FastCGI_V1','boot'],1000);
