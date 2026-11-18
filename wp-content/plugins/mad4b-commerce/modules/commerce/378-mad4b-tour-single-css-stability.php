<?php
/**
 * Plugin Name: MAD4B Native Tour CSS Stability
 * Description: Source-owned CSS bundle integrity for tour singles without disabling native LiteSpeed combine/minify.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Single_CSS_Stability_V1 {
    public static function site_ready(): bool {
        if (!class_exists('MAD4B_SCP_Site_Profile')) return false;
        $profile = MAD4B_SCP_Site_Profile::status();
        return !empty($profile['configured'])
            && !empty($profile['origin_match'])
            && !empty($profile['environment_match'])
            && !empty($profile['deployment_binding_match'])
            && wp_is_uuid((string)($profile['site_uuid'] ?? ''));
    }
    public static function eligible(): bool {
        return !is_admin() && !wp_doing_ajax()
            && is_singular('tours-and-activities')
            && defined('LSCWP_V')
            && self::site_ready();
    }
    public static function install(): void {
        if (!self::eligible()) return;
        // Original tour CSS-specific combine veto was proven harmful:
        // with the site's owned global LiteSpeed CSS combine/minify ON, the
        // veto caused 158 separate stylesheets instead of four combined ones.
        // Retain Elementor/JetEngine native ordering inside the combined
        // generated asset, not by blocking the primary CSS optimization.
        // Never override the original host's private HTML/no-store policy.
        return;
    }
    public static function disable_combine($value) { return $value; } // compatibility only; unhooked
    public static function readback(): array {
        return [
            'contract' => 'mad4b.native-tour-css-stability.v1',
            'site_ready' => self::site_ready(),
            'litespeed_loaded' => defined('LSCWP_V'),
            'native_single_route_enabled' => false,
            'site_css_bundle_policy' => 'litespeed_combine_minify_on',
            'safety_hooks' => [],
            'legacy_combine_veto_removed' => true,
            'global_options_mutated' => false,
        ];
    }
}
add_action('wp', ['MAD4B_Tour_Single_CSS_Stability_V1','install'], 110);
