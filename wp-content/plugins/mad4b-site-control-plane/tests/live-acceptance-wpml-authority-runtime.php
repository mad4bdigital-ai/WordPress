<?php

define( 'ABSPATH', __DIR__ . '/' );
function add_filter() {}

$GLOBALS['mad4b_wpml_response_receipt'] = array(
	'contract' => 'mad4b.external-wpml-response-contract.v1',
	'verified' => true,
	'stale' => false,
	'state' => 'verified_external_wpml',
	'classification' => 'success',
	'status' => 'valid',
	'get_parameters' => 'valid',
	'observed_at' => '2026-09-12T00:00:00+00:00',
);
$GLOBALS['mad4b_wpml_legacy_receipt'] = array(
	'contract' => 'mad4b.external-wpml-receipt.v1',
	'verified' => false,
	'stale' => false,
	'state' => 'external_wpml_failed',
);
$GLOBALS['mad4b_wpml_diagnostic'] = array(
	'contract' => 'mad4b.external-wpml-diagnostic.v2',
	'verified' => false,
	'stale' => false,
	'classification' => 'route_not_registered',
	'state' => 'route_not_registered',
	'route_registered' => false,
);

class MAD4B_SCP_WPML_Response_Contract {
	const CONTRACT = 'mad4b.external-wpml-response-contract.v1';
	public static function receipt_status() { return $GLOBALS['mad4b_wpml_response_receipt']; }
}

class MAD4B_SCP_Live_Acceptance_Observer {
	public static function external_wpml_receipt_status() { return $GLOBALS['mad4b_wpml_legacy_receipt']; }
}

class MAD4B_SCP_Live_Acceptance_Finalizer {
	const WPML_DIAGNOSTIC_CONTRACT = 'mad4b.external-wpml-diagnostic.v2';
	public static function external_wpml_receipt_status() { return $GLOBALS['mad4b_wpml_diagnostic']; }
	public static function aggregate_ready( array $gates ) {
		if ( empty( $gates ) ) return false;
		foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false;
		return true;
	}
}

class MAD4B_SCP_External_Snapshot_Finalizer {
	public static function live_acceptance_status( $input = array() ) {
		return array(
			'contract' => 'mad4b.live-acceptance-status.v1',
			'gates' => array(
				'environment_guard' => array( 'ready' => true ),
				'external_wpml' => array( 'ready' => false, 'state' => 'route_not_registered' ),
			),
			'ready' => false,
			'state' => 'pending_or_blocked',
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php';

function mad4b_wpml_authority_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$trusted = $GLOBALS['mad4b_wpml_response_receipt'];
$diagnostic = $GLOBALS['mad4b_wpml_diagnostic'];

$verified = MAD4B_SCP_External_WPML_Acceptance_Finalizer::finalize_status( $trusted, $diagnostic );
mad4b_wpml_authority_assert( ! empty( $verified['verified'] ), 'Trusted external receipt must remain verified.' );
mad4b_wpml_authority_assert( 'verified_external_wpml' === $verified['state'], 'Trusted external receipt must retain authoritative state.' );
mad4b_wpml_authority_assert( 'success' === $verified['classification'], 'Internal route diagnostics must not downgrade external success.' );
mad4b_wpml_authority_assert( false === $verified['route_registered'], 'Internal route probe must remain visible as diagnostics.' );
mad4b_wpml_authority_assert( 'diagnostic_only' === $verified['internal_probe_role'], 'Internal probe role must be explicit.' );

// Full wiring regression: the normalized response contract is authoritative even
// while the legacy observer is unverified and the internal route probe is false.
$wired = MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status();
mad4b_wpml_authority_assert( ! empty( $wired['verified'] ), 'Normalized WPML response contract must drive final authority.' );
mad4b_wpml_authority_assert( MAD4B_SCP_WPML_Response_Contract::CONTRACT === $wired['contract'], 'Final authority must retain the normalized response contract.' );
mad4b_wpml_authority_assert( 'verified_external_wpml' === $wired['state'], 'Full wiring must preserve verified external WPML state.' );
mad4b_wpml_authority_assert( false === $wired['route_registered'], 'Diagnostic route visibility must remain without becoming authority.' );

$aggregate = MAD4B_SCP_External_WPML_Acceptance_Finalizer::live_acceptance_status();
mad4b_wpml_authority_assert( ! empty( $aggregate['gates']['external_wpml']['ready'] ), 'Aggregate WPML gate must become ready from normalized external evidence.' );
mad4b_wpml_authority_assert( MAD4B_SCP_WPML_Response_Contract::CONTRACT === $aggregate['gates']['external_wpml']['source_contract'], 'Aggregate source contract must identify the normalized external receipt.' );
mad4b_wpml_authority_assert( ! empty( $aggregate['ready'] ), 'All-ready aggregate must remain reachable when WPML external evidence is valid.' );

// Fail closed when the canonical response contract is present but not verified.
// A stale/legacy observer receipt must never bypass the canonical contract.
$GLOBALS['mad4b_wpml_response_receipt']['verified'] = false;
$GLOBALS['mad4b_wpml_response_receipt']['state'] = 'stale_evidence';
$GLOBALS['mad4b_wpml_legacy_receipt']['verified'] = true;
$GLOBALS['mad4b_wpml_legacy_receipt']['state'] = 'verified_external_wpml';
$blocked = MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status();
mad4b_wpml_authority_assert( empty( $blocked['verified'] ), 'Legacy observer must not bypass an unverified canonical response contract.' );
mad4b_wpml_authority_assert( 'route_not_registered' === $blocked['classification'], 'Diagnostic route failure must remain visible without trusted canonical external evidence.' );

fwrite( STDOUT, "mad4b.live-acceptance-wpml-authority.runtime.v2: PASS\n" );
