<?php
/**
 * Plugin Name: MAD4B Native Tour CSS Stability
 * Description: Source-owned native CSS safety for tour singles on every enrolled company and environment.
 * Version: 1.0.0
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
        // Tour pages can have volatile Elementor/JetEngine/Fluent Forms inline CSS.
        // Keep their native source order regardless of globally enabled LiteSpeed
        // CSS combine/minify, without changing tenant-wide performance options.
        add_filter('litespeed_conf_load_option_optm-css_comb', [self::class, 'disable_combine'], PHP_INT_MAX);
        add_filter('litespeed_conf_load_option_optm-css_min', [self::class, 'disable_combine'], PHP_INT_MAX);
    }
    public static function disable_combine($value): string { return '0'; }
    public static function readback(): array {
        return [
            'contract' => 'mad4b.native-tour-css-stability.v1',
            'site_ready' => self::site_ready(),
            'litespeed_loaded' => defined('LSCWP_V'),
            'native_single_route_enabled' => true,
            'safety_hooks' => ['litespeed_conf_load_option_optm-css_comb','litespeed_conf_load_option_optm-css_min'],
            'global_options_mutated' => false,
        ];
    }
}
add_action('wp', ['MAD4B_Tour_Single_CSS_Stability_V1','install'], 110);
