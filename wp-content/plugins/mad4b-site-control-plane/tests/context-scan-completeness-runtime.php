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

class MAD4B_SCP_Audit {
	public static $events = array();
	public static function storage_status() { return array( 'ready' => true ); }
	public static function record( $ability, $summary = array(), $status = 'ok' ) {
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

// Unchanged provider versions must reuse the previously normalized governed
// asset instead of forcing another provider download/normalization round-trip.
$reuse_source_id = str_repeat( 'd', 64 );
$reuse_file_id = 'reuse-file-fixture';
$reuse_asset_id = hash( 'sha256', $reuse_source_id . '|' . $reuse_file_id );
$reuse_version = '2026-09-29T18:00:00Z';
$reuse_hash = hash( 'sha256', 'previous normalized provider content' );
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ][ $reuse_source_id ] = array(
	'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
	'source_id' => $reuse_source_id,
	'site_uuid' => $site_uuid,
	'brand_id' => 'brand-fixture',
	'provider' => 'google_drive',
	'mode' => 'governed',
	'external_root_id' => 'reuse-folder-fixture',
	'label' => 'Reuse Fixture',
	'task_scope' => '',
	'write_policy' => 'read_only',
	'recursive' => true,
	'status' => 'ready',
	'last_scan_complete' => true,
	'last_scan_generation' => str_repeat( '7', 64 ),
	'last_complete_scan_generation' => str_repeat( '7', 64 ),
	'last_complete_scan_at' => gmdate( 'c' ),
	'last_scan_truncation_reasons' => array(),
	'last_synced_at' => gmdate( 'c' ),
	'asset_count' => 1,
);
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ][ $reuse_asset_id ] = array(
	'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
	'asset_id' => $reuse_asset_id,
	'site_uuid' => $site_uuid,
	'brand_id' => 'brand-fixture',
	'source_id' => $reuse_source_id,
	'source_mode' => 'governed',
	'provider' => 'google_drive',
	'file_id' => $reuse_file_id,
	'parent_folder_id' => 'reuse-folder-fixture',
	'title' => 'Reusable Brand Context',
	'path' => 'Reuse Fixture/Reusable Brand Context',
	'mime_type' => 'text/plain',
	'version' => $reuse_version,
	'content_hash' => $reuse_hash,
	'content_complete' => true,
	'content_bytes' => 36,
	'content_available' => true,
	'content_excerpt' => 'previous normalized provider content',
	'category' => 'brand_strategy',
	'classification_confidence' => 1.0,
	'classification_source' => 'human',
	'authority_class' => 'brand_authority',
	'required' => true,
	'priority' => 100,
	'reviewed_by' => 1,
	'reviewed_at' => gmdate( 'c' ),
	'review_status' => 'approved',
	'reviewed_content_hash' => $reuse_hash,
	'review_decision' => 'approve',
	'review_note' => 'Preserve exact review on unchanged provider version.',
	'status' => 'ready',
);
$reuse_result = MAD4B_SCP_Context_Authority::replace_source_assets(
	$reuse_source_id,
	array(
		array(
			'file_id' => $reuse_file_id,
			'parent_folder_id' => 'reuse-folder-fixture',
			'title' => 'Reusable Brand Context',
			'path' => 'Reuse Fixture/Reusable Brand Context',
			'mimeType' => 'text/plain',
			'modifiedTime' => $reuse_version,
			'content_hash' => $reuse_hash,
			'content_complete' => true,
			'content_bytes' => 36,
			'normalization_status' => 'reused',
			'normalization_reason' => 'unchanged_provider_version',
			'reuse_existing' => true,
		),
	),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '8', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_scan_assert( ! is_wp_error( $reuse_result ), 'Unchanged provider asset reuse must commit.', $reuse_result );
mad4b_scan_assert( 1 === (int) $reuse_result['reused_asset_count'], 'Reuse result must expose exactly one reused asset.', $reuse_result );
$reused_assets = MAD4B_SCP_Context_Authority::assets();
mad4b_scan_assert( isset( $reused_assets[ $reuse_asset_id ] ), 'Reused asset must remain registered.', $reused_assets );
mad4b_scan_assert( $reuse_hash === $reused_assets[ $reuse_asset_id ]['content_hash'], 'Reused asset must preserve exact content hash.', $reused_assets[ $reuse_asset_id ] );
mad4b_scan_assert( 'approved' === $reused_assets[ $reuse_asset_id ]['review_status'], 'Reused asset must preserve exact review evidence.', $reused_assets[ $reuse_asset_id ] );
mad4b_scan_assert( 'reused' === $reused_assets[ $reuse_asset_id ]['normalization_status'], 'Reused asset must expose reuse normalization state.', $reused_assets[ $reuse_asset_id ] );
unset( $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ][ $reuse_asset_id ] );
unset( $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ][ $reuse_source_id ] );

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

