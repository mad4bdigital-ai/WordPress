<?php

define( 'ABSPATH', '/srv/wordpress/' );

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
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

final class MAD4B_SCP_Context_Authority {
	public static $assets = array();
	public static function categories() {
		return array(
			'brand_strategy' => 'Brand Strategy',
			'tone_of_voice' => 'Tone of Voice',
			'editorial_guidelines' => 'Editorial Guidelines',
			'terminology' => 'Terminology',
			'claim_policy' => 'Claim Policy',
			'seo_strategy' => 'SEO Strategy',
			'writer_reference' => 'Writer Reference',
		);
	}
	public static function status() {
		return array(
			'site_uuid' => '11111111-1111-4111-8111-111111111111',
			'brand_id' => 'brand-001',
			'profile_revision' => 7,
			'context_fingerprint' => str_repeat( 'a', 64 ),
			'authority_manifest_fingerprint' => str_repeat( 'd', 64 ),
			'registry_revision' => 12,
			'partial_source_count' => 0,
			'stale_asset_count' => 0,
			'conflicting_asset_count' => 0,
		);
	}
	public static function profile() { return array( 'brand_id' => 'brand-001', 'revision' => 7 ); }
	public static function assets() { return self::$assets; }
	public static function registry_revision() { return 12; }
	public static function authority_manifest_fingerprint( $assets = null ) { return str_repeat( 'd', 64 ); }
}

final class MAD4B_SCP_Google_Drive_Context {
	public static function read_context_asset( $asset_id ) {
		foreach ( MAD4B_SCP_Context_Authority::$assets as $asset ) {
			if ( (string) $asset['asset_id'] !== (string) $asset_id ) continue;
			$content = 'content:' . $asset_id;
			return array(
				'content' => $content,
				'bytes' => strlen( $content ),
				'content_sha256' => hash( 'sha256', $content ),
				'content_complete' => true,
			);
		}
		return new WP_Error( 'missing', 'Asset missing.' );
	}
}

final class MAD4B_SCP_Skill_Registry {
	public static $skill = array();
	public static function get_skill( $level, $target, $name ) {
		$skill = self::$skill;
		$skill['context_policy_sha256'] = MAD4B_SCP_Context_Preflight::policy_digest( isset( $skill['context_policy'] ) ? $skill['context_policy'] : array() );
		return $skill;
	}
}

final class MAD4B_SCP_Staging_Write_Authority {
	public static function context_receipt_from_input( $input ) {
		return is_array( $input ) && isset( $input['_mad4b_context_receipt'] ) && is_array( $input['_mad4b_context_receipt'] ) ? $input['_mad4b_context_receipt'] : array();
	}
}

