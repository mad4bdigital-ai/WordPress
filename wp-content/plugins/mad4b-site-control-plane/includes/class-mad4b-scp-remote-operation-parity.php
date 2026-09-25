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
	const SKILLS_STATE_OPTION = 'mad4b_scp_remote_skills_reconciliation_v1';
	const BROWSER_REQUEST_OPTION = 'mad4b_scp_remote_browser_sample_request_v1';

	const SKILLS_CONFIRMATION = 'RECONCILE MANAGED SKILLS';
	const FRONTEND_CONFIRMATION = 'COLLECT FRONTEND PERFORMANCE SAMPLES';
	const PERFORMANCE_CONFIRMATION = 'APPLY STAGING PERFORMANCE INDEXES';

	private static $booted = false;
	private static $running = array();
	private static $catalog_rejections = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 13 );
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
		);
		foreach ( $rows as &$builtin_row ) {
			$builtin_row['registrar_id'] = 'mad4b-core';
			$builtin_row['source_plugin'] = 'mad4b-site-control-plane';
			$builtin_row['trust_class'] = 'core';
		}
		unset( $builtin_row );
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
			$row['operation_id'] = $key;
			$row['catalog_contract'] = self::CONTRACT;
			$row['catalog_version'] = 2;
			$row['registration_digest'] = self::operation_registration_digest( $key, $row );
			$row['remote_registered'] = function_exists( 'wp_has_ability' ) && wp_has_ability( (string) $row['remote_ability'] );
			$row['manual_only'] = empty( $row['remote_ability'] );
			$executor = self::executor_status( isset( $row['executor'] ) ? (string) $row['executor'] : '' );
			$row['executor_available'] = ! empty( $executor['available'] );
			$row['executor_state'] = isset( $executor['state'] ) ? (string) $executor['state'] : 'unknown';
			$row['remote_parity_ready'] = ! $row['manual_only'] && $row['remote_registered'] && $row['executor_available'];
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

	private static function frontend_sample_request_status() {
		$request = get_option( self::BROWSER_REQUEST_OPTION, array() );
		if ( ! is_array( $request ) || empty( $request ) ) return array();
		$performance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
		$current_count = isset( $performance['evaluation_window']['sample_count'] ) ? (int) $performance['evaluation_window']['sample_count'] : 0;
		$baseline = isset( $request['baseline_sample_count'] ) ? (int) $request['baseline_sample_count'] : 0;
		$observed_delta = max( 0, $current_count - $baseline );
		$request['observed_sample_delta'] = $observed_delta;
		$request['telemetry_sample_count'] = $current_count;
		if ( 'pending_external_executor' === ( isset( $request['status'] ) ? (string) $request['status'] : '' )
			&& isset( $request['expires_at_epoch'] ) && time() > (int) $request['expires_at_epoch'] ) {
			$request['status'] = 'expired_waiting_executor';
			$request['completed_at'] = gmdate( 'c' );
			if ( ! self::persist_browser_request( $request ) ) {
				$request['status'] = 'persistence_failed';
				$request['persistence_state'] = 'failed';
			}
		} elseif ( 'pending_external_executor' === ( isset( $request['status'] ) ? (string) $request['status'] : '' )
			&& $observed_delta >= (int) ( isset( $request['requested_samples'] ) ? $request['requested_samples'] : 0 ) ) {
			$request['status'] = 'observed';
			$request['completed_at'] = gmdate( 'c' );
			if ( ! self::persist_browser_request( $request ) ) {
				$request['status'] = 'persistence_failed';
				$request['persistence_state'] = 'failed';
			}
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

	public static function reconcile_managed_skills( $input ) {
		if ( self::SKILLS_CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_remote_skill_confirmation_required', 'Exact managed Skill reconciliation confirmation is required.' );
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		if ( ! class_exists( 'MAD4B_SCP_Skill_Seeder' ) || ! class_exists( 'MAD4B_SCP_Skill_Provider_Discovery' ) || ! class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ) return new WP_Error( 'mad4b_remote_skill_services_unavailable', 'Managed Skill reconciliation services are unavailable.' );
		if ( ! class_exists( 'MAD4B_SCP_Skill_Registry' ) || ! MAD4B_SCP_Skill_Registry::editor_enabled() ) return new WP_Error( 'mad4b_remote_skill_editor_disabled', 'Managed Skill reconciliation requires the governed Skill editor to be enabled.' );
		if ( ! self::enter( 'skills' ) ) return new WP_Error( 'mad4b_remote_skill_reentry_denied', 'Managed Skill reconciliation is already running in this request.' );

		$identity = array(
			'source_commit_sha' => strtolower( (string) $input['expected_source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $input['expected_build_fingerprint'] ),
			'package_manifest_digest' => strtolower( (string) $input['expected_package_manifest_digest'] ),
		);
		$previous = self::skills_job_status();
		$attempt = isset( $previous['attempt'] ) ? max( 0, (int) $previous['attempt'] ) + 1 : 1;
		$state = array(
			'contract' => 'mad4b.remote-managed-skills-reconciliation-state.v1',
			'operation_id' => ! empty( $previous['operation_id'] ) && isset( $previous['expected_identity']['source_commit_sha'] ) && hash_equals( (string) $previous['expected_identity']['source_commit_sha'], $identity['source_commit_sha'] ) ? (string) $previous['operation_id'] : strtolower( wp_generate_uuid4() ),
			'expected_identity' => $identity,
			'attempt' => $attempt,
			'status' => 'running',
			'stage' => 'preflight',
			'last_error_code' => '',
			'updated_at' => gmdate( 'c' ),
			'production_mutation' => false,
		);
		$persisted = self::persist_skills_job( $state );
		if ( is_wp_error( $persisted ) ) return $persisted;

		try {
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
			$seed = MAD4B_SCP_Skill_Seeder::bootstrap();
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
			$providers = MAD4B_SCP_Skill_Provider_Discovery::reconcile();
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
			$certification = MAD4B_SCP_Skill_Runtime_Certification::observe();
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
		$request = array(
			'contract' => 'mad4b.remote-frontend-performance-sampling.v2',
			'request_id' => strtolower( wp_generate_uuid4() ),
			'status' => 'pending_external_executor',
			'execution_mode' => 'external_browser_agent',
			'executor_contract' => 'mad4b.browser-acceptance-core.v1',
			'target_path' => $path,
			'target_url' => home_url( $path ),
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
		if ( ! self::persist_browser_request( $request ) ) return new WP_Error( 'mad4b_frontend_sample_request_persist_failed', 'Frontend browser sampling request could not be durably read back.' );
		do_action( 'mad4b_scp_remote_browser_request_enqueued', $request );
		$audit = self::audit( self::FRONTEND_SAMPLE_ABILITY, array(
			'source_commit_sha' => (string) $request['expected_identity']['source_commit_sha'],
			'request_id' => (string) $request['request_id'],
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
