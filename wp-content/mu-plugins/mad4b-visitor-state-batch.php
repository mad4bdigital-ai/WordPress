<?php
/**
 * Plugin Name: MAD4B Visitor Preference State Batch
 * Version: 1.2.1
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
        $passport = Travel_Booking_Traveller_Nationality_Switcher::selected();
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
    public static function eager_shortcode($output, $tag) {
        if (self::$eager_done || !is_string($output) || $output === ''
            || !in_array((string)$tag,['mad4b_country_switcher','travel_booking_nationality_switcher'],true)
            || get_option('mad4b_visitor_preference_batch_v1','off') !== 'on'
            || get_option('mad4b_visitor_preference_eager_v1','off') !== 'on'
            || is_admin() || wp_doing_ajax()) return $output;
        if (strpos($output,'-switcher')===false) return $output;
        self::$eager_done=true;
        // Executed by the HTML parser as soon as the first real switcher renders.
        // No request when native Elementor/JetEngine visibility prevents rendering.
        return $output . '<script id="mad4b-visitor-state-early-trigger" data-no-optimize="1">'
            . 'window.MAD4BVisitorPreferenceBatch?.read();</script>';
    }
    public static function head(): void {
        if (get_option('mad4b_visitor_preference_batch_v1','off') !== 'on') return;
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        $url = admin_url('admin-ajax.php?action=mad4b_visitor_preference_state');
        echo '<script id="mad4b-visitor-preference-batch" data-no-optimize="1">';
        echo '(function(){"use strict";var promise=null;window.MAD4BVisitorPreferenceBatch={read:function(){if(promise)return promise;promise=fetch('.wp_json_encode($url).',{cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}}).then(function(response){if(!response.ok)throw Error("preference_state_unavailable");return response.json()}).then(function(result){if(!result||!result.success||result.data?.contract!=="mad4b.visitor-preferences.batch.v1")throw Error("preference_state_invalid");return result.data}).catch(function(error){promise=null;throw error});return promise},notify:function(){if(this._pending)return;this._pending=true;var self=this;Promise.resolve().then(function(){self._pending=false;document.dispatchEvent(new CustomEvent("header-preferences:state"));});}}})();';
        echo '</script>' . "\n";
    }
}
add_action('wp_ajax_nopriv_mad4b_visitor_preference_state',['MAD4B_Visitor_State_Batch','state']);
add_action('wp_ajax_mad4b_visitor_preference_state',['MAD4B_Visitor_State_Batch','state']);
add_action('wp_head',['MAD4B_Visitor_State_Batch','head'],1);
add_filter('do_shortcode_tag',['MAD4B_Visitor_State_Batch','eager_shortcode'],99,2);
