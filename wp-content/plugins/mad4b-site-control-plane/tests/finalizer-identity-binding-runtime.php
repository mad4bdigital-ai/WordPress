<?php

define( 'ABSPATH', '/srv/wordpress/' );

function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-external-handshake-evidence.php';

function mad4b_finalizer_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$subject = str_repeat( 'a', 64 );
$inventory = str_repeat( 'b', 64 );
$observed_at = '2026-09-11 13:00:00';
$verified_at = '2026-09-11 13:00:03';
$issuer = 'https://auth.mad4b.com';
$resource = 'https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt';
$package = array(
	'source_commit_sha' => str_repeat( '1', 40 ),
	'package_build_fingerprint' => str_repeat( '2', 64 ),
	'package_manifest_digest' => str_repeat( '3', 64 ),
	'artifact_identity' => 'mad4b-site-control-plane-test-' . str_repeat( '1', 40 ),
);

$attestation = array(
	'real_external_session' => true,
	'server_id' => MAD4B_SCP_External_Handshake_Evidence::SERVER_ID,
	'client_id' => MAD4B_SCP_External_Handshake_Evidence::CHATGPT_CLIENT_ID,
	'external_tool_inventory_fingerprint' => $inventory,
	'external_tool_count' => 12,
	'external_write_tool_count' => 4,
	'source_commit_sha' => $package['source_commit_sha'],
	'package_build_fingerprint' => $package['package_build_fingerprint'],
	'package_manifest_digest' => $package['package_manifest_digest'],
	'artifact_identity' => $package['artifact_identity'],
	'observed_at' => $observed_at,
);

$handshake = array(
	'verified' => true,
	'subject_fingerprint' => $subject,
	'scope_set' => array( MAD4B_SCP_External_Handshake_Evidence::REQUIRED_SCOPE ),
	'auth_method' => 'oauth2_bearer',
	'wp_user_id' => 7,
	'server_id' => MAD4B_SCP_External_Handshake_Evidence::SERVER_ID,
	'client_id' => MAD4B_SCP_External_Handshake_Evidence::CHATGPT_CLIENT_ID,
	'issuer' => $issuer,
	'resource' => $resource,
	'tool_inventory_fingerprint' => $inventory,
	'tool_count' => 12,
	'write_tool_count' => 4,
	'mcp_session_fingerprint' => str_repeat( 'c', 64 ),
	'source_commit_sha' => $package['source_commit_sha'],
	'package_build_fingerprint' => $package['package_build_fingerprint'],
	'package_manifest_digest' => $package['package_manifest_digest'],
	'artifact_identity' => $package['artifact_identity'],
	'verified_at' => $verified_at,
);

$identity = array(
	'authenticated' => true,
	'auth_method' => 'oauth2_bearer',
	'subject_fingerprint' => $subject,
	'wp_user_id' => 7,
	'token_scopes' => array( MAD4B_SCP_External_Handshake_Evidence::REQUIRED_SCOPE ),
);

$oauth = array( 'issuer' => $issuer, 'resource' => $resource );

mad4b_finalizer_assert(
	MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $handshake, $identity, $oauth ),
	'Exact current subject, OAuth authority, ChatGPT client, tools/list evidence and package identity must bind.'
);

$bad = $handshake;
$bad['source_commit_sha'] = str_repeat( '4', 40 );
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different source commit must fail closed.' );

$bad = $handshake;
$bad['package_build_fingerprint'] = str_repeat( '5', 64 );
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different package build fingerprint must fail closed.' );

$bad = $handshake;
$bad['package_manifest_digest'] = str_repeat( '6', 64 );
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different package manifest digest must fail closed.' );

$bad = $handshake;
$bad['artifact_identity'] = 'different-artifact';
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different artifact identity must fail closed.' );

$bad = $identity;
$bad['subject_fingerprint'] = str_repeat( 'd', 64 );
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $handshake, $bad, $oauth ), 'Different OAuth subject must fail closed.' );

$bad = $oauth;
$bad['issuer'] = 'https://other-authority.example';
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $handshake, $identity, $bad ), 'Different issuer must fail closed.' );

$bad = $oauth;
$bad['resource'] = 'https://other.example/wp-json/mcp/mad4b-chatgpt';
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $handshake, $identity, $bad ), 'Different protected resource must fail closed.' );

$bad = $handshake;
$bad['client_id'] = 'https://example.invalid/client.json';
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Non-ChatGPT client must fail closed.' );

$bad = $handshake;
$bad['tool_inventory_fingerprint'] = str_repeat( 'e', 64 );
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different external tool inventory must fail closed.' );

$bad = $handshake;
$bad['tool_count'] = 11;
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different tool count must fail closed.' );

$bad = $handshake;
$bad['write_tool_count'] = 3;
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different write inventory count must fail closed.' );

$bad = $handshake;
$bad['verified_at'] = '2026-09-11 13:00:10';
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Different tools/list observation window must fail closed.' );

$bad = $identity;
$bad['token_scopes'] = array();
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $handshake, $bad, $oauth ), 'Current identity without read scope must fail closed.' );

$bad = $handshake;
$bad['verified'] = false;
mad4b_finalizer_assert( ! MAD4B_SCP_External_Handshake_Evidence::observer_attestation_matches_finalizer_context( $attestation, $bad, $identity, $oauth ), 'Unverified authoritative handshake must fail closed.' );

echo "mad4b.finalizer-identity-binding.runtime.v1: PASS\n";
