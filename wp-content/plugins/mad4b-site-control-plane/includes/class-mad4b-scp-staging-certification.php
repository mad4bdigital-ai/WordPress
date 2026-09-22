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
					'rollback_artifact_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-f0-9]{64}
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
		$managed = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::managed_broker_status() : array();
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
		$rollback = self::rollback_status( $rollback_artifact_sha256, $rollback_artifact_name );
		$browser = self::browser_status();
		$provider_inventory = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ? MAD4B_SCP_Provider_Compatibility_Certification::inventory() : array();

		$gates = array(
			'exact_build' => self::gate( ! empty( $provenance['runtime_manifest_match'] ), 'runtime_build_provenance', $provenance, 'package' ),
			'safe_boot' => self::gate( ! empty( $connection['connection_certified'] ) || ! empty( $connection['ready'] ), 'connection_runtime', $connection, 'runtime' ),
			'context_authority' => self::gate( ! empty( $context['ready'] ), 'context_authority', $context, 'human_review' ),
			'brand_core_context_coverage' => self::gate( ! empty( $context_coverage['ready'] ), 'brand_core_context_coverage', $context_coverage, 'human_review' ),
			'google_provider_connection' => self::gate( ! empty( $google['connected'] ) && ! empty( $google['read_available'] ), 'google_provider_connection', $google, 'operator' ),
			'managed_google_broker' => self::gate( ! empty( $managed['configured'] ) && ! empty( $managed['one_click_sign_in_ready'] ), 'managed_google_broker', $managed, 'server_secret' ),
			'skills_runtime' => self::gate( ! empty( $skills['ready'] ), 'skills_runtime', $skills, 'runtime' ),
			'external_skill_snapshot' => self::gate( ! empty( $snapshot['verified'] ) || ! empty( $snapshot['exact_match'] ), 'external_skill_snapshot', $snapshot, 'external_client' ),
			'write_authority' => self::gate( ! empty( $authority['ready'] ) && ! empty( $authority['runtime_reconciled'] ), 'write_authority', $authority, 'operator_reconcile' ),
			'write_runtime' => self::gate( ! empty( $write_runtime['ready'] ), 'write_runtime_certification', $write_runtime, 'operator_reconcile' ),
			'browser_runtime' => self::gate( ! empty( $browser['browser_runtime_parity_verified'] ), 'browser_acceptance', $browser, 'external_browser' ),
			'performance_budget' => self::gate( ! empty( $performance['ready'] ) && ! empty( $performance['budget_evaluated'] ) && ! empty( $performance['budget_pass'] ), 'frontend_performance', $performance, 'runtime_observation' ),
			'rollback_candidate' => self::gate( ! empty( $rollback['candidate_identity_ready'] ) && ! empty( $rollback['artifact_retention_verified'] ), 'rollback_candidate', $rollback, 'release_operator' ),
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
			'seo_publication_authorized' => false,
			'production_activation_authorized' => false,
			'external_facts_self_certified' => false,
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

	private static function brand_core_context_coverage() {
		$required = array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' );
		if ( class_exists( 'MAD4B_SCP_Context_Preflight' ) ) {
			$presets = MAD4B_SCP_Context_Preflight::presets();
			if ( isset( $presets['brand_core']['required_context_sets'] ) && is_array( $presets['brand_core']['required_context_sets'] ) ) {
				$required = array_values( array_map( 'sanitize_key', $presets['brand_core']['required_context_sets'] ) );
			}
		}
		$assets = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::assets() : array();
		$coverage = array();
		$pending_review = array();
		foreach ( $required as $set ) $coverage[ $set ] = array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
			if ( ! in_array( $category, $required, true ) ) continue;
			$summary = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'source_mode' => isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '',
				'content_complete' => ! empty( $asset['content_complete'] ),
			);
			$eligible = 'governed' === $summary['source_mode']
				&& 'ready' === $summary['status']
				&& 'approved' === $summary['review_status']
				&& 'brand_authority' === $summary['authority_class']
				&& $summary['content_complete'];
			if ( $eligible ) $coverage[ $category ][] = $summary;
			elseif ( 'ready' === $summary['status'] && 'approved' !== $summary['review_status'] ) $pending_review[] = $summary;
		}

		$missing = array();
		foreach ( $required as $set ) if ( empty( $coverage[ $set ] ) ) $missing[] = $set;
		return array(
			'contract' => 'mad4b.brand-core-context-coverage.v1',
			'read_only' => true,
			'required_context_sets' => $required,
			'coverage' => $coverage,
			'missing_required_context_sets' => $missing,
			'pending_review_assets' => $pending_review,
			'ready' => empty( $missing ),
			'state' => empty( $missing ) ? 'ready' : 'blocked',
			'blockers' => array_map( static function ( $set ) { return 'required_context_set_missing:' . $set; }, $missing ),
		);
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
		$base = array(
			'contract' => self::ROLLBACK_CONTRACT,
			'candidate_identity_ready' => false,
			'artifact_retention_verified' => false,
			'candidate' => array(),
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
		$base['artifact_retention_verified'] = $sha_match && $name_match;
		$base['observed_artifact_sha256'] = $sha_match ? $observed_sha256 : '';
		$base['observed_artifact_name'] = $name_match ? $observed_name : '';
		$base['blockers'] = ! $valid
			? array( 'rollback_candidate_identity_invalid' )
			: ( $base['artifact_retention_verified'] ? array() : array( 'external_rollback_artifact_retention_requires_release_operator_verification' ) );
		return $base;
	}
}
 ),
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

	public static function status() {
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$connection = class_exists( 'MAD4B_SCP_Connection_Status' ) ? MAD4B_SCP_Connection_Status::status() : array();
		$context = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::status() : array();
		$context_coverage = self::brand_core_context_coverage();
		$google = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::connection_status() : array();
		$managed = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::managed_broker_status() : array();
		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::status() : array();
		$snapshot = class_exists( 'MAD4B_SCP_External_Snapshot_Finalizer' ) ? MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status() : array();
		$authority = class_exists( 'MAD4B_SCP_Live_Truth' ) ? MAD4B_SCP_Live_Truth::current_authority_status() : array();
		$write_runtime = class_exists( 'MAD4B_SCP_Live_Truth' ) ? MAD4B_SCP_Live_Truth::current_write_certification() : array();
		$reconciliation = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
		$performance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
		$rollback = self::rollback_status();
		$browser = self::browser_status();
		$provider_inventory = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ? MAD4B_SCP_Provider_Compatibility_Certification::inventory() : array();

		$gates = array(
			'exact_build' => self::gate( ! empty( $provenance['runtime_manifest_match'] ), 'runtime_build_provenance', $provenance, 'package' ),
			'safe_boot' => self::gate( ! empty( $connection['connection_certified'] ) || ! empty( $connection['ready'] ), 'connection_runtime', $connection, 'runtime' ),
			'context_authority' => self::gate( ! empty( $context['ready'] ), 'context_authority', $context, 'human_review' ),
			'brand_core_context_coverage' => self::gate( ! empty( $context_coverage['ready'] ), 'brand_core_context_coverage', $context_coverage, 'human_review' ),
			'google_provider_connection' => self::gate( ! empty( $google['connected'] ) && ! empty( $google['read_available'] ), 'google_provider_connection', $google, 'operator' ),
			'managed_google_broker' => self::gate( ! empty( $managed['configured'] ) && ! empty( $managed['one_click_sign_in_ready'] ), 'managed_google_broker', $managed, 'server_secret' ),
			'skills_runtime' => self::gate( ! empty( $skills['ready'] ), 'skills_runtime', $skills, 'runtime' ),
			'external_skill_snapshot' => self::gate( ! empty( $snapshot['verified'] ) || ! empty( $snapshot['exact_match'] ), 'external_skill_snapshot', $snapshot, 'external_client' ),
			'write_authority' => self::gate( ! empty( $authority['ready'] ) && ! empty( $authority['runtime_reconciled'] ), 'write_authority', $authority, 'operator_reconcile' ),
			'write_runtime' => self::gate( ! empty( $write_runtime['ready'] ), 'write_runtime_certification', $write_runtime, 'operator_reconcile' ),
			'browser_runtime' => self::gate( ! empty( $browser['browser_runtime_parity_verified'] ), 'browser_acceptance', $browser, 'external_browser' ),
			'performance_budget' => self::gate( ! empty( $performance['ready'] ) && ! empty( $performance['budget_evaluated'] ) && ! empty( $performance['budget_pass'] ), 'frontend_performance', $performance, 'runtime_observation' ),
			'rollback_candidate' => self::gate( ! empty( $rollback['candidate_identity_ready'] ), 'rollback_candidate', $rollback, 'release_operator' ),
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
			'seo_publication_authorized' => false,
			'production_activation_authorized' => false,
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

	private static function brand_core_context_coverage() {
		$required = array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' );
		if ( class_exists( 'MAD4B_SCP_Context_Preflight' ) ) {
			$presets = MAD4B_SCP_Context_Preflight::presets();
			if ( isset( $presets['brand_core']['required_context_sets'] ) && is_array( $presets['brand_core']['required_context_sets'] ) ) {
				$required = array_values( array_map( 'sanitize_key', $presets['brand_core']['required_context_sets'] ) );
			}
		}
		$assets = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::assets() : array();
		$coverage = array();
		$pending_review = array();
		foreach ( $required as $set ) $coverage[ $set ] = array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
			if ( ! in_array( $category, $required, true ) ) continue;
			$summary = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'source_mode' => isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '',
				'content_complete' => ! empty( $asset['content_complete'] ),
			);
			$eligible = 'governed' === $summary['source_mode']
				&& 'ready' === $summary['status']
				&& 'approved' === $summary['review_status']
				&& 'brand_authority' === $summary['authority_class']
				&& $summary['content_complete'];
			if ( $eligible ) $coverage[ $category ][] = $summary;
			elseif ( 'ready' === $summary['status'] && 'approved' !== $summary['review_status'] ) $pending_review[] = $summary;
		}

		$missing = array();
		foreach ( $required as $set ) if ( empty( $coverage[ $set ] ) ) $missing[] = $set;
		return array(
			'contract' => 'mad4b.brand-core-context-coverage.v1',
			'read_only' => true,
			'required_context_sets' => $required,
			'coverage' => $coverage,
			'missing_required_context_sets' => $missing,
			'pending_review_assets' => $pending_review,
			'ready' => empty( $missing ),
			'state' => empty( $missing ) ? 'ready' : 'blocked',
			'blockers' => array_map( static function ( $set ) { return 'required_context_set_missing:' . $set; }, $missing ),
		);
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

	public static function rollback_status() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-ROLLBACK-CANDIDATE.json' : '';
		$base = array(
			'contract' => self::ROLLBACK_CONTRACT,
			'candidate_identity_ready' => false,
			'artifact_retention_verified' => false,
			'candidate' => array(),
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
		$base['blockers'] = $valid ? array( 'external_rollback_artifact_retention_requires_release_operator_verification' ) : array( 'rollback_candidate_identity_invalid' );
		return $base;
	}
}
