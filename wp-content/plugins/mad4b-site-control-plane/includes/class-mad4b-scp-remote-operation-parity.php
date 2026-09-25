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

	const SKILLS_CONFIRMATION = 'RECONCILE MANAGED SKILLS';
	const FRONTEND_CONFIRMATION = 'COLLECT FRONTEND PERFORMANCE SAMPLES';
	const PERFORMANCE_CONFIRMATION = 'APPLY STAGING PERFORMANCE INDEXES';

	private static $booted = false;
	private static $running = array();

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
			'Issue bounded same-origin Frontend requests so current-build Query Monitor telemetry can collect server-side performance samples without a manual browser visit.',
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
				'remote_mode' => 'direct_bounded_operation',
				'production_policy' => 'deny',
				'human_decision_required' => false,
			),
			'frontend_performance_sampling' => array(
				'feature_id' => 'live-acceptance-performance',
				'capability_tags' => array( 'frontend', 'performance', 'sampling', 'acceptance', 'telemetry' ),
				'provider' => 'wordpress-http',
				'status_ability' => 'mad4b/frontend-performance-status',
				'local_surface' => 'frontend-browser-visit',
				'remote_ability' => self::FRONTEND_SAMPLE_ABILITY,
				'authority_surface' => 'mad4b-enrollment',
				'executor' => 'same_origin_http_frontend_probe',
				'remote_mode' => 'direct_bounded_operation',
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
				'executor' => 'wordpress_native_database_ddl',
				'remote_mode' => 'direct_bounded_operation',
				'production_policy' => 'deny',
				'human_decision_required' => false,
			),
		);
		foreach ( $rows as $key => &$row ) {
			$row['operation_id'] = $key;
			$row['remote_registered'] = function_exists( 'wp_has_ability' ) && wp_has_ability( $row['remote_ability'] );
			$row['manual_only'] = empty( $row['remote_ability'] );
			$row['remote_parity_ready'] = ! $row['manual_only'] && $row['remote_registered'];
		}
		unset( $row );
		return $rows;
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
		return array(
			'contract' => 'mad4b.operation-discovery.v1',
			'query' => $query,
			'count' => count( $matches ),
			'operations' => $matches,
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
			'generic_remote_admin_exposed' => false,
			'raw_shell_exposed' => false,
			'raw_sql_exposed' => false,
			'production_mutation_allowed' => false,
		);
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

		try {
			$seed_before = MAD4B_SCP_Skill_Seeder::inspect();
			$provider_before = MAD4B_SCP_Skill_Provider_Discovery::inspect();
			if ( ! empty( $seed_before['conflicts'] ) || ! empty( $provider_before['conflicts'] ) ) return new WP_Error( 'mad4b_remote_skill_conflict', 'Managed Skill reconciliation is blocked by current conflicts.', array( 'seed_conflicts' => isset( $seed_before['conflicts'] ) ? $seed_before['conflicts'] : array(), 'provider_conflicts' => isset( $provider_before['conflicts'] ) ? $provider_before['conflicts'] : array() ) );

			$seed = MAD4B_SCP_Skill_Seeder::bootstrap();
			if ( is_wp_error( $seed ) || ! is_array( $seed ) || 'ready' !== ( isset( $seed['state'] ) ? (string) $seed['state'] : '' ) ) return is_wp_error( $seed ) ? $seed : new WP_Error( 'mad4b_remote_skill_seed_failed', 'Canonical Skill seed reconciliation did not reach ready state.', array( 'seed' => $seed ) );

			$providers = MAD4B_SCP_Skill_Provider_Discovery::reconcile();
			if ( is_wp_error( $providers ) || ! is_array( $providers ) || 'ready' !== ( isset( $providers['state'] ) ? (string) $providers['state'] : '' ) ) return is_wp_error( $providers ) ? $providers : new WP_Error( 'mad4b_remote_skill_provider_failed', 'Provider Skill reconciliation did not reach ready state.', array( 'providers' => $providers ) );

			$certification = MAD4B_SCP_Skill_Runtime_Certification::observe();
			if ( ! is_array( $certification ) || empty( $certification['ready'] ) ) return new WP_Error( 'mad4b_remote_skill_certification_failed', 'Managed Skill reconciliation completed but runtime certification is not ready.', array( 'certification' => $certification ) );

			$audit = self::audit( self::SKILLS_ABILITY, array(
				'source_commit_sha' => isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '',
				'seed_before' => $seed_before,
				'provider_before' => $provider_before,
				'certification_ready' => true,
			) );
			if ( is_wp_error( $audit ) ) return $audit;

			return array(
				'contract' => 'mad4b.remote-managed-skills-reconciliation.v1',
				'state' => 'ready',
				'ready' => true,
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
		if ( ! function_exists( 'wp_remote_get' ) ) return new WP_Error( 'mad4b_frontend_sample_http_unavailable', 'WordPress HTTP client is unavailable.' );
		if ( ! self::enter( 'frontend_sample' ) ) return new WP_Error( 'mad4b_frontend_sample_reentry_denied', 'Frontend performance sampling is already running in this request.' );

		try {
			$results = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$probe = substr( hash( 'sha256', wp_generate_uuid4() . ':' . microtime( true ) . ':' . $i ), 0, 24 );
				$url = add_query_arg( 'mad4b_frontend_sample', $probe, home_url( $path ) );
				$started = microtime( true );
				$response = wp_remote_get( $url, array(
					'timeout' => 30,
					'redirection' => 0,
					'sslverify' => true,
					'headers' => array(
						'Cache-Control' => 'no-cache, no-store, max-age=0',
						'Pragma' => 'no-cache',
						'User-Agent' => 'MAD4B Frontend Performance Sampler/' . ( defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : 'unknown' ),
					),
				) );
				$elapsed = round( ( microtime( true ) - $started ) * 1000.0, 3 );
				if ( is_wp_error( $response ) ) {
					$results[] = array( 'sequence' => $i + 1, 'state' => 'http_error', 'error_code' => $response->get_error_code(), 'elapsed_ms' => $elapsed );
					continue;
				}
				$code = (int) wp_remote_retrieve_response_code( $response );
				$results[] = array( 'sequence' => $i + 1, 'state' => ( $code >= 200 && $code < 400 ) ? 'observed' : 'http_status_error', 'http_status' => $code, 'elapsed_ms' => $elapsed );
			}

			$performance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status() : array();
			$audit = self::audit( self::FRONTEND_SAMPLE_ABILITY, array(
				'source_commit_sha' => isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '',
				'target_path' => $path,
				'requested_samples' => $count,
				'results' => $results,
				'performance_sample_count' => isset( $performance['evaluation_window']['sample_count'] ) ? (int) $performance['evaluation_window']['sample_count'] : 0,
			) );
			if ( is_wp_error( $audit ) ) return $audit;

			return array(
				'contract' => 'mad4b.remote-frontend-performance-sampling.v1',
				'state' => 'completed',
				'target_path' => $path,
				'requested_samples' => $count,
				'results' => $results,
				'frontend_performance_status' => $performance,
				'remote_operation' => true,
				'production_mutation' => false,
			);
		} finally {
			self::leave( 'frontend_sample' );
		}
	}

	public static function apply_performance_indexes( $input ) {
		if ( self::PERFORMANCE_CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_performance_index_confirmation_required', 'Exact performance-index confirmation is required.' );
		$provenance = self::assert_exact_build( $input );
		if ( is_wp_error( $provenance ) ) return $provenance;
		if ( ! class_exists( 'MAD4B_SCP_Admin_Query_Performance' ) || ! method_exists( 'MAD4B_SCP_Admin_Query_Performance', 'apply_explicit' ) ) return new WP_Error( 'mad4b_performance_index_service_unavailable', 'Explicit performance-index maintenance service is unavailable.' );
		if ( ! self::enter( 'performance_indexes' ) ) return new WP_Error( 'mad4b_performance_index_reentry_denied', 'Performance-index maintenance is already running in this request.' );

		try {
			$result = MAD4B_SCP_Admin_Query_Performance::apply_explicit();
			if ( is_wp_error( $result ) ) return $result;
			$audit = self::audit( self::PERFORMANCE_INDEX_ABILITY, array(
				'source_commit_sha' => isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '',
				'ready_after' => is_array( $result ) && ! empty( $result['ready'] ),
				'state' => is_array( $result ) && isset( $result['state'] ) ? (string) $result['state'] : '',
			) );
			if ( is_wp_error( $audit ) ) return $audit;
			return array(
				'contract' => 'mad4b.remote-admin-query-performance-apply.v1',
				'result' => $result,
				'remote_operation' => true,
				'production_mutation' => false,
			);
		} finally {
			self::leave( 'performance_indexes' );
		}
	}
}

MAD4B_SCP_Remote_Operation_Parity::boot();
