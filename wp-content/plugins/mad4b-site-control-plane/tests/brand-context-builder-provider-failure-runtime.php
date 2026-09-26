<?php
/**
 * Brand Context provider uncertainty/freshness runtime regression.
 *
 * Uses bounded in-memory provider/durable stubs: no external provider call and
 * no WordPress mutation. Proves stale generation is fenced before create and
 * verified-no-effect reconciliation never re-executes create.
 */
$root = dirname( __DIR__ );

if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
	final class MAD4B_SCP_Site_Profile {
		public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
	}
}
if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) {
	final class MAD4B_SCP_Artifacts {
		public static $artifact = array();
		public static function get_artifact( $input ) {
			return array( 'artifact' => self::$artifact, 'mutation_performed' => false );
		}
	}
}
if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) {
	final class MAD4B_SCP_Context_Authority {
		public static $marked = 0;
		public static function profile() { return array( 'brand_name' => 'Brand' ); }
		public static function source( $source_id ) { return array( 'source_id' => $source_id, 'external_root_id' => 'folder-1', 'mode' => 'governed' ); }
		public static function assets() {
			$hash = hash( 'sha256', 'strategy' );
			return array( array(
				'asset_id' => hash( 'sha256', 'strategy-asset' ),
				'category' => 'brand_strategy',
				'status' => 'ready',
				'source_mode' => 'governed',
				'content_complete' => true,
				'authority_class' => 'brand_authority',
				'review_status' => 'approved',
				'content_hash' => $hash,
				'reviewed_content_hash' => $hash,
			) );
		}
		public static function registry_revision() { return 1; }
		public static function context_fingerprint() { return hash( 'sha256', 'context' ); }
		public static function authority_manifest_fingerprint() { return hash( 'sha256', 'authority' ); }
		public static function upsert_asset_from_provider( $source_id, $asset, $preserve = array() ) {
			return array( 'asset_id' => hash( 'sha256', (string)$asset['file_id'] ), 'file_id' => (string)$asset['file_id'], 'mime_type' => (string)$asset['mimeType'] );
		}
		public static function mark_generated_brand_draft( $asset_id, $category, $artifact_id, $digest, $receipt, array $generation = array() ) {
			++self::$marked;
			return array( 'asset_id' => $asset_id, 'status' => 'ready' );
		}
	}
}
if ( ! class_exists( 'MAD4B_SCP_Durable_Execution' ) ) {
	final class MAD4B_SCP_Durable_Execution {
		const NO_EFFECT_MIN_OBSERVATION_SECONDS = 60;
		public static $begin_calls = 0;
		public static function scope_key( $site, $contract, $op, $subject ) { return hash( 'sha256', implode('|', func_get_args()) ); }
		public static function begin_idempotency() { ++self::$begin_calls; return array( 'replay' => false ); }
		public static function record_idempotency_reconciliation_observation() {
			return array( 'observation_count' => 2, 'elapsed_seconds' => 120, 'minimum_interval_seconds' => 60, 'observations' => array(
				array( 'provider_scan_generation' => 'g1' ), array( 'provider_scan_generation' => 'g2' ),
			) );
		}
		public static function release_idempotency_after_verified_no_effect() { return array( 'released' => true ); }
		public static function complete_idempotency_from_reconciliation() { return array( 'completed' => true ); }
	}
}
if ( ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) {
	final class MAD4B_SCP_Context_Provider_Gateway {
		public static $mode = 'zero';
		public static $create_calls = 0;
		public static function capabilities() { return array( 'ready' => true ); }
		public static function read_context_asset( $asset_id ) { return array( 'content' => 'Approved strategy', 'observed_at' => gmdate('c') ); }
		public static function create_brand_asset() { ++self::$create_calls; return new WP_Error( 'unexpected_create', 'create must not execute in this fixture' ); }
		private static function candidate( $identity, $file_id ) {
			$content = 'Evidence-bound draft';
			return array(
				'file_id' => $file_id,
				'title' => 'Brand - Tone of Voice.md',
				'parent_folder_id' => 'folder-1',
				'content_complete' => true,
				'content_hash' => hash( 'sha256', $content ),
				'mimeType' => 'text/markdown',
				'appProperties' => array(
					'mad4b_kind' => 'brand_context',
					'mad4b_artifact' => $identity['artifact_id'],
					'mad4b_source' => $identity['source_id'],
					'mad4b_idempotency' => $identity['idempotency_key'],
					'mad4b_request' => $identity['request_sha256'],
				),
			);
		}
		public static function find_brand_materialization_candidates( $source_id, $identity ) {
			$assets = array();
			if ( 'one' === self::$mode ) $assets[] = self::candidate( $identity, 'file-1' );
			if ( 'duplicate' === self::$mode ) {
				$assets[] = self::candidate( $identity, 'file-1' );
				$assets[] = self::candidate( $identity, 'file-2' );
			}
			return array( 'complete' => true, 'assets' => $assets, 'scan_generation' => 'generation-' . self::$mode, 'truncation_reasons' => array() );
		}
		public static function materialization_zero_observation_ref() { return 'reconcile:zero:' . hash( 'sha256', 'zero' ); }
		public static function materialization_no_effect_ref() { return 'reconcile:no-effect:' . hash( 'sha256', 'none' ); }
		public static function materialization_reconciliation_ref() { return 'reconcile:effect:' . hash( 'sha256', 'one' ); }
	}
}

