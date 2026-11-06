<?php
/**
 * FAORE broker evidence bridge: inspect original 305/306 without issuing a
 * grant, attempting 312, running an executor or fabricating 313.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_Company_FAORE_Broker_Readback {
    const CONTRACT = 'mad4b.faore.original-broker-readback.v1';

    public static function boot(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ), 75 );
    }

    public static function permission() {
        return MAD4B_Company_Portfolio_Manager_V1::admin_permission();
    }

    public static function routes(): void {
        register_rest_route( 'mad4b/v1',
            '/company-manager/faore/broker/(?P<company>[a-z][a-z0-9-]{2,47})/(?P<job_id>[a-f0-9]{32})',
            array( 'methods' => 'GET',
                'permission_callback' => array( __CLASS__, 'permission' ),
                'callback' => array( __CLASS__, 'respond' ) ) );
    }

    private static function error( string $code, int $status = 409 ): WP_Error {
        return new WP_Error( 'mad4b_faore_' . $code,
            'Original Broker: ' . $code,
            array( 'status' => $status, 'contract' => self::CONTRACT ) );
    }

    public static function inspect( string $company, string $job_id ) {
        if ( ! preg_match( '/^[a-z][a-z0-9-]{2,47}$/D', $company )
            || ! preg_match( '/^[a-f0-9]{32}$/D', $job_id ) )
            return self::error( 'identity_invalid', 422 );

        if ( ! class_exists( 'MAD4B_Central_SSH_Command_Intake_V1', false )
            || ! class_exists( 'MAD4B_Central_VPS_Broker_V1', false )
            || ! class_exists( 'MAD4B_Manager_Auto_Write_V1', false ) )
            return self::error( 'original_broker_unavailable', 503 );

        $binding = MAD4B_Company_Portfolio_Manager_V1::company_binding( $company );
        if ( is_wp_error( $binding ) || ! is_array( $binding ) )
            return self::error( 'company_binding_unverified' );

        $target = MAD4B_Manager_Auto_Write_V1::company_target( $company );
        if ( ! is_array( $target ) || ( $target['state'] ?? '' ) !== 'REGISTERED'
            || ( $target['site_uuid'] ?? '' ) !== ( $binding['company_site_uuid'] ?? '' )
            || ! in_array( ( $target['environment'] ?? '' ),
                array( 'staging', 'production' ), true )
            || ! is_string( $target['canonical_origin'] ?? null ) )
            return self::error( 'original_company_target_unverified' );

        $request = get_option( MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX . $job_id, false );
        if ( ! is_array( $request ) || ( $request['request_id'] ?? '' ) !== $job_id )
            return self::error( 'original_305_not_found', 404 );

        foreach ( array( 'company', 'environment', 'site_uuid', 'canonical_origin' ) as $field ) {
            $expected = 'company' === $field ? $company : (string) $target[ $field ];
            if ( ! isset( $request[ $field ] )
                || ! is_string( $request[ $field ] )
                || ! hash_equals( $expected, $request[ $field ] ) )
                return self::error( 'original_request_scope_mismatch' );
        }

        $admitted = ( $request['status'] ?? '' ) === 'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            && ( $request['execution_performed'] ?? true ) === false
            && (int) ( $request['expires_epoch'] ?? 0 ) > time();
        $job = get_option( MAD4B_Central_VPS_Broker_V1::JOB . $job_id, false );
        $claim = get_option( MAD4B_Central_VPS_Broker_V1::CLAIM . $job_id, false );
        $claimed = $admitted && is_array( $job ) && is_array( $claim )
            && ( $job['job_id'] ?? '' ) === $job_id
            && ( $claim['job_id'] ?? '' ) === $job_id
            && ( $job['company'] ?? '' ) === $company
            && ( $job['environment'] ?? '' ) === $target['environment']
            && ( $job['site_uuid'] ?? '' ) === $target['site_uuid']
            && ( $job['canonical_origin'] ?? '' ) === $target['canonical_origin']
            && ( $job['state'] ?? '' ) === 'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            && ( $claim['state'] ?? '' ) === 'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            && ( $job['executed'] ?? true ) === false
            && (int) ( $job['expires_epoch'] ?? 0 ) > time();

        return array(
            'contract' => self::CONTRACT,
            'company_id' => $company,
            'environment' => $target['environment'],
            'site_uuid' => $target['site_uuid'],
            'original_305_job_id' => $job_id,
            'stage_305' => $admitted ? 'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
                : 'UNVERIFIED_OR_EXPIRED',
            'stage_306' => $claimed ? 'BROKER_CLAIM_OBSERVED_NOT_EXECUTED'
                : 'NOT_VERIFIED',
            'stage_312' => 'NOT_EVALUATED_BY_READBACK',
            'stage_313' => 'PENDING_INDEPENDENT_NATIVE_READBACK',
            'evidence_state' => 'Observed',
            'execution_verified' => false,
            'write_authorized' => false,
            'mutation_performed' => false,
            'read_only' => true,
        );
    }

    public static function respond( $request ) {
        $output = self::inspect( (string) $request['company'], (string) $request['job_id'] );
        if ( is_wp_error( $output ) ) return $output;
        $response = rest_ensure_response( $output );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }
}
