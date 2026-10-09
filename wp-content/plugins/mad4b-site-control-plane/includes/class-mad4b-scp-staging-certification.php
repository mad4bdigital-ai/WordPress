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
	const CONVERGENCE_CONTRACT = 'mad4b.staging-convergence-plan.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! wp_has_ability( 'mad4b/staging-certification-status' ) ) wp_register_ability( 'mad4b/staging-certification-status', array(
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
					'compact' => array( 'type' => 'boolean', 'default' => false ),
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
		if ( ! wp_has_ability( 'mad4b/staging-convergence-plan' ) ) wp_register_ability( 'mad4b/staging-convergence-plan', array(
			'label' => 'Plan Staging Convergence',
			'description' => 'Return an ordered, non-authorizing remediation DAG for every current Staging blocker, including Brand Core creation, authority reconciliation, external evidence, browser acceptance and performance sampling.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'convergence_plan' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'include_authoritative_content' => array( 'type' => 'boolean', 'default' => true ),
					'include_rendered_frontend' => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read', 'non_authorizing' => true ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$client_snapshot_token = isset( $input['client_snapshot_token'] ) ? trim( (string) $input['client_snapshot_token'] ) : '';
		$rollback_artifact_sha256 = isset( $input['rollback_artifact_sha256'] ) ? strtolower( trim( (string) $input['rollback_artifact_sha256'] ) ) : '';
		$rollback_artifact_name = isset( $input['rollback_artifact_name'] ) ? sanitize_text_field( (string) $input['rollback_artifact_name'] ) : '';
		$compact = ! empty( $input['compact'] );
		$provenance = self::safe_read( 'build_provenance', static function () {
			return class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		} );
		$connection = self::safe_read( 'connection_status', static function () {
			return class_exists( 'MAD4B_SCP_Connection_Status' ) ? MAD4B_SCP_Connection_Status::status() : array();
		} );
		$context = self::safe_read( 'context_authority', static function () {
			return class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::status() : array();
		} );
		$context_coverage = self::safe_read( 'brand_core_context_coverage', static function () {
			return self::brand_core_context_coverage();
		} );
		$google = self::safe_read( 'google_provider_connection', static function () {
			return class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::connection_status() : array();
		} );
		$google_mode = self::safe_read( 'google_auth_mode', static function () {
			return class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::auth_mode_status() : array();
		} );
		$managed = self::safe_read( 'managed_google_broker', static function () {
			return class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ? MAD4B_SCP_Google_Drive_Context::managed_broker_status() : array();
		} );
		$managed_gate = self::safe_read( 'managed_google_gate', static function () use ( $google_mode, $managed ) {
			return self::managed_google_gate_evidence( $google_mode, $managed );
		} );
		$skills = self::safe_read( 'skills_runtime', static function () {
			return class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::status() : array();
		} );
		$snapshot = self::safe_read( 'external_skill_snapshot', static function () use ( $client_snapshot_token ) {
			if ( ! class_exists( 'MAD4B_SCP_External_Snapshot_Finalizer' ) ) return array();
			return '' !== $client_snapshot_token
				? MAD4B_SCP_External_Snapshot_Finalizer::snapshot_verify( array( 'client_snapshot_token' => $client_snapshot_token ) )
				: MAD4B_SCP_External_Snapshot_Finalizer::external_snapshot_status();
		} );
		$authority = self::safe_read( 'write_authority', static function () {
			return class_exists( 'MAD4B_SCP_Live_Truth' ) ? MAD4B_SCP_Live_Truth::current_authority_status() : array();
		} );
		$write_runtime = self::safe_read( 'write_runtime', static function () {
			return class_exists( 'MAD4B_SCP_Live_Truth' ) ? MAD4B_SCP_Live_Truth::current_write_certification() : array();
		} );
		$reconciliation = self::safe_read( 'write_authority_reconciliation', static function () {
			return class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
		} );
		$performance = self::safe_read( 'frontend_performance', static function () {
			return class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
		} );
		$admin_query_performance = self::safe_read( 'admin_query_performance', static function () {
			return class_exists( 'MAD4B_SCP_Admin_Query_Performance' ) ? MAD4B_SCP_Admin_Query_Performance::status() : array();
		} );
		$qm_db_attribution = self::safe_read( 'query_monitor_db_attribution', static function () {
			return class_exists( 'MAD4B_SCP_Query_Monitor_Evidence_Bridge' ) ? MAD4B_SCP_Query_Monitor_Evidence_Bridge::db_attribution_status() : array();
		} );
		$oauth_authority_projection = self::safe_read( 'oauth_authority_projection', static function () {
			return class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'consent_grant_projection' )
				? MAD4B_SCP_Local_OAuth_Server::consent_grant_projection()
				: array();
		} );
		$rollback = self::safe_read( 'rollback_candidate', static function () use ( $rollback_artifact_sha256, $rollback_artifact_name ) {
			return self::rollback_status( $rollback_artifact_sha256, $rollback_artifact_name );
		} );
		$browser = self::safe_read( 'browser_runtime', static function () {
			return self::browser_status();
		} );
		$provider_inventory = self::safe_read( 'provider_certification_inventory', static function () {
			return class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ? MAD4B_SCP_Provider_Compatibility_Certification::inventory() : array();
		} );
		$wp_import_export = self::safe_read( 'wp_import_export_exact_artifact', static function () use ( $provider_inventory ) {
			return self::wp_import_export_remediation( $provider_inventory );
		} );

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

		if ( $compact ) {
			$compact_gates = array();
			foreach ( $gates as $name => $gate ) $compact_gates[ $name ] = self::compact_gate( $gate );
			return array(
				'contract' => self::CONTRACT,
				'payload_profile' => 'compact',
				'read_only' => true,
				'mutation_performed' => false,
				'production_mutation_performed' => false,
				'ready' => empty( $blocking ),
				'state' => empty( $blocking ) ? 'ready' : 'pending_or_blocked',
				'blocking_gates' => $blocking,
				'gates' => $compact_gates,
				'build' => array(
					'version' => isset( $provenance['version'] ) ? (string) $provenance['version'] : '',
					'source_commit_sha' => isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '',
					'build_fingerprint' => isset( $provenance['build_fingerprint'] ) ? (string) $provenance['build_fingerprint'] : '',
					'package_manifest_digest' => isset( $provenance['package_manifest_digest'] ) ? (string) $provenance['package_manifest_digest'] : '',
					'artifact_identity' => isset( $provenance['artifact_identity'] ) ? (string) $provenance['artifact_identity'] : '',
					'runtime_manifest_match' => ! empty( $provenance['runtime_manifest_match'] ),
				),
				'connection' => array(
					'environment' => isset( $connection['environment'] ) ? (string) $connection['environment'] : '',
					'control_plane_version' => isset( $connection['control_plane_version'] ) ? (string) $connection['control_plane_version'] : '',
					'mcp_adapter_version' => isset( $connection['mcp_adapter_version'] ) ? (string) $connection['mcp_adapter_version'] : '',
					'connection_certified' => ! empty( $connection['connection_certified'] ),
				),
				'write_authority' => array(
					'ready' => ! empty( $authority['ready'] ),
					'state' => isset( $authority['state'] ) ? (string) $authority['state'] : '',
					'candidate_binding_match' => ! empty( $authority['candidate_binding_match'] ),
					'current_source_commit_sha' => isset( $authority['current_source_commit_sha'] ) ? (string) $authority['current_source_commit_sha'] : '',
					'candidate_source_commit_sha' => isset( $authority['candidate_source_commit_sha'] ) ? (string) $authority['candidate_source_commit_sha'] : '',
				),
				'seo_publication_authorized' => false,
				'production_activation_authorized' => false,
				'external_facts_self_certified' => false,
			);
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

	public static function convergence_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$status = self::status( array( 'compact' => false ) );
		$blocking = isset( $status['blocking_gates'] ) && is_array( $status['blocking_gates'] ) ? array_values( $status['blocking_gates'] ) : array();
		$actions = array();
		$seen = array();
		$append = static function ( &$actions, &$seen, $id, array $row ) {
			if ( isset( $seen[ $id ] ) ) return;
			$seen[ $id ] = true;
			$actions[] = array_merge( array( 'action_id' => $id ), $row );
		};

		if ( in_array( 'safe_boot', $blocking, true ) ) {
			$append( $actions, $seen, 'external_mcp_handshake_refresh', array(
				'kind' => 'external_evidence',
				'executor' => 'external_mcp_client',
				'human_decision_required' => false,
				'automatic_execution_allowed' => false,
				'instruction' => 'Re-establish a real OAuth MCP session and complete initialize plus tools/list on mad4b-chatgpt.',
				'readback_ability' => 'mad4b/connection-status',
			) );
		}
		if ( in_array( 'brand_core_context_coverage', $blocking, true ) ) {
			$google_connection = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'public_connection_status' )
				? MAD4B_SCP_Google_Drive_Context::public_connection_status()
				: array();
			if ( ! empty( $google_connection['refresh_failed'] ) || ! empty( $google_connection['reconnect_required'] ) ) {
				$append( $actions, $seen, 'google_drive_reconnect', array(
					'kind' => 'external_oauth_reauthorization',
					'executor' => 'site_owner',
					'human_decision_required' => true,
					'automatic_execution_allowed' => false,
					'instruction' => 'Reconnect the governed Google Drive source with the already-selected access mode, then require a fresh provider readback before Brand generation continues.',
					'health_state' => isset( $google_connection['health_state'] ) ? (string) $google_connection['health_state'] : '',
					'refresh_failure_code' => isset( $google_connection['refresh_failure_code'] ) ? (string) $google_connection['refresh_failure_code'] : '',
					'refresh_failure_provider_code' => isset( $google_connection['refresh_failure_provider_code'] ) ? (string) $google_connection['refresh_failure_provider_code'] : '',
					'readback_ability' => 'context/google-drive-status',
					'production_policy' => 'same_site_profile_only',
				) );
			}
			$brand_plan = class_exists( 'MAD4B_SCP_Brand_Context_Builder' ) && method_exists( 'MAD4B_SCP_Brand_Context_Builder', 'convergence_plan' )
				? MAD4B_SCP_Brand_Context_Builder::convergence_plan( $input )
				: new WP_Error( 'mad4b_brand_convergence_plan_unavailable', 'Brand Core convergence planner is unavailable.' );
			$depends_on = array();
			if ( ! empty( $google_connection['refresh_failed'] ) || ! empty( $google_connection['reconnect_required'] ) ) $depends_on[] = 'google_drive_reconnect';
			$append( $actions, $seen, 'brand_core_convergence', array(
				'kind' => 'hybrid_creation',
				'executor' => 'managed_skill_or_agent_plus_wordpress',
				'human_decision_required' => true,
				'automatic_execution_allowed' => false,
				'depends_on' => $depends_on,
				'plan_ability' => 'context/brand-core-convergence-plan',
				'plan' => is_wp_error( $brand_plan ) ? array( 'error_code' => $brand_plan->get_error_code() ) : $brand_plan,
				'readback_ability' => 'context/brand-core-coverage',
			) );
		}
		if ( in_array( 'external_skill_snapshot', $blocking, true ) ) {
			$append( $actions, $seen, 'external_snapshot_refresh', array(
				'kind' => 'external_evidence',
				'executor' => 'external_mcp_client',
				'human_decision_required' => false,
				'automatic_execution_allowed' => false,
				'depends_on' => array( 'external_mcp_handshake_refresh' ),
				'instruction' => 'Export a fresh exact-build snapshot token and finalize it from the same verified external subject/session after tools/list.',
				'readback_ability' => 'mad4b/live-acceptance-status',
			) );
		}
		if ( in_array( 'write_authority', $blocking, true ) || in_array( 'write_runtime', $blocking, true ) ) {
			$grant_plan = class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan' )
				? MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan()
				: array();
			$grant_plan_error = is_wp_error( $grant_plan );
			$missing_write = ! $grant_plan_error && isset( $grant_plan['expected_missing_abilities'] ) && is_array( $grant_plan['expected_missing_abilities'] ) ? $grant_plan['expected_missing_abilities'] : array();
			$stale_grants = ! $grant_plan_error && isset( $grant_plan['expected_stale_grant_ids'] ) && is_array( $grant_plan['expected_stale_grant_ids'] ) ? $grant_plan['expected_stale_grant_ids'] : array();
			$missing_transport = ! $grant_plan_error && isset( $grant_plan['expected_missing_transport_abilities'] ) && is_array( $grant_plan['expected_missing_transport_abilities'] ) ? $grant_plan['expected_missing_transport_abilities'] : array();
			$requires_grant_reconcile = ! $grant_plan_error && ( ! empty( $missing_write ) || ! empty( $stale_grants ) || ! empty( $missing_transport ) );
			if ( $grant_plan_error ) {
				$append( $actions, $seen, 'write_authority_plan_blocked', array(
					'kind' => 'read_only_blocker',
					'executor' => 'wordpress_native',
					'human_decision_required' => false,
					'automatic_execution_allowed' => false,
					'plan_ability' => 'mad4b/staging-write-grant-reconciliation-plan',
					'error_code' => $grant_plan->get_error_code(),
					'instruction' => 'Repair the read-only exact authority planning preconditions before any authority mutation is considered.',
					'production_policy' => 'deny',
				) );
			}
			if ( $grant_plan_error ) {
				// The blocker action above is intentionally terminal for this authority lane.
			} elseif ( $requires_grant_reconcile ) {
				$append( $actions, $seen, 'write_authority_reconcile', array(
					'kind' => 'governed_mutation',
					'executor' => 'wordpress_native',
					'human_decision_required' => true,
					'automatic_execution_allowed' => false,
					'plan_ability' => 'mad4b/staging-write-grant-reconciliation-plan',
					'apply_ability' => 'mad4b/staging-write-grant-reconcile',
					'plan_sha256' => isset( $grant_plan['plan_sha256'] ) ? (string) $grant_plan['plan_sha256'] : '',
					'expected_missing_abilities' => array_values( $missing_write ),
					'expected_stale_grant_ids' => array_values( $stale_grants ),
					'expected_missing_transport_abilities' => array_values( $missing_transport ),
					'readback_ability' => 'mad4b/write-runtime-certification',
					'production_policy' => 'deny',
					'breakglass' => false,
				) );
			} else {
				$candidate_plan = class_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding' ) && method_exists( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'plan' )
					? MAD4B_SCP_Staging_Write_Candidate_Binding::plan()
					: array();
				$append( $actions, $seen, 'candidate_binding_only', array(
					'kind' => 'governed_mutation',
					'executor' => 'wordpress_native',
					'human_decision_required' => true,
					'automatic_execution_allowed' => false,
					'plan_ability' => 'mad4b/staging-write-candidate-binding-plan',
					'apply_ability' => 'mad4b/staging-write-candidate-bind',
					'plan' => $candidate_plan,
					'readback_ability' => 'mad4b/write-runtime-certification',
					'grant_mutation_allowed' => false,
					'production_policy' => 'deny',
					'breakglass' => false,
				) );
			}
		}
		if ( in_array( 'browser_runtime', $blocking, true ) ) {
			$append( $actions, $seen, 'browser_acceptance', array(
				'kind' => 'external_executor_job',
				'executor' => 'external_browser_agent',
				'human_decision_required' => false,
				'automatic_execution_allowed' => true,
				'operation_id' => 'browser_acceptance_execution',
				'apply_ability' => 'mad4b/browser-acceptance-run',
				'readback_ability' => 'mad4b/browser-acceptance-result',
			) );
		}
		if ( in_array( 'performance_budget', $blocking, true ) ) {
			$append( $actions, $seen, 'frontend_performance_sampling', array(
				'kind' => 'external_executor_job',
				'executor' => 'external_browser_agent',
				'human_decision_required' => false,
				'automatic_execution_allowed' => true,
				'operation_id' => 'frontend_performance_sampling',
				'apply_ability' => 'mad4b/frontend-performance-sample-run',
				'readback_ability' => 'mad4b/frontend-performance-status',
				'minimum_samples' => 3,
			) );
		}
		$append( $actions, $seen, 'provider_closure_review', array(
			'kind' => 'read_only_followup',
			'executor' => 'wordpress_native',
			'human_decision_required' => false,
			'automatic_execution_allowed' => true,
			'plan_ability' => 'mad4b/provider-closure-matrix',
			'instruction' => 'Keep uncertified provider writes fail-closed; route each item to adapter/catalog reconciliation, behavioral recertification, artifact authority, or owner-governed canary.',
		) );

		// Complete the dynamic gate-to-action map after all native planners
		// have spoken. No missing gate, dangling dependency or unverified
		// external executor may silently become an executable operation.
		$coverage = self::complete_convergence_coverage( $status['gates'] ?? array(), $actions );
		$actions = $coverage['actions'];
		// Every action must be checked against *current* site, profile and
		// package authority again by its own governed executor. This binding
		// is evidence for planning, not an approval or time-independent grant.
		$build_evidence = $status['gates']['exact_build']['evidence'] ?? array();
		$authority_evidence = $status['gates']['write_authority']['evidence'] ?? array();
		$plan_binding = array(
			'contract' => 'mad4b.staging-convergence-plan-binding.v1',
			'source_commit_sha' => (string) ( $build_evidence['source_commit_sha'] ?? '' ),
			'build_fingerprint' => (string) ( $build_evidence['build_fingerprint'] ?? '' ),
			'package_manifest_digest' => (string) ( $build_evidence['package_manifest_digest'] ?? '' ),
			'site_uuid' => (string) ( $authority_evidence['site_uuid'] ?? '' ),
			'site_profile_digest' => (string) ( $authority_evidence['site_profile_digest'] ?? '' ),
			'candidate_binding_match' => ! empty( $authority_evidence['candidate_binding_match'] ),
			'revalidate_before_any_effect' => true,
			'never_grants_authority' => true,
		);
		$basis = array(
			'contract' => self::CONVERGENCE_CONTRACT,
			'plan_binding' => $plan_binding,
			'gate_coverage_complete' => $coverage['coverage_complete'],
			'covered_gate_count' => $coverage['covered_gate_count'],
			'blocked_gate_count' => $coverage['blocked_gate_count'],
			'dispatch_allowed' => false,
			'coverage_contract' => $coverage['contract'],
			'gate_action_coverage' => $coverage['gate_action_coverage'],
			'plan_integrity_blockers' => $coverage['plan_integrity_blockers'],
			'external_execution_requires_independent_preflight' => true,
			'autonomous_mutation_authorized' => false,
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_performed' => false,
			'current_ready' => ! empty( $status['ready'] ),
			'blocking_gates' => $blocking,
			'actions' => $actions,
			'principle' => 'automate_evidence_and_planning_never_self_certify_or_auto_approve_authority',
		);
		$encoded = wp_json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$basis['plan_sha256'] = false === $encoded ? '' : hash( 'sha256', $encoded );
		return $basis;
	}

	/**
	 * Pure cross-gate closure reducer. A gate's owner and evidence source come
	 * from the live site snapshot; no site-specific CPT, domain, provider or
	 * authority is inferred here. Unknown/new gates become review-only actions.
	 *
	 * This is a *plan*, never an execution engine. It may enumerate existing
	 * governed apply Abilities, but does not approve or invoke them.
	 */
	public static function complete_convergence_coverage( array $gates, array $actions ) {
		$links = array(
			'external_mcp_handshake_refresh' => array( 'safe_boot' ),
			'google_drive_reconnect' => array( 'google_provider_connection', 'brand_core_context_coverage' ),
			'brand_core_convergence' => array( 'context_authority', 'brand_core_context_coverage' ),
			'external_snapshot_refresh' => array( 'external_skill_snapshot' ),
			'write_authority_plan_blocked' => array( 'write_authority', 'write_runtime' ),
			'write_authority_reconcile' => array( 'write_authority', 'write_runtime' ),
			'candidate_binding_only' => array( 'write_authority', 'write_runtime' ),
			'browser_acceptance' => array( 'browser_runtime' ),
			'frontend_performance_sampling' => array( 'performance_budget' ),
		);
		$readbacks = array(
			'exact_build' => 'mad4b/staging-certification-status',
			'safe_boot' => 'mad4b/connection-status',
			'context_authority' => 'context/brand-core-coverage',
			'brand_core_context_coverage' => 'context/brand-core-coverage',
			'google_provider_connection' => 'context/google-drive-status',
			'managed_google_broker' => 'mad4b/staging-certification-status',
			'skills_runtime' => 'mad4b/skills-runtime-certification',
			'external_skill_snapshot' => 'mad4b/live-acceptance-status',
			'write_authority' => 'mad4b/write-authority-status',
			'write_runtime' => 'mad4b/write-runtime-certification',
			'browser_runtime' => 'mad4b/browser-acceptance-capabilities',
			'performance_budget' => 'mad4b/frontend-performance-status',
			'admin_query_performance' => 'mad4b/staging-certification-status',
			'query_monitor_db_attribution' => 'mad4b/staging-certification-status',
			'oauth_live_authority_projection' => 'mad4b/staging-certification-status',
			'rollback_candidate' => 'mad4b/staging-certification-status',
			'wp_import_export_exact_artifact' => 'mad4b/provider-closure-matrix',
		);
		$blocked = array();
		$coverage = array();
		foreach ( $gates as $id => $gate ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_]{0,79}$/D', $id ) ) continue;
			if ( ! is_array( $gate ) || ! empty( $gate['ready'] ) ) continue;
			$blocked[ $id ] = $gate;
			$coverage[ $id ] = array();
		}
		$by_id = array();
		$issues = array();
		foreach ( $actions as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['action_id'] ?? null )
				|| ! preg_match( '/^[a-z][a-z0-9_]{0,95}$/D', $row['action_id'] ) ) {
				$issues[] = 'invalid_action_identity';
				continue;
			}
			$id = $row['action_id'];
			if ( isset( $by_id[ $id ] ) ) {
				$issues[] = 'duplicate_action_identity:' . $id;
				continue;
			}
			$related = array();
			foreach ( $links[ $id ] ?? array() as $gate_id ) {
				if ( isset( $blocked[ $gate_id ] ) ) {
					$related[] = $gate_id;
					$coverage[ $gate_id ][] = $id;
				}
			}
			$row['target_gates'] = $related;
			$row['read_only_plan'] = true;
			$row['authorizing'] = false;
			$row['mutation_performed'] = false;
			// A configuration flag is not a signed external browser receipt.
			// No external executor can be launched from this planning view.
			if ( 'external_executor_job' === ( $row['kind'] ?? '' ) ) {
				$row['automatic_execution_allowed'] = false;
				$row['external_preflight_required'] = true;
				$row['required_evidence'] = array(
					'current_build_bound_plan', 'registered_provider',
					'verified_external_executor_identity', 'signed_replay_safe_receipt',
					'fresh_post_execution_readback',
				);
			}
			$by_id[ $id ] = $row;
		}
		if ( isset( $blocked['external_skill_snapshot'] ) && isset( $by_id['external_snapshot_refresh'] )
			&& ! isset( $by_id['external_mcp_handshake_refresh'] ) ) {
			$by_id['external_mcp_handshake_refresh'] = array(
				'action_id' => 'external_mcp_handshake_refresh',
				'kind' => 'external_evidence',
				'executor' => 'external_mcp_client',
				'human_decision_required' => false,
				'automatic_execution_allowed' => false,
				'depends_on' => array(),
				'target_gates' => array( 'external_skill_snapshot' ),
				'instruction' => 'Repeat an authenticated exact-build MCP initialize and tools/list readback before finalizing external snapshot.',
				'readback_ability' => 'mad4b/live-acceptance-status',
				'read_only_plan' => true,
				'authorizing' => false,
				'mutation_performed' => false,
			);
			$coverage['external_skill_snapshot'][] = 'external_mcp_handshake_refresh';
		}
		foreach ( $blocked as $gate_id => $gate ) {
			if ( ! empty( $coverage[ $gate_id ] ) ) continue;
			$id = 'review_gate_' . $gate_id;
			$by_id[ $id ] = array(
				'action_id' => $id,
				'kind' => 'read_only_blocker',
				'executor' => (string) ( $gate['remediation_owner'] ?? 'operator' ),
				'human_decision_required' => true,
				'automatic_execution_allowed' => false,
				'depends_on' => array(),
				'target_gates' => array( $gate_id ),
				'evidence_source' => (string) ( $gate['source'] ?? '' ),
				'evidence_blockers' => array_values( array_slice(
					is_array( $gate['blockers'] ?? null ) ? $gate['blockers'] : array(), 0, 12
				) ),
				'readback_ability' => $readbacks[ $gate_id ] ?? 'mad4b/staging-certification-status',
				'instruction' => 'Inspect this exact live gate, resolve missing provider/host/owner evidence in its own governed lane, then rerun Staging certification.',
				'no_automatic_remediation_available' => true,
				'read_only_plan' => true,
				'authorizing' => false,
				'mutation_performed' => false,
			);
			$coverage[ $gate_id ][] = $id;
		}
		// A strictly bounded topological ordering. Unknown dependency IDs
		// stay blocked rather than being removed from the operation contract.
		$ordered = array();
		$state = array();
		$visit = static function ( $id ) use ( &$visit, &$state, &$ordered, &$issues, $by_id ) {
			if ( ( $state[ $id ] ?? '' ) === 'complete' ) return;
			if ( ( $state[ $id ] ?? '' ) === 'visiting' ) {
				$issues[] = 'action_dependency_cycle:' . $id;
				return;
			}
			$state[ $id ] = 'visiting';
			$deps = $by_id[ $id ]['depends_on'] ?? array();
			if ( ! is_array( $deps ) ) {
				$issues[] = 'invalid_action_dependencies:' . $id;
				$deps = array();
			}
			foreach ( $deps as $dependency ) {
				if ( ! is_string( $dependency ) || ! isset( $by_id[ $dependency ] ) ) {
					$issues[] = 'missing_action_dependency:' . $id;
					continue;
				}
				$visit( $dependency );
			}
			$state[ $id ] = 'complete';
			$ordered[] = $by_id[ $id ];
		};
		ksort( $by_id, SORT_STRING );
		foreach ( array_keys( $by_id ) as $id ) $visit( $id );
		$issues = array_values( array_unique( $issues ) );
		if ( $issues ) {
			// A broken plan cannot provide executable or approving authority.
			foreach ( $ordered as &$item ) {
				$item['automatic_execution_allowed'] = false;
				$item['plan_integrity_blocked'] = true;
			}
			unset( $item );
		}
		ksort( $coverage, SORT_STRING );
		return array(
			'contract' => 'mad4b.staging-gate-action-coverage.v1',
			'actions' => $ordered,
			'gate_action_coverage' => $coverage,
			'plan_integrity_blockers' => $issues,
			'blocked_gate_count' => count( $blocked ),
			'covered_gate_count' => count( $coverage ),
			'coverage_complete' => count( $blocked ) === count( $coverage ) && ! $issues,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function safe_read( $name, $callback ) {
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) {
			return array(
				'ready' => false,
				'state' => 'read_failed',
				'blockers' => array( 'connector_resilience_unavailable' ),
				'connector_error' => array(
					'contract' => 'mad4b.connector-resilience.unavailable',
					'retryable' => false,
				),
			);
		}
		$result = MAD4B_SCP_Connector_Resilience::safe_read( $name, $callback, array(
			'retry_transient' => true,
			'max_attempts' => MAD4B_SCP_Connector_Resilience::DEFAULT_READ_ATTEMPTS,
		) );
		if ( ! empty( $result['ok'] ) ) {
			return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		}
		return array(
			'ready' => false,
			'state' => 'read_failed',
			'blockers' => array( 'connector_read_failed:' . sanitize_key( (string) $name ) ),
			'connector_error' => array(
				'contract' => isset( $result['contract'] ) ? (string) $result['contract'] : MAD4B_SCP_Connector_Resilience::CONTRACT,
				'category' => isset( $result['category'] ) ? (string) $result['category'] : 'unknown',
				'retryable' => ! empty( $result['retryable'] ),
				'attempts' => isset( $result['attempts'] ) ? (int) $result['attempts'] : 1,
				'elapsed_ms' => isset( $result['elapsed_ms'] ) ? (int) $result['elapsed_ms'] : 0,
				'error_code' => isset( $result['error_code'] ) ? (string) $result['error_code'] : '',
				'error_class' => isset( $result['error_class'] ) ? (string) $result['error_class'] : '',
				'error_fingerprint' => isset( $result['error_fingerprint'] ) ? (string) $result['error_fingerprint'] : '',
				'raw_error_message_exposed' => false,
			),
		);
	}

	private static function compact_gate( array $gate ) {
		$evidence = isset( $gate['evidence'] ) && is_array( $gate['evidence'] ) ? $gate['evidence'] : array();
		return array(
			'ready' => ! empty( $gate['ready'] ),
			'state' => isset( $gate['state'] ) ? (string) $gate['state'] : '',
			'source' => isset( $gate['source'] ) ? (string) $gate['source'] : '',
			'remediation_owner' => isset( $gate['remediation_owner'] ) ? (string) $gate['remediation_owner'] : '',
			'blockers' => isset( $gate['blockers'] ) && is_array( $gate['blockers'] ) ? array_values( array_slice( $gate['blockers'], 0, 20 ) ) : array(),
			'evidence_contract' => isset( $evidence['contract'] ) ? (string) $evidence['contract'] : '',
			'evidence_state' => isset( $evidence['state'] ) ? (string) $evidence['state'] : '',
			'connector_error' => isset( $evidence['connector_error'] ) && is_array( $evidence['connector_error'] ) ? $evidence['connector_error'] : array(),
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
		foreach ( array( 'blockers', 'blocking_reasons', 'incomplete_evidence', 'provenance_mismatch', 'budget_failures', 'certification_blockers' ) as $key ) {
			if ( isset( $evidence[ $key ] ) && is_array( $evidence[ $key ] ) ) {
				foreach ( $evidence[ $key ] as $item ) if ( is_scalar( $item ) && '' !== (string) $item ) $blockers[] = (string) $item;
			}
		}
		if ( isset( $evidence['blocker'] ) && is_scalar( $evidence['blocker'] ) && '' !== (string) $evidence['blocker'] ) $blockers[] = (string) $evidence['blocker'];
		if ( ! $ready && empty( $blockers ) && isset( $evidence['state'] ) && is_scalar( $evidence['state'] ) ) {
			$state_blocker = sanitize_key( (string) $evidence['state'] );
			if ( '' !== $state_blocker && ! in_array( $state_blocker, array( 'ready', 'ok', 'not_applicable' ), true ) ) $blockers[] = $state_blocker;
		}
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
		$capabilities = MAD4B_SCP_Browser_Acceptance_Core::capabilities();
		$providers = isset( $capabilities['providers'] ) && is_array( $capabilities['providers'] ) ? $capabilities['providers'] : array();
		$ids = array();
		foreach ( $providers as $provider ) {
			if ( is_array( $provider ) && isset( $provider['provider_id'] ) && is_string( $provider['provider_id'] ) ) $ids[] = $provider['provider_id'];
		}
		$operator = class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI' ) ? MAD4B_SCP_Browser_Acceptance_Admin_UI::selection() : array();
		$chosen = isset( $operator['site_provider_id'] ) && is_string( $operator['site_provider_id'] ) ? $operator['site_provider_id'] : '';
		$blockers = array();
		if ( '' !== $chosen ) {
			if ( ! in_array( $chosen, $ids, true ) ) $blockers[] = 'selected_site_browser_provider_unregistered';
		} elseif ( 1 === count( $ids ) ) $chosen = $ids[0];
		elseif ( count( $ids ) > 1 ) $blockers[] = 'site_browser_provider_selection_required';
		else $blockers[] = 'site_browser_provider_missing';
		$profile_id = isset( $operator['profile_id'] ) && is_string( $operator['profile_id'] ) ? $operator['profile_id'] : '';
		// Never infer site identity from a provider name. Profiles are advertised by
		// the registered provider and remain subject to the signed-plan reducer.
		if ( '' === $profile_id && '' !== $chosen && ! $blockers ) {
			foreach ( $providers as $provider ) {
				if ( ! is_array( $provider ) || ( isset( $provider['provider_id'] ) ? $provider['provider_id'] : '' ) !== $chosen ) continue;
				$candidate = isset( $provider['capabilities']['default_profile_id'] ) ? $provider['capabilities']['default_profile_id'] : '';
				if ( is_string( $candidate ) && preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $candidate ) ) $profile_id = $candidate;
				break;
			}
		}
		if ( '' === $profile_id && ! $blockers ) $blockers[] = 'site_browser_acceptance_profile_required';
		if ( $blockers ) return array(
			'contract' => 'mad4b.staging-browser-certification-view.v3', 'ready' => false,
			'provider_count' => count( $ids ), 'selected_provider_id' => $chosen,
			'selected_profile_id' => $profile_id, 'blockers' => $blockers,
			'browser_runtime_parity_verified' => false, 'durable_receipt_used' => false,
		);
		$provider_id = $chosen;
		$selection_check = MAD4B_SCP_Browser_Acceptance_Admin_UI::runtime_target_guard( $provider_id, $profile_id );
		if ( is_wp_error( $selection_check ) ) return array(
			'contract' => 'mad4b.staging-browser-certification-view.v3', 'ready' => false,
			'provider_count' => count( $ids ), 'selected_provider_id' => $provider_id,
			'selected_profile_id' => $profile_id, 'blockers' => array( $selection_check->get_error_code() ),
			'browser_runtime_parity_verified' => false, 'durable_receipt_used' => false,
		);
		$plan = MAD4B_SCP_Browser_Acceptance_Core::plan( array( 'provider_id' => $provider_id, 'profile_id' => $profile_id, 'suite' => 'browser_runtime' ) );
		$result = array();
		$durable_job_id = '';
		$durable_receipt_used = false;
		$durable_receipt_source = 'none';
		if ( is_array( $plan ) && 'ready' === ( isset( $plan['state'] ) ? (string) $plan['state'] : '' ) && ! empty( $plan['plan_digest'] ) && ! empty( $plan['plan_signature'] ) ) {
			$receipt_status = class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) && method_exists( 'MAD4B_SCP_Remote_Operation_Parity', 'browser_acceptance_receipt_status' )
				? MAD4B_SCP_Remote_Operation_Parity::browser_acceptance_receipt_status( $plan )
				: array();
			if ( is_array( $receipt_status ) && ! empty( $receipt_status['ready'] ) ) {
				$receipt = isset( $receipt_status['receipt'] ) && is_array( $receipt_status['receipt'] ) ? $receipt_status['receipt'] : array();
				$result = isset( $receipt['browser_result'] ) && is_array( $receipt['browser_result'] ) ? $receipt['browser_result'] : array();
				$durable_job_id = isset( $receipt['job_id'] ) ? (string) $receipt['job_id'] : '';
				$durable_receipt_used = true;
				$durable_receipt_source = 'dedicated_receipt';
			}

			// Compatibility fallback for candidates completed before the dedicated
			// browser receipt option existed. Queue retention is bounded, so new
			// completions always persist the dedicated exact-build receipt above.
			if ( ! $durable_receipt_used ) {
				$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
				$jobs = class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) ? MAD4B_SCP_Remote_Work_Queue::list_jobs( 'browser_acceptance_execution' ) : array();
				foreach ( (array) ( isset( $jobs['items'] ) ? $jobs['items'] : array() ) as $job ) {
					if ( ! is_array( $job ) || 'completed' !== ( isset( $job['status'] ) ? (string) $job['status'] : '' ) ) continue;
					$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
					$expected = isset( $job['expected_identity'] ) && is_array( $job['expected_identity'] ) ? $job['expected_identity'] : array();
					$verified_result = isset( $job['result']['browser_result'] ) && is_array( $job['result']['browser_result'] ) ? $job['result']['browser_result'] : array();
					$identity_match = ! empty( $provenance['runtime_manifest_match'] )
						&& empty( $provenance['stale'] )
						&& isset( $expected['source_commit_sha'], $expected['build_fingerprint'], $expected['package_manifest_digest'] )
						&& hash_equals( strtolower( (string) $provenance['source_commit_sha'] ), strtolower( (string) $expected['source_commit_sha'] ) )
						&& hash_equals( strtolower( (string) $provenance['build_fingerprint'] ), strtolower( (string) $expected['build_fingerprint'] ) )
						&& hash_equals( strtolower( (string) $provenance['package_manifest_digest'] ), strtolower( (string) $expected['package_manifest_digest'] ) );
					$plan_match = $provider_id === ( isset( $payload['provider_id'] ) ? (string) $payload['provider_id'] : '' )
						&& $profile_id === ( isset( $payload['profile_id'] ) ? (string) $payload['profile_id'] : '' )
						&& hash_equals( strtolower( (string) $plan['plan_digest'] ), strtolower( (string) ( isset( $payload['plan_digest'] ) ? $payload['plan_digest'] : '' ) ) )
						&& hash_equals( strtolower( (string) $plan['plan_signature'] ), strtolower( (string) ( isset( $payload['plan_signature'] ) ? $payload['plan_signature'] : '' ) ) );
					$receipt_valid = 'PASS' === ( isset( $verified_result['verdict'] ) ? (string) $verified_result['verdict'] : '' )
						&& ! empty( $verified_result['verification']['browser_runtime_parity_verified'] )
						&& isset( $verified_result['plan_digest'] )
						&& hash_equals( strtolower( (string) $plan['plan_digest'] ), strtolower( (string) $verified_result['plan_digest'] ) );
					if ( $identity_match && $plan_match && $receipt_valid ) {
						$result = $verified_result;
						$durable_job_id = isset( $job['job_id'] ) ? (string) $job['job_id'] : '';
						$durable_receipt_used = true;
						$durable_receipt_source = 'queue_fallback';
						break;
					}
				}
			}

			if ( ! $durable_receipt_used ) {
				$result = MAD4B_SCP_Browser_Acceptance_Core::result( array(
					'provider_id' => $provider_id,
					'profile_id' => $profile_id,
					'suite' => 'browser_runtime',
					'plan_digest' => (string) $plan['plan_digest'],
					'plan_signature' => (string) $plan['plan_signature'],
				) );
			}
		}
		$verified = is_array( $result ) && 'PASS' === ( isset( $result['verdict'] ) ? (string) $result['verdict'] : '' )
			&& ! empty( $result['verification']['browser_runtime_parity_verified'] )
			&& isset( $result['plan_digest'], $plan['plan_digest'] )
			&& hash_equals( strtolower( (string) $plan['plan_digest'] ), strtolower( (string) $result['plan_digest'] ) );
		return array(
			'contract' => 'mad4b.staging-browser-certification-view.v3',
			'selected_provider_id' => $provider_id,
			'selected_profile_id' => $profile_id,
			'provider_count' => count( $ids ),
			'plan' => $plan,
			'result' => $result,
			'durable_receipt_used' => $durable_receipt_used,
			'durable_receipt_source' => $durable_receipt_source,
			'durable_job_id' => $durable_job_id,
			'ready' => $verified,
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