$normalization_loss = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(
		array(
			'file_id' => '',
			'title' => 'Invalid observed asset',
			'mimeType' => 'text/plain',
			'normalized_text' => 'Observed but not representable.',
			'content_complete' => true,
		),
	),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '4', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_scan_assert( ! is_wp_error( $normalization_loss ), 'Normalization loss must persist as explicit partial scan state.', $normalization_loss );
mad4b_scan_assert( empty( $normalization_loss['scan_complete'] ), 'Unrepresentable observed asset must downgrade caller-complete scan to partial.', $normalization_loss );
mad4b_scan_assert( in_array( 'asset_normalization_failed', $normalization_loss['truncation_reasons'], true ), 'Normalization loss reason must be explicit.', $normalization_loss );
$assets = MAD4B_SCP_Context_Authority::assets();
$sources = MAD4B_SCP_Context_Authority::sources();
mad4b_scan_assert( 'ready' === $assets[ $asset_id ]['status'], 'Normalization-loss scan must not mint absence for unseen existing asset.', $assets[ $asset_id ] );
mad4b_scan_assert( empty( $assets[ $asset_id ]['absence_scan_generation'] ), 'Normalization-loss scan must not create absence generation.', $assets[ $asset_id ] );
mad4b_scan_assert( str_repeat( '1', 64 ) === $sources[ $source_id ]['last_complete_scan_generation'], 'Normalization-loss scan must preserve the last proven complete generation.', $sources[ $source_id ] );

// Fill the raw registry with hidden orphan records without exposing them through
// Context Authority. A new observed asset then cannot fit and must downgrade
// the scan to partial instead of silently omitting it.
for ( $i = 0; $i < MAD4B_SCP_Context_Authority::MAX_ASSETS - 1; ++$i ) {
	$hidden_id = hash( 'sha256', 'hidden-orphan-' . $i );
	$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ][ $hidden_id ] = array(
		'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
		'asset_id' => $hidden_id,
		'site_uuid' => $site_uuid,
		'brand_id' => 'brand-fixture',
		'source_id' => str_repeat( 'f', 64 ),
		'source_mode' => 'governed',
		'provider' => 'google_drive',
		'file_id' => 'hidden-file-' . $i,
		'title' => 'Hidden orphan',
		'status' => 'ready',
	);
}
mad4b_scan_assert( MAD4B_SCP_Context_Authority::MAX_ASSETS === count( $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] ), 'Capacity fixture must exactly fill raw asset storage.' );

$capacity_loss = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(
		array(
			'file_id' => 'new-observed-file',
			'parent_folder_id' => 'folder-fixture',
			'title' => 'New Observed Asset',
			'mimeType' => 'text/plain',
			'modifiedTime' => gmdate( 'c' ),
			'normalized_text' => 'New governed context.',
			'content_complete' => true,
			'content_bytes' => 21,
			'normalization_status' => 'ready',
			'content_hash' => hash( 'sha256', 'New governed context.' ),
		),
	),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '5', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_scan_assert( ! is_wp_error( $capacity_loss ), 'Registry capacity loss must persist as explicit partial scan state.', $capacity_loss );
mad4b_scan_assert( empty( $capacity_loss['scan_complete'] ), 'Insufficient raw registry capacity must downgrade complete scan.', $capacity_loss );
mad4b_scan_assert( in_array( 'registry_asset_capacity_limit', $capacity_loss['truncation_reasons'], true ), 'Registry capacity truncation reason must be explicit.', $capacity_loss );
mad4b_scan_assert( 1 === (int) $capacity_loss['observed_asset_count'] && 0 === (int) $capacity_loss['represented_asset_count'], 'Capacity loss must distinguish observed from represented asset counts.', $capacity_loss );
$assets = MAD4B_SCP_Context_Authority::assets();
$sources = MAD4B_SCP_Context_Authority::sources();
mad4b_scan_assert( 'ready' === $assets[ $asset_id ]['status'], 'Capacity-loss scan must not mark existing unseen asset unavailable.', $assets[ $asset_id ] );
mad4b_scan_assert( str_repeat( '1', 64 ) === $sources[ $source_id ]['last_complete_scan_generation'], 'Capacity-loss scan must not replace complete-scan evidence.', $sources[ $source_id ] );

$complete = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '6', 64 ),
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
mad4b_scan_assert( str_repeat( '6', 64 ) === $assets[ $asset_id ]['absence_scan_generation'], 'Absence evidence must bind the exact complete scan generation.', $assets[ $asset_id ] );
mad4b_scan_assert( str_repeat( '6', 64 ) === $sources[ $source_id ]['last_complete_scan_generation'], 'Source must retain exact complete scan generation.', $sources[ $source_id ] );

