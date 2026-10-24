<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Isolated local-site identity for host-assisted MCP clients.
 *
 * Identity binding is not a transport grant. Existing OAuth, capability,
 * execution-policy and mutation gates remain authoritative.
 */
final class MAD4B_SCP_Local_Client {
    const CONTRACT = 'mad4b.local-client.v1';
    const REQUIRED_ENV = 'local';

    public static function bound() {
        if ( ! function_exists( 'wp_get_environment_type' )
            || self::REQUIRED_ENV !== wp_get_environment_type()
            || ! defined( 'MAD4B_LOCAL_CLIENT_SITE_UUID' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile', false ) ) return false;

        $expected = trim( (string) constant( 'MAD4B_LOCAL_CLIENT_SITE_UUID' ) );
        $actual   = (string) MAD4B_SCP_Site_Profile::site_uuid();
        if ( ! preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $expected )
            || ! hash_equals( strtolower( $expected ), strtolower( $actual ) ) ) return false;

        return MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
    }

    public static function status() {
        $bound = self::bound();
        return array(
            'contract' => self::CONTRACT,
            'bound' => $bound,
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'site_profile_ready' => class_exists( 'MAD4B_SCP_Site_Profile', false )
                && MAD4B_SCP_Site_Profile::origin_enrolled(),
            'local_access' => $bound ? 'site_identity_bound' : 'denied',
            'oauth_authority_inherited' => false,
            'governed_write_convergence_inherited' => false,
            'developer_breakglass_inherited' => false,
        );
    }

    public static function can_local_admin() {
        return self::bound()
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy', false )
            && MAD4B_SCP_Policy::can_connect_user( get_current_user_id() );
    }

    public static function can_local_write() {
        if ( ! self::can_local_admin() ) return false;
        $gate = MAD4B_SCP_Policy::mutation_gate_status();
        return is_array( $gate ) && ! empty( $gate['effective'] );
    }
}
