<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Remote Operation Parity
 *
 * Every automation-eligible local maintenance operation must have a bounded,
 * governed remote counterpart. This is not a generic remote-admin surface.
 * Each operation remains semantically named, exact-build-bound and fail-closed.
 */
final class MAD4B_SCP_Remote_Operation_Parity {
	const CONTRACT = 'mad4b.remote-operation-parity.v1';
	const STATUS_ABILITY = 'mad4b/remote-operation-parity-status';
	const DISCOVER_ABILITY = 'mad4b/operation-discover';
	const SKILLS_ABILITY = 'mad4b/reconcile-managed-skills';
	const FRONTEND_SAMPLE_ABILITY = 'mad4b/frontend-performance-sample-run';
	const PERFORMANCE_INDEX_ABILITY = 'mad4b/admin-query-performance-apply';
	const PERFORMANCE_RECONCILE_ABILITY = 'mad4b/admin-query-performance-reconcile';
	const WORK_QUEUE_ABILITY = 'mad4b/remote-operation-work-queue';
	const WORK_CLAIM_ABILITY = 'mad4b/remote-operation-work-claim';
	const WORK_COMPLETE_ABILITY = 'mad4b/remote-operation-work-complete';
	const SKILLS_STATE_OPTION = 'mad4b_scp_remote_skills_reconciliation_v1';
	const SKILLS_LOCK_OPTION = 'mad4b_scp_remote_skills_reconciliation_lock_v1';
	const SKILLS_LOCK_TTL = 900;
	const BROWSER_REQUEST_OPTION = 'mad4b_scp_remote_browser_sample_request_v1';

	const SKILLS_CONFIRMATION = 'RECONCILE MANAGED SKILLS';
	const FRONTEND_CONFIRMATION = 'COLLECT FRONTEND PERFORMANCE SAMPLES';
	const PERFORMANCE_CONFIRMATION = 'APPLY STAGING PERFORMANCE INDEXES';
	const PERFORMANCE_RECONCILE_CONFIRMATION = 'RECONCILE STALE STAGING PERFORMANCE INDEXES';

