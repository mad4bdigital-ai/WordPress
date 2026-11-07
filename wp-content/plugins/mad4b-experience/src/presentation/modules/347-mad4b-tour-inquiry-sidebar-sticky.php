<?php
/**
 * Plugin Name: MAD4B Tour Inquiry Sidebar Sticky
 * Description: Keep native Elementor tour inquiry panels available on desktop within the tour content section without fixing the whole column.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Inquiry_Sidebar_Sticky {
    public static function allowed(): bool { return MAD4B_Presentation_Experience::enabled('tour-inquiry-sidebar'); }

    public static function tour_page(): bool {
        return self::allowed() && !is_admin() && !is_preview()
            && is_singular('tours-and-activities');
    }

    public static function identify($element): void {
        // The same authored sidebar role is used by Tours, Properties and
        // Transportations. Mark it on any singular Elementor page that renders
        // the role; public service activation still owns route availability.
        if (!self::allowed() || is_admin() || is_preview() || !is_singular()
            || !is_object($element)
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

        // Semantic marker for hover/stacking protection; does not alter CSS.
        $element->add_render_attribute('_wrapper', 'class', 'mad4b-product-sticky-panel');
        // Preserve the existing Tours-only sticky behavior, unchanged.
        if (self::tour_page()) {
            $element->add_render_attribute('_wrapper', 'class', 'mad4b-tour-inquiry-column');
        }
    }

    public static function styles(): void {
        if (!self::tour_page()) return;
        $name = 'mad4b-tour-inquiry-sidebar-sticky.css';
        $path = MAD4B_Presentation_Experience::asset_path('') . $name;
        if (is_readable($path)) {
            wp_enqueue_style('mad4b-tour-inquiry-sidebar-sticky',
                MAD4B_Presentation_Experience::asset_url($name), [],
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
