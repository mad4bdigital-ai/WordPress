<?php
/**
 * FAORE local capability observation v1.
 * No dispatch, writes, credential handling or implied admission.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_FCP_Local_Observability {
    const CONTRACT = 'mad4b.faore.local-observation.v1';

    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ), 35 );
    }

    public static function permitted() {
        return current_user_can( 'manage_options' ) || current_user_can( 'mad4b_manage_company_operations' );
    }

    public static function routes() {
        register_rest_route( 'mad4b/v1', '/fcp/local-capabilities', array(
            'methods' => 'GET',
            'permission_callback' => array( __CLASS__, 'permitted' ),
            'callback' => array( __CLASS__, 'respond' ),
        ) );
    }

    public static function observe() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) {
            return new WP_Error( 'mad4b_fcp_site_identity_unverified',
                'An enrolled, matching site identity is required.', array( 'status' => 409 ) );
        }
        $uuid = MAD4B_SCP_Site_Profile::site_uuid();
        $env = MAD4B_SCP_Site_Profile::current_environment();
        $origin = MAD4B_SCP_Site_Profile::site_origin();
        if ( ! is_string( $uuid ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $uuid )
            || ! in_array( $env, array( 'staging', 'production', 'development' ), true )
            || ! is_string( $origin ) || 0 !== strpos( $origin, 'https://' ) ) {
            return new WP_Error( 'mad4b_fcp_site_binding_invalid',
                'Local site binding is invalid.', array( 'status' => 409 ) );
        }

        $capabilities = array( array(
            'name' => 'site.identity.read',
            'version' => '1.0',
            'health' => 'healthy',
            'provider_id' => 'mad4b-site-control-plane',
            'supported_operations' => array( 'read' ),
        ) );
        $observed = array();
        if ( class_exists( 'MAD4B_SCP_Site_Capability_Discovery', false ) ) {
            $status = MAD4B_SCP_Site_Capability_Discovery::observe( $origin );
            $observed = array(
                'inventory_state' => (string) ( $status['read_inventory_state'] ?? 'unavailable' ),
                'snapshot_digest' => (string) ( $status['snapshot_sha256'] ?? '' ),
                'discovery_complete' => ! empty( $status['discovery_complete'] ),
            );
        }
        // WPML presence is an observation only. The local skill resolver must
        // explicitly admit an audit before it is advertised as executable.
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $capabilities[] = array(
                'name' => 'wpml.translation.audit',
                'version' => (string) ICL_SITEPRESS_VERSION,
                'health' => 'degraded',
                'provider_id' => 'wpml-observed',
                'supported_operations' => array(),
            );
        }
        return array(
            'contract' => self::CONTRACT,
            'site_uuid' => $uuid,
            'environment' => $env,
            'canonical_origin' => $origin,
            'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
            'manifest_version' => '1.0',
            'capabilities' => $capabilities,
            'observed' => $observed,
            'read_only' => true,
            'authorizing' => false,
            'native_write_authorized' => false,
            'company_id_resolution' => 'central_registry_exact_site_binding_required',
        );
    }

    public static function respond() {
        $observation = self::observe();
        if ( is_wp_error( $observation ) ) return $observation;
        $response = rest_ensure_response( $observation );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }
}
