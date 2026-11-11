<?php
/**
 * Plugin Name: MAD4B Mobile CSS Combine Canary (Source only)
 * Version: 0.1.0
 * Description: Isolated, uncached mobile request experiment; changes no global LiteSpeed settings.
 */
defined('ABSPATH') || exit;
final class MAD4B_Mobile_CSS_Combine_Canary {
    public static function requested(): bool {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || !empty($_SERVER['PHP_AUTH_USER'])
            || (string)($_GET['mad4b_mobile_css_nocombine'] ?? '') !== '1'
            || (string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS'] ?? '') !== 'mobile'
            || strcasecmp((string)($_SERVER['HTTP_HOST'] ?? ''),'tours.dev.mad4b.com') !== 0) return false;
        return true;
    }
    public static function disable($setting) {
        return self::requested() ? false : $setting;
    }
    public static function report(): void {
        if (!self::requested()) return;
        header('X-MAD4B-Mobile-CSS-NoCombine-Canary: 1');
        header('Cache-Control: private, no-store, max-age=0',true);
    }
}
add_filter('litespeed_conf_load_option_optm-css_comb',['MAD4B_Mobile_CSS_Combine_Canary','disable'],99);
add_filter('litespeed_conf_load_option_optm-css_comb_ext_inl',['MAD4B_Mobile_CSS_Combine_Canary','disable'],99);
add_action('send_headers',['MAD4B_Mobile_CSS_Combine_Canary','report'],0);
add_action('wp_head', static function(){if ((string)($_GET['mad4b_mobile_css_nocombine']??'')!=='1')return;echo '<meta name="mad4b-css-canary-readback" content="'.esc_attr('eligible='.(MAD4B_Mobile_CSS_Combine_Canary::requested()?'1':'0').';trusted='.($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'absent').';host='.($_SERVER['HTTP_HOST']??'absent').';method='.($_SERVER['REQUEST_METHOD']??'absent').';cookies='.count($_COOKIE).';admin='.(is_admin()?'1':'0').';ajax='.(wp_doing_ajax()?'1':'0').';query='.($_GET['mad4b_mobile_css_nocombine']??'absent')).'">'."\n";},0);
