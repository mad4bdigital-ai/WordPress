<?php

define( 'ABSPATH', '/tmp/mad4b-context-write-mount/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

abstract class MAD4B_SCP_Adapter_Base {
	abstract public function id();
	abstract public function label();
	abstract public function is_available();
	abstract public function ability_names();
	abstract public function register_abilities();
	protected function add_ability() {}
	protected function schema( array $properties, array $required = array() ) { return array(); }
	public function reversible_contracts() { return array(); }
	public function capture_reversible_state( $ability_name, array $input ) { return new WP_Error( 'unsupported' ); }
	public function read_reversible_state( $ability_name, array $target ) { return new WP_Error( 'unsupported' ); }
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) { return new WP_Error( 'unsupported' ); }
}

class MAD4B_SCP_Context_Authority {
	public static function source_allows_write() { return true; }
	public static function registry_revision() { return 1; }
	public static function context_fingerprint() { return str_repeat( 'a', 64 ); }
	public static function authority_manifest_fingerprint() { return str_repeat( 'b', 64 ); }
	public static function begin_recreated_asset_rollback() { return true; }
	public static function rollback_recreated_asset() { return true; }
}

class MAD4B_SCP_Google_Drive_Context {
	const MAX_WRITE_BYTES = 1048576;
	const MAX_REVERSIBLE_TEXT_BYTES = 196608;
	public static $status = array();
	public static function write_capability_status() { return self::$status; }
	public static function create_asset() { return array(); }
	public static function update_asset() { return array(); }
	public static function recreate_asset() { return array(); }
	public static function reversible_update_state() { return array(); }
	public static function restore_update_state() { return true; }
	public static function reversible_recreate_state() { return array(); }
	public static function restore_recreate_state() { return true; }
}

class MAD4B_SCP_External_Handshake_Evidence {
	public static function build_fingerprint() { return str_repeat( 'c', 64 ); }
}

require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-context-adapter.php';

function mad4b_context_mount_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$adapter = new MAD4B_SCP_Context_Adapter();
$contract = $adapter->context_provider_contract_status();
mad4b_context_mount_assert( ! empty( $contract['ready'] ), 'First-party Context provider contract must certify exact runtime surfaces.', $contract );
mad4b_context_mount_assert( preg_match( '/^[a-f0-9]{64}$/', $contract['artifact_fingerprint'] ), 'Provider contract must expose exact critical-file artifact fingerprint.', $contract );
mad4b_context_mount_assert( str_repeat( 'c', 64 ) === $contract['control_plane_build_fingerprint'], 'Provider contract must bind the exact Control Plane build fingerprint.', $contract );
mad4b_context_mount_assert( 'runtime_structural_plus_build_bound_artifact_fingerprint' === $contract['certification_mode'], 'Provider certification mode must remain build-bound.', $contract );

MAD4B_SCP_Google_Drive_Context::$status = array(
	'write_available' => false,
	'selected_source_count' => 1,
	'create_source_count' => 1,
	'update_source_count' => 1,
	'recreate_source_count' => 1,
);
$result = $adapter->mutation_ability_runtime_eligibility( 'context/update-drive-asset' );
mad4b_context_mount_assert( is_wp_error( $result ), 'Read-only OAuth must not mount Context writes.' );
mad4b_context_mount_assert( 'mad4b_google_drive_write_scope_required' === $result->get_error_code(), 'Read-only OAuth must surface write-scope blocker.', $result->get_error_code() );

MAD4B_SCP_Google_Drive_Context::$status = array(
	'write_available' => true,
	'selected_source_count' => 0,
	'create_source_count' => 0,
	'update_source_count' => 0,
	'recreate_source_count' => 0,
);
$result = $adapter->mutation_ability_runtime_eligibility( 'context/update-drive-asset' );
mad4b_context_mount_assert( is_wp_error( $result ), 'Write OAuth without a selected source must fail closed.' );
mad4b_context_mount_assert( 'mad4b_context_source_required_for_write' === $result->get_error_code(), 'Missing source must have a stable blocker.', $result->get_error_code() );

MAD4B_SCP_Google_Drive_Context::$status = array(
	'write_available' => true,
	'selected_source_count' => 1,
	'create_source_count' => 0,
	'update_source_count' => 1,
	'recreate_source_count' => 1,
);
$result = $adapter->mutation_ability_runtime_eligibility( 'context/create-drive-asset' );
mad4b_context_mount_assert( is_wp_error( $result ), 'Repair-only source must not mount arbitrary create.' );
mad4b_context_mount_assert( 'mad4b_context_source_policy_blocks_write' === $result->get_error_code(), 'Repair-only create must surface source-policy blocker.', $result->get_error_code() );

mad4b_context_mount_assert( true === $adapter->mutation_ability_runtime_eligibility( 'context/update-drive-asset' ), 'Repair-only source must mount governed update when OAuth write is available.' );
mad4b_context_mount_assert( true === $adapter->mutation_ability_runtime_eligibility( 'context/recreate-drive-asset' ), 'Repair-only source must mount governed recreate when OAuth write is available.' );

MAD4B_SCP_Google_Drive_Context::$status['create_source_count'] = 1;
mad4b_context_mount_assert( true === $adapter->mutation_ability_runtime_eligibility( 'context/create-drive-asset' ), 'Managed source must mount governed create when OAuth write is available.' );

mad4b_context_mount_assert( true === $adapter->mutation_ability_runtime_eligibility( 'context/status' ), 'Read ability eligibility must remain unaffected by write gates.' );

echo "mad4b.site-control-plane.context-write-mount.runtime.v2: PASS\n";
