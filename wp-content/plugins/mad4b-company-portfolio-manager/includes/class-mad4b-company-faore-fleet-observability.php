<?php
/** Read-only FAORE fleet overview; company-owned data and authority stay local. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_Company_FAORE_Fleet_Observability {
    const CONTRACT = 'mad4b.faore.fleet-observation.v1';

    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ), 75 );
    }
    public static function permission() {
        return MAD4B_Company_Portfolio_Manager_V1::admin_permission();
    }
    public static function routes() {
        register_rest_route( 'mad4b/v1', '/company-manager/faore/fleet', array(
            'methods' => 'GET',
            'permission_callback' => array( __CLASS__, 'permission' ),
            'callback' => array( __CLASS__, 'respond' ),
        ) );
    }
    public static function observe() {
        $registry = get_option( MAD4B_Company_Portfolio_Manager_V1::REGISTRY, array() );
        if ( ! is_array( $registry ) ) {
            return new WP_Error( 'mad4b_faore_registry_invalid',
                'Company registry is unavailable.', array( 'status' => 409 ) );
        }
        $rows = array();
        $scanned = 0;
        foreach ( $registry as $code => $entry ) {
            if ( ++$scanned > 250 ) {
                return new WP_Error( 'mad4b_faore_registry_overflow',
                    'Fleet inventory size requires pagination.', array( 'status' => 409 ) );
            }
            if ( ! is_string( $code ) || ! preg_match( '/^[a-z][a-z0-9-]{2,47}$/D', $code ) ) {
                continue;
            }
            $binding = MAD4B_Company_Portfolio_Manager_V1::company_binding( $code );
            $ok = is_array( $binding ) && ! is_wp_error( $binding );
            $rows[] = array(
                'company_id' => $code,
                'binding_state' => $ok ? 'VERIFIED_REGISTRY_BINDING' : 'UNVERIFIED',
                'environment' => $ok ? (string) ( $binding['environment'] ?? 'unknown' ) : 'unknown',
                'site_uuid' => $ok ? (string) ( $binding['company_site_uuid'] ?? '' ) : '',
                'local_control_plane_health' => 'UNOBSERVED',
                'capabilities' => 'NOT_DISCOVERED',
                'operation_admission' => 'NOT_REQUESTED',
                'native_execution' => 'NOT_EXECUTED',
                'evidence_state' => $ok ? 'Observed' : 'Unverified',
            );
        }
        usort( $rows, static function ( $a, $b ) {
            return strcmp( $a['company_id'], $b['company_id'] );
        } );
        return array(
            'contract' => self::CONTRACT,
            'companies' => $rows,
            'company_count' => count( $rows ),
            'read_only' => true,
            'execution_authorized' => false,
            'dispatched_operations' => 0,
            'contains_company_owned_data' => false,
        );
    }
    public static function respond() {
        $report = self::observe();
        if ( is_wp_error( $report ) ) return $report;
        $response = rest_ensure_response( $report );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }
}
