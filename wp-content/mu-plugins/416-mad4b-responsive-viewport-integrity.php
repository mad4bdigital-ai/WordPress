<?php
/**
 * Plugin Name: MAD4B Responsive Viewport Integrity
 * Version: 1.1.0
 * Description: Keep the layout viewport stable on public pages when moving between desktop, tablet and mobile sizes.
 */
defined('ABSPATH') || exit;

final class MAD4B_Responsive_Viewport_Integrity_V1 {
    private const OPTION = 'mad4b_perf_responsive_viewport_clip_v1';
    private const POST_OPTOUT = 'mad4b_responsive_viewport_clip_disabled';

    private static function eligible(): bool {
        if (is_admin() || is_feed() || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_is_json_request') && wp_is_json_request())
            || isset($_GET['elementor-preview'], $_GET['action'])
            || isset($_GET['elementor-preview'])
            || isset($_GET['preview'])
            || (function_exists('is_customize_preview') && is_customize_preview())
            || get_option(self::OPTION, 'off') !== 'on') return false;

        // Preserve editor experiences and sensitive booking/account interfaces.
        if (isset($_GET['action']) && $_GET['action'] === 'elementor') return false;
        if (function_exists('is_checkout') && is_checkout()) return false;
        if (function_exists('is_cart') && is_cart()) return false;
        if (function_exists('is_account_page') && is_account_page()) return false;
        if (is_page(['checkout', 'cart', 'my-account', 'my-account-page', 'booking', 'bookings'])
            || is_singular(['tour_bookings', 'tour_orders'])
            || is_404()) return false;

        if (!(is_front_page() || is_home() || is_singular() || is_archive() || is_search())) return false;

        $id = get_queried_object_id();
        if ($id > 0 && get_post_meta($id, self::POST_OPTOUT, true) === '1') return false;
        return (bool) apply_filters('mad4b_responsive_viewport_clip_eligible', true, $id);
    }

    public static function render(): void {
        if (!self::eligible()) return;
        // 'clip' does not create a scroll container; preserve sticky elements.
        // Swiper/Elementor resize handlers continue to use the true viewport.
        echo '<style id="mad4b-responsive-viewport-v1" data-no-optimize="1">html{overflow-x:clip!important}</style>' . "\n";
    }
}
add_action('wp_head', ['MAD4B_Responsive_Viewport_Integrity_V1', 'render'], 0);
