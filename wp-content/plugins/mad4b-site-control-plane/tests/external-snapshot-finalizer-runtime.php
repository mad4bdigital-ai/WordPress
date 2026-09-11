<?php

define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.27' );
$GLOBALS['mad4b_options'] = array();
$GLOBALS['mad4b_bearer'] = true;
$GLOBALS['mad4b_candidate_sha'] = str_repeat( '1', 40 );
$GLOBALS['mad4b_build_fingerprint'] = str_repeat( '2', 64 );
$GLOBALS['mad4b_snapshot_identity'] = 'sha256:' . str_repeat( 'a', 64 );
$GLOBALS['mad4b_external_observed_offset'] = -5;
$GLOBALS['mad4b_external'] = array(
	'verified' => true,
	'real_external_session' => true,
	'finalizer_subject_binding_verified' => true,
	'client_id' => 'https://chatgpt.com/oauth/client.json',
	'server_id' => 'mad4b-chatgpt',
	'session_fingerprint_present' => true,
	'inventory_match' => true,
	'write_inventory_fingerprint_match' => true,
	'finalizer_context_digest' => str_repeat( '5', 64 ),
	'external_tool_inventory_fingerprint' => str_repeat( '3', 64 ),
	'external_write_inventory_fingerprint' => str_repeat( '4', 64 ),
	'observed_at' => '',
);

function add_filter() { return true; }
function add_action() { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_get_environment_type() { return 'staging'; }
function home_url( $path = '' ) { return 'https://staging.egypttourgates.com' . $path; }
function site_url( $path = '' ) { return 'https://staging.egypttourgates.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['mad4b_options'][ $name ] = $value; return true; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_options'] ) ? $GLOBALS['mad4b_options'][ $name ] : $default; }

class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}

class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array(
			'runtime_manifest_match' => true,
			'source_commit_sha' => $GLOBALS['mad4b_candidate_sha'],
			'build_fingerprint' => $GLOBALS['mad4b_build_fingerprint'],
		);
	}
	public static function external_handshake_attestation_status() {
		$out = $GLOBALS['mad4b_external'];
		$out['observed_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) $GLOBALS['mad4b_external_observed_offset'] );
		return $out;
	}
}

class MAD4B_SCP_Skill_Snapshot_Identity {
	public static function build() { return array( 'identity_token' => $GLOBALS['mad4b_snapshot_identity'] ); }
}

class MAD4B_SCP_OAuth_Resource_Bridge {
	public static function verified_bearer_active() { return ! empty( $GLOBALS['mad4b_bearer'] ); }
}

class MAD4B_SCP_Identity_Context {
	public static function current() {
		return array(
			'authenticated' => true,
			'auth_method' => 'oauth2_bearer',
			'token_scopes' => array( 'mad4b:read' ),
		);
	}
}

class MAD4B_SCP_Live_Acceptance_Reconciler {
	public static function mutation_acceptance_status() { return array( 'ready' => true, 'state' => 'ready', 'fresh' => true, 'blockers' => array() ); }
}

