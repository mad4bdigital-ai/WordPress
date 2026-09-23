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

function mad4b_review_exact_input( $asset_id, array $decision ) {
	$asset = MAD4B_SCP_Context_Authority::asset( $asset_id );
	mad4b_review_assert( ! empty( $asset ), 'Exact review input requires a live asset.', $asset_id );
	return array_merge(
		array(
			'expected_content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '',
			'expected_registry_revision' => MAD4B_SCP_Context_Authority::registry_revision(),
			'expected_authority_manifest_fingerprint' => MAD4B_SCP_Context_Authority::authority_manifest_fingerprint(),
			'required_scope_confirmed' => true,
		),
		$decision
	);
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

function mad4b_review_asset_payload( $file_id, $content, $title = 'Voice Reference Notes', $path = 'Brand Core/Voice Reference Notes.txt' ) {
	return array(
		'file_id' => $file_id,
		'parent_folder_id' => 'folder-brand-core',
		'title' => $title,
		'path' => $path,
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
		mad4b_review_asset_payload( 'writer-file-optional', str_repeat( "Writer reference sample with narrative structure and sentence rhythm.\n\n", 10 ), 'Writer Reference Sample', 'References/Writer Reference Sample.txt' ),
	),
	array(
		'complete' => true,
		'started_at' => '2026-09-19T18:00:00Z',
		'completed_at' => '2026-09-19T18:00:03Z',
		'scan_generation' => str_repeat( '1', 64 ),
	)
);
mad4b_review_assert( ! is_wp_error( $scan1 ), 'Initial complete scan must succeed.', $scan1 );

$optional_asset_id = hash( 'sha256', $source_id . '|writer-file-optional' );
$optional_initial = MAD4B_SCP_Context_Authority::asset( $optional_asset_id );
mad4b_review_assert( ! empty( $optional_initial ), 'Optional writer reference must be present after initial scan.', $optional_initial );
mad4b_review_assert( 'writer_reference' === $optional_initial['category'], 'Writer reference fixture must classify as writer_reference, not Brand Core.', $optional_initial );
mad4b_review_assert( empty( $optional_initial['required'] ), 'Writer reference fixture must remain optional by default.', $optional_initial );

$optional_escalation_input = mad4b_review_exact_input(
	$optional_asset_id,
	array(
		'category' => 'writer_reference',
		'authority_class' => 'reference',
		'required' => true,
		'required_scope_confirmed' => false,
		'quality_mode' => 'automatic',
		'quality_score' => '',
	)
);
$optional_escalation = MAD4B_SCP_Context_Authority::review_asset( $optional_asset_id, $optional_escalation_input );
mad4b_review_assert( is_wp_error( $optional_escalation ), 'Optional-to-required review escalation must require explicit site-wide confirmation.', $optional_escalation );
mad4b_review_assert( 'mad4b_context_required_scope_confirmation_required' === $optional_escalation->get_error_code(), 'Required Context escalation must fail with the exact confirmation error.', $optional_escalation->get_error_code() );
$optional_after_denial = MAD4B_SCP_Context_Authority::asset( $optional_asset_id );
mad4b_review_assert( empty( $optional_after_denial['required'] ) && 'unreviewed' === $optional_after_denial['review_status'], 'Denied required escalation must leave the optional asset unchanged.', $optional_after_denial );

$required_initial = MAD4B_SCP_Context_Authority::asset( $asset_id );
mad4b_review_assert( ! empty( $required_initial ) && ! empty( $required_initial['required'] ), 'Brand Core fixture must begin as an already-required governed asset.', $required_initial );
$required_initial_revision = MAD4B_SCP_Context_Authority::registry_revision();
$required_initial_category = (string) $required_initial['category'];
$required_initial_authority = (string) $required_initial['authority_class'];
$shift_category = 'editorial_guidelines' === $required_initial_category ? 'tone_of_voice' : 'editorial_guidelines';

$required_shift = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	mad4b_review_exact_input(
		$asset_id,
		array(
			'category' => $shift_category,
			'authority_class' => $required_initial_authority,
			'required' => true,
			'required_scope_confirmed' => false,
			'quality_mode' => 'automatic',
			'quality_score' => '',
			'review_note' => 'Attempted required-set category shift.',
		)
	)
);
mad4b_review_assert( is_wp_error( $required_shift ), 'Changing the category of an already-required asset must require explicit site-wide scope confirmation.', $required_shift );
mad4b_review_assert( 'mad4b_context_required_scope_confirmation_required' === $required_shift->get_error_code(), 'Required-set category shift must fail with the exact scope confirmation error.', $required_shift->get_error_code() );
$required_after_shift_denial = MAD4B_SCP_Context_Authority::asset( $asset_id );
mad4b_review_assert( $required_initial_category === $required_after_shift_denial['category'] && ! empty( $required_after_shift_denial['required'] ), 'Denied required-set shift must leave the original category and requirement unchanged.', array( 'before' => $required_initial, 'after' => $required_after_shift_denial ) );
mad4b_review_assert( $required_initial_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Denied required-set shift must not advance registry revision.' );

$required_reduction = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	mad4b_review_exact_input(
		$asset_id,
		array(
			'category' => $required_initial_category,
			'authority_class' => $required_initial_authority,
			'required' => false,
			'required_scope_confirmed' => false,
			'quality_mode' => 'automatic',
			'quality_score' => '',
			'review_note' => 'Attempted required-set reduction.',
		)
	)
);
mad4b_review_assert( is_wp_error( $required_reduction ), 'Removing an existing site-wide Context requirement must require explicit confirmation.', $required_reduction );
mad4b_review_assert( 'mad4b_context_required_scope_confirmation_required' === $required_reduction->get_error_code(), 'Required-set reduction must fail with the exact scope confirmation error.', $required_reduction->get_error_code() );
$required_after_reduction_denial = MAD4B_SCP_Context_Authority::asset( $asset_id );
mad4b_review_assert( $required_initial_category === $required_after_reduction_denial['category'] && ! empty( $required_after_reduction_denial['required'] ), 'Denied required-set reduction must leave the original category and requirement unchanged.', array( 'before' => $required_initial, 'after' => $required_after_reduction_denial ) );
mad4b_review_assert( $required_initial_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Denied required-set reduction must not advance registry revision.' );

$review = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	mad4b_review_exact_input(
		$asset_id,
		array(
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'quality_mode' => 'manual',
		'quality_score' => '97',
		'review_note' => 'Reviewer confirms the exact Tone of Voice authority and documented manual quality override.',
	)
	)
);
mad4b_review_assert( ! is_wp_error( $review ), 'Human review must succeed.', $review );
mad4b_review_assert( 'human' === $review['classification_source'], 'Human review must become classification authority.', $review );
mad4b_review_assert( 'approved' === $review['review_status'], 'Human review must approve the asset.', $review );
mad4b_review_assert( 97 === (int) $review['quality_score'], 'Human quality override must be persisted.', $review );
mad4b_review_assert( ! empty( $review['quality']['human_override'] ), 'Human quality override marker must be explicit.', $review );
mad4b_review_assert( ! empty( $review['reviewed_content_hash'] ) && hash_equals( (string) $review['content_hash'], (string) $review['reviewed_content_hash'] ), 'Human approval must bind to the exact reviewed content hash.', $review );
mad4b_review_assert( ! empty( $review['automatic_classification'] ) && 'automatic_heuristic' === $review['automatic_classification']['source'], 'Human review must preserve the automatic classification evidence separately.', $review );
mad4b_review_assert( 'human' === $review['classification_source'], 'Effective classification must remain explicitly human after review.', $review );

