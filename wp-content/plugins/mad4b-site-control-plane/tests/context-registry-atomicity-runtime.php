<?php

define( 'ABSPATH', '/tmp/mad4b-context-registry-atomicity/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['mad4b_context_options'] = array();
$GLOBALS['mad4b_fail_option'] = '';

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
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ? $GLOBALS['mad4b_context_options'][ $name ] : $default; }
function add_option( $name, $value ) {
	if ( $GLOBALS['mad4b_fail_option'] === $name ) return false;
	if ( array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	if ( $GLOBALS['mad4b_fail_option'] === $name ) return false;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return true;
}
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

function mad4b_atomic_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$source_id = str_repeat( 'a', 64 );
$asset_id = str_repeat( 'b', 64 );
$site_uuid = '11111111-1111-4111-8111-111111111111';
$source = array(
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
);
$asset = array(
	'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
	'asset_id' => $asset_id,
	'site_uuid' => $site_uuid,
	'brand_id' => 'brand-fixture',
	'source_id' => $source_id,
	'source_mode' => 'governed',
	'provider' => 'google_drive',
	'file_id' => 'file-fixture',
	'parent_folder_id' => 'folder-fixture',
	'title' => 'Tone',
	'mime_type' => 'text/plain',
	'content_hash' => str_repeat( 'c', 64 ),
	'content_complete' => true,
	'category' => 'tone_of_voice',
	'authority_class' => 'brand_authority',
	'required' => true,
	'priority' => 100,
	'review_status' => 'approved',
	'status' => 'ready',
);

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = array( $source_id => $source );
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array( $asset_id => $asset );
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ] = 7;

$baseline_sources = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ];
$baseline_assets = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ];

$GLOBALS['mad4b_fail_option'] = MAD4B_SCP_Context_Authority::SOURCES_OPTION;
$failed_second_write = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '2', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_atomic_assert( is_wp_error( $failed_second_write ), 'Second option write failure must fail the registry mutation.', $failed_second_write );
mad4b_atomic_assert( $baseline_assets === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ], 'Applied asset option must be compensated when source option persistence fails.' );
mad4b_atomic_assert( $baseline_sources === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ], 'Failed source option must remain unchanged.' );
mad4b_atomic_assert( 7 === (int) $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ], 'Failed mutation must not advance registry revision.' );

$GLOBALS['mad4b_fail_option'] = MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION;
$failed_revision = MAD4B_SCP_Context_Authority::replace_source_assets(
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
mad4b_atomic_assert( is_wp_error( $failed_revision ), 'Revision persistence failure must fail the mutation.', $failed_revision );
mad4b_atomic_assert( 'mad4b_context_registry_revision_write_failed' === $failed_revision->get_error_code(), 'Revision failure must expose canonical error.', $failed_revision->get_error_code() );
mad4b_atomic_assert( $baseline_assets === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ], 'Revision failure must restore asset snapshot.' );
mad4b_atomic_assert( $baseline_sources === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ], 'Revision failure must restore source snapshot.' );
mad4b_atomic_assert( 7 === (int) $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ], 'Revision failure compensation must preserve previous revision.' );

$GLOBALS['mad4b_fail_option'] = '';
$success = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '4', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_atomic_assert( ! is_wp_error( $success ), 'Healthy registry mutation must commit.' );
mad4b_atomic_assert( 8 === (int) $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ], 'Healthy mutation must advance registry revision exactly once.' );
$after = MAD4B_SCP_Context_Authority::assets();
mad4b_atomic_assert( 'unavailable' === $after[ $asset_id ]['status'], 'Committed complete scan must expose its intended asset state.' );

echo "mad4b.site-control-plane.context-registry-atomicity.runtime.v1: PASS\n";
