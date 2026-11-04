<?php
/**
 * MAD4B Experience: archive filter-sidebar hover containment.
 *
 * Native Elementor owns the sidebar sticky behavior and card hover design.
 * This module only isolates archive listing paint layers and opt-in widgets.
 * No direct vendor edits, no inline styles and no global z-index overrides.
 */
defined('ABSPATH') || exit;

final class MAD4B_Sticky_Hover_Protection {
    public static function enqueue(): void {
        if (is_admin() || !MAD4B_Presentation_Experience::enabled('sticky-hover-protection')
            || (!is_archive() && !is_singular())) {
            return;
        }
        $asset = 'mad4b-sticky-hover-protection.css';
        $path = MAD4B_Presentation_Experience::asset_path($asset);
        if (!is_readable($path)) {
            return;
        }
        wp_enqueue_style(
            'mad4b-sticky-hover-protection',
            MAD4B_Presentation_Experience::asset_url($asset),
            [],
            (string) filemtime($path)
        );
    }

    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 130);
    }
}
MAD4B_Sticky_Hover_Protection::boot();
