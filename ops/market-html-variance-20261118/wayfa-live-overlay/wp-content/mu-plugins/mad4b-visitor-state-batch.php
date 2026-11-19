<?php
/**
 * Plugin Name: MAD4B Visitor Preference State Batch
 * Version: 1.4.5
 * Description: One private read for independent market-country and passport preference states. Does not mutate any state.
 */
defined('ABSPATH') || exit;
final class MAD4B_Visitor_State_Batch {
    public static function state(): void {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0', true);
        if (!class_exists('MAD4B_Country_Switcher') || !class_exists('Travel_Booking_Traveller_Nationality_Switcher')) {
            wp_send_json_error(['code'=>'preference_provider_unavailable'],503);
        }
        $market = MAD4B_Country_Switcher::context();
        $country = (string)($market['country'] ?? '');
        // One-time migration of legitimate older time-bearing country
        // cookies on this *private, no-store* state-read endpoint only.
        // Never set a cookie on public HTML, cart/checkout, authenticated
        // requests, or when a Woo session can override the cookie.
        $raw=(string)($_COOKIE['mad4b_country_selected_v1']??'');
        $sensitive=isset($_COOKIE['woocommerce_items_in_cart'])
             || isset($_COOKIE['woocommerce_cart_hash']);
        foreach (array_keys($_COOKIE ?? []) as $name)
            if(preg_match('/^(?:wordpress_(?:logged_in_|sec_|[a-f0-9]{16,})|wp_woocommerce_session_)/iD',(string)$name))
                $sensitive=true;
        $session=(function_exists('WC')&&WC())?WC()->session:null;
        $hasWCSession=$session&&method_exists($session,'has_session')&&$session->has_session();
        if(!$sensitive && !$hasWCSession && !is_user_logged_in()
            && ($market['explicit']??false) && $raw!==''
            && !headers_sent() && strlen($raw)<=1500
            && class_exists('MAD4B_Country_Switcher',false)
            && method_exists('MAD4B_Country_Switcher','canonical_cookie')) {
            // signed_country() has already validated the original HMAC and
            // site_uuid. Invalid or forged input never gets upgraded.
            $verified=(string)MAD4B_Country_Switcher::saved_country();
            $canonical=$verified!==''?MAD4B_Country_Switcher::canonical_cookie($verified):'';
            if($canonical!=='' && !hash_equals($canonical,$raw))
                setcookie('mad4b_country_selected_v1',$canonical,[
                    'expires'=>time()+90*DAY_IN_SECONDS,'path'=>COOKIEPATH?:'/',
                    'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        }
        $passport = Travel_Booking_Traveller_Nationality_Switcher::selected();
        // A manual passport choice is a separate first-party verified
        // preference. Migrate its previous timestamp-bearing signature only
        // on the same private state read, never on cached HTML.
        $passportRaw=(string)($_COOKIE['travel_booking_nationality_preference_v1']??'');
        if(!$sensitive && !$hasWCSession && !is_user_logged_in()
            && $passportRaw!=='' && strlen($passportRaw)<=1400
            && !headers_sent()
            && class_exists('Travel_Booking_Traveller_Nationality_Switcher',false)
            && method_exists('Travel_Booking_Traveller_Nationality_Switcher','canonical_cookie')) {
            $manual=(string)Travel_Booking_Traveller_Nationality_Switcher::selected_manual();
            $canonicalPassport=$manual!==''?
                Travel_Booking_Traveller_Nationality_Switcher::canonical_cookie($manual):'';
            if($canonicalPassport!=='' && !hash_equals($canonicalPassport,$passportRaw))
                setcookie('travel_booking_nationality_preference_v1',$canonicalPassport,[
                    'expires'=>time()+90*DAY_IN_SECONDS,'path'=>COOKIEPATH?:'/',
                    'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        }
        // Flush read-only anonymous JSON before optional WordPress shutdown
        // tasks. Authenticated and WooCommerce cart/session requests retain
        // standard wp_die response and cookie lifecycle.
        $flush_mode = (string)get_option('mad4b_visitor_early_flush_v1', 'off');
        $flush_requested = $flush_mode === 'on'
            || ($flush_mode === 'canary'
                && ($_SERVER['HTTP_X_MAD4B_VISITOR_FLUSH_CANARY']??'') === 'v1');
        if ($flush_requested && PHP_SAPI === 'fpm-fcgi'
            && function_exists('fastcgi_finish_request')
            && empty($_SERVER['HTTP_AUTHORIZATION'])
            && empty($_SERVER['HTTP_PHP_AUTH_USER'])) {
            $sensitive_cookie = false;
            foreach (array_keys($_COOKIE ?? []) as $cookie_name) {
                if (preg_match('/^(?:wordpress_(?:logged_in_|sec_|[a-f0-9]{16,})|wp_woocommerce_session_|woocommerce_items_in_cart$|woocommerce_cart_hash$)/iD',(string)$cookie_name)) {
                    $sensitive_cookie = true;
                    break;
                }
            }
            if (!$sensitive_cookie && !is_user_logged_in()) {
                add_filter('wp_die_ajax_handler', static function($original) {
                    return static function($message, $title, $args) use ($original) {
                        // wp_send_json_success has already echoed the complete
                        // WordPress JSON response; normal PHP shutdown follows.
                        fastcgi_finish_request();
                        return call_user_func($original, $message, $title, $args);
                    };
                }, PHP_INT_MAX);
                if (!headers_sent()) header('X-MAD4B-Visitor-Flush: early-v1', true);
            }
        }
        wp_send_json_success([
            'contract'=>'mad4b.visitor-preferences.batch.v1',
            'country'=>[
                'contract'=>'mad4b.country-switcher.v1',
                'country'=>$country,
                'explicit'=>(bool)($market['explicit']??false),
                'market_profile_id'=>$market['selected_market']['profile_id']??null,
                'flag_url'=>$country?MAD4B_SCP_Switcher_Sources::country_flag($country):'',
            ],
            'nationality'=>[
                'contract'=>'travel_booking.traveller-nationality.v1',
                'country'=>(string)$passport,
                'preference_mode'=>Travel_Booking_Traveller_Nationality_Switcher::preference_mode(),
                'flag_url'=>$passport?Travel_Booking_Traveller_Nationality_Switcher::flag_url($passport):'',
            ],
        ]);
    }
    private static $eager_done = false;
    private static $safe_firstpaint = null;
    /**
     * A public HTML page can supply the initial country/passport state without
     * a second PHP bootstrap only if it contains no visitor-specific state.
     * This is a first-paint optimization, never a shared cache of preferences.
     */
    public static function safe_firstpaint(): bool {
        if (self::$safe_firstpaint !== null) return self::$safe_firstpaint;
        self::$safe_firstpaint = false;
        $mode = (string)get_option('mad4b_visitor_firstpaint_elision_v1','off');
        if ($mode !== 'on'
            && !($mode === 'canary'
                && ($_SERVER['HTTP_X_MAD4B_VISITOR_FIRSTPAINT']??'')==='v1'
                && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1')) return false;
        if (($_SERVER['REQUEST_METHOD']??'') !== 'GET'
            || !is_front_page() || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || is_preview()
            || !empty($_SERVER['HTTP_COOKIE']) || !empty($_COOKIE['mad4b_country_selected_v1'])
            || !empty($_SERVER['QUERY_STRING']) || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || !class_exists('MAD4B_Country_Switcher')
            || !class_exists('Travel_Booking_Traveller_Nationality_Switcher')) return false;
        foreach(headers_list() as $header){
            if(stripos($header,'Set-Cookie:') === 0) return false;
        }
        // GeoLite can resolve different countries for different visitors.
        // Never put a GeoIP first-paint skip marker into shared cached HTML.
        if (class_exists('MAD4B_SCP_Market_Context')
            && !empty(MAD4B_SCP_Market_Context::status()['local_geoip_db_ready'])) return false;
        $state=MAD4B_Country_Switcher::context();
        if (($state['country']??'') !== ''
            || ($state['explicit']??false)
            || Travel_Booking_Traveller_Nationality_Switcher::selected() !== ''
            || Travel_Booking_Traveller_Nationality_Switcher::preference_mode() !== 'auto')
            return false;
        return self::$safe_firstpaint = true;
    }

    public static function eager_shortcode($output, $tag) {
        if (self::safe_firstpaint()
            || self::$eager_done || !is_string($output) || $output === ''
            || !in_array((string)$tag,['mad4b_country_switcher','travel_booking_nationality_switcher'],true)
            || get_option('mad4b_visitor_preference_batch_v1','off') !== 'on'
            || get_option('mad4b_visitor_preference_eager_v1','off') !== 'on'
            || is_admin() || wp_doing_ajax()) return $output;
        if (strpos($output,'-switcher')===false) return $output;
        self::$eager_done=true;
        // Executed by the HTML parser as soon as the first real switcher renders.
        // No request when native Elementor/JetEngine visibility prevents rendering.
        return $output . '<script id="mad4b-visitor-state-early-trigger" data-no-optimize="1">'
            . 'window.MAD4BVisitorPreferenceBatch?.read()?.catch(function(){});</script>';
    }
    public static function head(): void {
        if (get_option('mad4b_visitor_preference_batch_v1','off') !== 'on') return;
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        $url = admin_url('admin-ajax.php?action=mad4b_visitor_preference_state');
        echo '<script id="mad4b-visitor-preference-batch" data-no-optimize="1">';
        echo '(function(){"use strict";var promise=null;window.MAD4BVisitorPreferenceBatch={read:function(){if(promise)return promise;promise=new Promise(function(resolve,reject){var start=function(){var ctl=new AbortController();var timer=setTimeout(function(){ctl.abort()},6500);fetch('.wp_json_encode($url).',{cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"},signal:ctl.signal}).then(resolve,reject).finally(function(){clearTimeout(timer)});};var ready=function(){setTimeout(start,0);};if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",ready,{once:true});else ready();}).then(function(response){if(!response.ok)throw Error("preference_state_unavailable");return response.json()}).then(function(result){if(!result||!result.success||result.data?.contract!=="mad4b.visitor-preferences.batch.v1")throw Error("preference_state_invalid");return result.data}).catch(function(error){promise=null;throw error});return promise},notify:function(){if(this._pending)return;this._pending=true;var self=this;Promise.resolve().then(function(){self._pending=false;document.dispatchEvent(new CustomEvent("header-preferences:state"));});}}})();';
        if (self::safe_firstpaint()) {
            // Both selects already contain their authoritative default values
            // from PHP. Preserve the original AJAX path for personalized views.
            echo 'window.mad4bCountryServerFirstPaint=true;';
            echo 'window.travelBookingNationalityServerFirstPaint=true;';
        }
        echo '</script>' . "\n";
    }
}
add_action('wp_ajax_nopriv_mad4b_visitor_preference_state',['MAD4B_Visitor_State_Batch','state']);
add_action('wp_ajax_mad4b_visitor_preference_state',['MAD4B_Visitor_State_Batch','state']);
add_action('wp_head',['MAD4B_Visitor_State_Batch','head'],1);
add_filter('do_shortcode_tag',['MAD4B_Visitor_State_Batch','eager_shortcode'],99,2);
