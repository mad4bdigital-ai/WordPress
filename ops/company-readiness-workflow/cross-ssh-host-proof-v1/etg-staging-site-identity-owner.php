<?php
/**
 * Plugin Name: MAD4B ETG Original Site Identity Enrollment (Staging)
 * Description: Exact signed-profile identity registration by the authenticated site administrator. No grants or remote execution.
 * Version: 0.1.1
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_ETG_Site_Identity_Enrollment_V1', false)) {
final class MAD4B_ETG_Site_Identity_Enrollment_V1 {
    const COMPANY = 'egypt-tour-gates';
    const ENV = 'staging';
    const ORIGIN = 'https://staging.egypttourgates.com';
    const UUID = 'd745d81f-6fc4-5c6a-99dd-d953c92137bf';
    const KEY = 'mad4b_company_site_identity_v1';
    const PAGE = 'mad4b-etg-original-identity';
    const ACTION = 'mad4b_etg_original_identity_enroll';
    public static function exact(): bool {
        if (!function_exists('wp_get_environment_type') ||
            wp_get_environment_type() !== self::ENV ||
            untrailingslashit(home_url('/')) !== self::ORIGIN ||
            !class_exists('MAD4B_SCP_Site_Profile', false) ||
            !class_exists('MAD4B_Company_Signed_Site_Profile_V4', false) ||
            !method_exists('MAD4B_SCP_Site_Profile','status')) return false;
        $s = MAD4B_SCP_Site_Profile::status();
        return is_array($s)
            && ($s['site_uuid'] ?? null) === self::UUID
            && ($s['canonical_origin'] ?? null) === self::ORIGIN
            && ($s['environment'] ?? null) === self::ENV
            && ($s['origin_match'] ?? false) === true
            && ($s['environment_match'] ?? false) === true
            && ($s['deployment_binding_match'] ?? false) === true
            && ($s['deployment_binding_bound'] ?? false) === true
            && ($s['profile_environment_authoritative'] ?? false) === true
            && ($s['foreign_profile_detected'] ?? true) === false
            && preg_match('/^[a-f0-9]{64}$/D',(string)($s['profile_digest'] ?? '')) === 1;
    }
    public static function original(): array {
        return ['company_code'=>self::COMPANY, 'site_uuid'=>self::UUID,
            'canonical_origin'=>self::ORIGIN, 'environment'=>self::ENV];
    }
    public static function state(): string {
        $existing = get_option(self::KEY, null);
        if ($existing !== null)
            return is_array($existing) && $existing === self::original()
                ? 'ORIGINAL_SITE_IDENTITY_ENROLLED'
                : 'EXISTING_SITE_IDENTITY_CONFLICT';
        return self::exact() ? 'EXACT_SITE_READY_FOR_OWNER_ENROLLMENT'
            : 'SIGNED_SITE_PROFILE_OR_HOST_BINDING_NOT_VERIFIED';
    }
    private static function permitted(): bool {
        return current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    public static function page(): void {
        if (!self::permitted()) wp_die('Original site administrator required',403);
        $state = self::state();
        echo '<div class="wrap"><h1>MAD4B — Site Identity Enrollment</h1>';
        echo '<p>Exact site: <code>'.esc_html(self::ORIGIN).'</code> | company: <code>'.
            esc_html(self::COMPANY).'</code> | environment: <code>'.esc_html(self::ENV).'</code>.</p>';
        echo '<p><strong>'.esc_html($state).'</strong></p>';
        echo '<p>Registers this site identity only. No Central Host Scope, 305 grant, Production or remote execution changes.</p>';
        if ($state==='EXACT_SITE_READY_FOR_OWNER_ENROLLMENT') {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
            wp_nonce_field(self::ACTION);
            submit_button('Register this exact Staging site identity');
            echo '</form>';
        }
        echo '</div>';
    }
    public static function enroll(): void {
        if (!self::permitted()) wp_die('Original site administrator required',403);
        check_admin_referer(self::ACTION);
        if (!self::exact() || get_option(self::KEY,null)!==null)
            wp_die('Original site identity not eligible or already exists',409);
        $row=self::original();
        if (!add_option(self::KEY,$row,'',false))
            wp_die('Atomic exact identity enrollment failed',409);
        if (get_option(self::KEY,null)!==$row)
            wp_die('Independent enrollment readback failed',409);
        wp_safe_redirect(add_query_arg('identity','enrolled',
            admin_url('tools.php?page='.self::PAGE)));
        exit;
    }
    public static function boot(): void {
        // MU plugins load before ordinary Site Control Plane and Signed V4.
        // Defer identity checks until all ordinary plugins have loaded.
        add_action('plugins_loaded', static function(){
            if (!self::exact()) return;
            add_action('admin_menu',static function(){
                if (self::permitted())
                    add_management_page('MAD4B Site Identity','MAD4B Site Identity',
                        'manage_options',self::PAGE,[__CLASS__,'page']);
            });
            add_action('admin_post_'.self::ACTION,[__CLASS__,'enroll']);
        },30);
    }
}
MAD4B_ETG_Site_Identity_Enrollment_V1::boot();
}
