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
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

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

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = array(
	$old => mad4b_source_fixture( $old, 'governed' ),
	$repair => mad4b_source_fixture( $repair, 'governed', 'repair_only' ),
	$managed => mad4b_source_fixture( $managed, 'governed', 'managed' ),
	$task => mad4b_source_fixture( $task, 'task_attachment', 'managed' ),
);

$sources = MAD4B_SCP_Context_Authority::sources();
mad4b_context_policy_assert( 'read_only' === $sources[ $old ]['write_policy'], 'legacy source without policy must remain read-only' );
mad4b_context_policy_assert( 'read_only' === $sources[ $task ]['write_policy'], 'task-only source must normalize to read-only even if stored otherwise' );

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

echo "mad4b.site-control-plane.context-source-write-policy.runtime.v1: PASS\n";
