<?php

define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.27' );
$GLOBALS['mad4b_options'] = array();
$GLOBALS['mad4b_bearer'] = true;
$GLOBALS['mad4b_candidate_sha'] = str_repeat( '1', 40 );
$GLOBALS['mad4b_build_fingerprint'] = str_repeat( '2', 64 );
$GLOBALS['mad4b_snapshot_identity'] = 'sha256:' . str_repeat( 'a', 64 );
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
		$out['observed_at'] = gmdate( 'Y-m-d H:i:s', time() - 5 );
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

$external_token = MAD4B_SCP_Portable_Snapshot_Attestation::external_token(
	$GLOBALS['mad4b_snapshot_identity'],
	$GLOBALS['mad4b_candidate_sha'],
	$GLOBALS['mad4b_build_fingerprint']
);
if ( 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $external_token ) ) {
	fwrite( STDERR, "candidate-bound package token was not produced\n" ); exit( 1 );
}
if ( hash_equals( $external_token, $GLOBALS['mad4b_snapshot_identity'] ) ) {
	fwrite( STDERR, "external package token collapsed to raw local snapshot identity\n" ); exit( 1 );
}

$raw_attempt = MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify(
	array( 'client_snapshot_token' => $GLOBALS['mad4b_snapshot_identity'] )
);
if ( ! empty( $raw_attempt['exact_match'] ) || ! empty( $raw_attempt['attestation_recorded'] ) ) {
	fwrite( STDERR, "raw local snapshot identity was accepted as external package evidence\n" ); exit( 1 );
}
if ( isset( $raw_attempt['live_snapshot_token'] ) || isset( $raw_attempt['expected_external_token'] ) ) {
	fwrite( STDERR, "snapshot verifier disclosed expected evidence\n" ); exit( 1 );
}

$verified = MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify(
	array( 'client_snapshot_token' => $external_token )
);
if ( empty( $verified['exact_match'] ) || empty( $verified['trusted_external_context'] ) || empty( $verified['attestation_recorded'] ) ) {
	fwrite( STDERR, "trusted external package token was not accepted\n" ); exit( 1 );
}
if ( empty( $verified['expected_token_disclosed'] ) === false || empty( $verified['local_snapshot_identity_disclosed'] ) === false ) {
	fwrite( STDERR, "verification disclosure flags are unsafe\n" ); exit( 1 );
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
if ( ! empty( $untrusted_status['ready'] ) || ! in_array( 'external_session_not_verified', $untrusted_status['blockers'], true ) ) {
	fwrite( STDERR, "snapshot gate stayed ready without verified external bearer context\n" ); exit( 1 );
}
$GLOBALS['mad4b_bearer'] = true;

$GLOBALS['mad4b_candidate_sha'] = str_repeat( '7', 40 );
$moved_candidate = MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
if ( ! empty( $moved_candidate['ready'] ) || ! in_array( 'candidate_mismatch', $moved_candidate['blockers'], true ) ) {
	fwrite( STDERR, "stale portable package remained valid after candidate movement\n" ); exit( 1 );
}

echo "mad4b.external-snapshot-finalizer-runtime.v2: PASS\n";
