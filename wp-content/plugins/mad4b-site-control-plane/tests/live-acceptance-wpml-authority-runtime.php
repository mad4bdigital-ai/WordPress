<?php

define( 'ABSPATH', __DIR__ . '/' );
function add_filter() {}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php';

function mad4b_wpml_authority_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$trusted = array(
	'contract' => 'mad4b.external-wpml-receipt.v1',
	'verified' => true,
	'stale' => false,
	'state' => 'verified_external_wpml',
	'status_valid' => true,
	'get_parameters_valid' => true,
	'build_binding_valid' => true,
	'observed_at' => '2026-09-12T00:00:00+00:00',
);
$diagnostic = array(
	'contract' => 'mad4b.external-wpml-diagnostic.v2',
	'verified' => false,
	'stale' => false,
	'classification' => 'route_not_registered',
	'state' => 'route_not_registered',
	'route_registered' => false,
);

$verified = MAD4B_SCP_External_WPML_Acceptance_Finalizer::finalize_status( $trusted, $diagnostic );
mad4b_wpml_authority_assert( ! empty( $verified['verified'] ), 'Trusted external receipt must remain verified.' );
mad4b_wpml_authority_assert( 'verified_external_wpml' === $verified['state'], 'Trusted external receipt must retain authoritative state.' );
mad4b_wpml_authority_assert( 'success' === $verified['classification'], 'Internal route diagnostics must not downgrade external success.' );
mad4b_wpml_authority_assert( false === $verified['route_registered'], 'Internal route probe must remain visible as diagnostics.' );
mad4b_wpml_authority_assert( 'diagnostic_only' === $verified['internal_probe_role'], 'Internal probe role must be explicit.' );

$untrusted = $trusted;
$untrusted['verified'] = false;
$untrusted['state'] = 'external_wpml_failed';
$blocked = MAD4B_SCP_External_WPML_Acceptance_Finalizer::finalize_status( $untrusted, $diagnostic );
mad4b_wpml_authority_assert( empty( $blocked['verified'] ), 'Unverified external evidence must remain fail-closed.' );
mad4b_wpml_authority_assert( 'route_not_registered' === $blocked['classification'], 'Diagnostic route failure must remain visible without trusted external evidence.' );

fwrite( STDOUT, "mad4b.live-acceptance-wpml-authority.runtime.v1: PASS\n" );
