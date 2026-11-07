<?php
/**
 * Plugin Name: MAD4B JetBlog Mobile Initial AJAX Defer (Company Policy)
 * Description: Defers below-fold initial JetBlog Smart Listing mobile POST requests until near viewport without touching pagination or signed Ajax payloads.
 * Version: 0.3.0
 */
defined('ABSPATH') || exit;
final class MAD4B_JetBlog_Mobile_Lazy_Canary {
    private const SOURCE_SHA = '1c03c3f87299c68376daea342e33131d237b54b76470e155accfd03a4a4c5ee4';
    private const ASSET_SHA = 'b91aed8a63acd42fdd7cc289c30583a3a19f2092c17901b7ef6bbe046d127013';
    private const ASSET = 'mad4b-jetblog-lazy-mobile.js';

    public static function enabled(): bool {
        // Portable runtime: company identity + per-company feature policy.
        // Never infer authority from "staging" or a hardcoded domain.
        if (!class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()
            || is_admin() || is_user_logged_in() || !wp_is_mobile()
            || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET') return false;
        // Retain legacy default on Staging, but require local opt-in for any
        // Production site. An unauthenticated query string must never enable
        // a Canary. The option is always company-local.
        $default = wp_get_environment_type()==='staging' ? 'on' : 'off';
        return get_option('mad4b_jetblog_mobile_lazy_v1',$default)==='on';
    }
    public static function script_tag($tag,$handle,$src): string {
        if ($handle !== 'jet-blog' || !self::enabled())return (string)$tag;
        $expected=content_url('/mu-plugins/assets/'.self::ASSET);
        if (!is_string($src)||strpos($src,$expected)!==0)return (string)$tag;
        // LiteSpeed must not replace the canary with its old optimized
        // JetBlog bundle; leave Elementor dependencies unchanged.
        return str_replace('<script ', '<script data-no-optimize="1" ', (string)$tag);
    }
    public static function enqueue(): void {
        if (!self::enabled())return;
        $source=WP_PLUGIN_DIR.'/jet-blog/assets/js/jet-blog.js';
        $asset=WPMU_PLUGIN_DIR.'/assets/'.self::ASSET;
        if (!is_file($source)||!is_file($asset)
            ||!hash_equals(self::SOURCE_SHA,(string)hash_file('sha256',$source))
            ||!hash_equals(self::ASSET_SHA,(string)hash_file('sha256',$asset)))return;
        $scripts=wp_scripts();
        if (!isset($scripts->registered['jet-blog']))return;
        $script=$scripts->registered['jet-blog'];
        // Preserve JetBlog's dependencies, localized JetBlogSettings, version
        // resolution and Elementor widget registration. Replace only src.
        $script->src=content_url('/mu-plugins/assets/'.self::ASSET);
        $script->ver='0.3.0';
    }
}
add_action('elementor/frontend/after_enqueue_scripts',['MAD4B_JetBlog_Mobile_Lazy_Canary','enqueue'],999);
add_filter('script_loader_tag',['MAD4B_JetBlog_Mobile_Lazy_Canary','script_tag'],9999,3);
