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

    public static function dependency_status() {
        // Detection is informational. A loaded provider is not automatically certified.
        $wp_site = function_exists( 'get_current_blog_id' ) && function_exists( 'get_current_network_id' );
        $abilities = function_exists( 'wp_register_ability' ) && function_exists( 'wp_has_ability' );
        $policy = class_exists( 'MAD4B_SCP_Policy', false ) && is_callable( array( 'MAD4B_SCP_Policy', 'can_read' ) );
        $adapter_observed = class_exists( 'WP\\MCP\\Core\\McpAdapter', false );
        $adapter_version = $adapter_observed && defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' )
            ? (string) constant( 'WP\\MCP\\Core\\McpAdapter::VERSION' ) : '';
        $release_target = class_exists( 'MAD4B_SCP_Runtime_Release_Set', false ) &&
            is_callable( array( 'MAD4B_SCP_Runtime_Release_Set', 'target_adapter_version' ) )
            ? (string) MAD4B_SCP_Runtime_Release_Set::target_adapter_version() : '';

        return array(
            'contract' => 'mad4b.wordpress-dedicated-dependency-readiness.v1',
            'identity_services' => array(
                'site_profile_loaded' => class_exists( 'MAD4B_SCP_Site_Profile', false ),
                'brand_context_loaded' => class_exists( 'MAD4B_SCP_Context_Authority', false ),
                'wordpress_site_context_available' => $wp_site,
            ),
            'mcp_discovery' => array(
                'abilities_api_available' => $abilities,
                'read_policy_available' => $policy,
                'adapter_class_observed' => $adapter_observed,
                'adapter_runtime_version' => $adapter_version,
                'policy_target_adapter_version' => $release_target,
                'pair_certification_required' => true,
                'pair_certification_verified' => false,
                'status' => ! $abilities || ! $policy ? 'BLOCKED'
                    : ( $adapter_observed ? 'POTENTIALLY_AVAILABLE' : 'TRANSPORT_NOT_OBSERVED' ),
                'transport_and_registration_independently_certified' => false,
            ),
            'optional_provider_observation' => array(
                'google_drive' => class_exists( 'MAD4B_SCP_Google_Drive_Context', false ) ? 'DETECTED_UNVERIFIED' : 'NOT_DETECTED',
                'woocommerce' => class_exists( 'WooCommerce', false ) ? 'DETECTED_UNVERIFIED' : 'NOT_DETECTED',
                'elementor' => class_exists( 'Elementor\\Plugin', false ) ? 'DETECTED_UNVERIFIED' : 'NOT_DETECTED',
                'wpml' => defined( 'ICL_SITEPRESS_VERSION' ) ? 'DETECTED_UNVERIFIED' : 'NOT_DETECTED',
                'rank_math' => defined( 'RANK_MATH_VERSION' ) ? 'DETECTED_UNVERIFIED' : 'NOT_DETECTED',
            ),
            'provider_detection_grants_authority' => false,
        );
    }

    public static function supported_modes() {
        return array( 'shared_multi_tenant', 'dedicated_isolated', 'dedicated_autonomous', 'wordpress_dedicated' );
    }

    private static $registered_ability = null;
    private static $registration_collision = false;

    /**
     * Registration ownership is a local mount guard, not MCP discovery evidence.
     * Only our returned WP_Ability instance may be projected to a custom server.
     */
    public static function mcp_registration_status() {
        if ( self::$registration_collision ) return array( 'ready' => false, 'code' => 'ABILITY_REGISTRATION_COLLISION', 'mounted' => false, 'authorizing' => false );
        if ( ! function_exists( 'wp_get_ability' ) || ! is_object( self::$registered_ability ) ) {
            return array( 'ready' => false, 'code' => 'REGISTERED_NOT_MOUNTED', 'mounted' => false, 'authorizing' => false );
        }
        $ability = wp_get_ability( self::ABILITY );
        if ( ! is_object( $ability ) || $ability !== self::$registered_ability ||
            ! method_exists( $ability, 'get_category' ) || 'mad4b-read' !== $ability->get_category() ||
            ! method_exists( $ability, 'get_meta' ) ) {
            return array( 'ready' => false, 'code' => 'ABILITY_REGISTRATION_COLLISION', 'mounted' => false, 'authorizing' => false );
        }
        $meta = $ability->get_meta();
        if ( ! is_array( $meta ) || ! isset( $meta['mcp'] ) || ! is_array( $meta['mcp'] ) ||
            ! array_key_exists( 'public', $meta['mcp'] ) || false !== $meta['mcp']['public'] ) {
            return array( 'ready' => false, 'code' => 'ABILITY_METADATA_DRIFT', 'mounted' => false, 'authorizing' => false );
        }
        return array( 'ready' => true, 'code' => 'ELIGIBLE_FOR_EXPLICIT_CUSTOM_SERVER', 'mounted' => false, 'authorizing' => false );
    }

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 9 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) {
            // Repeated own registration is harmless. Any other object, including
            // a callback replaced during the request, must fail closed.
            $existing = function_exists( 'wp_get_ability' ) ? wp_get_ability( self::ABILITY ) : null;
            if ( ! is_object( self::$registered_ability ) || $existing !== self::$registered_ability ) self::$registration_collision = true;
            return;
        }
        self::$registered_ability = wp_register_ability( self::ABILITY, array(
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

    /**
     * Non-authorizing operator guidance. A suggested recovery is never an approval
     * ticket, never changes WP/Host configuration, and never certifies a provider.
     */
    private static function recovery_for( $reason ) {
        $actions = array(
            'SITE_PROFILE_UNAVAILABLE' => array( 'inspect_installation', 'Verify the Site Profile component is loaded.' ),
            'SITE_NOT_ENROLLED' => array( 'enroll_site_profile', 'Enroll the current site with its true canonical origin and environment.' ),
            'SITE_IDENTITY_NOT_READY' => array( 'repair_site_identity', 'Compare host environment and origin to Site Profile; repair the host or enrollment through authorized setup.' ),
            'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED' => array( 'enroll_deployment_binding', 'Complete the existing deployment binding in an authorized setup flow; do not infer authority from the site name.' ),
            'SITE_PROFILE_REVISION_MISSING' => array( 'repair_site_revision', 'Re-enroll or reconcile the Site Profile revision without rewriting its identity.' ),
            'SITE_UUID_INVALID' => array( 'repair_site_uuid', 'Inspect the enrolled Site Profile identity; do not mint a replacement ID on read.' ),
            'BRAND_CONTEXT_UNAVAILABLE' => array( 'inspect_brand_service', 'Verify Context Authority is loaded and initialized.' ),
            'BRAND_PROFILE_UNRESOLVED' => array( 'configure_brand_profile', 'Review the current site brand and its source ownership before configuring it.' ),
            'BRAND_PROFILE_REVISION_MISSING' => array( 'review_brand_revision', 'Review and persist the approved brand revision; do not transfer old sources automatically.' ),
            'SITE_BLOG_LOCAL_BINDING_MISMATCH' => array( 'inspect_multisite_binding', 'Verify current blog, canonical origin, and persisted Site Profile; rebind only after operator review.' ),
            'WORDPRESS_SITE_CONTEXT_UNAVAILABLE' => array( 'inspect_wordpress_site', 'Check WordPress blog and network context.' ),
            'WORDPRESS_SITE_CONTEXT_INVALID' => array( 'inspect_wordpress_network', 'Verify current WordPress blog/network IDs before retrying.' ),
            'WORDPRESS_ENVIRONMENT_UNSUPPORTED' => array( 'repair_environment_taxonomy', 'Use a supported WordPress environment and preserve its distinct acceptance gates.' ),
            'REQUEST_SCOPE_INVALID' => array( 'correct_scope_input', 'Send an object containing only supported scope assertions.' ),
            'REQUEST_SCOPE_MISMATCH' => array( 'remove_untrusted_scope', 'Use the server-resolved scope; do not override tenant, site, mode, or brand from the conversation.' ),
        );
        $known = isset( $actions[ $reason ] ) ? $actions[ $reason ] : array( 'inspect_scope', 'Inspect the deployment scope and underlying enrollment without widening access.' );
        return array(
            'code' => $known[0],
            'instruction' => $known[1],
            'automatic_mutation_allowed' => false,
            'requires_independent_readback' => true,
        );
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
            'operator_state' => 'blocked',
            'mcp_registration' => self::mcp_registration_status(),
            'next_safe_action' => self::recovery_for( $reason ),
            'portable_state' => 'BLOCKED',
            'reason' => $reason,
            'missing_dependencies' => self::dependency_codes( $reason ),
            'dependency_status' => self::dependency_status(),
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
            'BRAND_PROFILE_REVISION_MISSING', 'WORDPRESS_SITE_CONTEXT_INVALID',
            'SITE_PROFILE_REVISION_MISSING', 'SITE_BLOG_LOCAL_BINDING_MISMATCH',
            'WORDPRESS_SITE_CONTEXT_UNAVAILABLE',
            'WORDPRESS_ENVIRONMENT_UNSUPPORTED'
        );
        return in_array( $reason, $known, true ) ? array( $reason ) : array();
    }

    public static function status() {
        return self::resolve();
    }

    public static function resolve( $untrusted_request_scope = array() ) {
        if ( ! is_array( $untrusted_request_scope ) ) return self::blocked( 'REQUEST_SCOPE_INVALID' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return self::blocked( 'SITE_PROFILE_UNAVAILABLE' );
        // Defense-in-depth: the resolver must never create a Site Profile.
        if ( ! function_exists( 'get_option' ) || ! is_array( get_option( MAD4B_SCP_Site_Profile::OPTION, null ) ) ) return self::blocked( 'SITE_NOT_ENROLLED' );
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
        if ( empty( $site['revision'] ) || (int) $site['revision'] < 1 ) {
            return self::blocked( 'SITE_PROFILE_REVISION_MISSING' );
        }
        $environment = isset( $site['environment'] ) ? (string) $site['environment'] : '';
        if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) {
            return self::blocked( 'WORDPRESS_ENVIRONMENT_UNSUPPORTED' );
        }
        $uuid = strtolower( trim( isset( $site['site_uuid'] ) ? (string) $site['site_uuid'] : '' ) );
        if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) ) {
            return self::blocked( 'SITE_UUID_INVALID' );
        }
        // WordPress can switch blogs within one PHP request while Site Profile
        // retains static per-request caches. Cross-check the current blog's own
        // enrolled option, not only a possibly stale cached Site Profile status.
        if ( ! function_exists( 'get_option' ) ||
             ! method_exists( 'MAD4B_SCP_Site_Profile', 'current_origin' ) ) {
            return self::blocked( 'SITE_BLOG_LOCAL_BINDING_MISMATCH' );
        }
        $blog_record = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
        $current_origin = MAD4B_SCP_Site_Profile::current_origin();
        if ( ! is_array( $blog_record ) ||
             empty( $blog_record['site_uuid'] ) ||
             ! hash_equals( $uuid, strtolower( trim( (string) $blog_record['site_uuid'] ) ) ) ||
             ! isset( $blog_record['revision'] ) ||
             (int) $blog_record['revision'] !== (int) $site['revision'] ||
             empty( $blog_record['canonical_origin'] ) ||
             '' === $current_origin ||
             ! hash_equals( (string) $blog_record['canonical_origin'], $current_origin ) ) {
            return self::blocked( 'SITE_BLOG_LOCAL_BINDING_MISMATCH' );
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
        if ( ! function_exists( 'get_current_blog_id' ) ||
             ! function_exists( 'get_current_network_id' ) ) return self::blocked( 'WORDPRESS_SITE_CONTEXT_UNAVAILABLE' );
        $blog_id = (int) get_current_blog_id();
        $network_id = (int) get_current_network_id();
        if ( $blog_id < 1 || $network_id < 1 ) return self::blocked( 'WORDPRESS_SITE_CONTEXT_INVALID' );
        $scope = array(
            'tenant_ref' => 'wp-site:' . $uuid,
            'brand_ref' => $brand_id,
            'site_uuid' => $uuid,
            'blog_id' => $blog_id,
            'network_id' => $network_id,
            'environment' => $environment,
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
            'operator_state' => 'partial',
            'mcp_registration' => self::mcp_registration_status(),
            'next_safe_action' => array( 'code' => 'certify_runtime_pair', 'instruction' => 'Verify MCP discovery, effective authorization, execution, and independent Staging readback before declaring operational readiness.', 'automatic_mutation_allowed' => false, 'requires_independent_readback' => true ),
            'portable_state' => 'BOUND_FOR_REVIEW_ONLY',
            'scope' => $scope,
            'dependency_revision' => array(
                'site_profile' => isset( $site['revision'] ) ? (int) $site['revision'] : 0,
                'brand_profile' => (int) $brand['revision'],
            ),
            'missing_dependencies' => array(),
            'dependency_status' => self::dependency_status(),
            'host_binding_requires_independent_acceptance' => true,
            'brand_resolution' => 'current_site_brand_profile',
            'review_only' => true,
            'execution_authorized' => false,
            'publication_authorized' => false,
            'production_authorized' => false,
        );
    }
}
