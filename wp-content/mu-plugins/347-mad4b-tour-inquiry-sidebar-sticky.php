<?php
/**
 * Plugin Name: MAD4B Tour Inquiry Sidebar Sticky
 * Description: Keep native Elementor tour inquiry panels available on desktop within the tour content section without fixing the whole column.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Inquiry_Sidebar_Sticky {
    const HOST = 'tours.dev.mad4b.com';
    const SITE = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';

    public static function allowed(): bool {
        if (wp_get_environment_type() !== 'staging'
            || wp_parse_url(home_url('/'), PHP_URL_HOST) !== self::HOST) return false;
        $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
        return is_array($profile) && ($profile['site_uuid'] ?? '') === self::SITE;
    }

    public static function tour_page(): bool {
        return self::allowed() && !is_admin() && !is_preview()
            && is_singular('tours-and-activities');
    }

    public static function identify($element): void {
        if (!self::tour_page() || !is_object($element)
            || !method_exists($element, 'get_settings')
            || !method_exists($element, 'add_render_attribute')
            || !method_exists($element, 'get_id')) return;

        // Site's authored "Side Inquery Form" container is present across
        // translated Tour Content Elementor templates. ID is a fallback for
        // already-deployed templates if the editor title was translated.
        $title = strtolower(trim((string) $element->get_settings('_title')));
        if ($title !== 'side inquery form'
            && $title !== 'side inquiry form'
            && $element->get_id() !== 'c064aeb') return;

        $element->add_render_attribute('_wrapper', 'class', 'mad4b-tour-inquiry-column');
    }

    public static function styles(): void {
        if (!self::tour_page()) return;
        $name = 'mad4b-tour-inquiry-sidebar-sticky.css';
        $path = WP_CONTENT_DIR . '/mu-plugins/assets/' . $name;
        if (is_readable($path)) {
            wp_enqueue_style('mad4b-tour-inquiry-sidebar-sticky',
                content_url('mu-plugins/assets/' . $name), [],
                (string) filemtime($path));
        }
    }

    public static function boot(): void {
        if (!self::allowed()) return;
        add_action('elementor/frontend/container/before_render', [__CLASS__, 'identify'], 15);
        add_action('wp_enqueue_scripts', [__CLASS__, 'styles'], 115);
    }
}
add_action('init', ['MAD4B_Tour_Inquiry_Sidebar_Sticky', 'boot'], 24);
