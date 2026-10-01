<?php

define( 'ABSPATH', '/srv/wordpress/' );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
$GLOBALS['mad4b_wpml_receipt'] = array();
$GLOBALS['mad4b_wpml_identity'] = array(
	'identity_ready' => true,
	'source_commit_sha' => str_repeat( 'a', 40 ),
	'build_fingerprint' => str_repeat( 'b', 64 ),
	'package_manifest_digest' => str_repeat( 'c', 64 ),
	'artifact_identity' => 'mad4b-site-control-plane-test-' . str_repeat( 'a', 40 ),
);
function get_option( $key, $default = false ) { return 'mad4b_scp_external_wpml_response_contract_v1' === $key ? $GLOBALS['mad4b_wpml_receipt'] : $default; }
class MAD4B_SCP_Live_Acceptance_Observer {
	public static function staging_passive_receipt_allowed() { return true; }
	public static function build_provenance_identity_status() { return $GLOBALS['mad4b_wpml_identity']; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-wpml-response-contract.php';

function mad4b_wpml_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$enveloped = MAD4B_SCP_WPML_Response_Contract::evaluate_response(
	true,
	200,
	array( 'success' => true, 'data' => array( 'status' => 'valid', 'get_parameters' => 'valid' ) )
);
mad4b_wpml_assert( 'success' === $enveloped['classification'], 'Real WPML success envelope must be accepted.' );
mad4b_wpml_assert( 'wp_rest_success_envelope' === $enveloped['body_classification'], 'Real WPML success envelope must be classified explicitly.' );
mad4b_wpml_assert( 'valid' === $enveloped['status'] && 'valid' === $enveloped['get_parameters'], 'Nested compatible values must be normalized.' );

$legacy = MAD4B_SCP_WPML_Response_Contract::evaluate_response(
	true,
	200,
	array( 'status' => 'valid', 'get_parameters' => 'valid' )
);
mad4b_wpml_assert( 'success' === $legacy['classification'], 'Direct compatible response shape must remain supported.' );

$missing_parameter = MAD4B_SCP_WPML_Response_Contract::evaluate_response(
	false,
	200,
	array( 'success' => true, 'data' => array( 'status' => 'valid', 'get_parameters' => 'valid' ) )
);
mad4b_wpml_assert( 'contract_mismatch' === $missing_parameter['classification'], 'Missing test_get_parameter=1 must fail closed.' );

$route_missing = MAD4B_SCP_WPML_Response_Contract::evaluate_response( true, 404, array( 'code' => 'rest_no_route' ), 'rest_no_route', false );
mad4b_wpml_assert( 'route_not_registered' === $route_missing['classification'], 'Explicit internal route absence must normalize to route_not_registered.' );

$rest_no_route = MAD4B_SCP_WPML_Response_Contract::evaluate_response( true, 404, array( 'code' => 'rest_no_route' ), 'rest_no_route', true );
mad4b_wpml_assert( 'rest_no_route' === $rest_no_route['classification'], 'REST no-route error must be distinct.' );

$wp_error = MAD4B_SCP_WPML_Response_Contract::evaluate_response( true, 500, null, 'internal_error', true );
mad4b_wpml_assert( 'wp_error' === $wp_error['classification'], 'WP_Error must normalize without raw body persistence.' );

$contract_mismatch = MAD4B_SCP_WPML_Response_Contract::evaluate_response(
	true,
	200,
	array( 'success' => true, 'data' => array( 'status' => 'valid', 'get_parameters' => 'invalid' ) )
);
mad4b_wpml_assert( 'contract_mismatch' === $contract_mismatch['classification'], 'Semantically incompatible JSON must fail closed.' );

$non_json = MAD4B_SCP_WPML_Response_Contract::evaluate_response( true, 200, '<html>not json</html>', '', true );
mad4b_wpml_assert( 'non_json_response' === $non_json['classification'], 'Non-JSON response must be explicit.' );

$redirect = MAD4B_SCP_WPML_Response_Contract::evaluate_response( true, 302, array(), '', true );
mad4b_wpml_assert( 'redirect_response' === $redirect['classification'], 'Redirect response must not count as WPML success.' );

// Persisted WPML evidence is valid only for the exact four-part package identity.
$GLOBALS['mad4b_wpml_receipt'] = array(
	'contract' => MAD4B_SCP_WPML_Response_Contract::CONTRACT,
	'observed' => true,
	'observed_at' => gmdate( 'c' ),
	'build_fingerprint' => $GLOBALS['mad4b_wpml_identity']['build_fingerprint'],
	'source_commit_sha' => $GLOBALS['mad4b_wpml_identity']['source_commit_sha'],
	'package_manifest_digest' => $GLOBALS['mad4b_wpml_identity']['package_manifest_digest'],
	'artifact_identity' => $GLOBALS['mad4b_wpml_identity']['artifact_identity'],
	'request_route' => '/wpml/v1/rest/status',
	'test_get_parameter_present' => true,
	'classification' => 'success',
	'status' => 'valid',
	'get_parameters' => 'valid',
);
$status = MAD4B_SCP_WPML_Response_Contract::receipt_status();
mad4b_wpml_assert( ! empty( $status['verified'] ) && ! empty( $status['package_identity_match'] ), 'Exact package-bound WPML receipt must verify.' );

$GLOBALS['mad4b_wpml_identity']['artifact_identity'] = 'different-artifact';
$status = MAD4B_SCP_WPML_Response_Contract::receipt_status();
mad4b_wpml_assert( empty( $status['verified'] ) && 'stale_package_identity_evidence' === $status['state'], 'WPML receipt must stale on artifact identity drift even when build fingerprint is unchanged.' );
$GLOBALS['mad4b_wpml_identity']['artifact_identity'] = $GLOBALS['mad4b_wpml_receipt']['artifact_identity'];

$legacy = $GLOBALS['mad4b_wpml_receipt'];
unset( $legacy['source_commit_sha'], $legacy['package_manifest_digest'], $legacy['artifact_identity'] );
$GLOBALS['mad4b_wpml_receipt'] = $legacy;
$status = MAD4B_SCP_WPML_Response_Contract::receipt_status();
mad4b_wpml_assert( empty( $status['verified'] ) && 'stale_package_identity_evidence' === $status['state'], 'Legacy fingerprint-only WPML evidence must fail closed.' );

echo "mad4b.wpml-response-contract.runtime.v1: PASS\n";
