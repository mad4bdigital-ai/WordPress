<?php
/**
 * Per-company admission for the Core Web Vitals provider.
 * No writes or scheduling: merely decides whether its original class may boot.
 * Legacy continuity requires exact-bound site identity plus pre-existing
 * configuration, field-data history and an installed daily Cron event.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_CWV_Site_Admission {
    const CONTRACT = 'mad4b.cwv-per-company-admission.v1';
    const FEATURE = 'core_web_vitals';
    const SETTINGS = 'mad4b_scp_cwv_settings_v1';
    const HISTORY = 'mad4b_scp_core_web_vitals_v1';
    const DAILY = 'mad4b_core_web_vitals_daily';

    public static function admitted() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'status' )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'feature_enabled' )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'site_origin' )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) {
            return false;
        }
        $status = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $status ) || empty( $status['authority_ready'] )
            || empty( $status['deployment_binding_match'] ) ) {
            return false;
        }
        // New sites must explicitly opt in through their own enrolled profile.
        if ( MAD4B_SCP_Site_Profile::feature_enabled( self::FEATURE ) ) {
            return true;
        }
        // Tours.dev previously ran CWV before profile feature flags existed.
        // Preserve that *observed* instance, not an unverified WP-Cron alone.
        if ( ! function_exists( 'get_option' ) || ! function_exists( 'wp_next_scheduled' ) ) {
            return false;
        }
        $settings = get_option( self::SETTINGS, array() );
        $history = get_option( self::HISTORY, array() );
        if ( ! is_array( $settings ) || ! is_array( $history ) || empty( $history )
            || empty( $settings['page_url'] ) || ! is_string( $settings['page_url'] ) ) {
            return false;
        }
        $site = parse_url( (string) MAD4B_SCP_Site_Profile::site_origin() );
        $target = parse_url( trim( $settings['page_url'] ) );
        if ( ! is_array( $site ) || ! is_array( $target )
            || empty( $site['scheme'] ) || empty( $site['host'] )
            || empty( $target['scheme'] ) || empty( $target['host'] )
            || isset( $target['user'] ) || isset( $target['pass'] )
            || strtolower( $site['scheme'] ) !== strtolower( $target['scheme'] )
            || strtolower( $site['host'] ) !== strtolower( $target['host'] )
            || (int) ( $site['port'] ?? 0 ) !== (int) ( $target['port'] ?? 0 ) ) {
            return false;
        }
        $scheduled = wp_next_scheduled( self::DAILY );
        return is_numeric( $scheduled ) && (int) $scheduled > 0;
    }
}
