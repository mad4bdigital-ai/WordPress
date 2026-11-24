<?php
/**
 * Plugin Name: MAD4B Navigation Editor Grid — Native SSR Admission
 * Description: Keep public header navigation grids complete during HTML render; never detach their dynamic menu context into independent JetEngine lazy AJAX.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if (!function_exists('mad4b_nav_grid_requires_native_ssr')) {
    function mad4b_nav_grid_requires_native_ssr($settings): bool {
        if (!is_array($settings)) return false;
        $classes = preg_split('/\s+/', trim((string)($settings['_css_classes'] ?? '')));
        return is_array($classes) && in_array('navigation-editor-grid', $classes, true);
    }
}
if (!function_exists('mad4b_nav_grid_frontend_render_context')) {
    function mad4b_nav_grid_frontend_render_context(): bool {
        return !is_admin() && !wp_doing_ajax()
            && !is_preview()
            && !(defined('WP_CLI') && WP_CLI)
            && !(defined('REST_REQUEST') && REST_REQUEST)
            && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET';
    }
}
/** Before MAD4B Adaptive mutates posts_num/use_load_more, reject this
 * header-only public menu grid from automatic lazy admission. */
add_filter('mad4b/company_adaptive/listing_eligible', static function($eligible, $settings, $listing_id) {
    return mad4b_nav_grid_frontend_render_context() && mad4b_nav_grid_requires_native_ssr($settings)
        ? false : $eligible;
}, 9, 3);
/** Last-resort guard against any other JetEngine lazy filter turning the
 * server-rendered mega menu into a placeholder that cannot be opened. */
add_filter('jet-engine/listing/grid/is_lazy_load', static function($enabled, $settings) {
    return mad4b_nav_grid_frontend_render_context() && mad4b_nav_grid_requires_native_ssr($settings)
        ? false : $enabled;
}, 1001, 2);
