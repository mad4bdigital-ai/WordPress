<?php

define( 'ABSPATH', '/tmp/mad4b-context-human-review/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['mad4b_context_options'] = array();
$GLOBALS['mad4b_context_audit'] = array();

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
function get_current_user_id() { return 42; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_trim_words( $text, $num_words = 55, $more = null ) {
	$words = preg_split( '/\s+/u', trim( strip_tags( (string) $text ) ), -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $words ) <= $num_words ) return implode( ' ', $words );
	return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( null === $more ? '…' : $more );
}
function wp_generate_uuid4() { static $i = 0; ++$i; return sprintf( '11111111-1111-4111-8111-%012d', $i ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ? $GLOBALS['mad4b_context_options'][ $name ] : $default; }
function add_option( $name, $value ) {
	if ( array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	$changed = ! array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) || $GLOBALS['mad4b_context_options'][ $name ] !== $value;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return $changed;
}
function delete_option( $name ) {
	if ( ! array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	unset( $GLOBALS['mad4b_context_options'][ $name ] );
	return true;
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

class MAD4B_SCP_Audit {
	public static $fail_append = false;
	public static function storage_status() { return array( 'ready' => true ); }
	public static function record( $event, $data = array(), $status = 'ok' ) {
		if ( self::$fail_append ) return new WP_Error( 'fixture_audit_append_failed', 'Injected append-only audit failure.' );
		$GLOBALS['mad4b_context_audit'][] = array( 'event' => $event, 'data' => $data, 'status' => $status );
		return array( 'event' => $event, 'status' => $status );
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_review_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$site_uuid = '11111111-1111-4111-8111-111111111111';
$source_id = str_repeat( 'a', 64 );
$file_id = 'tone-file-001';
$asset_id = hash( 'sha256', $source_id . '|' . $file_id );

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ] = array(
	'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT,
	'site_uuid' => $site_uuid,
	'brand_id' => 'brand-fixture',
	'brand_name' => 'Fixture Brand',
	'revision' => 1,
	'status' => 'configured',
	'context_policy' => 'site_bound_governed_plus_task_sources',
	'context_fingerprint' => str_repeat( '0', 64 ),
	'authority_manifest_fingerprint' => str_repeat( '0', 64 ),
	'last_verified_at' => '',
	'created_at' => gmdate( 'c' ),
	'updated_at' => gmdate( 'c' ),
);
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = array(
	$source_id => array(
		'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
		'source_id' => $source_id,
		'site_uuid' => $site_uuid,
		'brand_id' => 'brand-fixture',
		'provider' => 'google_drive',
		'mode' => 'governed',
		'write_policy' => 'repair_only',
		'external_root_id' => 'folder-brand-core',
		'label' => 'Brand Core',
		'task_scope' => '',
		'recursive' => true,
		'status' => 'selected',
		'last_synced_at' => '',
		'last_scan_complete' => false,
		'asset_count' => 0,
		'created_at' => gmdate( 'c' ),
		'updated_at' => gmdate( 'c' ),
	),
);

function mad4b_review_asset_payload( $file_id, $content, $title = 'Voice Reference Notes' ) {
	return array(
		'file_id' => $file_id,
		'parent_folder_id' => 'folder-brand-core',
		'title' => $title,
		'path' => 'Brand Core/Voice Reference Notes.txt',
		'mimeType' => 'text/plain',
		'modifiedTime' => gmdate( 'c' ),
		'normalized_text' => $content,
		'content_complete' => true,
		'content_bytes' => strlen( $content ),
		'normalization_status' => 'ready',
	);
}

$initial_text = str_repeat(
	"Brand voice guidance. Keep claims precise, useful, and consistent. Approved terminology applies across channels.\n\n",
	12
);
$scan1 = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(
		mad4b_review_asset_payload( $file_id, $initial_text ),
		mad4b_review_asset_payload( 'writer-file-optional', str_repeat( "Writer reference sample with narrative structure and sentence rhythm.\n\n", 10 ), 'Writer Reference Sample' ),
	),
	array(
		'complete' => true,
		'started_at' => '2026-09-19T18:00:00Z',
		'completed_at' => '2026-09-19T18:00:03Z',
		'scan_generation' => str_repeat( '1', 64 ),
	)
);
mad4b_review_assert( ! is_wp_error( $scan1 ), 'Initial complete scan must succeed.', $scan1 );

$review = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	array(
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'quality_mode' => 'manual',
		'quality_score' => '97',
	)
);
mad4b_review_assert( ! is_wp_error( $review ), 'Human review must succeed.', $review );
mad4b_review_assert( 'human' === $review['classification_source'], 'Human review must become classification authority.', $review );
mad4b_review_assert( 'approved' === $review['review_status'], 'Human review must approve the asset.', $review );
mad4b_review_assert( 97 === (int) $review['quality_score'], 'Human quality override must be persisted.', $review );
mad4b_review_assert( ! empty( $review['quality']['human_override'] ), 'Human quality override marker must be explicit.', $review );

$review_fingerprint = MAD4B_SCP_Context_Authority::authority_manifest_fingerprint();

$scan2 = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(
		mad4b_review_asset_payload( $file_id, $initial_text ),
		mad4b_review_asset_payload( 'writer-file-optional', str_repeat( "Writer reference sample with narrative structure and sentence rhythm.\n\n", 10 ), 'Writer Reference Sample' ),
	),
	array(
		'complete' => true,
		'started_at' => '2026-09-19T18:01:00Z',
		'completed_at' => '2026-09-19T18:01:03Z',
		'scan_generation' => str_repeat( '2', 64 ),
	)
);
mad4b_review_assert( ! is_wp_error( $scan2 ), 'Same-content rescan must succeed.', $scan2 );
$same = MAD4B_SCP_Context_Authority::asset( $asset_id );
mad4b_review_assert( 'human' === $same['classification_source'], 'Human classification must survive same-hash rescan.', $same );
mad4b_review_assert( 'tone_of_voice' === $same['category'], 'Human category must survive same-hash rescan.', $same );
mad4b_review_assert( 'brand_authority' === $same['authority_class'], 'Human authority class must survive same-hash rescan.', $same );
mad4b_review_assert( ! empty( $same['required'] ), 'Human required flag must survive same-hash rescan.', $same );
mad4b_review_assert( 'approved' === $same['review_status'], 'Same-hash rescan must preserve approval.', $same );
mad4b_review_assert( 97 === (int) $same['quality_score'] && ! empty( $same['quality']['human_override'] ), 'Same-hash rescan must preserve human quality override.', $same );
mad4b_review_assert( hash_equals( $review_fingerprint, MAD4B_SCP_Context_Authority::authority_manifest_fingerprint() ), 'Same-hash rescan must not drift the authority manifest solely because of provider refresh.' );

$changed_text = $initial_text . "\nNew provider content changes the governed asset body and therefore requires renewed review.";
$scan3 = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array( mad4b_review_asset_payload( $file_id, $changed_text ) ),
	array(
		'complete' => true,
		'started_at' => '2026-09-19T18:02:00Z',
		'completed_at' => '2026-09-19T18:02:03Z',
		'scan_generation' => str_repeat( '3', 64 ),
	)
);
mad4b_review_assert( ! is_wp_error( $scan3 ), 'Changed-content rescan must succeed.', $scan3 );
$changed = MAD4B_SCP_Context_Authority::asset( $asset_id );
mad4b_review_assert( 'human' === $changed['classification_source'], 'Human classification decision must remain visible after content changes.', $changed );
mad4b_review_assert( 'tone_of_voice' === $changed['category'] && 'brand_authority' === $changed['authority_class'], 'Human authority metadata must remain stable after content changes.', $changed );
mad4b_review_assert( 'needs_review_content_changed' === $changed['review_status'], 'Changed content must invalidate approval.', $changed );
mad4b_review_assert( empty( $changed['quality']['human_override'] ), 'Changed content must not reuse a prior human quality override.', $changed );

$status = MAD4B_SCP_Context_Authority::status();
mad4b_review_assert( empty( $status['ready'] ), 'Context Authority must fail closed until changed mandatory content is reviewed again.', $status );
mad4b_review_assert( in_array( 'mandatory_context_review_required', $status['blockers'], true ), 'Changed mandatory content must surface review blocker.', $status['blockers'] );
mad4b_review_assert( ! hash_equals( $review_fingerprint, MAD4B_SCP_Context_Authority::authority_manifest_fingerprint() ), 'Review invalidation must change the authority manifest fingerprint.' );

$automatic_review = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	array(
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'quality_mode' => 'automatic',
		'quality_score' => '',
	)
);
mad4b_review_assert( ! is_wp_error( $automatic_review ), 'Reviewer must be able to return a previously overridden asset to automatic scoring.', $automatic_review );
mad4b_review_assert( 'approved' === $automatic_review['review_status'], 'Automatic-score review must approve the current changed content.', $automatic_review );
mad4b_review_assert( empty( $automatic_review['quality']['human_override'] ), 'Automatic-score review must clear the prior human quality override.', $automatic_review );
mad4b_review_assert( (int) $automatic_review['quality_auto_score'] === (int) $automatic_review['quality_score'], 'Automatic-score review must restore the current computed score.', $automatic_review );
mad4b_review_assert( 'human_override' !== ( isset( $automatic_review['quality']['mode'] ) ? (string) $automatic_review['quality']['mode'] : '' ), 'Automatic-score review must restore automatic scoring semantics.', $automatic_review );

