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
    private static function site_sticky_config():array {
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return [];
        $profile=MAD4B_SCP_Site_Profile::status();
        $settings=get_option('mad4b_experience_archive_sticky_v1',[]);
        if(!is_array($profile)||!is_array($settings)
           ||($profile['configured']??false)!==true
           ||($profile['origin_match']??false)!==true
           ||($profile['deployment_binding_match']??false)!==true
           ||($profile['environment_match']??false)!==true
           ||($settings['site_uuid']??'')!==($profile['site_uuid']??null)
           ||!preg_match('/^[a-f0-9]{8}$/D',(string)($settings['element_id']??''))
           ||!is_int($settings['top_px']??null)
           ||$settings['top_px']<0||$settings['top_px']>240)return [];
        return $settings;
    }
    public static function filter_archive_scope(): bool {
        return !is_admin()
            && is_post_type_archive('tours-and-activities')
            && MAD4B_Presentation_Experience::enabled('sticky-hover-protection')
            && (bool)self::site_sticky_config();
    }

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
        if (self::filter_archive_scope()) {
            $cfg=self::site_sticky_config();
            wp_add_inline_style('mad4b-sticky-hover-protection',
                '@media (min-width:1025px){body.post-type-archive-tours-and-activities ' .
                '.elementor-element-'.$cfg['element_id'].'{position:sticky!important;top:'.
                $cfg['top_px'].'px;align-self:flex-start;z-index:99;}}');
        }
    }

    public static function boot(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 130);
    }
}
MAD4B_Sticky_Hover_Protection::boot();
