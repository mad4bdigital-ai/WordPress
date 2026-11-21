<?php
/**
 * FAORE read-only projection of the original WordPress Remote Work Queue.
 * This does not enqueue, claim, complete or extend any existing lease.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_FCP_Queue_Readback {
    const CONTRACT = 'mad4b.faore.remote-work-queue-readback.v1';
    const READ_OPERATIONS = array(
        'assistant_provider_catalog_snapshot',
        'assistant_dependency_readback',
        'assistant_configuration_diff',
    );

    public static function boot(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ), 36 );
    }

    public static function permitted(): bool {
        return current_user_can( 'manage_options' )
            || current_user_can( 'mad4b_manage_company_operations' );
    }

    public static function routes(): void {
        register_rest_route( 'mad4b/v1',
            '/fcp/local-queue/(?P<job_id>[a-f0-9-]{36})',
            array( 'methods' => 'GET',
                'permission_callback' => array( __CLASS__, 'permitted' ),
                'callback' => array( __CLASS__, 'respond' ) ) );
    }

    public static function inspect( string $job_id ) {
        if ( 1 !== preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $job_id ) )
            return new WP_Error( 'mad4b_fcp_queue_job_id_invalid',
                'Original Remote Work Queue job UUID is required.',
                array( 'status' => 422 ) );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
            return new WP_Error( 'mad4b_fcp_queue_site_identity_unverified',
                'Original Site Profile binding is required.',
                array( 'status' => 409 ) );
        if ( ! class_exists( 'MAD4B_SCP_Remote_Work_Queue', false )
            || ! class_exists( 'MAD4B_SCP_Assistant_Read_Work_Operations', false ) )
            return new WP_Error( 'mad4b_fcp_queue_original_provider_unavailable',
                'Original work queue and read operations are required.',
                array( 'status' => 503 ) );

        $job = MAD4B_SCP_Remote_Work_Queue::get_job( $job_id );
        if ( is_wp_error( $job ) ) return $job;
        if ( ! is_array( $job ) || ( $job['job_id'] ?? '' ) !== $job_id
            || ! in_array( $job['operation_id'] ?? '', self::READ_OPERATIONS, true ) )
            return new WP_Error( 'mad4b_fcp_queue_operation_not_allowed',
                'Only original bounded assistant read work can be observed.',
                array( 'status' => 403 ) );

        $identity_matches = is_array( $job['payload'] ?? null )
            && is_array( $job['expected_identity'] ?? null )
            && MAD4B_SCP_Assistant_Read_Work_Operations::validate_for_operation(
                $job['operation_id'], $job['payload'] )
            && MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches(
                $job['payload'], $job['expected_identity'] );
        $state = (string) ( $job['effective_status'] ?? $job['status'] ?? 'unavailable' );

        return array(
            'contract' => self::CONTRACT,
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'environment' => MAD4B_SCP_Site_Profile::current_environment(),
            'original_queue_job_id' => $job_id,
            'operation_id' => (string) $job['operation_id'],
            'queue_status' => $state,
            'runtime_binding_matches' => $identity_matches,
            'reconciliation_required' => ! empty( $job['reconciliation_required'] )
                || ! $identity_matches || 'reconciling' === $state,
            'blind_retry_allowed' => false,
            'execution_verified' => false,
            'evidence_state' => 'Unverified',
            'read_only' => true,
        );
    }

    public static function respond( $request ) {
        $result = self::inspect( (string) $request['job_id'] );
        if ( is_wp_error( $result ) ) return $result;
        $response = rest_ensure_response( $result );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }
}