// A governed provider mutation is not a rescan, but it changes the same content
// authority. It must therefore invalidate human content approval exactly like a
// changed-content scan while preserving the human classification decision.
$provider_write_text = $changed_text . "\nGoverned provider update changes the approved body and requires a new review.";
$before_provider_write = MAD4B_SCP_Context_Authority::asset( $asset_id );
$provider_upsert = MAD4B_SCP_Context_Authority::upsert_asset_from_provider(
	$source_id,
	mad4b_review_asset_payload( $file_id, $provider_write_text ),
	$before_provider_write
);
mad4b_review_assert( ! is_wp_error( $provider_upsert ), 'Governed provider update registry refresh must succeed.', $provider_upsert );
mad4b_review_assert( 'human' === $provider_upsert['classification_source'], 'Provider update must preserve human classification authority.', $provider_upsert );
mad4b_review_assert( 'tone_of_voice' === $provider_upsert['category'] && 'brand_authority' === $provider_upsert['authority_class'], 'Provider update must preserve reviewed governance metadata.', $provider_upsert );
mad4b_review_assert( ! empty( $provider_upsert['required'] ), 'Provider update must preserve the required-context decision.', $provider_upsert );
mad4b_review_assert( 'needs_review_content_changed' === $provider_upsert['review_status'], 'Provider content mutation must invalidate human content approval.', $provider_upsert );
mad4b_review_assert( empty( $provider_upsert['reviewed_at'] ) && empty( $provider_upsert['reviewed_by'] ), 'Invalidated provider content must not retain reviewer identity/timestamp as current approval evidence.', $provider_upsert );
mad4b_review_assert( empty( $provider_upsert['quality']['human_override'] ), 'Provider content mutation must not inherit a manual quality override from different content.', $provider_upsert );