$exact_review_authority_fingerprint = MAD4B_SCP_Context_Authority::authority_manifest_fingerprint();
$exact_review_context_fingerprint = MAD4B_SCP_Context_Authority::context_fingerprint();
$legacy_unbound_records = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ];
$legacy_unbound_records[ $asset_id ]['reviewed_content_hash'] = '';
mad4b_review_assert(
	! hash_equals( $exact_review_authority_fingerprint, MAD4B_SCP_Context_Authority::authority_manifest_fingerprint( $legacy_unbound_records ) ),
	'Authority manifest fingerprint must distinguish exact-bound review evidence from a legacy unbound approval.'
);
mad4b_review_assert(
	! hash_equals( $exact_review_context_fingerprint, MAD4B_SCP_Context_Authority::context_fingerprint( $legacy_unbound_records, $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] ) ),
	'Context fingerprint must distinguish exact-bound review evidence from a legacy unbound approval.'
);

$review_fingerprint = MAD4B_SCP_Context_Authority::authority_manifest_fingerprint();

$scan2 = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(
		mad4b_review_asset_payload( $file_id, $initial_text ),
		mad4b_review_asset_payload( 'writer-file-optional', str_repeat( "Writer reference sample with narrative structure and sentence rhythm.\n\n", 10 ), 'Writer Reference Sample', 'References/Writer Reference Sample.txt' ),
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

$stale_review_input = mad4b_review_exact_input(
	$asset_id,
	array(
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'quality_mode' => 'automatic',
		'quality_score' => '',
	)
);
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
mad4b_review_assert( empty( $changed['reviewed_at'] ) && empty( $changed['reviewed_by'] ), 'Changed content must clear reviewer identity/timestamp as current approval evidence.', $changed );
mad4b_review_assert( empty( $changed['quality']['human_override'] ), 'Changed content must not reuse a prior human quality override.', $changed );

$stale_review = MAD4B_SCP_Context_Authority::review_asset( $asset_id, $stale_review_input );
mad4b_review_assert( is_wp_error( $stale_review ), 'A stale browser review must fail closed after content changes.', $stale_review );
mad4b_review_assert( 'mad4b_context_review_content_drift' === $stale_review->get_error_code(), 'Stale review must expose exact content drift.', $stale_review->get_error_code() );
$after_stale_review = MAD4B_SCP_Context_Authority::asset( $asset_id );
mad4b_review_assert( 'needs_review_content_changed' === $after_stale_review['review_status'], 'Rejected stale review must not approve changed content.', $after_stale_review );
mad4b_review_assert( empty( $after_stale_review['reviewed_content_hash'] ), 'Rejected stale review must not bind a reviewed content hash.', $after_stale_review );

$status = MAD4B_SCP_Context_Authority::status();
mad4b_review_assert( empty( $status['ready'] ), 'Context Authority must fail closed until changed mandatory content is reviewed again.', $status );
mad4b_review_assert( in_array( 'mandatory_context_review_required', $status['blockers'], true ), 'Changed mandatory content must surface review blocker.', $status['blockers'] );
mad4b_review_assert( ! hash_equals( $review_fingerprint, MAD4B_SCP_Context_Authority::authority_manifest_fingerprint() ), 'Review invalidation must change the authority manifest fingerprint.' );

$scan4 = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array( mad4b_review_asset_payload( $file_id, $changed_text ) ),
	array(
		'complete' => true,
		'started_at' => '2026-09-19T18:03:00Z',
		'completed_at' => '2026-09-19T18:03:03Z',
		'scan_generation' => str_repeat( '4', 64 ),
	)
);
mad4b_review_assert( ! is_wp_error( $scan4 ), 'Same changed-content rescan before renewed review must succeed without re-approving.', $scan4 );
$still_pending_review = MAD4B_SCP_Context_Authority::asset( $asset_id );
mad4b_review_assert( 'human' === $still_pending_review['classification_source'], 'Human classification must survive repeated changed-content rescans.', $still_pending_review );
mad4b_review_assert( 'needs_review_content_changed' === $still_pending_review['review_status'], 'Repeated same-hash rescan must not auto-approve content awaiting renewed review.', $still_pending_review );
mad4b_review_assert( empty( $still_pending_review['reviewed_at'] ) && empty( $still_pending_review['reviewed_by'] ), 'Repeated same-hash rescan must not recreate reviewer approval evidence.', $still_pending_review );
$still_blocked = MAD4B_SCP_Context_Authority::status();
mad4b_review_assert( empty( $still_blocked['ready'] ), 'Context Authority must remain blocked across repeated rescans until human re-review.', $still_blocked );
mad4b_review_assert( in_array( 'mandatory_context_review_required', $still_blocked['blockers'], true ), 'Repeated rescan must retain mandatory review blocker.', $still_blocked['blockers'] );