	private static $booted = false;
	private static $running = array();
	private static $catalog_rejections = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 13 );
	}

	public static function enrollment_abilities() {
		return array(
			self::SKILLS_ABILITY,
			self::FRONTEND_SAMPLE_ABILITY,
			self::PERFORMANCE_INDEX_ABILITY,
			self::PERFORMANCE_RECONCILE_ABILITY,
			self::WORK_CLAIM_ABILITY,
			self::WORK_COMPLETE_ABILITY,
		);
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::STATUS_ABILITY ) ) ) {
			wp_register_ability(
				self::STATUS_ABILITY,
				array(
					'label' => 'Get Remote Operation Parity Status',
					'description' => 'Inspect whether automation-eligible local maintenance operations have bounded governed remote counterparts.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'status' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, true ),
				)
			);
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::DISCOVER_ABILITY ) ) ) {
			wp_register_ability(
				self::DISCOVER_ABILITY,
				array(
					'label' => 'Discover Governed Operations',
					'description' => 'Search the governed operation catalog by feature, operation, provider, executor, authority surface, remote mode, or free-text capability intent without knowing an ability name in advance.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'discover' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'query' => array( 'type' => 'string', 'maxLength' => 160 ),
							'feature_id' => array( 'type' => 'string', 'maxLength' => 96 ),
							'authority_surface' => array( 'type' => 'string', 'maxLength' => 64 ),
							'executor' => array( 'type' => 'string', 'maxLength' => 96 ),
							'provider' => array( 'type' => 'string', 'maxLength' => 96 ),
							'remote_ready_only' => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, true ),
				)
			);
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::WORK_QUEUE_ABILITY ) ) ) {
			wp_register_ability(
				self::WORK_QUEUE_ABILITY,
				array(
					'label' => 'Get Remote Operation Work Queue',
					'description' => 'Inspect bounded semantic work awaiting an external governed executor without exposing lease secrets.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'remote_work_queue' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => self::work_queue_schema(),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, true ),
				)
			);
		}

		self::register_remote_operation(
			self::SKILLS_ABILITY,
			'Reconcile Managed Skills Remotely',
			'Reconcile only MAD4B-managed canonical seed Skills and managed provider Skills through the bounded Staging enrollment surface.',
			self::operation_schema( self::SKILLS_CONFIRMATION ),
			array( __CLASS__, 'reconcile_managed_skills' ),
			true
		);

		self::register_remote_operation(
			self::FRONTEND_SAMPLE_ABILITY,
			'Collect Frontend Performance Samples',
			'Queue bounded exact-build Frontend sampling for an external governed browser executor; server self-loopback is never treated as browser-runtime evidence.',
			self::frontend_sample_schema(),
			array( __CLASS__, 'collect_frontend_samples' ),
			false
		);

		self::register_remote_operation(
			self::PERFORMANCE_INDEX_ABILITY,
			'Apply Staging Performance Indexes Remotely',
			'Apply only the fixed additive MAD4B Staging performance indexes exposed by the existing performance maintenance service.',
			self::operation_schema( self::PERFORMANCE_CONFIRMATION ),
			array( __CLASS__, 'apply_performance_indexes' ),
			true
		);

		self::register_remote_operation(
			self::WORK_CLAIM_ABILITY,
			'Claim Remote Operation Work',
			'Claim one bounded semantic external-executor job with an expiring fenced lease. No generic command execution is exposed.',
			self::work_claim_schema(),
			array( __CLASS__, 'claim_remote_work' ),
			false
		);

		self::register_remote_operation(
			self::WORK_COMPLETE_ABILITY,
			'Complete Remote Operation Work',
			'Complete a leased semantic job only after WordPress independently observes the required acceptance evidence.',
			self::work_complete_schema(),
			array( __CLASS__, 'complete_remote_work' ),
			true
		);
	}

	private static function register_remote_operation( $name, $label, $description, array $schema, $callback, $idempotent ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );
		try {
			wp_register_ability(
				$name,
				array(
					'label' => $label,
					'description' => $description,
					'category' => 'mad4b-governance',
					'execute_callback' => $callback,
					'permission_callback' => array( __CLASS__, 'can_execute' ),
					'input_schema' => $schema,
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( false, $idempotent ),
				)
			);
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	private static function meta( $readonly, $idempotent ) {
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array(
				'public' => false,
				'type' => 'tool',
				'surface' => $readonly ? 'read' : 'enrollment',
				'mad4b_remote_operation_parity' => self::CONTRACT,
				'generic_remote_admin' => false,
				'production_mutation_allowed' => false,
			),
			'annotations' => array(
				'readonly' => (bool) $readonly,
				'destructive' => false,
				'idempotent' => (bool) $idempotent,
			),
		);
	}

	public static function catalog() {
		$rows = array(
			'managed_skills_reconciliation' => array(
				'feature_id' => 'dynamic-skills',
				'capability_tags' => array( 'skills', 'seed', 'provider-reconciliation', 'maintenance', 'bootstrap' ),
				'provider' => 'core',
				'status_ability' => 'mad4b/skills-runtime-certification',
				'local_surface' => 'wp-admin:MAD4B/Skills/Reconcile Managed Skills',
				'remote_ability' => self::SKILLS_ABILITY,
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'wordpress_native',
				'remote_mode' => 'checkpointed_convergence',
				'production_policy' => 'deny',
				'human_decision_required' => false,
			),
			'frontend_performance_sampling' => array(
				'feature_id' => 'live-acceptance-performance',
				'capability_tags' => array( 'frontend', 'performance', 'sampling', 'acceptance', 'telemetry' ),
				'provider' => 'browser-acceptance-core',
				'status_ability' => 'mad4b/frontend-performance-status',
				'local_surface' => 'frontend-browser-visit',
				'remote_ability' => self::FRONTEND_SAMPLE_ABILITY,
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'external_browser_agent',
				'remote_mode' => 'durable_external_executor_request',
				'production_policy' => 'deny',
				'human_decision_required' => false,
			),
			'external_executor_work_claim' => array(
				'feature_id' => 'remote-operation-parity',
				'capability_tags' => array( 'external-executor', 'queue', 'lease', 'fencing', 'browser', 'acceptance' ),
				'provider' => 'remote-work-queue',
				'status_ability' => self::WORK_QUEUE_ABILITY,
				'local_surface' => '',
				'remote_ability' => self::WORK_CLAIM_ABILITY,
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'external_browser_agent',
				'remote_mode' => 'leased_semantic_work_queue',
				'production_policy' => 'deny',
				'human_decision_required' => false,
			),
			'brand_context_materialization_reconciliation' => array(
				'feature_id' => '007-content-intelligence-workflow-platform',
				'capability_tags' => array( 'brand-context', 'materialization', 'reconciliation', 'idempotency', 'provider-readback', 'self-healing' ),
				'provider' => 'context-provider-gateway',
				'status_ability' => 'context/provider-capabilities',
				'local_surface' => '',
				'remote_ability' => 'context/reconcile-brand-materialization',
				'authority_surface' => 'context',
				'executor' => 'wordpress_native',
				'remote_mode' => 'durable_multi_observation_reconciliation',
				'production_policy' => 'governed_source_policy',
				'human_decision_required' => false,
			),
			'admin_query_performance_indexes' => array(
				'feature_id' => 'admin-query-performance',
				'capability_tags' => array( 'database', 'index', 'performance', 'maintenance', 'ddl' ),
				'provider' => 'wordpress-database',
				'status_ability' => 'mad4b/admin-query-performance-status',
				'local_surface' => 'wp-admin:MAD4B/Performance/Apply performance indexes',
				'remote_ability' => self::PERFORMANCE_INDEX_ABILITY,
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'wordpress_cron_maintenance_worker',
				'remote_mode' => 'durable_scheduled_operation',
				'production_policy' => 'deny',
				'human_decision_required' => false,
			),
			'admin_query_performance_reconciliation' => array(
				'feature_id' => 'admin-query-performance',
				'capability_tags' => array( 'database', 'index', 'performance', 'maintenance', 'reconciliation', 'uncertain-execution' ),
				'provider' => 'wordpress-database',
				'status_ability' => 'mad4b/admin-query-performance-status',
				'local_surface' => '',
				'remote_ability' => self::PERFORMANCE_RECONCILE_ABILITY,
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'wordpress_native',
				'remote_mode' => 'postcondition_only_reconciliation',
				'production_policy' => 'deny',
				'human_decision_required' => false,
			),
			'staging_candidate_binding' => array(
				'feature_id' => 'staging-write-authority',
				'capability_tags' => array( 'candidate', 'binding', 'write-authority', 'bootstrap', 'staging' ),
				'provider' => 'core',
				'status_ability' => 'mad4b/staging-write-candidate-binding-audit',
				'local_surface' => '',
				'remote_ability' => 'mad4b/staging-write-candidate-bind',
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'wordpress_native',
				'remote_mode' => 'exact_candidate_binding',
				'production_policy' => 'deny',
				'human_decision_required' => true,
			),
			'full_staging_authority_convergence' => array(
				'feature_id' => 'full-staging-authority',
				'capability_tags' => array( 'authority', 'convergence', 'candidate-binding', 'developer', 'breakglass', 'staging' ),
				'provider' => 'core',
				'status_ability' => 'mad4b/full-staging-authority-status',
				'local_surface' => '',
				'remote_ability' => 'mad4b/full-staging-authority-apply',
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'wordpress_native',
				'remote_mode' => 'exact_plan_composite_convergence',
				'production_policy' => 'deny',
				'human_decision_required' => true,
			),
			'provider_closure_matrix' => array(
				'feature_id' => 'provider-certification',
				'capability_tags' => array( 'provider', 'certification', 'write-eligibility', 'closure', 'diagnostics' ),
				'provider' => 'core',
				'status_ability' => 'mad4b/provider-closure-matrix',
				'local_surface' => '',
				'remote_ability' => 'mad4b/provider-closure-matrix',
				'authority_surface' => 'mad4b-read',
				'executor' => 'wordpress_native',
				'remote_mode' => 'read_only_diagnostic',
				'production_policy' => 'read_only',
				'human_decision_required' => false,
			),
			'provider_behavioral_recertification' => array(
				'feature_id' => 'provider-certification',
				'capability_tags' => array( 'provider', 'behavioral', 'recertification', 'rollback', 'write-eligibility' ),
				'provider' => 'core',
				'status_ability' => 'mad4b/provider-closure-matrix',
				'local_surface' => '',
				'remote_ability' => 'mad4b/provider-behavioral-recertify',
				'authority_surface' => 'mad4b-write',
				'executor' => 'wordpress_native',
				'remote_mode' => 'exact_reversible_probe',
				'production_policy' => 'deny',
				'human_decision_required' => true,
			),
			'provider_canary_execution' => array(
				'feature_id' => 'provider-certification',
				'capability_tags' => array( 'provider', 'canary', 'activation', 'high-risk-write', 'rollback' ),
				'provider' => 'core',
				'status_ability' => 'mad4b/provider-closure-matrix',
				'local_surface' => '',
				'remote_ability' => 'mad4b/provider-canary-execute',
				'authority_surface' => 'mad4b-write',
				'executor' => 'wordpress_native',
				'remote_mode' => 'owner_governed_canary',
				'production_policy' => 'deny',
				'human_decision_required' => true,
			),
		);
		foreach ( $rows as &$builtin_row ) {
			$builtin_row['registrar_id'] = 'mad4b-core';
			$builtin_row['source_plugin'] = 'mad4b-site-control-plane';
			$builtin_row['trust_class'] = 'core';
		}
		unset( $builtin_row );
		$builtin_operation_ids = array_fill_keys( array_map( 'sanitize_key', array_keys( $rows ) ), true );
		$filtered = apply_filters( 'mad4b_scp_remote_operation_catalog', $rows );
		if ( is_array( $filtered ) ) $rows = array_slice( $filtered, 0, 500, true );
		$required = array( 'feature_id', 'capability_tags', 'provider', 'remote_ability', 'authority_surface', 'executor', 'remote_mode', 'production_policy', 'human_decision_required', 'registrar_id', 'source_plugin', 'trust_class' );
		$normalized = array();
		self::$catalog_rejections = array();
		foreach ( $rows as $key => $row ) {
			$raw_key = (string) $key;
			$key = sanitize_key( $raw_key );
			if ( '' === $key || ! is_array( $row ) ) {
				self::$catalog_rejections[] = array( 'operation_id' => $raw_key, 'reason' => 'invalid_operation_registration_shape' );
				continue;
			}
			$missing_fields = array();
			foreach ( $required as $field ) if ( ! array_key_exists( $field, $row ) ) $missing_fields[] = $field;
			if ( ! empty( $missing_fields ) || ! is_array( $row['capability_tags'] ) ) {
				self::$catalog_rejections[] = array( 'operation_id' => $key, 'reason' => 'registration_metadata_incomplete', 'missing_fields' => $missing_fields );
				continue;
			}
			if ( ! in_array( (string) $row['trust_class'], array( 'core', 'certified_addon', 'informational' ), true ) ) {
				self::$catalog_rejections[] = array( 'operation_id' => $key, 'reason' => 'registration_trust_class_invalid', 'trust_class' => (string) $row['trust_class'] );
				continue;
			}
			$is_builtin = isset( $builtin_operation_ids[ $key ] );
			if ( $is_builtin ) {
				if ( 'core' !== (string) $row['trust_class']
					|| 'mad4b-core' !== (string) $row['registrar_id']
					|| 'mad4b-site-control-plane' !== (string) $row['source_plugin'] ) {
					self::$catalog_rejections[] = array( 'operation_id' => $key, 'reason' => 'builtin_registration_identity_drift' );
					continue;
				}
			} else {
				if ( 'core' === (string) $row['trust_class']
					|| 'mad4b-core' === (string) $row['registrar_id']
					|| 'mad4b-site-control-plane' === (string) $row['source_plugin'] ) {
					self::$catalog_rejections[] = array( 'operation_id' => $key, 'reason' => 'external_registration_core_impersonation_denied' );
					continue;
				}
				if ( 'certified_addon' === (string) $row['trust_class'] ) {
					if ( ! class_exists( 'MAD4B_SCP_Addon_Registry' ) || ! method_exists( 'MAD4B_SCP_Addon_Registry', 'execution_binding' ) ) {
						self::$catalog_rejections[] = array( 'operation_id' => $key, 'reason' => 'certified_addon_registry_unavailable' );
						continue;
					}
					$binding = MAD4B_SCP_Addon_Registry::execution_binding( (string) $row['source_plugin'] );
					if ( is_wp_error( $binding ) ) {
						self::$catalog_rejections[] = array( 'operation_id' => $key, 'reason' => 'certified_addon_pair_not_current', 'error_code' => $binding->get_error_code() );
						continue;
					}
					$binding_provider = isset( $binding['provider_id'] ) ? sanitize_key( (string) $binding['provider_id'] ) : '';
					if ( '' === $binding_provider || $binding_provider !== sanitize_key( (string) $row['provider'] ) ) {
						self::$catalog_rejections[] = array( 'operation_id' => $key, 'reason' => 'certified_addon_provider_binding_mismatch' );
						continue;
					}
					$row['addon_pair_fingerprint'] = isset( $binding['pair_fingerprint'] ) ? (string) $binding['pair_fingerprint'] : '';
					$row['addon_certification_fingerprint'] = isset( $binding['certification_fingerprint'] ) ? (string) $binding['certification_fingerprint'] : '';
				}
			}
			$row['operation_id'] = $key;
			$row['catalog_contract'] = self::CONTRACT;
			$row['catalog_version'] = 2;
			$row['registration_digest'] = self::operation_registration_digest( $key, $row );
			$row['remote_registered'] = function_exists( 'wp_has_ability' ) && wp_has_ability( (string) $row['remote_ability'] );
			$row['manual_only'] = empty( $row['remote_ability'] );
			$executor = self::executor_status( isset( $row['executor'] ) ? (string) $row['executor'] : '' );
			$row['executor_available'] = ! empty( $executor['available'] );
			$row['executor_state'] = isset( $executor['state'] ) ? (string) $executor['state'] : 'unknown';
			$row['execution_eligible'] = 'informational' !== (string) $row['trust_class'];
			$row['remote_parity_ready'] = ! $row['manual_only'] && $row['remote_registered'] && $row['executor_available'] && $row['execution_eligible'];
			$normalized[ $key ] = $row;
		}
		ksort( $normalized, SORT_STRING );
		return $normalized;
	}

	private static function executor_status( $executor ) {
		$executor = sanitize_key( (string) $executor );
		if ( 'external_browser_agent' === $executor ) {
			if ( ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Core' ) || ! method_exists( 'MAD4B_SCP_Browser_Acceptance_Core', 'capabilities' ) ) return array( 'available' => false, 'state' => 'browser_acceptance_core_unavailable' );
			$capabilities = MAD4B_SCP_Browser_Acceptance_Core::capabilities();
			$count = is_array( $capabilities ) && isset( $capabilities['provider_count'] ) ? (int) $capabilities['provider_count'] : 0;
			return array( 'available' => $count > 0, 'state' => $count > 0 ? 'browser_provider_available' : 'browser_provider_waiting' );
		}
		if ( 'wordpress_cron_maintenance_worker' === $executor ) {
			if ( ! function_exists( 'wp_schedule_single_event' ) ) return array( 'available' => false, 'state' => 'wp_cron_api_unavailable' );
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) return array( 'available' => false, 'state' => 'wp_cron_disabled' );
			return array( 'available' => true, 'state' => 'wp_cron_available' );
		}
		if ( in_array( $executor, array( 'wordpress_native', 'wordpress_native_database_ddl' ), true ) ) return array( 'available' => true, 'state' => 'wordpress_runtime_available' );
		return array( 'available' => '' !== $executor, 'state' => '' !== $executor ? 'externally_managed_executor' : 'executor_unspecified' );
	}

	private static function operation_registration_digest( $operation_id, array $row ) {
		$payload = array(
			'operation_id' => (string) $operation_id,
			'feature_id' => isset( $row['feature_id'] ) ? (string) $row['feature_id'] : '',
			'remote_ability' => isset( $row['remote_ability'] ) ? (string) $row['remote_ability'] : '',
			'authority_surface' => isset( $row['authority_surface'] ) ? (string) $row['authority_surface'] : '',
			'executor' => isset( $row['executor'] ) ? (string) $row['executor'] : '',
			'provider' => isset( $row['provider'] ) ? (string) $row['provider'] : '',
			'registrar_id' => isset( $row['registrar_id'] ) ? (string) $row['registrar_id'] : '',
			'source_plugin' => isset( $row['source_plugin'] ) ? (string) $row['source_plugin'] : '',
			'trust_class' => isset( $row['trust_class'] ) ? (string) $row['trust_class'] : '',
			'addon_pair_fingerprint' => isset( $row['addon_pair_fingerprint'] ) ? (string) $row['addon_pair_fingerprint'] : '',
			'addon_certification_fingerprint' => isset( $row['addon_certification_fingerprint'] ) ? (string) $row['addon_certification_fingerprint'] : '',
			'capability_tags' => isset( $row['capability_tags'] ) && is_array( $row['capability_tags'] ) ? array_values( array_map( 'strval', $row['capability_tags'] ) ) : array(),
		);
		sort( $payload['capability_tags'], SORT_STRING );
		ksort( $payload, SORT_STRING );
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	public static function discover( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$query = isset( $input['query'] ) ? strtolower( trim( sanitize_text_field( (string) $input['query'] ) ) ) : '';
		$feature_id = isset( $input['feature_id'] ) ? sanitize_key( (string) $input['feature_id'] ) : '';
		$surface = isset( $input['authority_surface'] ) ? sanitize_key( (string) $input['authority_surface'] ) : '';
		$executor = isset( $input['executor'] ) ? sanitize_key( (string) $input['executor'] ) : '';
		$provider = isset( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : '';
		$remote_ready_only = ! empty( $input['remote_ready_only'] );
		$matches = array();
		foreach ( self::catalog() as $operation_id => $row ) {
			if ( '' !== $feature_id && $feature_id !== sanitize_key( (string) $row['feature_id'] ) ) continue;
			if ( '' !== $surface && $surface !== sanitize_key( (string) $row['authority_surface'] ) ) continue;
			if ( '' !== $executor && $executor !== sanitize_key( (string) $row['executor'] ) ) continue;
			if ( '' !== $provider && $provider !== sanitize_key( (string) $row['provider'] ) ) continue;
			if ( $remote_ready_only && empty( $row['remote_parity_ready'] ) ) continue;
			if ( '' !== $query ) {
				$haystack = strtolower( implode( ' ', array_merge(
					array( $operation_id, (string) $row['feature_id'], (string) $row['remote_ability'], (string) $row['local_surface'], (string) $row['executor'], (string) $row['provider'], (string) $row['authority_surface'], (string) $row['remote_mode'], (string) $row['status_ability'] ),
					isset( $row['capability_tags'] ) && is_array( $row['capability_tags'] ) ? array_map( 'strval', $row['capability_tags'] ) : array()
				) ) );
				if ( false === strpos( $haystack, $query ) ) continue;
			}
			$matches[ $operation_id ] = $row;
		}
		$ability_hints = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability_name => $ability ) {
				if ( count( $ability_hints ) >= 100 ) break;
				if ( class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'is_chatgpt_full_catalog_candidate' ) && ! MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( $ability_name ) ) continue;
				$label = is_object( $ability ) && method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
				$description = is_object( $ability ) && method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
				$category = is_object( $ability ) && method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
				if ( '' !== $query && false === strpos( strtolower( (string) $ability_name . ' ' . $label . ' ' . $description . ' ' . $category ), $query ) ) continue;
				$ability_hints[] = array(
					'ability_name' => (string) $ability_name,
					'label' => $label,
					'description' => $description,
					'category' => $category,
				);
			}
		}
		return array(
			'contract' => 'mad4b.operation-discovery.v1',
			'query' => $query,
			'count' => count( $matches ),
			'operations' => $matches,
			'rejected_registration_count' => count( self::$catalog_rejections ),
			'rejected_registrations' => array_values( self::$catalog_rejections ),
			'ability_hint_count' => count( $ability_hints ),
			'ability_hints' => $ability_hints,
			'discovery_federation' => array(
				'operations' => self::DISCOVER_ABILITY,
				'abilities' => 'mad4b/tool-discover',
				'addons' => 'mad4b/addon-registry-status',
				'capability_traits' => 'mad4b/capability-trait-resolve',
			),
			'future_feature_discovery' => true,
			'requires_prior_ability_name' => false,
		);
	}

	public static function status() {
		$operations = self::catalog();
		$missing = array();
		foreach ( $operations as $id => $row ) {
			if ( empty( $row['remote_parity_ready'] ) ) $missing[] = $id;
		}
		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $missing ),
			'state' => empty( $missing ) ? 'ready' : 'remote_parity_incomplete',
			'operation_count' => count( $operations ),
			'manual_only_count' => count( $missing ),
			'manual_only_operations' => $missing,
			'operations' => $operations,
			'rejected_registration_count' => count( self::$catalog_rejections ),
			'rejected_registrations' => array_values( self::$catalog_rejections ),
			'skills_reconciliation_job' => self::skills_job_status(),
			'frontend_sample_request' => self::frontend_sample_request_status(),
			'generic_remote_admin_exposed' => false,
			'raw_shell_exposed' => false,
			'raw_sql_exposed' => false,
			'production_mutation_allowed' => false,
			'discovery_federation' => array(
				'operations' => self::DISCOVER_ABILITY,
				'abilities' => 'mad4b/tool-discover',
				'addons' => 'mad4b/addon-registry-status',
				'capability_traits' => 'mad4b/capability-trait-resolve',
			),
		);
	}

	private static function skills_identity_matches( array $left, array $right ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) {
			$a = strtolower( trim( (string) ( isset( $left[ $key ] ) ? $left[ $key ] : '' ) ) );
			$b = strtolower( trim( (string) ( isset( $right[ $key ] ) ? $right[ $key ] : '' ) ) );
			if ( '' === $a || '' === $b || ! hash_equals( $a, $b ) ) return false;
		}
		return true;
	}

	private static function compare_and_swap_option( $name, $expected, $replacement = null ) {
		global $wpdb;
		if ( ! isset( $wpdb->options ) ) return false;
		$where = array(
			'option_name' => (string) $name,
			'option_value' => maybe_serialize( $expected ),
		);
		if ( null === $replacement ) {
			$changed = $wpdb->delete( $wpdb->options, $where, array( '%s', '%s' ) );
		} else {
			$changed = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => maybe_serialize( $replacement ) ),
				$where,
				array( '%s' ),
				array( '%s', '%s' )
			);
		}
		if ( 1 === (int) $changed ) {
			wp_cache_delete( (string) $name, 'options' );
			return true;
		}
		return false;
	}

	private static function refresh_skills_lock( $owner ) {
		$current = get_option( self::SKILLS_LOCK_OPTION, array() );
		if ( ! is_array( $current ) || empty( $current['owner'] ) || ! hash_equals( (string) $current['owner'], (string) $owner ) ) {
			return new WP_Error( 'mad4b_remote_skill_lock_fenced', 'Managed Skill reconciliation lost its durable lock ownership.' );
		}
		if ( time() > (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) {
			return new WP_Error( 'mad4b_remote_skill_lock_expired', 'Managed Skill reconciliation lock expired before heartbeat.' );
		}
		$next = $current;
		$next['expires_at_epoch'] = time() + self::SKILLS_LOCK_TTL;
		$next['heartbeat_at'] = gmdate( 'c' );
		if ( ! self::compare_and_swap_option( self::SKILLS_LOCK_OPTION, $current, $next ) ) {
			return new WP_Error( 'mad4b_remote_skill_lock_heartbeat_raced', 'Managed Skill reconciliation lock changed during heartbeat.' );
		}
		return true;
	}

	private static function acquire_skills_lock() {
		$owner = strtolower( wp_generate_uuid4() );
		$record = array( 'owner' => $owner, 'expires_at_epoch' => time() + self::SKILLS_LOCK_TTL, 'acquired_at' => gmdate( 'c' ) );
		if ( add_option( self::SKILLS_LOCK_OPTION, $record, '', false ) ) return $owner;
		$current = get_option( self::SKILLS_LOCK_OPTION, array() );
		if ( is_array( $current ) && time() > (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) {
			if ( ! self::compare_and_swap_option( self::SKILLS_LOCK_OPTION, $current, null ) ) {
				return new WP_Error( 'mad4b_remote_skill_lock_reclaim_raced', 'Managed Skill reconciliation lock changed while reclaiming an expired lease.' );
			}
			if ( add_option( self::SKILLS_LOCK_OPTION, $record, '', false ) ) return $owner;
		}
		return new WP_Error( 'mad4b_remote_skill_reconciliation_busy', 'Managed Skill reconciliation already has an active durable lease.' );
	}

	private static function release_skills_lock( $owner ) {
		$current = get_option( self::SKILLS_LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], (string) $owner ) ) {
			self::compare_and_swap_option( self::SKILLS_LOCK_OPTION, $current, null );
		}
	}

	private static function skills_job_status() {
		$state = get_option( self::SKILLS_STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function persist_skills_job( array $state ) {
		update_option( self::SKILLS_STATE_OPTION, $state, false );
		$stored = get_option( self::SKILLS_STATE_OPTION, array() );
		$expected_json = wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$stored_json = wp_json_encode( $stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_array( $stored ) || ! is_string( $expected_json ) || ! is_string( $stored_json )
			|| ! hash_equals( hash( 'sha256', $expected_json ), hash( 'sha256', $stored_json ) ) ) {
			return new WP_Error( 'mad4b_remote_skill_checkpoint_persist_failed', 'Managed Skill reconciliation checkpoint could not be durably read back.' );
		}
		return $stored;
	}

	private static function persist_browser_request( array $request ) {
		update_option( self::BROWSER_REQUEST_OPTION, $request, false );
		$stored = get_option( self::BROWSER_REQUEST_OPTION, array() );
		$expected_json = wp_json_encode( $request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$stored_json = wp_json_encode( $stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_array( $stored ) && is_string( $expected_json ) && is_string( $stored_json )
			&& hash_equals( hash( 'sha256', $expected_json ), hash( 'sha256', $stored_json ) );
	}

	private static function matched_frontend_probe_samples( array $performance, $probe_hash, $not_before = '' ) {
		$probe_hash = strtolower( trim( (string) $probe_hash ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $probe_hash ) ) return 0;
		$not_before_epoch = '' !== trim( (string) $not_before ) ? strtotime( (string) $not_before . ' UTC' ) : false;
		if ( false === $not_before_epoch ) return 0;

		$ledger = isset( $performance['frontend_probe_evidence'] ) && is_array( $performance['frontend_probe_evidence'] )
			? $performance['frontend_probe_evidence']
			: array();
		if ( isset( $ledger[ $probe_hash ] ) && is_array( $ledger[ $probe_hash ] ) ) {
			$count = 0;
			foreach ( (array) ( isset( $ledger[ $probe_hash ]['observations'] ) ? $ledger[ $probe_hash ]['observations'] : array() ) as $observation ) {
				if ( ! is_array( $observation ) ) continue;
				$observed = isset( $observation['observed_at'] ) ? strtotime( (string) $observation['observed_at'] . ' UTC' ) : false;
				if ( false !== $observed && $observed >= $not_before_epoch ) $count++;
			}
			return $count;
		}

		// Upgrade compatibility only: exact probe evidence from the bounded sample
		// window may exist before the dedicated ledger has been populated.
		$samples = isset( $performance['evaluation_window']['samples'] ) && is_array( $performance['evaluation_window']['samples'] )
			? $performance['evaluation_window']['samples']
			: array();
		$count = 0;
		foreach ( $samples as $sample ) {
			if ( ! is_array( $sample ) || 'frontend' !== ( isset( $sample['request_class'] ) ? (string) $sample['request_class'] : '' ) ) continue;
			$actual = isset( $sample['frontend_probe_hash'] ) ? strtolower( trim( (string) $sample['frontend_probe_hash'] ) ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $actual ) || ! hash_equals( $probe_hash, $actual ) ) continue;
			$observed = isset( $sample['observed_at'] ) ? strtotime( (string) $sample['observed_at'] . ' UTC' ) : false;
			if ( false === $observed || $observed < $not_before_epoch ) continue;
			$count++;
		}
		return $count;
	}

	private static function frontend_sample_request_status() {
		$request = get_option( self::BROWSER_REQUEST_OPTION, array() );
		if ( ! is_array( $request ) || empty( $request ) ) return array();
		$performance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
		$current_count = isset( $performance['evaluation_window']['sample_count'] ) ? (int) $performance['evaluation_window']['sample_count'] : 0;
		$request['telemetry_sample_count'] = $current_count;
		$claimed_at = '';
		if ( ! empty( $request['work_job_id'] ) && class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) ) {
			$work_job = MAD4B_SCP_Remote_Work_Queue::get_job( (string) $request['work_job_id'] );
			if ( ! is_wp_error( $work_job ) ) {
				$request['work_job_status'] = isset( $work_job['effective_status'] ) ? (string) $work_job['effective_status'] : ( isset( $work_job['status'] ) ? (string) $work_job['status'] : '' );
				$request['work_claim_generation'] = isset( $work_job['claim_generation'] ) ? (int) $work_job['claim_generation'] : 0;
				$claimed_at = isset( $work_job['claimed_at'] ) ? (string) $work_job['claimed_at'] : '';
			}
		}
		$observed_probe_samples = self::matched_frontend_probe_samples(
			$performance,
			isset( $request['probe_hash'] ) ? (string) $request['probe_hash'] : '',
			$claimed_at
		);
		$request['observed_probe_samples'] = $observed_probe_samples;
		$request['observed_sample_delta'] = $observed_probe_samples;
		$request['evidence_ready'] = $observed_probe_samples >= (int) ( isset( $request['requested_samples'] ) ? $request['requested_samples'] : 0 );
		if ( 'pending_external_executor' === ( isset( $request['status'] ) ? (string) $request['status'] : '' )
			&& isset( $request['expires_at_epoch'] ) && time() > (int) $request['expires_at_epoch'] ) {
			$request['status'] = 'expired_waiting_executor';
			$request['completed_at'] = gmdate( 'c' );
			if ( ! self::persist_browser_request( $request ) ) {
				$request['status'] = 'persistence_failed';
				$request['persistence_state'] = 'failed';
			}
		} elseif ( 'completed' === ( isset( $request['work_job_status'] ) ? (string) $request['work_job_status'] : '' )
			&& ! empty( $request['evidence_ready'] ) ) {
			$request['status'] = 'observed';
			$request['completed_at'] = gmdate( 'c' );
		}
		return $request;
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_remote_operation_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_remote_operation_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_remote_operation_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_remote_operation_staging_only', 'Remote maintenance parity operations are Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_remote_operation_origin_mismatch', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_remote_operation_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function operation_schema( $confirmation ) {
		$properties = self::exact_build_properties();
		$properties['confirmation'] = array( 'type' => 'string', 'enum' => array( $confirmation ) );
		return array(
			'type' => 'object',
			'properties' => $properties,
			'required' => array( 'expected_source_commit_sha', 'expected_build_fingerprint', 'expected_package_manifest_digest', 'confirmation' ),
			'additionalProperties' => false,
		);
	}

	private static function frontend_sample_schema() {
		$properties = self::exact_build_properties();
		$properties['sample_count'] = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 3 );
		$properties['target_path'] = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '^/[A-Za-z0-9/_\\.\\-]*$' );
		$properties['confirmation'] = array( 'type' => 'string', 'enum' => array( self::FRONTEND_CONFIRMATION ) );
		return array(
			'type' => 'object',
			'properties' => $properties,
			'required' => array( 'expected_source_commit_sha', 'expected_build_fingerprint', 'expected_package_manifest_digest', 'sample_count', 'confirmation' ),
			'additionalProperties' => false,
		);
	}

	private static function work_queue_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'operation_id' => array( 'type' => 'string', 'enum' => array( 'frontend_performance_sampling' ) ),
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
			),
			'additionalProperties' => false,
		);
	}

	private static function work_claim_schema() {
		$properties = self::exact_build_properties();
		$properties['job_id'] = array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' );
		$properties['executor_id'] = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9._-]+$' );
		$properties['lease_seconds'] = array( 'type' => 'integer', 'minimum' => 60, 'maximum' => 900 );
		return array(
			'type' => 'object',
			'properties' => $properties,
			'required' => array( 'expected_source_commit_sha', 'expected_build_fingerprint', 'expected_package_manifest_digest', 'job_id', 'executor_id' ),
			'additionalProperties' => false,
		);
	}

	private static function work_complete_schema() {
		$properties = self::exact_build_properties();
		$properties['job_id'] = array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' );
		$properties['executor_id'] = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9._-]+$' );
		$properties['lease_token'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		return array(
			'type' => 'object',
			'properties' => $properties,
			'required' => array( 'expected_source_commit_sha', 'expected_build_fingerprint', 'expected_package_manifest_digest', 'job_id', 'executor_id', 'lease_token' ),
			'additionalProperties' => false,
		);
	}

	private static function exact_build_properties() {
		return array(
			'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
			'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
			'expected_package_manifest_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
		);
	}

	private static function assert_exact_build( $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_remote_operation_input_invalid', 'Input must be an object.' );
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_remote_operation_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$p = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $p ) || empty( $p['manifest_present'] ) || empty( $p['manifest_valid'] ) || empty( $p['runtime_manifest_match'] ) || ! empty( $p['stale'] ) || ! empty( $p['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_remote_operation_provenance_not_ready', 'Exact current build provenance is not ready.' );

		$expected_sha = strtolower( trim( (string) ( isset( $input['expected_source_commit_sha'] ) ? $input['expected_source_commit_sha'] : '' ) ) );
		$expected_build = strtolower( trim( (string) ( isset( $input['expected_build_fingerprint'] ) ? $input['expected_build_fingerprint'] : '' ) ) );
		$expected_manifest = strtolower( trim( (string) ( isset( $input['expected_package_manifest_digest'] ) ? $input['expected_package_manifest_digest'] : '' ) ) );
		$current_sha = strtolower( (string) ( isset( $p['source_commit_sha'] ) ? $p['source_commit_sha'] : '' ) );
		$current_build = strtolower( (string) ( isset( $p['build_fingerprint'] ) ? $p['build_fingerprint'] : '' ) );
		$current_manifest = strtolower( (string) ( isset( $p['package_manifest_digest'] ) ? $p['package_manifest_digest'] : '' ) );

		if ( ! hash_equals( $current_sha, $expected_sha ) ) return new WP_Error( 'mad4b_remote_operation_candidate_mismatch', 'Source commit changed before remote operation execution.' );
		if ( ! hash_equals( $current_build, $expected_build ) ) return new WP_Error( 'mad4b_remote_operation_build_mismatch', 'Build fingerprint changed before remote operation execution.' );
		if ( ! hash_equals( $current_manifest, $expected_manifest ) ) return new WP_Error( 'mad4b_remote_operation_manifest_mismatch', 'Package manifest changed before remote operation execution.' );
		return $p;
	}

	private static function enter( $operation ) {
		if ( ! empty( self::$running[ $operation ] ) ) return false;
		self::$running[ $operation ] = true;
		return true;
	}

	private static function leave( $operation ) {
		unset( self::$running[ $operation ] );
	}

	private static function audit( $event, array $data, $status = 'ok' ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return true;
		$result = MAD4B_SCP_Audit::record( $event, array_merge( array(
			'contract' => self::CONTRACT,
			'remote_operation' => true,
			'production_mutation' => false,
		), $data ), $status );
		return is_wp_error( $result ) ? $result : true;
	}

	public static function remote_work_queue( $input = array() ) {
		if ( ! class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) ) return new WP_Error( 'mad4b_remote_work_queue_unavailable', 'Remote Work Queue is unavailable.' );
		$input = is_array( $input ) ? $input : array();
		if ( ! empty( $input['job_id'] ) ) {
			$job = MAD4B_SCP_Remote_Work_Queue::get_job( (string) $input['job_id'] );
			return is_wp_error( $job ) ? $job : array( 'contract' => MAD4B_SCP_Remote_Work_Queue::CONTRACT, 'read_only' => true, 'mutation_performed' => false, 'count' => 1, 'items' => array( $job ) );
		}
		return MAD4B_SCP_Remote_Work_Queue::list_jobs( isset( $input['operation_id'] ) ? (string) $input['operation_id'] : '' );
	}

	public static function claim_remote_work( $input ) {
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		if ( ! class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) ) return new WP_Error( 'mad4b_remote_work_queue_unavailable', 'Remote Work Queue is unavailable.' );
		$identity = array(
			'source_commit_sha' => strtolower( (string) $input['expected_source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $input['expected_build_fingerprint'] ),
			'package_manifest_digest' => strtolower( (string) $input['expected_package_manifest_digest'] ),
		);
		$result = MAD4B_SCP_Remote_Work_Queue::claim(
			(string) $input['job_id'],
			(string) $input['executor_id'],
			isset( $input['lease_seconds'] ) ? (int) $input['lease_seconds'] : 300,
			$identity
		);
		if ( is_wp_error( $result ) ) return $result;
		$audit = self::audit( self::WORK_CLAIM_ABILITY, array(
			'job_id' => (string) $input['job_id'],
			'executor_id' => sanitize_key( (string) $input['executor_id'] ),
			'claim_generation' => isset( $result['job']['claim_generation'] ) ? (int) $result['job']['claim_generation'] : 0,
		) );
		return is_wp_error( $audit ) ? $audit : $result;
	}

	public static function complete_remote_work( $input ) {
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		if ( ! class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) ) return new WP_Error( 'mad4b_remote_work_queue_unavailable', 'Remote Work Queue is unavailable.' );
		$job = MAD4B_SCP_Remote_Work_Queue::get_job( (string) $input['job_id'] );
		if ( is_wp_error( $job ) ) return $job;
		if ( 'frontend_performance_sampling' !== ( isset( $job['operation_id'] ) ? (string) $job['operation_id'] : '' ) ) return new WP_Error( 'mad4b_remote_work_completion_operation_unsupported', 'This remote work completion verifier does not support the requested semantic operation.' );
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$performance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
		$current_count = isset( $performance['evaluation_window']['sample_count'] ) ? (int) $performance['evaluation_window']['sample_count'] : 0;
		$required = isset( $payload['requested_samples'] ) ? max( 1, (int) $payload['requested_samples'] ) : 1;
		$probe_hash = isset( $payload['probe_hash'] ) ? strtolower( trim( (string) $payload['probe_hash'] ) ) : '';
		$claimed_at = isset( $job['claimed_at'] ) ? (string) $job['claimed_at'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $probe_hash ) || '' === $claimed_at ) {
			return new WP_Error( 'mad4b_remote_work_probe_binding_missing', 'Remote browser work lacks an exact probe identity or authoritative claim timestamp.' );
		}
		$matched = self::matched_frontend_probe_samples( $performance, $probe_hash, $claimed_at );
		if ( $matched < $required ) return new WP_Error(
			'mad4b_remote_work_evidence_not_observed',
			'External executor completion is denied until current-build Frontend telemetry independently observes the exact claimed probe samples.',
			array( 'required_probe_samples' => $required, 'observed_probe_samples' => $matched, 'telemetry_sample_count' => $current_count, 'probe_hash' => $probe_hash )
		);
		$result = MAD4B_SCP_Remote_Work_Queue::complete(
			(string) $input['job_id'],
			(string) $input['executor_id'],
			(string) $input['lease_token'],
			array(
				'verification' => 'query_monitor_frontend_probe_telemetry',
				'probe_hash' => $probe_hash,
				'claimed_at' => $claimed_at,
				'required_probe_samples' => $required,
				'observed_probe_samples' => $matched,
				'telemetry_sample_count' => $current_count,
				'verified_at' => gmdate( 'c' ),
			)
		);
		if ( is_wp_error( $result ) ) return $result;
		$request = get_option( self::BROWSER_REQUEST_OPTION, array() );
		if ( is_array( $request ) && isset( $request['work_job_id'] ) && hash_equals( (string) $request['work_job_id'], (string) $input['job_id'] ) ) {
			$request['status'] = 'observed';
			$request['work_job_status'] = 'completed';
			$request['observed_probe_samples'] = $matched;
			$request['observed_sample_delta'] = $matched;
			$request['evidence_ready'] = true;
			$request['telemetry_sample_count'] = $current_count;
			$request['completed_at'] = gmdate( 'c' );
			self::persist_browser_request( $request );
		}
		$audit = self::audit( self::WORK_COMPLETE_ABILITY, array(
			'job_id' => (string) $input['job_id'],
			'executor_id' => sanitize_key( (string) $input['executor_id'] ),
			'observed_probe_samples' => $matched,
			'probe_hash' => $probe_hash,
		) );
		return is_wp_error( $audit ) ? $audit : $result;
	}

	public static function reconcile_managed_skills( $input ) {
		if ( self::SKILLS_CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_remote_skill_confirmation_required', 'Exact managed Skill reconciliation confirmation is required.' );
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		if ( ! class_exists( 'MAD4B_SCP_Skill_Seeder' ) || ! class_exists( 'MAD4B_SCP_Skill_Provider_Discovery' ) || ! class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ) return new WP_Error( 'mad4b_remote_skill_services_unavailable', 'Managed Skill reconciliation services are unavailable.' );
		if ( ! class_exists( 'MAD4B_SCP_Skill_Registry' ) || ! MAD4B_SCP_Skill_Registry::editor_enabled() ) return new WP_Error( 'mad4b_remote_skill_editor_disabled', 'Managed Skill reconciliation requires the governed Skill editor to be enabled.' );
		if ( ! self::enter( 'skills' ) ) return new WP_Error( 'mad4b_remote_skill_reentry_denied', 'Managed Skill reconciliation is already running in this request.' );
		$skills_lock = self::acquire_skills_lock();
		if ( is_wp_error( $skills_lock ) ) {
			self::leave( 'skills' );
			return $skills_lock;
		}

		$identity = array(
			'source_commit_sha' => strtolower( (string) $input['expected_source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $input['expected_build_fingerprint'] ),
			'package_manifest_digest' => strtolower( (string) $input['expected_package_manifest_digest'] ),
		);
		$previous = self::skills_job_status();
		$same_identity = isset( $previous['expected_identity'] ) && is_array( $previous['expected_identity'] ) && self::skills_identity_matches( $previous['expected_identity'], $identity );
		$attempt = $same_identity && isset( $previous['attempt'] ) ? max( 0, (int) $previous['attempt'] ) + 1 : 1;
		$state = array(
			'contract' => 'mad4b.remote-managed-skills-reconciliation-state.v1',
			'operation_id' => $same_identity && ! empty( $previous['operation_id'] ) ? (string) $previous['operation_id'] : strtolower( wp_generate_uuid4() ),
			'expected_identity' => $identity,
			'attempt' => $attempt,
			'status' => 'running',
			'stage' => 'preflight',
			'last_error_code' => '',
			'updated_at' => gmdate( 'c' ),
			'production_mutation' => false,
			'resumed' => $same_identity,
			'resumed_from_stage' => $same_identity && isset( $previous['stage'] ) ? (string) $previous['stage'] : '',
		);
		$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) {
			self::release_skills_lock( $skills_lock );
			self::leave( 'skills' );
			return $persisted;
		}

		try {
			$heartbeat = self::refresh_skills_lock( $skills_lock );
			if ( is_wp_error( $heartbeat ) ) return $heartbeat;
			$seed_before = MAD4B_SCP_Skill_Seeder::inspect();
			$provider_before = MAD4B_SCP_Skill_Provider_Discovery::inspect();
			if ( ! empty( $seed_before['conflicts'] ) || ! empty( $provider_before['conflicts'] ) ) {
				$state['status'] = 'blocked';
				$state['stage'] = 'preflight';
				$state['last_error_code'] = 'mad4b_remote_skill_conflict';
				$state['updated_at'] = gmdate( 'c' );
				$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;
				return new WP_Error( 'mad4b_remote_skill_conflict', 'Managed Skill reconciliation is blocked by current conflicts.', array( 'seed_conflicts' => isset( $seed_before['conflicts'] ) ? $seed_before['conflicts'] : array(), 'provider_conflicts' => isset( $provider_before['conflicts'] ) ? $provider_before['conflicts'] : array(), 'checkpoint' => $state ) );
			}

			$state['stage'] = 'seed_reconciliation';
			$state['updated_at'] = gmdate( 'c' );
			$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;
			$resume_seed = $same_identity && ! empty( $previous['seed_ready'] ) && ! empty( $seed_before['ready'] );
			$heartbeat = self::refresh_skills_lock( $skills_lock );
			if ( is_wp_error( $heartbeat ) ) return $heartbeat;
			$seed = $resume_seed
				? array( 'state' => 'ready', 'ready' => true, 'resumed' => true, 'inspection' => $seed_before )
				: MAD4B_SCP_Skill_Seeder::bootstrap();
			$heartbeat = self::refresh_skills_lock( $skills_lock );
			if ( is_wp_error( $heartbeat ) ) return $heartbeat;
			$state['seed_resumed'] = $resume_seed;
			if ( is_wp_error( $seed ) || ! is_array( $seed ) || 'ready' !== ( isset( $seed['state'] ) ? (string) $seed['state'] : '' ) ) {
				$error = is_wp_error( $seed ) ? $seed : new WP_Error( 'mad4b_remote_skill_seed_failed', 'Canonical Skill seed reconciliation did not reach ready state.', array( 'seed' => $seed ) );
				$state['status'] = 'blocked';
				$state['last_error_code'] = $error->get_error_code();
				$state['updated_at'] = gmdate( 'c' );
				$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;
				return $error;
			}
			$state['stage'] = 'seed_ready';
			$state['seed_ready'] = true;
			$state['updated_at'] = gmdate( 'c' );
			$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;

			$state['stage'] = 'provider_reconciliation';
			$state['updated_at'] = gmdate( 'c' );
			$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;
			$resume_provider = $same_identity && ! empty( $previous['provider_ready'] ) && ! empty( $provider_before['ready'] );
			$heartbeat = self::refresh_skills_lock( $skills_lock );
			if ( is_wp_error( $heartbeat ) ) return $heartbeat;
			$providers = $resume_provider
				? array( 'state' => 'ready', 'ready' => true, 'resumed' => true, 'inspection' => $provider_before )
				: MAD4B_SCP_Skill_Provider_Discovery::reconcile();
			$heartbeat = self::refresh_skills_lock( $skills_lock );
			if ( is_wp_error( $heartbeat ) ) return $heartbeat;
			$state['provider_resumed'] = $resume_provider;
			if ( is_wp_error( $providers ) || ! is_array( $providers ) || 'ready' !== ( isset( $providers['state'] ) ? (string) $providers['state'] : '' ) ) {
				$error = is_wp_error( $providers ) ? $providers : new WP_Error( 'mad4b_remote_skill_provider_failed', 'Provider Skill reconciliation did not reach ready state.', array( 'providers' => $providers ) );
				$state['status'] = 'blocked';
				$state['last_error_code'] = $error->get_error_code();
				$state['updated_at'] = gmdate( 'c' );
				$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;
				return $error;
			}
			$state['stage'] = 'provider_ready';
			$state['provider_ready'] = true;
			$state['updated_at'] = gmdate( 'c' );
			$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;

			$state['stage'] = 'runtime_certification';
			$state['updated_at'] = gmdate( 'c' );
			$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;
			$heartbeat = self::refresh_skills_lock( $skills_lock );
			if ( is_wp_error( $heartbeat ) ) return $heartbeat;
			$certification = MAD4B_SCP_Skill_Runtime_Certification::observe();
			$heartbeat = self::refresh_skills_lock( $skills_lock );
			if ( is_wp_error( $heartbeat ) ) return $heartbeat;
			if ( ! is_array( $certification ) || empty( $certification['ready'] ) ) {
				$state['status'] = 'blocked';
				$state['last_error_code'] = 'mad4b_remote_skill_certification_failed';
				$state['updated_at'] = gmdate( 'c' );
				$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;
				return new WP_Error( 'mad4b_remote_skill_certification_failed', 'Managed Skill reconciliation completed but runtime certification is not ready.', array( 'certification' => $certification, 'checkpoint' => $state ) );
			}

			$state['status'] = 'completed';
			$state['stage'] = 'certified';
			$state['certification_ready'] = true;
			$state['last_error_code'] = '';
			$state['updated_at'] = gmdate( 'c' );
			$state['completed_at'] = gmdate( 'c' );
			$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;

			$audit = self::audit( self::SKILLS_ABILITY, array(
				'source_commit_sha' => isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '',
				'operation_id' => (string) $state['operation_id'],
				'attempt' => (int) $state['attempt'],
				'seed_before' => $seed_before,
				'provider_before' => $provider_before,
				'certification_ready' => true,
			) );
			if ( is_wp_error( $audit ) ) return $audit;

			return array(
				'contract' => 'mad4b.remote-managed-skills-reconciliation.v2',
				'state' => 'ready',
				'ready' => true,
				'checkpoint' => $state,
				'seed' => $seed,
				'providers' => $providers,
				'certification' => $certification,
				'remote_operation' => true,
				'production_mutation' => false,
			);
		} finally {
			self::release_skills_lock( $skills_lock );
			self::leave( 'skills' );
		}
	}

	public static function collect_frontend_samples( $input ) {
		if ( self::FRONTEND_CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_frontend_sample_confirmation_required', 'Exact Frontend sampling confirmation is required.' );
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		$count = isset( $input['sample_count'] ) ? max( 1, min( 3, (int) $input['sample_count'] ) ) : 1;
		$path = isset( $input['target_path'] ) ? trim( (string) $input['target_path'] ) : '/';
		if ( '' === $path ) $path = '/';
		if ( 1 !== preg_match( '#^/[A-Za-z0-9/_\.\-]*$#', $path ) || false !== strpos( $path, '..' ) ) return new WP_Error( 'mad4b_frontend_sample_path_invalid', 'Frontend sample target must be a bounded same-origin relative path.' );

		$current = self::frontend_sample_request_status();
		if ( ! empty( $current ) && 'pending_external_executor' === ( isset( $current['status'] ) ? (string) $current['status'] : '' ) && time() < (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) {
			$same = hash_equals( (string) ( isset( $current['target_path'] ) ? $current['target_path'] : '' ), $path )
				&& (int) ( isset( $current['requested_samples'] ) ? $current['requested_samples'] : 0 ) === $count
				&& hash_equals( (string) ( isset( $current['expected_identity']['source_commit_sha'] ) ? $current['expected_identity']['source_commit_sha'] : '' ), strtolower( (string) $input['expected_source_commit_sha'] ) );
			if ( $same ) return array( 'contract' => 'mad4b.remote-frontend-performance-sampling.v2', 'state' => 'already_queued', 'request' => $current, 'manual_interaction_required' => false, 'production_mutation' => false );
			return new WP_Error( 'mad4b_frontend_sample_request_in_flight', 'A different current-build browser sampling request is already pending.' );
		}

		$performance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
		$baseline = isset( $performance['evaluation_window']['sample_count'] ) ? (int) $performance['evaluation_window']['sample_count'] : 0;
		$request_id = strtolower( wp_generate_uuid4() );
		$probe_hash = hash( 'sha256', $request_id );
		$target_url = add_query_arg( 'mad4b_frontend_probe', $request_id, home_url( $path ) );
		$request = array(
			'contract' => 'mad4b.remote-frontend-performance-sampling.v2',
			'request_id' => $request_id,
			'probe_hash' => $probe_hash,
			'status' => 'pending_external_executor',
			'execution_mode' => 'external_browser_agent',
			'executor_contract' => 'mad4b.browser-acceptance-core.v1',
			'target_path' => $path,
			'target_url' => $target_url,
			'requested_samples' => $count,
			'baseline_sample_count' => $baseline,
			'expected_identity' => array(
				'source_commit_sha' => strtolower( (string) $input['expected_source_commit_sha'] ),
				'build_fingerprint' => strtolower( (string) $input['expected_build_fingerprint'] ),
				'package_manifest_digest' => strtolower( (string) $input['expected_package_manifest_digest'] ),
			),
			'created_at' => gmdate( 'c' ),
			'expires_at' => gmdate( 'c', time() + HOUR_IN_SECONDS ),
			'expires_at_epoch' => time() + HOUR_IN_SECONDS,
			'manual_interaction_required' => false,
			'production_mutation' => false,
		);
		if ( ! class_exists( 'MAD4B_SCP_Remote_Work_Queue' ) ) return new WP_Error( 'mad4b_remote_work_queue_unavailable', 'Remote Work Queue is unavailable.' );
		$work = MAD4B_SCP_Remote_Work_Queue::enqueue(
			'frontend_performance_sampling',
			array(
				'request_id' => (string) $request['request_id'],
				'probe_hash' => (string) $request['probe_hash'],
				'target_path' => $path,
				'target_url' => (string) $request['target_url'],
				'requested_samples' => $count,
				'baseline_sample_count' => $baseline,
			),
			(array) $request['expected_identity'],
			HOUR_IN_SECONDS
		);
		if ( is_wp_error( $work ) ) return $work;
		$request['work_job_id'] = isset( $work['job']['job_id'] ) ? (string) $work['job']['job_id'] : '';
		$request['work_queue_state'] = isset( $work['state'] ) ? (string) $work['state'] : '';
		if ( '' === $request['work_job_id'] ) return new WP_Error( 'mad4b_frontend_sample_work_job_missing', 'Frontend browser sampling could not obtain a durable external work job.' );
		if ( ! self::persist_browser_request( $request ) ) return new WP_Error( 'mad4b_frontend_sample_request_persist_failed', 'Frontend browser sampling request could not be durably read back.' );
		do_action( 'mad4b_scp_remote_browser_request_enqueued', $request );
		$audit = self::audit( self::FRONTEND_SAMPLE_ABILITY, array(
			'source_commit_sha' => (string) $request['expected_identity']['source_commit_sha'],
			'request_id' => (string) $request['request_id'],
			'probe_hash' => (string) $request['probe_hash'],
			'target_path' => $path,
			'requested_samples' => $count,
			'execution_mode' => 'external_browser_agent',
		) );
		if ( is_wp_error( $audit ) ) return $audit;
		return array(
			'contract' => 'mad4b.remote-frontend-performance-sampling.v2',
			'state' => 'queued',
			'request' => $request,
			'manual_interaction_required' => false,
			'browser_runtime_evidence_required' => true,
			'production_mutation' => false,
		);
	}

	public static function reconcile_performance_indexes( $input ) {
		if ( self::PERFORMANCE_RECONCILE_CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_performance_reconcile_confirmation_required', 'Exact performance maintenance reconciliation confirmation is required.' );
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		if ( ! class_exists( 'MAD4B_SCP_Admin_Query_Performance' ) || ! method_exists( 'MAD4B_SCP_Admin_Query_Performance', 'reconcile_stale_job' ) ) return new WP_Error( 'mad4b_performance_reconcile_service_unavailable', 'Performance maintenance reconciliation service is unavailable.' );
		$result = MAD4B_SCP_Admin_Query_Performance::reconcile_stale_job( array(
			'source_commit_sha' => strtolower( (string) $input['expected_source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $input['expected_build_fingerprint'] ),
			'package_manifest_digest' => strtolower( (string) $input['expected_package_manifest_digest'] ),
		) );
		if ( is_wp_error( $result ) ) return $result;
		$audit = self::audit( self::PERFORMANCE_RECONCILE_ABILITY, array(
			'source_commit_sha' => isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '',
			'reconciliation_state' => isset( $result['state'] ) ? (string) $result['state'] : '',
			'job_id' => isset( $result['job']['job_id'] ) ? (string) $result['job']['job_id'] : '',
			'ready' => ! empty( $result['ready'] ),
		) );
		if ( is_wp_error( $audit ) ) return $audit;
		return array(
			'contract' => 'mad4b.remote-admin-query-performance-reconciliation.v1',
			'state' => isset( $result['state'] ) ? (string) $result['state'] : 'unknown',
			'ready' => ! empty( $result['ready'] ),
			'job' => isset( $result['job'] ) ? $result['job'] : array(),
			'blind_retry_performed' => false,
			'remote_operation' => true,
			'production_mutation' => false,
		);
	}

	public static function apply_performance_indexes( $input ) {
		if ( self::PERFORMANCE_CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_performance_index_confirmation_required', 'Exact performance-index confirmation is required.' );
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		if ( ! class_exists( 'MAD4B_SCP_Admin_Query_Performance' ) || ! method_exists( 'MAD4B_SCP_Admin_Query_Performance', 'enqueue_explicit' ) ) return new WP_Error( 'mad4b_performance_index_service_unavailable', 'Queued performance-index maintenance service is unavailable.' );
		$result = MAD4B_SCP_Admin_Query_Performance::enqueue_explicit( array(
			'source_commit_sha' => strtolower( (string) $input['expected_source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $input['expected_build_fingerprint'] ),
			'package_manifest_digest' => strtolower( (string) $input['expected_package_manifest_digest'] ),
		) );
		if ( is_wp_error( $result ) ) return $result;
		$audit = self::audit( self::PERFORMANCE_INDEX_ABILITY, array(
			'source_commit_sha' => isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '',
			'queue_state' => isset( $result['state'] ) ? (string) $result['state'] : '',
			'job_id' => isset( $result['job']['job_id'] ) ? (string) $result['job']['job_id'] : '',
		) );
		if ( is_wp_error( $audit ) ) return $audit;
		return array(
			'contract' => 'mad4b.remote-admin-query-performance-apply.v2',
			'state' => isset( $result['state'] ) ? (string) $result['state'] : 'queued',
			'job' => isset( $result['job'] ) ? $result['job'] : array(),
			'synchronous_ddl' => false,
			'remote_operation' => true,
			'production_mutation' => false,
		);
	}

}

MAD4B_SCP_Remote_Operation_Parity::boot();
