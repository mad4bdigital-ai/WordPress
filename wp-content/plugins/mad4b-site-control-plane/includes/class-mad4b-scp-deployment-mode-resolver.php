<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only deployment-mode view for this WordPress installation.
 *
 * The common Context Authority supports all registered deployment modes.
 * This WordPress adapter resolves only wordpress_dedicated and never accepts
 * mode, tenant, site or brand identity from a conversation as authority.
 */
final class MAD4B_SCP_Deployment_Mode_Resolver {
    const CONTRACT = 'mad4b.deployment-mode-resolution.v1';
    const COMMON_CONTRACT = 'mad4b.context-deployment-mode.v1';
    const ADAPTER_VERSION = '1.1.0';
    const MODE = 'wordpress_dedicated';
    const ABILITY = 'mad4b/deployment-mode-status';

    public static function supported_modes() {
        return array( 'shared_multi_tenant', 'dedicated_isolated', 'dedicated_autonomous', 'wordpress_dedicated' );
    }

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 9 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'MAD4B Deployment Mode Status',
            'description' => 'Read-only, site-bound deployment mode and scoped identity resolution.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => class_exists( 'MAD4B_SCP_Policy' )
                ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false,
                'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    private static function blocked( $reason ) {
        return array(
            'contract' => self::CONTRACT,
            'common_contract' => self::COMMON_CONTRACT,
            'adapter_version' => self::ADAPTER_VERSION,
            'supported_modes' => self::supported_modes(),
            'candidate_mode' => self::MODE,
            'active_mode' => 'unresolved',
            'status' => 'BLOCKED',
            'portable_state' => 'BLOCKED',
            'reason' => $reason,
            'missing_dependencies' => self::dependency_codes( $reason ),
            'scope' => null,
            'review_only' => true,
            'host_binding_requires_independent_acceptance' => true,
            'execution_authorized' => false,
            'publication_authorized' => false,
            'production_authorized' => false,
        );
    }

    private static function dependency_codes( $reason ) {
        $known = array(
            'SITE_PROFILE_UNAVAILABLE', 'SITE_NOT_ENROLLED', 'SITE_IDENTITY_NOT_READY',
            'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED', 'SITE_UUID_INVALID',
            'BRAND_CONTEXT_UNAVAILABLE', 'BRAND_PROFILE_UNRESOLVED',
            'BRAND_PROFILE_REVISION_MISSING', 'WORDPRESS_SITE_CONTEXT_INVALID'
        );
        return in_array( $reason, $known, true ) ? array( $reason ) : array();
    }

    public static function status() {
        return self::resolve();
    }

    public static function resolve( $untrusted_request_scope = array() ) {
        if ( ! is_array( $untrusted_request_scope ) ) return self::blocked( 'REQUEST_SCOPE_INVALID' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return self::blocked( 'SITE_PROFILE_UNAVAILABLE' );
        $site = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $site ) || empty( $site['configured'] ) ) return self::blocked( 'SITE_NOT_ENROLLED' );
        foreach ( array( 'origin_match', 'environment_match', 'authority_ready' ) as $check ) {
            if ( empty( $site[ $check ] ) ) return self::blocked( 'SITE_IDENTITY_NOT_READY' );
        }
        // A legacy, unbound profile may report deployment_binding_match=true.
        // Dedicated identity must require an existing bound and matching enrollment.
        if ( empty( $site['deployment_binding_bound'] ) ||
             empty( $site['deployment_binding_configured'] ) ) {
            return self::blocked( 'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED' );
        }
        if ( empty( $site['deployment_binding_match'] ) ) {
            return self::blocked( 'SITE_IDENTITY_NOT_READY' );
        }
        $uuid = strtolower( trim( isset( $site['site_uuid'] ) ? (string) $site['site_uuid'] : '' ) );
        if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) ) {
            return self::blocked( 'SITE_UUID_INVALID' );
        }
        if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return self::blocked( 'BRAND_CONTEXT_UNAVAILABLE' );
        $brand = MAD4B_SCP_Context_Authority::profile();
        $brand_id = is_array( $brand ) && isset( $brand['brand_id'] ) ? strtolower( trim( (string) $brand['brand_id'] ) ) : '';
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $brand_id ) ||
             empty( $brand['site_uuid'] ) ||
             ! hash_equals( $uuid, strtolower( trim( (string) $brand['site_uuid'] ) ) ) ) {
            return self::blocked( 'BRAND_PROFILE_UNRESOLVED' );
        }
        if ( empty( $brand['revision'] ) || ! is_numeric( $brand['revision'] ) ||
             (int) $brand['revision'] < 1 ) return self::blocked( 'BRAND_PROFILE_REVISION_MISSING' );
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
        $network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
        if ( $blog_id < 1 || $network_id < 1 ) return self::blocked( 'WORDPRESS_SITE_CONTEXT_INVALID' );
        $scope = array(
            'tenant_ref' => 'wp-site:' . $uuid,
            'brand_ref' => $brand_id,
            'site_uuid' => $uuid,
            'blog_id' => $blog_id,
            'network_id' => $network_id,
            'environment' => isset( $site['environment'] ) ? (string) $site['environment'] : '',
            'deployment_mode' => self::MODE,
        );
        foreach ( $untrusted_request_scope as $key => $value ) {
            if ( ! is_string( $key ) || ! array_key_exists( $key, $scope ) ||
                ! ( is_string( $value ) || is_int( $value ) ) ||
                (string) $scope[ $key ] !== (string) $value ) {
                return self::blocked( 'REQUEST_SCOPE_MISMATCH' );
            }
        }
        return array(
            'contract' => self::CONTRACT,
            'common_contract' => self::COMMON_CONTRACT,
            'adapter_version' => self::ADAPTER_VERSION,
            'supported_modes' => self::supported_modes(),
            'active_mode' => self::MODE,
            'status' => 'RESOLVED_FOR_REVIEW_ONLY',
            'portable_state' => 'BOUND_FOR_REVIEW_ONLY',
            'scope' => $scope,
            'dependency_revision' => array(
                'site_profile' => isset( $site['revision'] ) ? (int) $site['revision'] : 0,
                'brand_profile' => (int) $brand['revision'],
            ),
            'missing_dependencies' => array(),
            'brand_resolution' => 'current_site_brand_profile',
            'review_only' => true,
            'execution_authorized' => false,
            'publication_authorized' => false,
            'production_authorized' => false,
        );
    }
}
