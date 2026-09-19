<?php

define( 'ABSPATH', '/tmp/mad4b-context-recreate-rollback/' );

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

class MAD4B_SCP_Audit {
	public static $events = array();
	public static function record( $ability, $payload = array(), $status = 'ok' ) { self::$events[] = array( $ability, $payload, $status ); return true; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_context_rollback_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$source_id = str_repeat( 'a', 64 );
$old_id = str_repeat( 'b', 64 );
$new_id = str_repeat( 'c', 64 );
$site_uuid = '11111111-1111-4111-8111-111111111111';

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = array(
	$source_id => array(
		'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
		'source_id' => $source_id,
		'site_uuid' => $site_uuid,
		'brand_id' => 'brand-fixture',
		'provider' => 'google_drive',
		'mode' => 'governed',
		'external_root_id' => 'folder-fixture',
		'label' => 'Brand Core',
		'task_scope' => '',
		'write_policy' => 'repair_only',
		'recursive' => true,
		'status' => 'ready',
		'last_synced_at' => '',
		'asset_count' => 1,
		'created_at' => gmdate( 'c' ),
		'updated_at' => gmdate( 'c' ),
	),
);

$base = array(
	'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
	'site_uuid' => $site_uuid,
	'brand_id' => 'brand-fixture',
	'source_id' => $source_id,
	'source_mode' => 'governed',
	'task_scope' => '',
	'provider' => 'google_drive',
	'title' => 'Tone of Voice',
	'path' => 'Brand Core/Tone of Voice',
	'mime_type' => 'application/vnd.google-apps.document',
	'version' => '',
	'content_hash' => str_repeat( 'd', 64 ),
	'content_available' => false,
	'content_excerpt' => '',
	'category' => 'tone_of_voice',
	'classification_confidence' => 1.0,
	'classification_source' => 'human',
	'authority_class' => 'brand_authority',
	'required' => true,
	'priority' => 100,
	'language' => 'en',
	'scope' => 'all',
	'quality_score' => 90,
	'quality_auto_score' => 90,
	'quality' => array( 'contract' => MAD4B_SCP_Context_Authority::QUALITY_CONTRACT, 'overall_score' => 90 ),
	'reviewed_by' => 1,
	'reviewed_at' => gmdate( 'c' ),
	'review_status' => 'approved',
	'last_synced_at' => gmdate( 'c' ),
);

$old = $base;
$old['asset_id'] = $old_id;
$old['file_id'] = 'old-file';
$old['status'] = 'recreated';
$old['availability_reason'] = 'replacement_created';
$old['replacement_asset_id'] = $new_id;
$old['recreated_at'] = gmdate( 'c' );

$new = $base;
$new['asset_id'] = $new_id;
$new['file_id'] = 'replacement-file';
$new['status'] = 'ready';
$new['availability_reason'] = '';

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array( $old_id => $old, $new_id => $new );

$before = array(
	'asset_id' => $old_id,
	'source_id' => $source_id,
	'original_file_id' => 'old-file',
	'status' => 'unavailable',
	'availability_reason' => 'not_seen_in_latest_scan',
	'replacement_asset_id' => '',
	'replacement_file_id' => '',
	'replacement_content_sha256' => '',
);

$result = MAD4B_SCP_Context_Authority::rollback_recreated_asset( $old_id, $new_id, $before );
mad4b_context_rollback_assert( ! is_wp_error( $result ), 'exact recreation lineage should roll back' );
$assets = MAD4B_SCP_Context_Authority::assets();
mad4b_context_rollback_assert( isset( $assets[ $old_id ] ), 'original asset must remain in registry' );
mad4b_context_rollback_assert( ! isset( $assets[ $new_id ] ), 'replacement asset must be removed from registry' );
mad4b_context_rollback_assert( 'unavailable' === $assets[ $old_id ]['status'], 'original asset must return to unavailable state' );
mad4b_context_rollback_assert( 'not_seen_in_latest_scan' === $assets[ $old_id ]['availability_reason'], 'original missing reason must be restored' );
mad4b_context_rollback_assert( empty( $assets[ $old_id ]['replacement_asset_id'] ), 'replacement lineage must be cleared after undo' );
mad4b_context_rollback_assert( ! empty( MAD4B_SCP_Audit::$events ), 'recreation rollback must be audited' );

echo "mad4b.site-control-plane.context-recreate-registry-rollback.runtime.v1: PASS\n";