require_once $root . '/includes/class-mad4b-scp-brand-context-builder.php';

$fail = static function ( $message ) { fwrite( STDERR, 'FAIL brand-context-builder-provider-failure-runtime: ' . $message . PHP_EOL ); exit( 1 ); };
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };
$error_code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

$artifact_id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$source_id = str_repeat( 'a', 64 );
$content = 'Evidence-bound draft';
MAD4B_SCP_Artifacts::$artifact = array(
	'artifact_id' => $artifact_id,
	'job_id' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
	'artifact_type' => 'brand_context_draft',
	'status' => 'active',
	'payload' => array(
		'category' => 'tone_of_voice',
		'content' => $content,
		'evidence_digest' => str_repeat( '0', 64 ),
		'plan_sha256' => str_repeat( '1', 64 ),
		'draft_preflight_sha256' => str_repeat( '2', 64 ),
		'include_rendered_frontend' => false,
	),
	'metadata' => array(),
);
$input = array(
	'artifact_id' => $artifact_id,
	'source_id' => $source_id,
	'format' => 'markdown',
	'expected_draft_content_sha256' => hash( 'sha256', $content ),
);

$stale = MAD4B_SCP_Brand_Context_Builder::materialize_draft( $input );
$check( 'mad4b_brand_draft_evidence_stale' === $error_code( $stale ), 'stale generation evidence was not fenced before provider mutation' );
$check( 0 === MAD4B_SCP_Context_Provider_Gateway::$create_calls, 'provider create executed despite stale generation evidence' );
$check( 0 === MAD4B_SCP_Durable_Execution::$begin_calls, 'durable mutation claim began before freshness fence' );

MAD4B_SCP_Context_Provider_Gateway::$mode = 'zero';
$no_effect = MAD4B_SCP_Brand_Context_Builder::reconcile_materialization( $input );
$check( is_array( $no_effect ) && 'verified_no_effect' === $no_effect['status'] && ! empty( $no_effect['safe_to_retry'] ), 'verified no-effect reconciliation did not release retry safely' );
$check( 0 === MAD4B_SCP_Context_Provider_Gateway::$create_calls, 'reconciliation re-executed provider create after verified no-effect' );

MAD4B_SCP_Context_Provider_Gateway::$mode = 'duplicate';
$duplicate = MAD4B_SCP_Brand_Context_Builder::reconcile_materialization( $input );
$check( 'mad4b_brand_materialization_reconcile_duplicate_identity' === $error_code( $duplicate ), 'duplicate provider identity did not fail closed' );
$check( 0 === MAD4B_SCP_Context_Provider_Gateway::$create_calls, 'duplicate reconciliation triggered provider create' );

$method = new ReflectionMethod( 'MAD4B_SCP_Brand_Context_Builder', 'run_scheduled_materialization_reconciliation' );
$file = file( $method->getFileName() );
$body = implode( '', array_slice( $file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
$check( false === strpos( $body, 'self::materialize_draft(' ), 'scheduled reconciliation still contains automatic mutation retry' );
$check( false !== strpos( $body, 'self::reconcile_materialization(' ), 'scheduled reconciliation no longer reconciles' );

echo "mad4b.brand-context-builder.provider-failure-runtime.v1: PASS\n";