$provider_write_status = MAD4B_SCP_Context_Authority::status();
mad4b_review_assert( empty( $provider_write_status['ready'] ), 'Mandatory Context must fail closed after governed provider content mutation until renewed review.', $provider_write_status );
mad4b_review_assert( in_array( 'mandatory_context_review_required', $provider_write_status['blockers'], true ), 'Governed provider content mutation must surface mandatory review blocker.', $provider_write_status['blockers'] );

$provider_rereview = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	array(
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'quality_mode' => 'automatic',
		'quality_score' => '',
	)
);
mad4b_review_assert( ! is_wp_error( $provider_rereview ), 'Reviewer must be able to approve the exact provider-mutated content.', $provider_rereview );
mad4b_review_assert( 'approved' === $provider_rereview['review_status'], 'Renewed review must approve the provider-mutated content.', $provider_rereview );

$ready_status = MAD4B_SCP_Context_Authority::status();
mad4b_review_assert( ! empty( $ready_status['ready'] ), 'Unavailable optional reference must not block mandatory Brand Context readiness.', $ready_status );
mad4b_review_assert( 'ready_with_warnings' === $ready_status['state'], 'Optional unavailable context must produce ready_with_warnings, not blocked.', $ready_status );
mad4b_review_assert( ! in_array( 'mandatory_context_review_required', $ready_status['blockers'], true ), 'Renewed review must clear the mandatory review blocker.', $ready_status['blockers'] );
mad4b_review_assert( in_array( 'optional_context_contains_unavailable_assets', $ready_status['warnings'], true ), 'Missing optional writer reference must remain visible as a warning.', $ready_status['warnings'] );
mad4b_review_assert( 1 === (int) $ready_status['optional_unavailable_asset_count'], 'Exactly one optional unavailable asset must be reported.', $ready_status );

$before_failed_review_asset = MAD4B_SCP_Context_Authority::asset( $asset_id );
$before_failed_review_revision = MAD4B_SCP_Context_Authority::registry_revision();
MAD4B_SCP_Audit::$fail_append = true;
$failed_audit_review = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	array(
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'quality_mode' => 'manual',
		'quality_score' => '88',
	)
);
MAD4B_SCP_Audit::$fail_append = false;
mad4b_review_assert( is_wp_error( $failed_audit_review ), 'Audit append failure must fail the governance mutation.', $failed_audit_review );
mad4b_review_assert( 'mad4b_context_registry_audit_commit_failed' === $failed_audit_review->get_error_code(), 'Audit append failure must expose compensated registry error.', $failed_audit_review->get_error_code() );
mad4b_review_assert( $before_failed_review_asset === MAD4B_SCP_Context_Authority::asset( $asset_id ), 'Audit failure must restore the exact pre-review asset state.' );
mad4b_review_assert( $before_failed_review_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Audit failure must restore the exact pre-review registry revision.' );

$review_events = array_values( array_filter( $GLOBALS['mad4b_context_audit'], static function ( $row ) { return 'mad4b/context-asset-review' === $row['event']; } ) );
mad4b_review_assert( 3 === count( $review_events ), 'Initial review, changed-content reset review, and provider-mutation renewed review must each emit one audit event.', $review_events );
mad4b_review_assert( 'manual' === $review_events[0]['data']['quality_mode'], 'First review audit must record manual quality mode.', $review_events[0] );
mad4b_review_assert( 'automatic' === $review_events[1]['data']['quality_mode'], 'Second review audit must record automatic quality mode.', $review_events[1] );
mad4b_review_assert( 'automatic' === $review_events[2]['data']['quality_mode'], 'Provider-mutation renewed review must record automatic quality mode.', $review_events[2] );

echo "mad4b.site-control-plane.context-human-review.runtime.v5: PASS\n";
