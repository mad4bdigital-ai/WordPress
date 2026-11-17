<?php
/**
 * Plugin Name: MAD4B Cart Island Contract
 * Description: Static, public-cache-safe Woo Cart shell with independently hydrated private Store API state. No cart or payment flow enabled by this module.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Cart_Island_V1 {
    public const CONTRACT = 'mad4b.cart-island.v1';
    private const SCRIPT = 'mad4b-cart-island-v1';
    private const SOURCE = 'assets/mad4b-cart-island-v1.js';
    private const STYLE = 'assets/mad4b-cart-island-v1.css';
    private static $islands = 0;

    public static function experience(): string {
        $value = function_exists('mad4b_woocommerce_public_experience_v1')
            ? mad4b_woocommerce_public_experience_v1()
            : (string)get_option('mad4b_woocommerce_public_experience_v1', 'standard');
        return $value === 'standard' ? 'standard' : 'payment_only';
    }

    private static function payment_route(): bool {
        if (is_admin() || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)) return true;
        if (function_exists('is_wc_endpoint_url') &&
            (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')))
            return true;
        if (function_exists('is_checkout') && is_checkout()) return true;
        $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??'/'), PHP_URL_PATH);
        return (bool)preg_match('~/(?:checkout|order-pay|order-received|payment|pago)(?:/|$)~iD',$path);
    }

    public static function enabled(): bool {
        // No public cart or add-to-cart features are re-enabled in payment_only.
        // When Commerce is later put into standard storefront mode, pre-enrolled
        // shortcodes/Elementor templates activate without switching session models.
        return self::experience()==='standard'
            && (string)get_option('mad4b_cart_island_enabled_v1','auto')!=='off'
            && class_exists('WooCommerce',false)
            && !self::payment_route();
    }

    public static function fragment_data($original, $handle) {
        if ($handle !== 'wc-cart-fragments') return $original;
        // Woo order-pay and signed checkout remain original/native.
        if (self::experience()==='payment_only' && !self::payment_route())
            return null;
        return $original;
    }

    public static function shortcode($atts=[]): string {
        if (!self::enabled()) return '';
        $asset=WPMU_PLUGIN_DIR.'/'.self::SOURCE;
        $style=WPMU_PLUGIN_DIR.'/'.self::STYLE;
        if (!is_file($asset) || is_link($asset)
            || !is_file($style) || is_link($style)) return '';
        // Source is site-public (no per-user nonce, cart data or Cart-Token).
        // The browser fetches the cart separately from Woo's native Store API.
        $url=rest_url('wc/store/v1/cart');
        if (wp_parse_url($url,PHP_URL_HOST) !== wp_parse_url(home_url('/'),PHP_URL_HOST))
            return '';

        wp_enqueue_script(self::SCRIPT,content_url('mu-plugins/'.self::SOURCE),
            [],(string)filemtime($asset),true);
        wp_enqueue_style(self::SCRIPT,content_url('mu-plugins/'.self::STYLE),
            [],(string)filemtime($style));
        $panel_id='mad4b-cart-island-panel-v1-'.(++self::$islands);
        // Simple semantic shell can be included in cached public HTML byte-for-byte.
        // Even a logged-in or cart-holding request receives identical markup.
        return '<div class="mad4b-cart-island" data-mad4b-cart-island="v1"'
            .' data-cart-endpoint="'.esc_url($url).'"'
            .' data-cart-empty="'.esc_attr__('Your cart is empty.','woocommerce').'"'
            .' data-cart-error="'.esc_attr__('Cart unavailable.','woocommerce').'">'
            .'<button class="mad4b-cart-island__toggle" type="button" data-cart-open'
            .' aria-expanded="false" aria-controls="'.esc_attr($panel_id).'">'
            .esc_html__('Cart','woocommerce')
            .' <span data-cart-count aria-live="polite">0</span></button>'
            .'<section id="'.esc_attr($panel_id).'" data-cart-panel hidden'
            .' role="region" aria-label="'.esc_attr__('Cart','woocommerce').'">'
            .'<div data-cart-status role="status" aria-live="polite"></div>'
            .'<ul data-cart-items aria-label="'.esc_attr__('Cart','woocommerce').'"></ul>'
            .'<output data-cart-total></output>'
            .'</section></div>';
    }

    public static function classic_widget($instance, $widget, $args) {
        // Classic Woo Cart Widget is server-rendered and is unsafe inside
        // a public cached shell. Replace it with a static Cart Island.
        // Native Woo Mini-Cart Block already has Store API ownership.
        if (self::enabled() && is_object($widget)
            && is_a($widget,'WC_Widget_Cart')) {
            echo self::shortcode();
            return false;
        }
        return $instance;
    }

    public static function boot(): void {
        add_shortcode('mad4b_cart_island',[self::class,'shortcode']);
        add_filter('woocommerce_get_script_data',[self::class,'fragment_data'],15,2);
        add_filter('widget_display_callback',[self::class,'classic_widget'],19,3);
    }
}
add_action('init',['MAD4B_Cart_Island_V1','boot'],21);
