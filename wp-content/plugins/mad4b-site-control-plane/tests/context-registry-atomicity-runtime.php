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

class MAD4B_SCP_Audit {
	public static $ready = true;
	public static $fail_append = false;
	public static $events = array();
	public static function storage_status() { return array( 'ready' => self::$ready ); }
	public static function record( $ability, $summary = array(), $status = 'ok' ) {
		if ( self::$fail_append ) return new WP_Error( 'fixture_audit_append_failed', 'fixture audit append failed' );
		self::$events[] = array( 'ability' => (string) $ability, 'summary' => $summary, 'status' => (string) $status );
		return true;
	}
}

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

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ] = array(
	'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT,
	'site_uuid' => $site_uuid,
	'brand_id' => 'brand-fixture',
	'brand_name' => 'Fixture Brand',
	'revision' => 1,
	'status' => 'configured',
	'context_policy' => 'site_bound_governed_plus_task_sources',
	'context_fingerprint' => str_repeat( '0', 64 ),
	'last_verified_at' => '',
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

$before_upsert_sources = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ];
$before_upsert_revision = MAD4B_SCP_Context_Authority::registry_revision();
MAD4B_SCP_Audit::$fail_append = true;
$failed_upsert = MAD4B_SCP_Context_Authority::upsert_source(
	array(
		'provider' => 'google_drive',
		'mode' => 'governed',
		'external_root_id' => 'folder-audit-compensation',
		'label' => 'Audit Compensation Folder',
		'write_policy' => 'repair_only',
	)
);
mad4b_atomic_assert( is_wp_error( $failed_upsert ), 'Source upsert must fail when append-only audit commit fails.', $failed_upsert );
mad4b_atomic_assert( 'mad4b_context_registry_audit_commit_failed' === $failed_upsert->get_error_code(), 'Source upsert audit failure must expose compensated audit error.', $failed_upsert->get_error_code() );
mad4b_atomic_assert( $before_upsert_sources === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ], 'Source upsert audit failure must restore the exact pre-upsert source registry.' );
mad4b_atomic_assert( $before_upsert_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Source upsert audit failure must restore the pre-upsert registry revision.' );

MAD4B_SCP_Audit::$fail_append = false;
$successful_upsert = MAD4B_SCP_Context_Authority::upsert_source(
	array(
		'provider' => 'google_drive',
		'mode' => 'governed',
		'external_root_id' => 'folder-audited-success',
		'label' => 'Audited Source Folder',
		'write_policy' => 'repair_only',
	)
);
mad4b_atomic_assert( ! is_wp_error( $successful_upsert ), 'Healthy source upsert must commit with audit evidence.', $successful_upsert );
mad4b_atomic_assert( $before_upsert_revision + 1 === MAD4B_SCP_Context_Authority::registry_revision(), 'Healthy audited source upsert must advance registry revision exactly once.' );
$source_events = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $row ) { return 'mad4b/context-source-upsert' === $row['ability']; } ) );
mad4b_atomic_assert( 1 === count( $source_events ), 'Healthy source upsert must append exactly one governed audit event.', $source_events );
mad4b_atomic_assert( 'google_drive' === $source_events[0]['summary']['provider'] && 'repair_only' === $source_events[0]['summary']['write_policy'], 'Source upsert audit must bind provider and write policy.', $source_events[0] );

$before_profile = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ];
$before_profile_revision = MAD4B_SCP_Context_Authority::registry_revision();
MAD4B_SCP_Audit::$fail_append = true;
$failed_profile = MAD4B_SCP_Context_Authority::save_profile( 'Changed Fixture Brand' );
mad4b_atomic_assert( is_wp_error( $failed_profile ), 'Brand Context Profile save must fail when append-only audit commit fails.', $failed_profile );
mad4b_atomic_assert( 'mad4b_context_registry_audit_commit_failed' === $failed_profile->get_error_code(), 'Profile audit failure must expose compensated audit error.', $failed_profile->get_error_code() );
mad4b_atomic_assert( $before_profile === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ], 'Profile audit failure must restore exact prior Brand Context Profile.' );
mad4b_atomic_assert( $before_profile_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Profile audit failure must restore pre-save registry revision.' );

MAD4B_SCP_Audit::$fail_append = false;
$saved_profile = MAD4B_SCP_Context_Authority::save_profile( 'Changed Fixture Brand' );
mad4b_atomic_assert( ! is_wp_error( $saved_profile ), 'Healthy Brand Context Profile save must commit with audit evidence.', $saved_profile );
mad4b_atomic_assert( $before_profile_revision + 1 === MAD4B_SCP_Context_Authority::registry_revision(), 'Healthy audited profile save must advance registry revision exactly once.' );
$profile_events = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $row ) { return 'mad4b/context-profile-save' === $row['ability']; } ) );
mad4b_atomic_assert( 1 === count( $profile_events ), 'Healthy Brand Context Profile save must append exactly one governed audit event.', $profile_events );
mad4b_atomic_assert( 2 === (int) $profile_events[0]['summary']['revision'] && empty( $profile_events[0]['summary']['created'] ), 'Profile save audit must bind exact revision and update/create state.', $profile_events[0] );

echo "mad4b.site-control-plane.context-registry-atomicity.runtime.v3: PASS\n";