final class MAD4B_SCP_Audit {
	public static $events = array();
	public static function storage_status() { return array( 'ready' => true ); }
	public static function record( $ability, $payload = array(), $status = 'ok' ) {
		self::$events[] = array( 'ability' => $ability, 'payload' => $payload, 'status' => $status );
		return true;
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-preflight.php';

function mad4b_context_preflight_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

function mad4b_context_asset( $id, $category, $mode, $review, $task_scope = '', $priority = 100, $quality = 90 ) {
	return array(
		'asset_id' => $id,
		'source_id' => 'source-' . $mode,
		'source_mode' => $mode,
		'task_scope' => $task_scope,
		'category' => $category,
		'authority_class' => 'governed' === $mode ? 'brand_authority' : 'reference',
		'title' => $category . ' asset',
		'quality_score' => $quality,
		'priority' => $priority,
		'status' => 'ready',
		'content_complete' => true,
		'review_status' => $review,
	);
}

$skill = array(
	'logical_id' => 'site:_site:blog-writer',
	'sha256' => str_repeat( 'b', 64 ),
	'context_policy' => array( 'preset' => 'brand_core' ),
);
MAD4B_SCP_Skill_Registry::$skill = $skill;

MAD4B_SCP_Context_Authority::$assets = array(
	'brand' => mad4b_context_asset( 'brand', 'brand_strategy', 'governed', 'unreviewed' ),
	'tone' => mad4b_context_asset( 'tone', 'tone_of_voice', 'governed', 'approved' ),
	'editorial' => mad4b_context_asset( 'editorial', 'editorial_guidelines', 'governed', 'approved' ),
	'writer' => mad4b_context_asset( 'writer', 'writer_reference', 'task_attachment', 'unreviewed', 'campaign-x', 40, 95 ),
);

$blocked = MAD4B_SCP_Context_Preflight::preflight_entry( $skill, 'campaign-x' );
mad4b_context_preflight_assert( is_array( $blocked ) && empty( $blocked['ready'] ), 'Unreviewed mandatory governed asset must fail closed.', $blocked );
mad4b_context_preflight_assert( in_array( 'required_context_sets_missing', $blocked['blockers'], true ), 'Missing approved required category must be explicit.', $blocked );
mad4b_context_preflight_assert( in_array( 'brand_strategy', $blocked['missing_context_sets'], true ), 'brand_strategy must be reported missing while its asset is unreviewed.', $blocked );

MAD4B_SCP_Context_Authority::$assets['brand']['review_status'] = 'approved';
$ready = MAD4B_SCP_Context_Preflight::preflight_entry( $skill, 'campaign-x' );
mad4b_context_preflight_assert( ! empty( $ready['ready'] ) && 'ready' === $ready['state'], 'Approved mandatory context must become ready.', $ready );
mad4b_context_preflight_assert( empty( $ready['blockers'] ), 'Ready preflight must not retain blockers.', $ready );
mad4b_context_preflight_assert( 'mad4b.context-envelope.v1' === $ready['envelope']['contract'], 'Context envelope contract mismatch.', $ready );
mad4b_context_preflight_assert( 'mad4b.content-context-receipt.v1' === $ready['receipt']['contract'], 'Context receipt contract mismatch.', $ready );
mad4b_context_preflight_assert( preg_match( '/^[a-f0-9]{64}$/', $ready['receipt']['receipt_sha256'] ), 'Context receipt must carry deterministic SHA256.', $ready );

$ids = array();
foreach ( $ready['envelope']['assets'] as $asset ) $ids[] = (string) $asset['asset_id'];
sort( $ids, SORT_STRING );
mad4b_context_preflight_assert( in_array( 'brand', $ids, true ) && in_array( 'tone', $ids, true ) && in_array( 'editorial', $ids, true ), 'All approved mandatory Brand Core assets must load.', $ids );
mad4b_context_preflight_assert( in_array( 'writer', $ids, true ), 'Exact task-scoped optional writer reference should load.', $ids );
mad4b_context_preflight_assert( 'site_policy' === $ready['envelope']['precedence'][0] && 'brand_core' === $ready['envelope']['precedence'][1], 'Context precedence must keep Site Policy and Brand Core ahead of references.', $ready['envelope']['precedence'] );

MAD4B_SCP_Context_Authority::$assets['terminology'] = mad4b_context_asset( 'terminology', 'terminology', 'governed', 'approved' );
MAD4B_SCP_Context_Authority::$assets['terminology']['required'] = true;
$site_union = MAD4B_SCP_Context_Preflight::preflight_entry( $skill, 'campaign-x' );
mad4b_context_preflight_assert( ! empty( $site_union['ready'] ), 'Site-mandatory approved Context must join Skill-required Context without blocking.', $site_union );
mad4b_context_preflight_assert( in_array( 'terminology', $site_union['envelope']['effective_required_context_sets'], true ), 'Site mandatory terminology must be included in effective required sets.', $site_union['envelope'] );
mad4b_context_preflight_assert( 12 === (int) $site_union['receipt']['registry_revision'], 'Receipt must bind the exact Context registry revision.', $site_union['receipt'] );
mad4b_context_preflight_assert( str_repeat( 'd', 64 ) === $site_union['receipt']['authority_manifest_fingerprint'], 'Receipt must bind authority manifest fingerprint.', $site_union['receipt'] );

$validation = MAD4B_SCP_Context_Preflight::validate_receipt_binding( $site_union['receipt'] );
mad4b_context_preflight_assert( is_array( $validation ) && ! empty( $validation['ready'] ), 'Fresh exact Context Receipt must validate against live Skill and Authority state.', $validation );

$missing_receipt = MAD4B_SCP_Context_Preflight::mutation_context_guard(
	'mad4b/content-update-post',
	array( 'post_id' => 12, 'post_content' => 'Generated content' )
);
mad4b_context_preflight_assert( is_wp_error( $missing_receipt ) && 'mad4b_content_context_receipt_required' === $missing_receipt->get_error_code(), 'Brand-bearing content mutation must require Context Receipt.', $missing_receipt );

$guarded = MAD4B_SCP_Context_Preflight::mutation_context_guard(
	'mad4b/content-update-post',
	array(
		'post_id' => 12,
		'post_content' => 'Generated content',
		'_mad4b_context_receipt' => $site_union['receipt'],
	)
);
mad4b_context_preflight_assert( is_array( $guarded ) && ! empty( $guarded['ready'] ), 'Exact receipt must authorize Context guard.', $guarded );

$tampered = $site_union['receipt'];
$tampered['registry_revision'] = 99;
$tampered_result = MAD4B_SCP_Context_Preflight::validate_receipt_binding( $tampered );
mad4b_context_preflight_assert( is_wp_error( $tampered_result ) && 'mad4b_context_receipt_integrity_failed' === $tampered_result->get_error_code(), 'Tampered receipt must fail canonical digest integrity before use.', $tampered_result );

$expired = $site_union['receipt'];
$expired['observed_at'] = gmdate( 'c', time() - MAD4B_SCP_Context_Preflight::MAX_RECEIPT_AGE - 60 );
$expired_result = MAD4B_SCP_Context_Preflight::validate_receipt_binding( $expired );
mad4b_context_preflight_assert( is_wp_error( $expired_result ) && 'mad4b_context_receipt_expired' === $expired_result->get_error_code(), 'Expired Context Receipt must fail closed.', $expired_result );

$committed = MAD4B_SCP_Context_Preflight::commit_receipt_evidence(
	$site_union['receipt'],
	array(
		'ability' => 'mad4b/content-update-post',
		'provider' => 'core',
		'target_fingerprint' => str_repeat( 'e', 64 ),
		'approval_ticket_id' => '11111111-1111-4111-8111-111111111111',
		'request_id' => 'request-fixture',
	)
);
mad4b_context_preflight_assert( is_array( $committed ) && ! empty( $committed['ready'] ), 'Validated Context Receipt must commit durable append-only binding evidence.', $committed );
mad4b_context_preflight_assert( ! empty( MAD4B_SCP_Audit::$events ) && 'mad4b/context-receipt-bound' === MAD4B_SCP_Audit::$events[0]['ability'], 'Context Receipt binding must be auditable before provider execution.', MAD4B_SCP_Audit::$events );

$wrong_scope = MAD4B_SCP_Context_Preflight::preflight_entry( $skill, 'another-task' );
$wrong_ids = array();
foreach ( $wrong_scope['envelope']['assets'] as $asset ) $wrong_ids[] = (string) $asset['asset_id'];
mad4b_context_preflight_assert( ! in_array( 'writer', $wrong_ids, true ), 'Task-only asset must not cross task scopes.', $wrong_ids );

$none = MAD4B_SCP_Context_Preflight::preflight_entry(
	array(
		'logical_id' => 'site/utility/no-context',
		'sha256' => str_repeat( 'c', 64 ),
		'context_policy' => array( 'preset' => 'none' ),
	)
);
mad4b_context_preflight_assert( ! empty( $none['ready'] ) && 'not_required' === $none['state'], 'Non-context Skill must remain usable without Brand Context.', $none );

echo "mad4b.site-control-plane.context-preflight.runtime.v1: PASS\n";