$automatic_review = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	mad4b_review_exact_input(
		$asset_id,
		array(
			'category' => 'tone_of_voice',
			'authority_class' => 'brand_authority',
			'required' => true,
			'quality_mode' => 'automatic',
			'quality_score' => '',
		)
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
	mad4b_review_exact_input(
		$asset_id,
		array(
			'category' => 'tone_of_voice',
			'authority_class' => 'brand_authority',
			'required' => true,
			'quality_mode' => 'automatic',
			'quality_score' => '',
		)
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
mad4b_review_assert( 0 === (int) $ready_status['legacy_unbound_review_asset_count'], 'Ready required Context must have no legacy unbound approvals.', $ready_status );

$review_queue = MAD4B_SCP_Context_Authority::review_queue();
mad4b_review_assert( 'mad4b.context-review-queue.v1' === $review_queue['contract'], 'Review queue must expose the bounded read-only contract.', $review_queue );
mad4b_review_assert( 1 === (int) $review_queue['counts']['approved_exact'], 'Review queue must count the exact approved Brand asset.', $review_queue );
mad4b_review_assert( 0 === (int) $review_queue['counts']['legacy_unbound'], 'Review queue must not report legacy-unbound approvals after exact review.', $review_queue );

$brand_core = MAD4B_SCP_Context_Authority::brand_core_coverage();
mad4b_review_assert( 'mad4b.brand-core-context-coverage.v1' === $brand_core['contract'], 'Brand Core diagnostic must expose its read-only contract.', $brand_core );
mad4b_review_assert( ! empty( $brand_core['coverage']['tone_of_voice']['ready'] ), 'Exact approved Tone of Voice must satisfy its Brand Core set.', $brand_core );
mad4b_review_assert( in_array( 'brand_strategy', $brand_core['missing_required_context_sets'], true ), 'Brand Strategy must remain explicitly missing in this fixture.', $brand_core );
mad4b_review_assert( in_array( 'editorial_guidelines', $brand_core['missing_required_context_sets'], true ), 'Editorial Guidelines must remain explicitly missing in this fixture.', $brand_core );

$before_failed_review_asset = MAD4B_SCP_Context_Authority::asset( $asset_id );
$before_failed_review_revision = MAD4B_SCP_Context_Authority::registry_revision();
MAD4B_SCP_Audit::$fail_append = true;
$failed_audit_review = MAD4B_SCP_Context_Authority::review_asset(
	$asset_id,
	mad4b_review_exact_input(
		$asset_id,
		array(
		'category' => 'tone_of_voice',
		'authority_class' => 'brand_authority',
		'required' => true,
		'quality_mode' => 'manual',
		'quality_score' => '88',
		'review_note' => 'Injected audit-failure review retains an explicit reviewer rationale.',
	)
	)
);
MAD4B_SCP_Audit::$fail_append = false;
mad4b_review_assert( is_wp_error( $failed_audit_review ), 'Audit append failure must fail the governance mutation.', $failed_audit_review );
mad4b_review_assert( 'mad4b_context_registry_audit_commit_failed' === $failed_audit_review->get_error_code(), 'Audit append failure must expose compensated registry error.', $failed_audit_review->get_error_code() );
mad4b_review_assert( $before_failed_review_asset === MAD4B_SCP_Context_Authority::asset( $asset_id ), 'Audit failure must restore the exact pre-review asset state.' );
mad4b_review_assert( $before_failed_review_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Audit failure must restore the exact pre-review registry revision.' );

$optional_needs_changes = MAD4B_SCP_Context_Authority::review_asset(
	$optional_asset_id,
	mad4b_review_exact_input(
		$optional_asset_id,
		array(
			'category' => 'writer_reference',
			'authority_class' => 'reference',
			'required' => false,
			'quality_mode' => 'automatic',
			'quality_score' => '',
			'decision' => 'needs_changes',
			'review_note' => 'The reference needs editorial changes before it may be relied on.',
		)
	)
);
mad4b_review_assert( ! is_wp_error( $optional_needs_changes ), 'Reviewer must be able to request changes against exact optional content.', $optional_needs_changes );
mad4b_review_assert( 'needs_changes' === $optional_needs_changes['review_status'], 'Needs-changes decision must persist its explicit state.', $optional_needs_changes );
mad4b_review_assert( 'needs_changes' === $optional_needs_changes['review_decision'], 'Needs-changes decision must retain decision semantics.', $optional_needs_changes );
mad4b_review_assert( ! empty( $optional_needs_changes['reviewed_content_hash'] ) && hash_equals( (string) $optional_needs_changes['content_hash'], (string) $optional_needs_changes['reviewed_content_hash'] ), 'Needs-changes decision must bind to exact content.', $optional_needs_changes );

$decision_preserve_scan = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(
		mad4b_review_asset_payload( $file_id, $provider_write_text ),
		mad4b_review_asset_payload( 'writer-file-optional', str_repeat( "Writer reference sample with narrative structure and sentence rhythm.\n\n", 10 ), 'Writer Reference Sample', 'References/Writer Reference Sample.txt' ),
	),
	array(
		'complete' => true,
		'started_at' => '2026-09-19T18:05:00Z',
		'completed_at' => '2026-09-19T18:05:03Z',
		'scan_generation' => str_repeat( '5', 64 ),
	)
);
mad4b_review_assert( ! is_wp_error( $decision_preserve_scan ), 'Same-hash scan after needs-changes decision must succeed.', $decision_preserve_scan );
$optional_after_same_hash_scan = MAD4B_SCP_Context_Authority::asset( $optional_asset_id );
mad4b_review_assert( 'needs_changes' === $optional_after_same_hash_scan['review_status'], 'Same-hash rescan must preserve an exact needs-changes decision.', $optional_after_same_hash_scan );
mad4b_review_assert( 'needs_changes' === $optional_after_same_hash_scan['review_decision'], 'Same-hash rescan must preserve decision semantics.', $optional_after_same_hash_scan );
mad4b_review_assert( ! empty( $optional_after_same_hash_scan['reviewed_content_hash'] ) && hash_equals( (string) $optional_after_same_hash_scan['content_hash'], (string) $optional_after_same_hash_scan['reviewed_content_hash'] ), 'Same-hash rescan must preserve exact decision binding.', $optional_after_same_hash_scan );

$optional_reject = MAD4B_SCP_Context_Authority::review_asset(
	$optional_asset_id,
	mad4b_review_exact_input(
		$optional_asset_id,
		array(
			'category' => 'writer_reference',
			'authority_class' => 'reference',
			'required' => false,
			'quality_mode' => 'automatic',
			'quality_score' => '',
			'decision' => 'reject',
			'review_note' => 'This reference is not approved as governed brand evidence.',
		)
	)
);
mad4b_review_assert( ! is_wp_error( $optional_reject ), 'Reviewer must be able to reject exact optional content.', $optional_reject );
mad4b_review_assert( 'rejected' === $optional_reject['review_status'], 'Reject decision must persist rejected state.', $optional_reject );
mad4b_review_assert( 'reject' === $optional_reject['review_decision'], 'Reject decision semantics must remain explicit.', $optional_reject );
mad4b_review_assert( ! empty( $optional_reject['review_note'] ), 'Rejected content must retain reviewer rationale.', $optional_reject );

$review_events = array_values( array_filter( $GLOBALS['mad4b_context_audit'], static function ( $row ) { return 'mad4b/context-asset-review' === $row['event']; } ) );
mad4b_review_assert( 5 === count( $review_events ), 'Three primary approvals plus needs-changes and rejection must each emit one audit event.', $review_events );
mad4b_review_assert( 'manual' === $review_events[0]['data']['quality_mode'], 'First review audit must record manual quality mode.', $review_events[0] );
mad4b_review_assert( 'automatic' === $review_events[1]['data']['quality_mode'], 'Second review audit must record automatic quality mode.', $review_events[1] );
mad4b_review_assert( 'automatic' === $review_events[2]['data']['quality_mode'], 'Provider-mutation renewed review must record automatic quality mode.', $review_events[2] );
mad4b_review_assert( 'needs_changes' === $review_events[3]['data']['decision'] && 'needs_changes' === $review_events[3]['data']['review_status'], 'Needs-changes review audit must preserve exact decision semantics.', $review_events[3] );
mad4b_review_assert( 'reject' === $review_events[4]['data']['decision'] && 'rejected' === $review_events[4]['data']['review_status'], 'Rejected review audit must preserve exact decision semantics.', $review_events[4] );
mad4b_review_assert( ! empty( $review_events[3]['data']['review_note'] ) && ! empty( $review_events[4]['data']['review_note'] ), 'Non-approve review audit evidence must retain reviewer rationale.', array( $review_events[3], $review_events[4] ) );
mad4b_review_assert( MAD4B_SCP_Context_Authority::HUMAN_REVIEW_CONTRACT === $review_events[0]['data']['contract'], 'Human review audit must use the v2 exact-review contract.', $review_events[0] );
mad4b_review_assert( ! empty( $review_events[0]['data']['expected_content_hash'] ) && $review_events[0]['data']['expected_content_hash'] === $review_events[0]['data']['observed_content_hash'], 'Human review audit must bind expected and observed content hashes.', $review_events[0] );
mad4b_review_assert( (int) $review_events[0]['data']['registry_revision_after'] === (int) $review_events[0]['data']['registry_revision_before'] + 1, 'Human review audit must record the exact monotonic registry transition.', $review_events[0] );
mad4b_review_assert( empty( $review_events[0]['data']['required_scope_escalated'] ), 'Already-required Brand Core review must not be mislabeled as a scope escalation.', $review_events[0] );
mad4b_review_assert( 'wp_admin' === $review_events[0]['data']['actor_type'] && 42 === (int) $review_events[0]['data']['wp_user_id'], 'Human review audit must attribute the WordPress reviewer.', $review_events[0] );
mad4b_review_assert( ! empty( $review_events[0]['data']['automatic_classification'] ), 'Human review audit must retain automatic classification provenance.', $review_events[0] );

echo "mad4b.site-control-plane.context-human-review.runtime.v11: PASS\n";