$scan_events = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $row ) { return 'mad4b/context-source-scan' === $row['ability']; } ) );
mad4b_scan_assert( 5 === count( $scan_events ), 'Every committed source scan, including exact-version reuse, must append one bounded governance audit event.', $scan_events );
$reuse_scan_events = array_values( array_filter( $scan_events, static function ( $row ) use ( $reuse_source_id ) {
	return isset( $row['summary']['source_id'] ) && hash_equals( $reuse_source_id, (string) $row['summary']['source_id'] );
} ) );
mad4b_scan_assert( 1 === count( $reuse_scan_events ), 'Exact-version reuse must append exactly one source-scan audit event.', $reuse_scan_events );
mad4b_scan_assert( 'ok' === $reuse_scan_events[0]['status'] && 1 === (int) $reuse_scan_events[0]['summary']['reused_asset_count'], 'Reuse audit must preserve explicit reused-asset evidence.', $reuse_scan_events[0] );
$legacy_scan_events = array_values( array_filter( $scan_events, static function ( $row ) use ( $source_id ) {
	return isset( $row['summary']['source_id'] ) && hash_equals( $source_id, (string) $row['summary']['source_id'] );
} ) );
mad4b_scan_assert( 4 === count( $legacy_scan_events ), 'Legacy completeness scenarios must retain their four bounded source-scan audit events.', $legacy_scan_events );
mad4b_scan_assert( 'partial' === $legacy_scan_events[0]['status'] && empty( $legacy_scan_events[0]['summary']['scan_complete'] ), 'Partial source scan must remain explicit in audit evidence.', $legacy_scan_events[0] );
mad4b_scan_assert( 'ok' === $legacy_scan_events[3]['status'] && ! empty( $legacy_scan_events[3]['summary']['scan_complete'] ), 'Complete source scan must be recorded as complete audit evidence.', $legacy_scan_events[3] );
mad4b_scan_assert( ! array_key_exists( 'content', $legacy_scan_events[3]['summary'] ) && ! array_key_exists( 'file_id', $legacy_scan_events[3]['summary'] ), 'Scan audit summary must not contain raw Context content or provider file IDs.', $legacy_scan_events[3] );


// End-to-end regression for the exact live Brand Context path:
// provider inventory -> source_scan_plan -> source_scan_apply -> registry revision +1.
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
		'last_scan_generation' => str_repeat( '6', 64 ),
		'last_complete_scan_generation' => str_repeat( '6', 64 ),
		'last_complete_scan_at' => '2026-09-28T04:00:00Z',
		'last_scan_truncation_reasons' => array(),
		'last_synced_at' => '2026-09-28T04:00:00Z',
		'asset_count' => 0,
	),
);
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array();
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ] = 20;

