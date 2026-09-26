<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only convergence view for post-deployment Staging certification.
 *
 * This class never reconciles grants, approves Context assets, starts OAuth,
 * opens a browser, changes provider state, publishes SEO, or touches Production.
 */
final class MAD4B_SCP_Staging_Certification {
	const CONTRACT = 'mad4b.staging-certification-status.v1';
	const ROLLBACK_CONTRACT = 'mad4b.rollback-candidate.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/staging-certification-status' ) ) return;
		wp_register_ability( 'mad4b/staging-certification-status', array(
			'label' => 'Get Staging Certification Status',
			'description' => 'Read the exact post-deployment Staging gates and remaining human/external remediation without mutation.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'client_snapshot_token' => array( 'type' => 'string', 'maxLength' => 80 ),
					'rollback_artifact_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-f0-9]{64}$' ),
					'rollback_artifact_name' => array( 'type' => 'string', 'maxLength' => 255 ),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$client_snapshot_token = isset( $input['client_snapshot_token'] ) ? trim( (string) $input['client_snapshot_token'] ) : '';
		$rollback_artifact_sha256 = isset( $input['rollback_artifact_sha256'] ) ? strtolower( trim( (string) $input['rollback_artifact_sha256'] ) ) : '';
		$rollback_artifact_name = isset( $input['rollback_artifact_name'] ) ? sanitize_text_field( (string) $input['rollback_artifact_name'] ) : '';
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$connection = class_exists( 'MAD4B_SCP_Connection_Status' ) ? MAD4B_SCP_Connection_Status::status() : array();
		$context = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::status() : array();
		$context_coverage = self::brand_core_context_coverage();
		$google = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::connection_status() : array();
		$google_mode = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::auth_mode_status() : array();
		$managed = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::managed_broker_status() : array();
		$managed_gate = self::managed_google_gate_evidence( $google_mode, $managed );
		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::status() : array();
		$snapshot = class_exists( 'MAD4B_SCP_External_Snapshot_Finalizer' )
			? ( '' !== $client_snapshot_token
				? MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify( array( 'client_snapshot_token' => $client_snapshot_token ) )
				: MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status() )
			: array();
		$authority = class_exists( 'MAD4B_SCP_Live_Truth' ) ? MAD4B_SCP_Live_Truth::current_authority_status() : array();
		$write_runtime = class_exists( 'MAD4B_SCP_Live_Truth' ) ? MAD4B_SCP_Live_Truth::current_write_certification() : array();
		$reconciliation = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
		$performance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
		$admin_query_performance = class_exists( 'MAD4B_SCP_Admin_Query_Performance' ) ? MAD4B_SCP_Admin_Query_Performance::status() : array();
		$qm_db_attribution = class_exists( 'MAD4B_SCP_Query_Monitor_Evidence_Bridge' ) ? MAD4B_SCP_Query_Monitor_Evidence_Bridge::db_attribution_status() : array();
		$oauth_authority_projection = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'consent_grant_projection' ) ? MAD4B_SCP_Local_OAuth_Server::consent_grant_projection() : array();
		$rollback = self::rollback_status( $rollback_artifact_sha256, $rollback_artifact_name );
		$browser = self::browser_status();
		$provider_inventory = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ? MAD4B_SCP_Provider_Compatibility_Certification::inventory() : array();
		$wp_import_export = self::wp_import_export_remediation( $provider_inventory );

		$gates = array(
			'exact_build' => self::gate( ! empty( $provenance['runtime_manifest_match'] ), 'runtime_build_provenance', $provenance, 'package' ),
			'safe_boot' => self::gate( ! empty( $connection['connection_certified'] ) || ! empty( $connection['ready'] ), 'connection_runtime', $connection, 'runtime' ),
			'context_authority' => self::gate( ! empty( $context['ready'] ), 'context_authority', $context, 'human_review' ),
			'brand_core_context_coverage' => self::gate( ! empty( $context_coverage['ready'] ), 'brand_core_context_coverage', $context_coverage, 'human_review' ),
			'google_provider_connection' => self::gate( ! empty( $google['connected'] ) && ! empty( $google['read_available'] ), 'google_provider_connection', $google, 'operator' ),
			'managed_google_broker' => self::gate( ! empty( $managed_gate['ready'] ), 'managed_google_broker', $managed_gate, 'server_secret' ),
			'skills_runtime' => self::gate( ! empty( $skills['ready'] ), 'skills_runtime', $skills, 'runtime' ),
			'external_skill_snapshot' => self::gate( ! empty( $snapshot['verified'] ) || ! empty( $snapshot['exact_match'] ), 'external_skill_snapshot', $snapshot, 'external_client' ),
			'write_authority' => self::gate( ! empty( $authority['ready'] ) && ! empty( $authority['runtime_reconciled'] ), 'write_authority', $authority, 'operator_reconcile' ),
			'write_runtime' => self::gate( ! empty( $write_runtime['ready'] ), 'write_runtime_certification', $write_runtime, 'operator_reconcile' ),
			'browser_runtime' => self::gate( ! empty( $browser['browser_runtime_parity_verified'] ), 'browser_acceptance', $browser, 'external_browser' ),
			'performance_budget' => self::gate( ! empty( $performance['ready'] ) && ! empty( $performance['budget_evaluated'] ) && ! empty( $performance['budget_pass'] ), 'frontend_performance', $performance, 'runtime_observation' ),
			'admin_query_performance' => self::gate( ! empty( $admin_query_performance['ready'] ), 'admin_query_performance', $admin_query_performance, 'staging_schema' ),
			'query_monitor_db_attribution' => self::gate( ! empty( $qm_db_attribution['ready'] ) && ! empty( $qm_db_attribution['caller_component_trace_expected'] ), 'query_monitor_db_attribution', $qm_db_attribution, 'query_monitor_dropin' ),
			'oauth_live_authority_projection' => self::gate(
				isset( $oauth_authority_projection['contract'] ) && 'mad4b.oauth-consent-grant-projection.v3' === (string) $oauth_authority_projection['contract']
				&& ! empty( $oauth_authority_projection['read_only'] )
				&& ! empty( $oauth_authority_projection['projection_consistent'] )
				&& isset( $oauth_authority_projection['grant_lookup_strategy'] ) && 'bulk_agent_grant_snapshot' === (string) $oauth_authority_projection['grant_lookup_strategy']
				&& empty( $oauth_authority_projection['mutation_performed'] )
				&& empty( $oauth_authority_projection['oauth_scope_changed'] )
				&& empty( $oauth_authority_projection['write_authority_granted_by_consent'] ),
				'oauth_live_authority_projection',
				$oauth_authority_projection,
				'oauth_consent_ui'
			),
			'rollback_candidate' => self::gate( ! empty( $rollback['candidate_identity_ready'] ) && ! empty( $rollback['artifact_retention_verified'] ), 'rollback_candidate', $rollback, 'release_operator' ),
			'wp_import_export_exact_artifact' => self::gate( ! empty( $wp_import_export['ready'] ), 'wp_import_export_exact_artifact', $wp_import_export, 'provider_operator' ),
		);

		$blocking = array();
		foreach ( $gates as $name => $gate ) {
			if ( empty( $gate['ready'] ) ) $blocking[] = $name;
		}

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_performed' => false,
			'ready' => empty( $blocking ),
			'state' => empty( $blocking ) ? 'ready' : 'pending_or_blocked',
			'blocking_gates' => $blocking,
			'gates' => $gates,
			'write_authority_reconciliation_plan' => $reconciliation,
			'provider_certification_inventory' => $provider_inventory,
			'wp_import_export_remediation' => $wp_import_export,
			'google_auth_mode_status' => $google_mode,
			'managed_google_broker_status' => $managed,
			'admin_query_performance_status' => $admin_query_performance,
			'query_monitor_db_attribution_status' => $qm_db_attribution,
			'oauth_live_authority_projection' => $oauth_authority_projection,
			'seo_publication_authorized' => false,
			'production_activation_authorized' => false,
			'external_facts_self_certified' => false,
		);
	}

	private static function managed_google_gate_evidence( array $mode_status, array $managed ) {
		$mode = isset( $mode_status['mode'] ) ? sanitize_key( (string) $mode_status['mode'] ) : '';
		$applicable = 'managed_google' === $mode;
		$non_managed_ready = in_array( $mode, array( 'dedicated_google', 'custom_credentials' ), true );
		$mode_known = $applicable || $non_managed_ready;
		$ready = $non_managed_ready || ( $applicable && ! empty( $managed['configured'] ) && ! empty( $managed['one_click_sign_in_ready'] ) );
		$blockers = array();
		if ( ! $mode_known ) $blockers[] = 'google_auth_mode_unresolved';
		if ( $applicable && isset( $managed['blockers'] ) && is_array( $managed['blockers'] ) ) {
			$blockers = array_values( array_unique( array_merge( $blockers, array_filter( array_map( 'strval', $managed['blockers'] ) ) ) ) );
		}
		if ( $applicable && ! $ready && empty( $blockers ) ) $blockers[] = 'managed_google_selected_but_not_ready';
		return array(
			'contract' => 'mad4b.staging-managed-google-gate.v1',
			'selected_auth_mode' => $mode,
			'applicable' => $applicable,
			'ready' => $ready,
			'state' => $ready ? ( $applicable ? 'ready' : 'not_applicable' ) : 'blocked',
			'configured' => ! empty( $managed['configured'] ),
			'one_click_sign_in_ready' => ! empty( $managed['one_click_sign_in_ready'] ),
			'blockers' => $blockers,
			'managed_broker_status' => $managed,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function gate( $ready, $source, array $evidence, $owner ) {
		$blockers = array();
		foreach ( array( 'blockers', 'blocking_reasons', 'incomplete_evidence', 'provenance_mismatch', 'budget_failures' ) as $key ) {
			if ( isset( $evidence[ $key ] ) && is_array( $evidence[ $key ] ) ) {
				foreach ( $evidence[ $key ] as $item ) if ( is_scalar( $item ) && '' !== (string) $item ) $blockers[] = (string) $item;
			}
		}
		if ( isset( $evidence['blocker'] ) && is_scalar( $evidence['blocker'] ) && '' !== (string) $evidence['blocker'] ) $blockers[] = (string) $evidence['blocker'];
		return array(
			'ready' => (bool) $ready,
			'state' => $ready ? 'ready' : 'pending_or_blocked',
			'source' => (string) $source,
			'remediation_owner' => (string) $owner,
			'blockers' => array_values( array_unique( $blockers ) ),
			'evidence' => $evidence,
		);
	}

	private static function wp_import_export_remediation( array $inventory ) {
		$catalog_path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/certified-providers.json' : '';
		$catalog = array();
		if ( $catalog_path && is_readable( $catalog_path ) ) {
			$decoded = json_decode( (string) file_get_contents( $catalog_path ), true );
			if ( is_array( $decoded ) ) $catalog = $decoded;
		}
		$expected = isset( $catalog['providers']['wp-import-export']['components'] ) && is_array( $catalog['providers']['wp-import-export']['components'] )
			? $catalog['providers']['wp-import-export']['components']
			: array();
		$assessment = isset( $inventory['providers']['wp-import-export'] ) && is_array( $inventory['providers']['wp-import-export'] )
			? $inventory['providers']['wp-import-export']
			: array();
		$state = isset( $assessment['compatibility_state'] ) ? (string) $assessment['compatibility_state'] : 'unknown';
		$ready = 'certified' === $state;
		$import = isset( $expected['import'] ) && is_array( $expected['import'] ) ? $expected['import'] : array();
		$export = isset( $expected['export'] ) && is_array( $expected['export'] ) ? $expected['export'] : array();
		return array(
			'contract' => 'mad4b.wp-import-export-exact-remediation.v1',
			'read_only' => true,
			'ready' => $ready,
			'state' => $ready ? 'ready' : 'blocked',
			'compatibility_state' => $state,
			'runtime_assessment' => $assessment,
			'expected_import' => array(
				'version' => isset( $import['version'] ) ? (string) $import['version'] : '',
				'archive' => isset( $import['archive'] ) ? (string) $import['archive'] : '',
				'archive_sha256' => isset( $import['archive_sha256'] ) ? (string) $import['archive_sha256'] : '',
				'certification_authority' => isset( $import['certification_authority'] ) ? (string) $import['certification_authority'] : '',
			),
			'expected_export' => array(
				'version' => isset( $export['version'] ) ? (string) $export['version'] : '',
				'archive' => isset( $export['archive'] ) ? (string) $export['archive'] : '',
				'archive_sha256' => isset( $export['archive_sha256'] ) ? (string) $export['archive_sha256'] : '',
			),
			'remediation' => $ready ? array() : array(
				'install_exact_repository_import_artifact',
				'run_exact_package_readback',
				'run_disposable_behavioral_and_rollback_probe',
				'recheck_composite_provider_mount_plan',
			),
			'automatic_install_performed' => false,
			'production_mutation_performed' => false,
			'blockers' => $ready ? array() : array( 'wp_import_export_exact_artifact_not_certified' ),
		);
	}

	private static function brand_core_context_coverage() {
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) || ! method_exists( 'MAD4B_SCP_Context_Authority', 'brand_core_coverage' ) ) {
			return array(
				'contract' => 'mad4b.brand-core-context-coverage.v1',
				'read_only' => true,
				'mutation_performed' => false,
				'ready' => false,
				'state' => 'blocked',
				'blockers' => array( 'canonical_brand_core_coverage_unavailable' ),
			);
		}
		$canonical = MAD4B_SCP_Context_Authority::brand_core_coverage();
		$missing = isset( $canonical['missing_required_context_sets'] ) && is_array( $canonical['missing_required_context_sets'] ) ? $canonical['missing_required_context_sets'] : array();
		$conflicting = isset( $canonical['conflicting_required_context_sets'] ) && is_array( $canonical['conflicting_required_context_sets'] ) ? $canonical['conflicting_required_context_sets'] : array();
		$blockers = array();
		foreach ( $missing as $set ) $blockers[] = 'required_context_set_missing:' . sanitize_key( (string) $set );
		foreach ( $conflicting as $set ) $blockers[] = 'required_context_set_conflicting:' . sanitize_key( (string) $set );
		$pending_review = array();
		foreach ( isset( $canonical['coverage'] ) && is_array( $canonical['coverage'] ) ? $canonical['coverage'] : array() as $category => $row ) {
			foreach ( isset( $row['observed_assets'] ) && is_array( $row['observed_assets'] ) ? $row['observed_assets'] : array() as $asset ) {
				if ( is_array( $asset ) && 'approved' !== ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed' ) ) $pending_review[] = array_merge( array( 'category' => (string) $category ), $asset );
			}
		}
		$canonical['state'] = ! empty( $canonical['ready'] ) ? 'ready' : 'blocked';
		$canonical['blockers'] = array_values( array_unique( $blockers ) );
		$canonical['pending_review_assets'] = $pending_review;
		$canonical['canonical_coverage_source'] = 'MAD4B_SCP_Context_Authority::brand_core_coverage';
		return $canonical;
	}

	private static function browser_status() {
		if ( ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Core' ) ) return array( 'ready' => false, 'blockers' => array( 'browser_acceptance_core_unavailable' ) );
		$plan = MAD4B_SCP_Browser_Acceptance_Core::plan( array( 'provider_id' => 'etg-dfsb', 'profile_id' => 'tours', 'suite' => 'browser_runtime' ) );
		$result = array();
		if ( is_array( $plan ) && 'ready' === ( isset( $plan['state'] ) ? (string) $plan['state'] : '' ) && ! empty( $plan['plan_digest'] ) && ! empty( $plan['plan_signature'] ) ) {
			$result = MAD4B_SCP_Browser_Acceptance_Core::result( array(
				'provider_id' => 'etg-dfsb',
				'profile_id' => 'tours',
				'suite' => 'browser_runtime',
				'plan_digest' => (string) $plan['plan_digest'],
				'plan_signature' => (string) $plan['plan_signature'],
			) );
		}
		$verified = is_array( $result ) && ! empty( $result['verification']['browser_runtime_parity_verified'] );
		return array(
			'contract' => 'mad4b.staging-browser-certification-view.v1',
			'plan' => $plan,
			'result' => $result,
			'browser_runtime_parity_verified' => $verified,
			'blockers' => $verified ? array() : array( 'browser_runtime_not_observed' ),
		);
	}

	public static function rollback_status( $observed_sha256 = '', $observed_name = '' ) {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-ROLLBACK-CANDIDATE.json' : '';
		$receipt_path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-ROLLBACK-RETENTION-RECEIPT.json' : '';
		$base = array(
			'contract' => self::ROLLBACK_CONTRACT,
			'candidate_identity_ready' => false,
			'artifact_retention_verified' => false,
			'artifact_retention_evidence_source' => '',
			'candidate' => array(),
			'retention_receipt' => array(),
			'blockers' => array( 'rollback_candidate_manifest_missing' ),
		);
		if ( '' === $path || ! is_readable( $path ) ) return $base;
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || self::ROLLBACK_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) {
			$base['blockers'] = array( 'rollback_candidate_manifest_invalid' );
			return $base;
		}
		$valid = ! empty( $data['source_commit_sha'] ) && preg_match( '/^[a-f0-9]{40}$/', (string) $data['source_commit_sha'] )
			&& ! empty( $data['build_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $data['build_fingerprint'] )
			&& ! empty( $data['package_manifest_digest'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $data['package_manifest_digest'] )
			&& ! empty( $data['artifact_sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $data['artifact_sha256'] );
		$base['candidate_identity_ready'] = (bool) $valid;
		$base['candidate'] = $data;

		$observed_sha256 = strtolower( trim( (string) $observed_sha256 ) );
		$observed_name = trim( (string) $observed_name );
		$sha_match = $valid && 1 === preg_match( '/^[a-f0-9]{64}$/', $observed_sha256 ) && hash_equals( strtolower( (string) $data['artifact_sha256'] ), $observed_sha256 );
		$name_match = '' === $observed_name || ( isset( $data['artifact_name'] ) && hash_equals( (string) $data['artifact_name'], $observed_name ) );
		$external_verified = $sha_match && $name_match;

		$receipt_verified = false;
		$receipt = array();
		if ( $valid && '' !== $receipt_path && is_readable( $receipt_path ) ) {
			$receipt = json_decode( (string) file_get_contents( $receipt_path ), true );
			if ( ! is_array( $receipt ) ) $receipt = array();
			$expires_at = ! empty( $receipt['artifact_expires_at'] ) ? strtotime( (string) $receipt['artifact_expires_at'] ) : false;
			$time_valid = false !== $expires_at && $expires_at > ( time() + DAY_IN_SECONDS );
			$receipt_verified =
				'mad4b.rollback-retention-receipt.v1' === ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' )
				&& 'github_actions_artifact_api' === ( isset( $receipt['verification_source'] ) ? (string) $receipt['verification_source'] : '' )
				&& empty( $receipt['expired'] )
				&& $time_valid
				&& isset( $receipt['rollback_source_commit_sha'] ) && hash_equals( (string) $data['source_commit_sha'], (string) $receipt['rollback_source_commit_sha'] )
				&& isset( $receipt['rollback_control_plane_version'], $data['control_plane_version'] ) && hash_equals( (string) $data['control_plane_version'], (string) $receipt['rollback_control_plane_version'] )
				&& isset( $receipt['plugin_artifact_name'], $data['artifact_name'] ) && hash_equals( (string) $data['artifact_name'], (string) $receipt['plugin_artifact_name'] )
				&& isset( $receipt['plugin_artifact_sha256'] ) && hash_equals( strtolower( (string) $data['artifact_sha256'] ), strtolower( (string) $receipt['plugin_artifact_sha256'] ) )
				&& isset( $receipt['distribution_artifact_id'], $data['distribution_artifact_id'] ) && (string) $data['distribution_artifact_id'] === (string) $receipt['distribution_artifact_id']
				&& isset( $receipt['distribution_artifact_name'], $data['distribution_artifact_name'] ) && hash_equals( (string) $data['distribution_artifact_name'], (string) $receipt['distribution_artifact_name'] )
				&& isset( $receipt['distribution_artifact_sha256'], $data['distribution_artifact_sha256'] ) && hash_equals( strtolower( (string) $data['distribution_artifact_sha256'] ), strtolower( (string) $receipt['distribution_artifact_sha256'] ) );
			$base['retention_receipt'] = array(
				'contract' => isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '',
				'verification_source' => isset( $receipt['verification_source'] ) ? (string) $receipt['verification_source'] : '',
				'artifact_expires_at' => isset( $receipt['artifact_expires_at'] ) ? (string) $receipt['artifact_expires_at'] : '',
				'verified_at' => isset( $receipt['verified_at'] ) ? (string) $receipt['verified_at'] : '',
				'time_valid' => $time_valid,
				'exact_match' => $receipt_verified,
			);
		}

		$base['artifact_retention_verified'] = $external_verified || $receipt_verified;
		$base['artifact_retention_evidence_source'] = $receipt_verified ? 'ci_verified_packaged_receipt' : ( $external_verified ? 'external_release_operator' : '' );
		$base['observed_artifact_sha256'] = $sha_match ? $observed_sha256 : '';
		$base['observed_artifact_name'] = $name_match ? $observed_name : '';
		$base['blockers'] = ! $valid
			? array( 'rollback_candidate_identity_invalid' )
			: ( $base['artifact_retention_verified'] ? array() : array( 'rollback_artifact_retention_unverified' ) );
		return $base;
	}
}