class MAD4B_SCP_Live_Acceptance_Finalizer {
	public static function live_acceptance_status( $input = array() ) {
		return array(
			'contract' => 'mad4b.live-acceptance-status.v1',
			'gates' => array( 'production_unchanged' => array( 'ready' => false, 'state' => 'pending_external_evidence' ) ),
			'ready' => false,
		);
	}
	public static function aggregate_ready( array $gates ) {
		if ( empty( $gates ) ) return false;
		foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-portable-snapshot-attestation.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-external-snapshot-finalizer.php';

$candidate = array(
	'ready' => true,
	'candidate_sha' => $GLOBALS['mad4b_candidate_sha'],
	'build_fingerprint' => $GLOBALS['mad4b_build_fingerprint'],
);
$issued = MAD4B_SCP_Portable_Snapshot_Attestation::issue_external_token( $GLOBALS['mad4b_snapshot_identity'], $candidate );
if ( is_wp_error( $issued ) || empty( $issued['external_snapshot_token'] ) || empty( $issued['record'] ) ) {
	fwrite( STDERR, "export-only package proof was not issued\n" ); exit( 1 );
}
$external_token = (string) $issued['external_snapshot_token'];
if ( 1 !== preg_match( '/^mad4bext_[a-f0-9]{64}$/', $external_token ) ) {
	fwrite( STDERR, "export-only package proof has invalid format\n" ); exit( 1 );
}
if ( hash_equals( $external_token, $GLOBALS['mad4b_snapshot_identity'] ) ) {
	fwrite( STDERR, "external package proof collapsed to raw local snapshot identity\n" ); exit( 1 );
}

// Simulate the successful authenticated wp-admin export commit. Persistent state
// contains only the digest; the package plaintext proof must not be recoverable.
$GLOBALS['mad4b_options'][ MAD4B_SCP_Portable_Snapshot_Attestation::TOKEN_LEDGER_OPTION ] = array( $issued['record'] );
$ledger_json = json_encode( $GLOBALS['mad4b_options'][ MAD4B_SCP_Portable_Snapshot_Attestation::TOKEN_LEDGER_OPTION ] );
if ( false !== strpos( $ledger_json, $external_token ) ) {
	fwrite( STDERR, "plaintext external package proof leaked into WordPress persistence\n" ); exit( 1 );
}

$raw_attempt = MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify(
	array( 'client_snapshot_token' => $GLOBALS['mad4b_snapshot_identity'] )
);
if ( ! empty( $raw_attempt['exact_match'] ) || ! empty( $raw_attempt['attestation_recorded'] ) ) {
	fwrite( STDERR, "raw local snapshot identity was accepted as external package evidence\n" ); exit( 1 );
}
if ( isset( $raw_attempt['live_snapshot_token'] ) || isset( $raw_attempt['expected_external_token'] ) || isset( $raw_attempt['external_snapshot_token'] ) ) {
	fwrite( STDERR, "snapshot verifier disclosed expected package evidence\n" ); exit( 1 );
}

// A valid package secret is still insufficient when the tools/list evidence is
// older than the export. This proves export -> Scan/Refresh -> verify ordering.
$GLOBALS['mad4b_external_observed_offset'] = -120;
$pre_export = MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify(
	array( 'client_snapshot_token' => $external_token )
);
if ( ! empty( $pre_export['trusted_external_context'] ) || ! empty( $pre_export['attestation_recorded'] ) || 'fresh_post_export_external_context_required' !== $pre_export['package_proof_state'] ) {
	fwrite( STDERR, "pre-export external inventory was accepted for a new package\n" ); exit( 1 );
}
$GLOBALS['mad4b_external_observed_offset'] = -5;

$verified = MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify(
	array( 'client_snapshot_token' => $external_token )
);
if ( empty( $verified['exact_match'] ) || empty( $verified['trusted_external_context'] ) || empty( $verified['attestation_recorded'] ) ) {
	fwrite( STDERR, "trusted fresh post-export proof was not accepted\n" ); exit( 1 );
}
if ( ! isset( $verified['expected_token_disclosed'], $verified['local_snapshot_identity_disclosed'] ) || $verified['expected_token_disclosed'] || $verified['local_snapshot_identity_disclosed'] ) {
	fwrite( STDERR, "verification disclosure flags are unsafe\n" ); exit( 1 );
}
$stored_attestation = $GLOBALS['mad4b_options'][ MAD4B_SCP_External_Snapshot_Finalizer::OPTION ];
if ( false !== strpos( json_encode( $stored_attestation ), $external_token ) ) {
	fwrite( STDERR, "plaintext external package proof leaked into finalizer attestation\n" ); exit( 1 );
}
$status = MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
if ( empty( $status['ready'] ) ) {
	fwrite( STDERR, "stored external snapshot attestation is not ready\n" ); exit( 1 );
}

$GLOBALS['mad4b_external']['finalizer_context_digest'] = str_repeat( '6', 64 );
$changed_session = MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
if ( ! empty( $changed_session['ready'] ) || ! in_array( 'external_session_binding_changed', $changed_session['blockers'], true ) ) {
	fwrite( STDERR, "changed MCP/finalizer session was not rejected\n" ); exit( 1 );
}
$GLOBALS['mad4b_external']['finalizer_context_digest'] = str_repeat( '5', 64 );

$GLOBALS['mad4b_bearer'] = false;
$untrusted = MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify(
	array( 'client_snapshot_token' => $external_token )
);
if ( ! empty( $untrusted['trusted_external_context'] ) || ! empty( $untrusted['attestation_recorded'] ) ) {
	fwrite( STDERR, "untrusted request recorded external snapshot evidence\n" ); exit( 1 );
}
$untrusted_status = MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
if ( ! empty( $untrusted_status['ready'] ) || ! in_array( 'fresh_post_export_external_context_required', $untrusted_status['blockers'], true ) ) {
	fwrite( STDERR, "snapshot gate stayed ready without verified external bearer context\n" ); exit( 1 );
}
$GLOBALS['mad4b_bearer'] = true;

$GLOBALS['mad4b_candidate_sha'] = str_repeat( '7', 40 );
$moved_candidate = MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
if ( ! empty( $moved_candidate['ready'] ) || ! in_array( 'candidate_mismatch', $moved_candidate['blockers'], true ) ) {
	fwrite( STDERR, "stale portable package remained valid after candidate movement\n" ); exit( 1 );
}
$GLOBALS['mad4b_candidate_sha'] = str_repeat( '1', 40 );

$GLOBALS['mad4b_snapshot_identity'] = 'sha256:' . str_repeat( '9', 64 );
$moved_snapshot = MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
if ( ! empty( $moved_snapshot['ready'] ) || ! in_array( 'snapshot_changed_after_export', $moved_snapshot['blockers'], true ) ) {
	fwrite( STDERR, "stale portable package remained valid after local snapshot movement\n" ); exit( 1 );
}

$GLOBALS['mad4b_snapshot_identity'] = 'sha256:' . str_repeat( 'a', 64 );
$GLOBALS['mad4b_external_observed_offset'] = -1000;
$stale_handshake = MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
if ( ! empty( $stale_handshake['ready'] ) || ! in_array( 'fresh_post_export_external_context_required', $stale_handshake['blockers'], true ) ) {
	fwrite( STDERR, "stale external inventory stayed eligible for snapshot finalization\n" ); exit( 1 );
}

echo "mad4b.external-snapshot-finalizer-runtime.v3: PASS\n";
