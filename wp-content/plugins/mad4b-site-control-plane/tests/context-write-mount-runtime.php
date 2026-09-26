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
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }

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
	public static $assets = array();
	public static function source() { return array( 'source_id' => str_repeat( 'e', 64 ), 'provider' => 'google_drive', 'external_root_id' => 'folder-fixture', 'recursive' => true ); }
	public static function asset( $asset_id = '' ) { return isset( self::$assets[ $asset_id ] ) ? self::$assets[ $asset_id ] : array(); }
	public static function source_allows_write() { return true; }
	public static function source_write_policy() { return 'read_only'; }
	public static function assets() { return self::$assets; }
	public static function upsert_asset_from_provider( $source_id = '', $provider_asset = array(), $preserve = array() ) { return is_array( $provider_asset ) ? $provider_asset : array(); }
	public static function register_recreated_asset( $old_asset_id = '', $source_id = '', $provider_asset = array(), $preserve = array() ) { return array( 'replacement' => $provider_asset ); }
	public static function registry_revision() { return 1; }
	public static function context_fingerprint() { return str_repeat( 'a', 64 ); }
	public static function authority_manifest_fingerprint() { return str_repeat( 'b', 64 ); }
	public static function begin_recreated_asset_rollback() { return true; }
	public static function cancel_recreated_asset_rollback() { return true; }
	public static function rollback_recreated_asset() { return true; }
	public static function mark_generated_brand_draft() { return true; }
	public static function begin_generated_brand_rollback() { return true; }
	public static function cancel_generated_brand_rollback() { return true; }
	public static function mark_generated_brand_draft_rolled_back() { return true; }
}

class MAD4B_SCP_Google_Drive_Context {
	const MAX_WRITE_BYTES = 1048576;
	const MAX_REVERSIBLE_TEXT_BYTES = 196608;
	public static $status = array();
	public static function write_capability_status() { return self::$status; }
	public static function read_context_asset() { return array( 'content' => 'fixture', 'observed_at' => gmdate( 'c' ) ); }
	public static function scan_folder() { return array( 'files' => array() ); }
	public static function rollback_created_brand_asset() { return true; }
	public static function asset_write_capabilities() { return array( 'update' => false, 'recreate' => false, 'blockers' => array() ); }
	public static function create_asset() { return array(); }
	public static function create_brand_asset() { return array(); }
	public static function find_brand_materialization_candidates() { return array( 'complete' => true, 'assets' => array(), 'scan_generation' => str_repeat( 'a', 64 ) ); }
	public static function update_asset() { return array(); }
	public static function recreate_asset() { return array(); }
	public static function reversible_update_state( $asset_id = '', $expected = '' ) {
		return array(
			'asset_id' => $asset_id ? (string) $asset_id : str_repeat( 'd', 64 ),
			'source_id' => str_repeat( 'e', 64 ),
			'file_id' => 'drive-file-fixture',
			'content' => 'before',
			'content_sha256' => hash( 'sha256', 'before' ),
		);
	}
	public static function restore_update_state() { return true; }
	public static function reversible_recreate_state( $asset_id = '' ) {
		return array(
			'asset_id' => $asset_id ? (string) $asset_id : str_repeat( 'f', 64 ),
			'source_id' => str_repeat( 'e', 64 ),
			'original_file_id' => 'missing-drive-file',
			'parent_folder_id' => 'folder-fixture',
			'status' => 'unavailable',
			'replacement_asset_id' => '',
			'replacement_file_id' => '',
		);
	}
	public static function restore_recreate_state() { return true; }
}

