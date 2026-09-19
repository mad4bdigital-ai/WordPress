<?php

define( 'ABSPATH', '/tmp/mad4b-context-scan-completeness/' );
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
function add_option( $name, $value ) { if ( array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false; $GLOBALS['mad4b_context_options'][ $name ] = $value; return true; }
function update_option( $name, $value ) { $GLOBALS['mad4b_context_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['mad4b_context_options'][ $name ] ); return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_trim_words( $value, $num_words = 55, $more = null ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }

class MAD4B_SCP_Site_Profile {
	public static function status() {
		return array(
			'configured' => true,
			'origin_match' => true,
			'environment_match' => true,
			'site_uuid' => '11111111-1111-4111-8111-111111111111',
			'environment' => 'staging',
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_scan_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$source_id = str_repeat( 'a', 64 );
$asset_id = str_repeat( 'b', 64 );
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
		'last_scan_complete' => true,
		'last_scan_generation' => str_repeat( '1', 64 ),
		'last_complete_scan_generation' => str_repeat( '1', 64 ),
		'last_complete_scan_at' => gmdate( 'c' ),
		'last_scan_truncation_reasons' => array(),
		'last_synced_at' => gmdate( 'c' ),
		'asset_count' => 1,
	),
);
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array(
	$asset_id => array(
		'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
		'asset_id' => $asset_id,
		'site_uuid' => $site_uuid,
		'brand_id' => 'brand-fixture',
		'source_id' => $source_id,
		'source_mode' => 'governed',
		'provider' => 'google_drive',
		'file_id' => 'file-fixture',
		'parent_folder_id' => 'folder-fixture',
		'title' => 'Tone of Voice',
		'mime_type' => 'text/plain',
		'content_hash' => str_repeat( 'c', 64 ),
		'content_complete' => true,
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'priority' => 100,
		'review_status' => 'approved',
		'status' => 'ready',
	),
);

$partial = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => false,
		'scan_generation' => str_repeat( '2', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array( 'scan_file_limit' ),
	)
);
mad4b_scan_assert( ! is_wp_error( $partial ), 'Partial scan must persist bounded source state.', $partial );
$assets = MAD4B_SCP_Context_Authority::assets();
$sources = MAD4B_SCP_Context_Authority::sources();
mad4b_scan_assert( 'ready' === $assets[ $asset_id ]['status'], 'Partial scan must never mark an unseen asset unavailable.', $assets[ $asset_id ] );
mad4b_scan_assert( empty( $assets[ $asset_id ]['absence_scan_generation'] ), 'Partial scan must not mint absence evidence.', $assets[ $asset_id ] );
mad4b_scan_assert( 'partial_scan' === $sources[ $source_id ]['status'], 'Partial scan must remain explicit on the source.', $sources[ $source_id ] );
mad4b_scan_assert( str_repeat( '1', 64 ) === $sources[ $source_id ]['last_complete_scan_generation'], 'Partial scan must not replace the last complete scan generation.', $sources[ $source_id ] );

$complete = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '3', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_scan_assert( ! is_wp_error( $complete ), 'Complete scan must persist.', $complete );
$assets = MAD4B_SCP_Context_Authority::assets();
$sources = MAD4B_SCP_Context_Authority::sources();
mad4b_scan_assert( 'unavailable' === $assets[ $asset_id ]['status'], 'Only complete scan may mark unseen asset unavailable.', $assets[ $asset_id ] );
mad4b_scan_assert( 'not_seen_in_complete_scan' === $assets[ $asset_id ]['availability_reason'], 'Complete-scan missing reason must be explicit.', $assets[ $asset_id ] );
mad4b_scan_assert( str_repeat( '3', 64 ) === $assets[ $asset_id ]['absence_scan_generation'], 'Absence evidence must bind the exact complete scan generation.', $assets[ $asset_id ] );
mad4b_scan_assert( str_repeat( '3', 64 ) === $sources[ $source_id ]['last_complete_scan_generation'], 'Source must retain exact complete scan generation.', $sources[ $source_id ] );

echo "mad4b.site-control-plane.context-scan-completeness.runtime.v1: PASS\n";
