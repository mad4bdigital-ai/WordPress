<?php

define( 'ABSPATH', __DIR__ . '/' );
function add_filter() { return true; }
function gmdate_compat() { return gmdate( 'c' ); }

$GLOBALS['mad4b_wpml_external'] = array(
    'contract' => 'mad4b.external-wpml-response-contract.v1',
    'verified' => true,
    'stale' => false,
    'state' => 'verified_external_wpml',
    'classification' => 'success',
    'status' => 'valid',
    'get_parameters' => 'valid',
    'observed_at' => '2026-09-12T14:39:44+00:00',
);
$GLOBALS['mad4b_wpml_diag'] = array(
    'contract' => 'mad4b.external-wpml-diagnostic.v2',
    'verified' => false,
    'stale' => false,
    'classification' => 'route_not_registered',
    'state' => 'route_not_registered',
    'route_registered' => false,
);

class MAD4B_SCP_WPML_Response_Contract {
    const CONTRACT = 'mad4b.external-wpml-response-contract.v1';
    public static function receipt_status() { return $GLOBALS['mad4b_wpml_external']; }
}
class MAD4B_SCP_Live_Acceptance_Finalizer {
    const WPML_DIAGNOSTIC_CONTRACT = 'mad4b.external-wpml-diagnostic.v2';
    public static function external_wpml_receipt_status() { return $GLOBALS['mad4b_wpml_diag']; }
    public static function aggregate_ready( array $gates ) {
        if ( empty( $gates ) ) return false;
        foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false;
        return true;
    }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php';

function mad4b_wpml_compose_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

// Simulate the rc.29 zero-touch callback already selected at priority 260.
$zero_touch = static function ( $input = array() ) {
    return array(
        'contract' => 'mad4b.live-acceptance-status.v1',
        'gates' => array(
            'environment_guard' => array( 'ready' => true ),
            'snapshot_external' => array(
                'ready' => true,
                'state' => 'ready',
                'fresh' => true,
                'evidence_contract' => 'mad4b.external-snapshot-dynamic-attestation.v1',
                'evidence_mode' => 'dynamic_authenticated_mcp_tools_list',
                'self_certified' => false,
                'blockers' => array(),
            ),
            'external_wpml' => array(
                'ready' => false,
                'state' => 'route_not_registered',
                'fresh' => true,
                'source_contract' => 'mad4b.external-wpml-diagnostic.v2',
                'blockers' => array( 'route_not_registered' ),
            ),
        ),
        'ready' => false,
        'state' => 'pending_or_blocked',
        'zero_touch_external_snapshot_contract' => 'mad4b.external-snapshot-dynamic-attestation.v1',
        'external_facts_self_certified' => false,
    );
};
$args = MAD4B_SCP_External_WPML_Acceptance_Finalizer::bind_callbacks(
    array( 'execute_callback' => $zero_touch ),
    'mad4b/live-acceptance-status'
);
mad4b_wpml_compose_assert( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ), 'WPML authority callback not bound.' );
$live = call_user_func( $args['execute_callback'], array() );
$wpml = $live['gates']['external_wpml'];
$snapshot = $live['gates']['snapshot_external'];
mad4b_wpml_compose_assert( ! empty( $wpml['ready'] ), 'Fresh authoritative external WPML receipt was downgraded.' );
mad4b_wpml_compose_assert( 'mad4b.external-wpml-response-contract.v1' === $wpml['source_contract'], 'WPML source contract is not authoritative external receipt.' );
mad4b_wpml_compose_assert( empty( $wpml['blockers'] ), 'Internal route diagnostic remained a blocker.' );
mad4b_wpml_compose_assert( ! empty( $snapshot['ready'] ), 'WPML wrapper discarded zero-touch Snapshot readiness.' );
mad4b_wpml_compose_assert( 'dynamic_authenticated_mcp_tools_list' === $snapshot['evidence_mode'], 'WPML wrapper changed zero-touch Snapshot evidence mode.' );
mad4b_wpml_compose_assert( isset( $live['zero_touch_external_snapshot_contract'] ), 'Upstream zero-touch metadata was lost.' );
mad4b_wpml_compose_assert( false === $live['external_facts_self_certified'], 'External facts became self-certified.' );

// Fail closed for stale canonical external evidence: it must not override the diagnostic blocker.
$GLOBALS['mad4b_wpml_external']['stale'] = true;
$stale = call_user_func( $args['execute_callback'], array() );
mad4b_wpml_compose_assert( empty( $stale['gates']['external_wpml']['ready'] ), 'Stale external WPML evidence bypassed fail-closed behavior.' );
mad4b_wpml_compose_assert( in_array( 'route_not_registered', $stale['gates']['external_wpml']['blockers'], true ), 'Stale evidence erased the diagnostic blocker.' );
$GLOBALS['mad4b_wpml_external']['stale'] = false;

// Fail closed for an invalid canonical response contract.
$GLOBALS['mad4b_wpml_external']['status'] = 'invalid';
$invalid = call_user_func( $args['execute_callback'], array() );
mad4b_wpml_compose_assert( empty( $invalid['gates']['external_wpml']['ready'] ), 'Invalid external WPML response bypassed fail-closed behavior.' );
$GLOBALS['mad4b_wpml_external']['status'] = 'valid';

echo "mad4b.live-acceptance-wpml-composition.runtime.v1: PASS\n";