class MAD4B_SCP_External_Handshake_Evidence {
	public static function build_fingerprint() { return str_repeat( 'c', 64 ); }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-provider-gateway.php';

class MAD4B_SCP_Brand_Context_Builder {
	const ROLLBACK_CONTRACT = 'mad4b.rollback.google-drive-brand-context-create.v1';
	const MAX_DRAFT_BYTES = 120000;
	public static function gap_plan() { return array(); }
	public static function append_draft() { return array(); }
	public static function source_scan_plan() { return array(); }
	public static function source_scan_apply() { return array(); }
	public static function materialize_draft() { return array(); }
	public static function reconcile_materialization() { return array(); }
	public static function rollback_materialized_draft() { return array(); }
}

require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-context-adapter.php';
if ( ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) { fwrite( STDERR, "FAIL: real Context provider gateway did not load\n" ); exit( 1 ); }

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

$rollback_asset_id = str_repeat( 'd', 64 );
$snapshot = $adapter->capture_reversible_state(
	'context/update-drive-asset',
	array( 'asset_id' => $rollback_asset_id, 'expected_content_hash' => str_repeat( '1', 64 ) )
);
mad4b_context_mount_assert( ! is_wp_error( $snapshot ), 'Context update rollback snapshot must bind the exact provider contract.', $snapshot );
$binding = isset( $snapshot['state']['_mad4b_provider_binding'] ) ? $snapshot['state']['_mad4b_provider_binding'] : array();
mad4b_context_mount_assert( MAD4B_SCP_Context_Adapter::PROVIDER_CONTRACT === ( isset( $binding['contract'] ) ? $binding['contract'] : '' ), 'Rollback snapshot must identify the first-party provider contract.', $binding );
mad4b_context_mount_assert( hash_equals( $contract['artifact_fingerprint'], (string) $binding['artifact_fingerprint'] ), 'Rollback snapshot must bind exact provider artifact fingerprint.', $binding );
mad4b_context_mount_assert( hash_equals( $contract['control_plane_build_fingerprint'], (string) $binding['control_plane_build_fingerprint'] ), 'Rollback snapshot must bind exact Control Plane build fingerprint.', $binding );

$read_state = $adapter->read_reversible_state( 'context/update-drive-asset', $snapshot['target'] );
mad4b_context_mount_assert( ! is_wp_error( $read_state ) && isset( $read_state['_mad4b_provider_binding'] ), 'Reversible readback must carry the same build-bound provider binding.', $read_state );
mad4b_context_mount_assert( hash_equals( (string) $binding['artifact_fingerprint'], (string) $read_state['_mad4b_provider_binding']['artifact_fingerprint'] ), 'Reversible readback provider artifact binding must be stable on the exact build.', $read_state );

$tampered_state = $snapshot['state'];
$tampered_state['_mad4b_provider_binding']['artifact_fingerprint'] = str_repeat( '9', 64 );
$restore = $adapter->restore_reversible_state( 'context/update-drive-asset', $snapshot['target'], $tampered_state, array() );
mad4b_context_mount_assert( is_wp_error( $restore ), 'Rollback with provider artifact drift must fail closed before provider restore.', $restore );
mad4b_context_mount_assert( 'mad4b_context_undo_provider_contract_drift' === $restore->get_error_code(), 'Provider artifact drift must expose the exact rollback blocker.', $restore->get_error_code() );

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
mad4b_context_mount_assert( is_wp_error( $result ), 'Arbitrary create must remain unmounted until its exact rollback contract is certified, regardless of source policy.' );
mad4b_context_mount_assert( 'mad4b_google_drive_create_rollback_not_certified' === $result->get_error_code(), 'Create must surface the global rollback-certification blocker before source-policy evaluation.', $result->get_error_code() );

mad4b_context_mount_assert( true === $adapter->mutation_ability_runtime_eligibility( 'context/update-drive-asset' ), 'Repair-only source must mount governed update when OAuth write is available.' );
mad4b_context_mount_assert( true === $adapter->mutation_ability_runtime_eligibility( 'context/recreate-drive-asset' ), 'Repair-only source must mount governed recreate when OAuth write is available.' );

MAD4B_SCP_Google_Drive_Context::$status['create_source_count'] = 1;
$result = $adapter->mutation_ability_runtime_eligibility( 'context/create-drive-asset' );
mad4b_context_mount_assert( is_wp_error( $result ), 'Managed source must not mount arbitrary create before exact create rollback is certified.', $result );
mad4b_context_mount_assert( 'mad4b_google_drive_create_rollback_not_certified' === $result->get_error_code(), 'Create mount must expose the rollback-certification blocker.', $result->get_error_code() );

mad4b_context_mount_assert( true === $adapter->mutation_ability_runtime_eligibility( 'context/status' ), 'Read ability eligibility must remain unaffected by write gates.' );

$governed_id = str_repeat( '1', 64 );
$task_a_id = str_repeat( '2', 64 );
$task_b_id = str_repeat( '3', 64 );
MAD4B_SCP_Context_Authority::$assets = array(
	$governed_id => array(
		'asset_id' => $governed_id, 'source_id' => str_repeat( 'a', 64 ), 'source_mode' => 'governed',
		'title' => 'Governed Brand Core', 'category' => 'brand_strategy', 'status' => 'ready',
	),
	$task_a_id => array(
		'asset_id' => $task_a_id, 'source_id' => str_repeat( 'b', 64 ), 'source_mode' => 'task_attachment',
		'task_scope' => 'task-a', 'title' => 'Private Task A Brief', 'category' => 'campaign_strategy', 'status' => 'ready',
	),
	$task_b_id => array(
		'asset_id' => $task_b_id, 'source_id' => str_repeat( 'c', 64 ), 'source_mode' => 'task_attachment',
		'task_scope' => 'task-b', 'title' => 'Private Task B Brief', 'category' => 'campaign_strategy', 'status' => 'ready',
	),
);

$default_assets = $adapter->list_assets( array() );
mad4b_context_mount_assert( ! is_wp_error( $default_assets ), 'Default Context asset list must remain readable.', $default_assets );
mad4b_context_mount_assert( 1 === $default_assets['count'] && $governed_id === $default_assets['items'][0]['asset_id'], 'Default asset list must exclude every task attachment metadata record.', $default_assets );

$task_a_assets = $adapter->list_assets( array( 'task_scope' => 'task-a' ) );
mad4b_context_mount_assert( ! is_wp_error( $task_a_assets ), 'Exact task-scoped asset list must remain readable.', $task_a_assets );
$task_a_ids = array_map( static function ( $row ) { return $row['asset_id']; }, $task_a_assets['items'] );
mad4b_context_mount_assert( in_array( $governed_id, $task_a_ids, true ), 'Governed Context must remain visible under task-scoped listing.', $task_a_assets );
mad4b_context_mount_assert( in_array( $task_a_id, $task_a_ids, true ), 'Matching task attachment must be visible under exact task scope.', $task_a_assets );
mad4b_context_mount_assert( ! in_array( $task_b_id, $task_a_ids, true ), 'Foreign task attachment metadata must not cross task-scope boundary.', $task_a_assets );

$missing_scope = $adapter->list_assets( array( 'mode' => 'task_attachment' ) );
mad4b_context_mount_assert( is_wp_error( $missing_scope ) && 'mad4b_context_task_scope_required' === $missing_scope->get_error_code(), 'Explicit task-attachment listing without exact task_scope must fail closed.', $missing_scope );

$task_only = $adapter->list_assets( array( 'mode' => 'task_attachment', 'task_scope' => 'task-b' ) );
mad4b_context_mount_assert( ! is_wp_error( $task_only ) && 1 === $task_only['count'] && $task_b_id === $task_only['items'][0]['asset_id'], 'Exact task-only listing must return only the matching task attachment.', $task_only );

echo "mad4b.site-control-plane.context-write-mount.runtime.v6: PASS\n";
