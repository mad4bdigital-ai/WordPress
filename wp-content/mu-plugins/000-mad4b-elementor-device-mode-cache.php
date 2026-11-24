<?php
/**
 * Plugin Name: MAD4B — Dynamic Elementor Device Mode Cache
 * Description: Site-aware, language-neutral Elementor breakpoint memoization for public content.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

/**
 * Read-only frontend optimization: no new permissions, signatures or approval checks.
 * Per-site override:
 *   wp option update mad4b_elementor_device_mode_cache_enabled_v1 0
 * Option absent => enabled automatically for provisioned companies.
 */
add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin() || wp_doing_ajax() || is_preview() || is_customize_preview()
        || is_user_logged_in() || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')
        || (defined('REST_REQUEST') && REST_REQUEST)
        || (defined('DOING_CRON') && DOING_CRON)
        || is_feed() || is_404() || is_search() || is_embed() || is_trackback()) {
        return;
    }

    if ((string) get_option('mad4b_elementor_device_mode_cache_enabled_v1', '1') === '0') {
        return;
    }

    // Respect the existing Site Profile when it is present, without adding
    // certificates, user approvals, or new governance dependencies.
    if (class_exists('MAD4B_SCP_Site_Profile')) {
        $profile = MAD4B_SCP_Site_Profile::status();
        if (!is_array($profile)
            || empty($profile['origin_match'])
            || empty($profile['environment_match'])
            || empty($profile['deployment_binding_match'])) {
            return;
        }
    }

    // Never alter transaction, account, or WooCommerce product behavior.
    if ((function_exists('is_cart') && is_cart())
        || (function_exists('is_checkout') && is_checkout())
        || (function_exists('is_account_page') && is_account_page())
        || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url())
        || is_singular('product') || is_post_type_archive('product')) {
        return;
    }

    // Uses WordPress query context; all WPML language variants and published
    // content types are recognized without IDs, slugs or hardcoded locales.
    if (!(is_front_page() || is_home() || is_singular() || is_post_type_archive() || is_archive())) {
        return;
    }

    $js = <<<'JS'
(function(){
    "use strict";
    // LiteSpeed may move Elementor's main JS into a deferred combined bundle.
    // An inline "after" script can then run BEFORE elementorFrontend exists.
    // Retry only until the real public instance is ready, with bounded checks.
    function install() {
        var frontend = window.elementorFrontend;
        if (!frontend || typeof frontend.getCurrentDeviceMode !== 'function') return false;
        if (typeof frontend.isEditMode === 'function' && frontend.isEditMode()) return true;
        if (frontend.__mad4bDeviceModeCache) return true;

        var original = frontend.getCurrentDeviceMode;
    var valid = false, cachedValue = null, cachedWidth = 0;
    function invalidate(){ valid = false; }

    window.addEventListener('resize', invalidate, {passive:true});
    window.addEventListener('orientationchange', invalidate, {passive:true});
    window.addEventListener('pageshow', invalidate, {passive:true});
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function(){
            if (cachedWidth !== window.innerWidth) invalidate();
        }, {passive:true});
    }
    // Late or dynamically loaded stylesheets can change responsive breakpoints.
    document.addEventListener('load', function(ev){
        var target = ev.target;
        if (target && target.tagName === 'LINK' && target.relList
            && target.relList.contains('stylesheet')) invalidate();
    }, true);

    frontend.getCurrentDeviceMode = function(){
        // Keep the original API for any nonstandard parameterized calls.
        if (arguments.length) return original.apply(this, arguments);
        if (valid && cachedWidth === window.innerWidth) return cachedValue;
        var mode = original.apply(this, arguments);
        cachedValue = mode;
        cachedWidth = window.innerWidth;
        valid = true;
        return mode;
    };
        frontend.__mad4bDeviceModeCache = true;
        return true;
    }

    if (install()) return;

    var retryCount = 0, retryTimer = 0;
    var jqListener = false;
    function cleanup() {
        document.removeEventListener('DOMContentLoaded', onReady);
        window.removeEventListener('load', onReady);
        window.removeEventListener('pageshow', onReady);
        if (window.jQuery && jqListener) {
            window.jQuery(window).off('elementor/frontend/init.mad4bDeviceCache', onReady);
        }
        if (retryTimer) {
            window.clearTimeout(retryTimer);
            retryTimer = 0;
        }
    }
    function onReady() {
        if (install()) cleanup();
    }
    function boundedRetry() {
        retryTimer = 0;
        if (install()) { cleanup(); return; }
        if (++retryCount < 16) {
            retryTimer = window.setTimeout(boundedRetry, Math.min(50 + retryCount * 50, 400));
        }
    }
    document.addEventListener('DOMContentLoaded', onReady);
    window.addEventListener('load', onReady);
    window.addEventListener('pageshow', onReady);
    if (window.jQuery) {
        jqListener = true;
        window.jQuery(window).on('elementor/frontend/init.mad4bDeviceCache', onReady);
    }
    retryTimer = window.setTimeout(boundedRetry, 50);
})();
JS;
    // Runs before Elementor's init hooks after its main script is loaded.
    wp_add_inline_script('elementor-frontend', $js, 'after');
}, 1000);
