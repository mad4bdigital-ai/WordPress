<?php
/**
 * WP-CLI hermetic runtime smoke after exact MCP Adapter + Control Plane install.
 * No site mutation, write authority enrollment, or Production access.
 */
if ( ! class_exists( 'MAD4B_SCP_Read_Consistency' ) ) {
    throw new RuntimeException( 'Session-safe read service is absent' );
}
foreach ( array( 'identity', 'triage', 'full' ) as $scope ) {
    $result = MAD4B_SCP_Read_Consistency::session_safe_diagnostics(
        array( 'diagnostic_scope' => $scope, 'budget_ms' => 12000, 'retry_transient_reads' => false )
    );
    if ( is_wp_error( $result ) ) {
        throw new RuntimeException( 'Session-safe read returned a typed error for ' . $scope . ': ' . $result->get_error_code() );
    }
    if ( ! is_array( $result ) || 'mad4b.session-safe-diagnostics.v1' !== ( $result['contract'] ?? '' ) ) {
        throw new RuntimeException( 'Invalid session-safe contract: ' . $scope );
    }
    if ( empty( $result['generation_match'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['runtime_generation'] ?? '' ) ) ) {
        throw new RuntimeException( 'Read generation identity failed: ' . $scope );
    }
    if ( ! empty( $result['mutation_performed'] ) || ! empty( $result['production_mutation_performed'] ) ||
        ! empty( $result['valid_for_release_merge'] ) ) {
        throw new RuntimeException( 'Read mutated state or claimed release authority: ' . $scope );
    }
    $json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( false === $json || strlen( $json ) > MAD4B_SCP_Read_Consistency::MAX_SESSION_SAFE_REPORT_BYTES ) {
        throw new RuntimeException( 'Session-safe response bound exceeded: ' . $scope );
    }
    if ( isset( $result['sections'] ) && is_array( $result['sections'] ) ) {
        foreach ( $result['sections'] as $section ) {
            if ( ! is_array( $section ) || empty( $section['checks'] ) || ! is_array( $section['checks'] ) ) continue;
            if ( isset( $section['evidence_digest_scope'] ) &&
                'bounded_bundle_summary' !== $section['evidence_digest_scope'] ) {
                throw new RuntimeException( 'Unexpected evidence digest scope: ' . $scope );
            }
        }
    }
}
echo "mad4b.runtime-session-safe-diagnostics.v1: PASS\n";
