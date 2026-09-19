<?php

define( 'ABSPATH', '/tmp/mad4b-context-recreate-rollback/' );
define( 'DAY_IN_SECONDS', 86400 );

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
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_trim_words( $value, $num_words = 55, $more = null ) {
	$words = preg_split( '/\s+/u', trim( strip_tags( (string) $value ) ), -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $words ) <= $num_words ) return implode( ' ', $words );
	return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( null === $more ? '…' : $more );
}
function esc_url_raw( $value ) { return (string) $value; }

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
$old['parent_folder_id'] = 'folder-fixture';
$old['status'] = 'unavailable';
$old['availability_reason'] = 'not_seen_in_latest_scan';
$old['replacement_asset_id'] = '';

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array( $old_id => $old );

$provider_asset = array(
	'file_id' => 'replacement-file',
	'parent_folder_id' => 'folder-fixture',
	'title' => 'Tone of Voice',
	'path' => 'Brand Core/Tone of Voice',
	'mimeType' => 'text/plain',
	'modifiedTime' => gmdate( 'c' ),
	'webViewLink' => 'https://drive.example/replacement-file',
	'normalized_text' => "Brand tone guidance with clear terminology and structured editorial rules.\n\n# Voice\nUse precise language and consistent terminology.",
	'content_hash' => hash( 'sha256', "Brand tone guidance with clear terminology and structured editorial rules.\n\n# Voice\nUse precise language and consistent terminology." ),
);
$transition = MAD4B_SCP_Context_Authority::register_recreated_asset( $old_id, $source_id, $provider_asset, $old );
mad4b_context_rollback_assert( ! is_wp_error( $transition ), 'unavailable original and provider replacement must commit atomically' );
mad4b_context_rollback_assert( ! empty( $transition['replacement']['asset_id'] ), 'atomic recreation must return replacement identity' );
$new_id = (string) $transition['replacement']['asset_id'];
$after_register = MAD4B_SCP_Context_Authority::assets();
mad4b_context_rollback_assert( 'recreated' === $after_register[ $old_id ]['status'], 'atomic recreation must mark original recreated' );
mad4b_context_rollback_assert( hash_equals( $new_id, (string) $after_register[ $old_id ]['replacement_asset_id'] ), 'original must bind exact replacement identity' );
mad4b_context_rollback_assert( isset( $after_register[ $new_id ] ) && 'ready' === $after_register[ $new_id ]['status'], 'replacement must become ready in same registry transition' );

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

$intent = MAD4B_SCP_Context_Authority::begin_recreated_asset_rollback( $old_id, $new_id );
mad4b_context_rollback_assert( ! is_wp_error( $intent ), 'rollback intent must persist before provider deletion' );
$pending = MAD4B_SCP_Context_Authority::assets();
mad4b_context_rollback_assert( 'rollback_pending' === $pending[ $old_id ]['status'], 'original must enter rollback_pending before deletion' );
mad4b_context_rollback_assert( 'rollback_pending' === $pending[ $new_id ]['status'], 'replacement must enter rollback_pending before deletion' );

$result = MAD4B_SCP_Context_Authority::rollback_recreated_asset( $old_id, $new_id, $before );
mad4b_context_rollback_assert( ! is_wp_error( $result ), 'exact recreation lineage should finalize after persisted rollback intent' );
$assets = MAD4B_SCP_Context_Authority::assets();
mad4b_context_rollback_assert( isset( $assets[ $old_id ] ), 'original asset must remain in registry' );
mad4b_context_rollback_assert( ! isset( $assets[ $new_id ] ), 'replacement asset must be removed from registry' );
mad4b_context_rollback_assert( 'unavailable' === $assets[ $old_id ]['status'], 'original asset must return to unavailable state' );
mad4b_context_rollback_assert( 'not_seen_in_latest_scan' === $assets[ $old_id ]['availability_reason'], 'original missing reason must be restored' );
mad4b_context_rollback_assert( empty( $assets[ $old_id ]['replacement_asset_id'] ), 'replacement lineage must be cleared after undo' );
mad4b_context_rollback_assert( ! empty( MAD4B_SCP_Audit::$events ), 'recreation rollback must be audited' );

echo "mad4b.site-control-plane.context-recreate-registry-rollback.runtime.v2: PASS\n";