class MAD4B_SCP_Context_Provider_Gateway {
	public static $complete = true;
	public static $assets = null;
	public static function scan_source( $requested_source_id ) {
		$assets = is_array( self::$assets ) ? self::$assets : array(
			array(
				'file_id' => 'drive-new-tone-of-voice',
				'parent_folder_id' => 'folder-fixture',
				'title' => 'Egypt Tour Gates - Tone of Voice.md',
				'mimeType' => 'text/markdown',
				'modifiedTime' => '2026-09-28T04:00:30Z',
				'webViewLink' => 'https://docs.google.com/document/d/drive-new-tone-of-voice/edit',
				'normalized_text' => 'Evidence-bound tone of voice regression fixture.',
				'content_complete' => true,
				'content_bytes' => 49,
				'normalization_status' => 'ready',
				'content_hash' => hash( 'sha256', 'Evidence-bound tone of voice regression fixture.' ),
				'appProperties' => array( 'mad4b_kind' => 'brand_context' ),
			),
		);
		return array(
			'complete' => (bool) self::$complete,
			'scan_generation' => hash( 'sha256', self::$complete ? 'brand-source-scan-regression' : 'brand-source-scan-partial-regression' ),
			'started_at' => '2026-09-28T04:01:00Z',
			'completed_at' => '2026-09-28T04:01:01Z',
			'truncation_reasons' => self::$complete ? array() : array( 'scan_time_budget', 'scan_queue_incomplete' ),
			'assets' => $assets,
		);
	}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-context-builder.php';

$scan_plan = MAD4B_SCP_Brand_Context_Builder::source_scan_plan( array( 'source_id' => $source_id ) );
mad4b_scan_assert( ! is_wp_error( $scan_plan ), 'End-to-end source scan plan must succeed.', $scan_plan );
mad4b_scan_assert( in_array( 'drive-new-tone-of-voice', $scan_plan['new_files'], true ), 'New provider file must be visible in the exact scan plan.', $scan_plan );
$before_apply_revision = MAD4B_SCP_Context_Authority::registry_revision();
$scan_apply = MAD4B_SCP_Brand_Context_Builder::source_scan_apply(
	array(
		'source_id' => $source_id,
		'expected_plan_sha256' => $scan_plan['plan_sha256'],
		'expected_provider_inventory_digest' => $scan_plan['provider_inventory_digest'],
		'expected_registry_revision' => $scan_plan['registry_revision'],
	)
);
mad4b_scan_assert( ! is_wp_error( $scan_apply ), 'End-to-end source scan apply must commit the observed provider file.', $scan_apply );
mad4b_scan_assert( $before_apply_revision + 1 === MAD4B_SCP_Context_Authority::registry_revision(), 'Successful source scan apply must advance registry revision exactly once.' );
$registered = array_values( array_filter( MAD4B_SCP_Context_Authority::assets(), static function ( $asset ) {
	return is_array( $asset ) && isset( $asset['file_id'] ) && 'drive-new-tone-of-voice' === (string) $asset['file_id'];
} ) );
mad4b_scan_assert( 1 === count( $registered ), 'End-to-end source scan apply must register the newly observed Drive asset exactly once.', $registered );

// A bounded partial provider scan may now commit only what was actually observed.
// Context Authority still forbids absence evidence until a later complete scan.
MAD4B_SCP_Context_Provider_Gateway::$complete = false;
MAD4B_SCP_Context_Provider_Gateway::$assets = array(
	array(
		'file_id' => 'drive-partial-brand-strategy',
		'parent_folder_id' => 'folder-fixture',
		'title' => 'All Royal Egypt - Positioning Direction.md',
		'mimeType' => 'text/markdown',
		'modifiedTime' => '2026-09-28T04:02:30Z',
		'webViewLink' => 'https://docs.google.com/document/d/drive-partial-brand-strategy/edit',
		'normalized_text' => 'Partial-scan brand strategy evidence.',
		'content_complete' => true,
		'content_bytes' => 37,
		'normalization_status' => 'ready',
		'content_hash' => hash( 'sha256', 'Partial-scan brand strategy evidence.' ),
		'appProperties' => array( 'mad4b_kind' => 'brand_context' ),
	),
);
$partial_plan = MAD4B_SCP_Brand_Context_Builder::source_scan_plan( array( 'source_id' => $source_id ) );
mad4b_scan_assert( ! is_wp_error( $partial_plan ) && empty( $partial_plan['scan_complete'] ), 'Partial source scan plan must remain explicit and exact-bound.', $partial_plan );
$partial_before_revision = MAD4B_SCP_Context_Authority::registry_revision();
$partial_apply = MAD4B_SCP_Brand_Context_Builder::source_scan_apply(
	array(
		'source_id' => $source_id,
		'expected_plan_sha256' => $partial_plan['plan_sha256'],
		'expected_provider_inventory_digest' => $partial_plan['provider_inventory_digest'],
		'expected_registry_revision' => $partial_plan['registry_revision'],
	)
);
mad4b_scan_assert( ! is_wp_error( $partial_apply ), 'Exact partial source scan apply must commit observed assets.', $partial_apply );
mad4b_scan_assert( ! empty( $partial_apply['continuation_required'] ) && 'continue_source_scan' === $partial_apply['next_action'], 'Partial source scan apply must expose deterministic continuation.', $partial_apply );
mad4b_scan_assert( $partial_before_revision + 1 === MAD4B_SCP_Context_Authority::registry_revision(), 'Partial source scan apply must advance registry revision exactly once.' );
$partial_assets = MAD4B_SCP_Context_Authority::assets();
$prior_tone = array_values( array_filter( $partial_assets, static function ( $asset ) {
	return is_array( $asset ) && isset( $asset['file_id'] ) && 'drive-new-tone-of-voice' === (string) $asset['file_id'];
} ) );
$partial_brand = array_values( array_filter( $partial_assets, static function ( $asset ) {
	return is_array( $asset ) && isset( $asset['file_id'] ) && 'drive-partial-brand-strategy' === (string) $asset['file_id'];
} ) );
mad4b_scan_assert( 1 === count( $prior_tone ) && 'ready' === $prior_tone[0]['status'], 'Partial apply must not mark an unseen previously registered asset unavailable.', $prior_tone );
mad4b_scan_assert( 1 === count( $partial_brand ), 'Partial apply must register the newly observed asset exactly once.', $partial_brand );
$partial_sources = MAD4B_SCP_Context_Authority::sources();
mad4b_scan_assert( 'partial_scan' === $partial_sources[ $source_id ]['status'] && empty( $partial_sources[ $source_id ]['last_scan_complete'] ), 'Partial apply must preserve explicit incomplete source state.', $partial_sources[ $source_id ] );

echo "mad4b.site-control-plane.context-scan-completeness.runtime.v4: PASS\n";
