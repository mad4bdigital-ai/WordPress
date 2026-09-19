<?php

define( 'ABSPATH', '/tmp/mad4b-context-policy-runtime/' );

$GLOBALS['mad4b_context_options'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ? $GLOBALS['mad4b_context_options'][ $name ] : $default; }
function add_option( $name, $value ) { $GLOBALS['mad4b_context_options'][ $name ] = $value; return true; }
function update_option( $name, $value ) { $GLOBALS['mad4b_context_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['mad4b_context_options'][ $name ] ); return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

class MAD4B_SCP_Audit {
	public static $events = array();
	public static function storage_status() { return array( 'ready' => true ); }
	public static function record( $ability, $summary = array(), $status = 'ok' ) {
		self::$events[] = array( 'ability' => (string) $ability, 'summary' => $summary, 'status' => (string) $status );
		return true;
	}
}

class MAD4B_SCP_Site_Profile {
	public static $site_uuid = '11111111-1111-4111-8111-111111111111';
	public static function status() {
		return array(
			'configured' => true,
			'origin_match' => true,
			'environment_match' => true,
			'site_uuid' => self::$site_uuid,
			'environment' => 'staging',
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_context_policy_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

function mad4b_source_fixture( $source_id, $mode, $policy = null ) {
	$record = array(
		'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
		'source_id' => $source_id,
		'site_uuid' => '11111111-1111-4111-8111-111111111111',
		'brand_id' => 'brand-fixture',
		'provider' => 'google_drive',
		'mode' => $mode,
		'external_root_id' => 'folder-' . substr( $source_id, 0, 8 ),
		'label' => 'Fixture',
		'task_scope' => 'task_attachment' === $mode ? 'fixture-task' : '',
		'recursive' => true,
		'status' => 'ready',
		'last_synced_at' => '',
		'asset_count' => 0,
		'created_at' => gmdate( 'c' ),
		'updated_at' => gmdate( 'c' ),
	);
	if ( null !== $policy ) $record['write_policy'] = $policy;
	return $record;
}

$old = str_repeat( 'a', 64 );
$repair = str_repeat( 'b', 64 );
$managed = str_repeat( 'c', 64 );
$task = str_repeat( 'd', 64 );
$root = str_repeat( 'e', 64 );

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = array(
	$old => mad4b_source_fixture( $old, 'governed' ),
	$repair => mad4b_source_fixture( $repair, 'governed', 'repair_only' ),
	$managed => mad4b_source_fixture( $managed, 'governed', 'managed' ),
	$task => mad4b_source_fixture( $task, 'task_attachment', 'managed' ),
	$root => array_merge( mad4b_source_fixture( $root, 'governed', 'managed' ), array( 'external_root_id' => 'root' ) ),
);

$valid_asset = str_repeat( '1', 64 );
$root_asset = str_repeat( '2', 64 );
$orphan_asset = str_repeat( '3', 64 );
$mode_mismatch_asset = str_repeat( '4', 64 );

function mad4b_asset_fixture( $asset_id, $source_id, $mode ) {
	return array(
		'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
		'asset_id' => $asset_id,
		'site_uuid' => '11111111-1111-4111-8111-111111111111',
		'brand_id' => 'brand-fixture',
		'source_id' => $source_id,
		'source_mode' => $mode,
		'file_id' => 'file-' . substr( $asset_id, 0, 8 ),
		'title' => 'Fixture Asset',
		'status' => 'ready',
	);
}

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array(
	$valid_asset => mad4b_asset_fixture( $valid_asset, $managed, 'governed' ),
	$root_asset => mad4b_asset_fixture( $root_asset, $root, 'governed' ),
	$orphan_asset => mad4b_asset_fixture( $orphan_asset, str_repeat( 'f', 64 ), 'governed' ),
	$mode_mismatch_asset => mad4b_asset_fixture( $mode_mismatch_asset, $task, 'governed' ),
);

$sources = MAD4B_SCP_Context_Authority::sources();
mad4b_context_policy_assert( 'read_only' === $sources[ $old ]['write_policy'], 'legacy source without policy must remain read-only' );
mad4b_context_policy_assert( 'read_only' === $sources[ $task ]['write_policy'], 'task-only source must normalize to read-only even if stored otherwise' );
mad4b_context_policy_assert( ! isset( $sources[ $root ] ), 'My Drive root must never materialize as a Context source boundary' );

$assets = MAD4B_SCP_Context_Authority::assets();
mad4b_context_policy_assert( isset( $assets[ $valid_asset ] ), 'Asset bound to a current valid source must remain eligible.' );
mad4b_context_policy_assert( ! isset( $assets[ $root_asset ] ), 'Asset bound to rejected My Drive root source must be excluded from Context Authority.' );
mad4b_context_policy_assert( ! isset( $assets[ $orphan_asset ] ), 'Asset whose source no longer exists must be excluded from Context Authority.' );
mad4b_context_policy_assert( ! isset( $assets[ $mode_mismatch_asset ] ), 'Asset source mode must exactly match its current source mode.' );
mad4b_context_policy_assert( 1 === count( $assets ), 'Only source-authoritative assets may materialize in the Context registry view.' );

mad4b_context_policy_assert( false === MAD4B_SCP_Context_Authority::source_allows_write( $old, 'update' ), 'legacy read-only source must deny update' );
mad4b_context_policy_assert( false === MAD4B_SCP_Context_Authority::source_allows_write( $repair, 'create' ), 'repair-only source must deny create' );
mad4b_context_policy_assert( true === MAD4B_SCP_Context_Authority::source_allows_write( $repair, 'update' ), 'repair-only source must allow update' );
mad4b_context_policy_assert( true === MAD4B_SCP_Context_Authority::source_allows_write( $repair, 'recreate' ), 'repair-only source must allow recreate' );
mad4b_context_policy_assert( true === MAD4B_SCP_Context_Authority::source_allows_write( $managed, 'create' ), 'managed source must allow create' );
mad4b_context_policy_assert( true === MAD4B_SCP_Context_Authority::source_allows_write( $managed, 'update' ), 'managed source must allow update' );
mad4b_context_policy_assert( true === MAD4B_SCP_Context_Authority::source_allows_write( $managed, 'recreate' ), 'managed source must allow recreate' );
mad4b_context_policy_assert( false === MAD4B_SCP_Context_Authority::source_allows_write( $task, 'recreate' ), 'task-only source must deny Drive writes' );

mad4b_context_policy_assert( 1 === MAD4B_SCP_Context_Authority::writable_source_count( 'create' ), 'only managed source should enable create' );
mad4b_context_policy_assert( 2 === MAD4B_SCP_Context_Authority::writable_source_count( 'update' ), 'repair and managed sources should enable update' );
mad4b_context_policy_assert( 2 === MAD4B_SCP_Context_Authority::writable_source_count( 'recreate' ), 'repair and managed sources should enable recreate' );

$partial_refresh = MAD4B_SCP_Context_Authority::replace_source_assets(
	$managed,
	array(),
	array(
		'complete' => false,
		'scan_generation' => str_repeat( '9', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array( 'fixture_partial' ),
	)
);
mad4b_context_policy_assert( ! is_wp_error( $partial_refresh ), 'Authorized source refresh must commit without rewriting the filtered view as raw storage.' );

$raw_sources_after = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ];
$raw_assets_after = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ];
mad4b_context_policy_assert( isset( $raw_sources_after[ $root ] ), 'Hidden My Drive root source record must not be silently deleted by an unrelated source mutation.' );
mad4b_context_policy_assert( isset( $raw_assets_after[ $root_asset ] ), 'Asset hidden by rejected root source must remain preserved in raw storage.' );
mad4b_context_policy_assert( isset( $raw_assets_after[ $orphan_asset ] ), 'Orphan asset must remain preserved in raw storage until an explicit cleanup/removal action.' );
mad4b_context_policy_assert( isset( $raw_assets_after[ $mode_mismatch_asset ] ), 'Mode-mismatch asset must remain preserved in raw storage until explicit remediation.' );

$visible_after = MAD4B_SCP_Context_Authority::assets();
mad4b_context_policy_assert( isset( $visible_after[ $valid_asset ] ), 'Authorized asset must remain visible after partial refresh.' );
mad4b_context_policy_assert(
	! isset( $visible_after[ $root_asset ] )
	&& ! isset( $visible_after[ $orphan_asset ] )
	&& ! isset( $visible_after[ $mode_mismatch_asset ] ),
	'Every raw hidden asset must remain excluded from the live Authority projection after mutation.'
);
$policy_scan_events = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $row ) { return 'mad4b/context-source-scan' === $row['ability']; } ) );
mad4b_context_policy_assert( 1 === count( $policy_scan_events ), 'Partial authorized source refresh must append one governance scan event.' );
mad4b_context_policy_assert( 'partial' === $policy_scan_events[0]['status'], 'Partial authorized source refresh audit must remain explicitly partial.' );

echo "mad4b.site-control-plane.context-source-write-policy.runtime.v5: PASS\n";
