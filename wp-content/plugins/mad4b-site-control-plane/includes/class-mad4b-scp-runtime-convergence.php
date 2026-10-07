<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Generic post-deploy runtime convergence for governed non-Production sites.
 *
 * The class joins release identity, schema, managed Skills, authority binding,
 * external acceptance and provider closure into one dependency graph. Only
 * idempotent lifecycle work is eligible for automatic convergence. Candidate
 * binding, provider write certification, canaries and Production remain
 * separate governed gates.
 */
final class MAD4B_SCP_Runtime_Convergence {
	const CONTRACT = 'mad4b.runtime-convergence.v1';
	const STATUS_ABILITY = 'mad4b/runtime-convergence-status';
	const PLAN_ABILITY = 'mad4b/runtime-convergence-plan';
	const APPLY_ABILITY = 'mad4b/runtime-convergence-apply';
	const CONFIRMATION = 'CONVERGE STAGING RUNTIME';
	const CHECKPOINT_OPTION = 'mad4b_scp_runtime_convergence_v1';
	const LOCK_OPTION = 'mad4b_scp_runtime_maintenance_lock_v1';
	const CRON_HOOK = 'mad4b_scp_runtime_convergence_resume';
	const LOCK_TTL = 300;
	const MAX_TRANSIENT_RETRIES = 5;
	const POST_UPDATE_QUIET_SECONDS = 20;

	private static $booted = false;
	private static $abilities_registered = false;

	public static function post_update_quiet_seconds() {
		return self::POST_UPDATE_QUIET_SECONDS;
	}

	public static function maintenance_not_before() {
		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! is_array( $checkpoint ) ) return 0;
		$explicit = isset( $checkpoint['resume_not_before'] ) ? absint( $checkpoint['resume_not_before'] ) : 0;
		if ( $explicit > 0 ) return $explicit;
		$source = isset( $checkpoint['source'] ) ? sanitize_key( (string) $checkpoint['source'] ) : '';
		$state = isset( $checkpoint['state'] ) ? sanitize_key( (string) $checkpoint['state'] ) : '';
		if ( 'self_update' !== $source || ! in_array( $state, array( 'pending_restart', 'pending_safe_phases', 'pending_manual_resume', 'waiting_for_exact_runtime_restart' ), true ) ) return 0;
		// Backward-compatible barrier: the update request runs the previously loaded
		// plugin code. Older builds therefore cannot persist resume_not_before, so
		// derive the same quiet window from the checkpoint timestamp on first boot.
		$stamp = isset( $checkpoint['updated_at'] ) ? strtotime( (string) $checkpoint['updated_at'] ) : false;
		if ( false === $stamp && isset( $checkpoint['created_at'] ) ) $stamp = strtotime( (string) $checkpoint['created_at'] );
		return false === $stamp ? 0 : max( 0, (int) $stamp + self::POST_UPDATE_QUIET_SECONDS );
	}

	public static function restart_grace_status() {
		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		$now = time();
		$not_before = self::maintenance_not_before();
		$source = is_array( $checkpoint ) && isset( $checkpoint['source'] ) ? sanitize_key( (string) $checkpoint['source'] ) : '';
		$state = is_array( $checkpoint ) && isset( $checkpoint['state'] ) ? sanitize_key( (string) $checkpoint['state'] ) : '';
		$barrier_states = array( 'pending_restart', 'pending_safe_phases', 'waiting_for_exact_runtime_restart', 'pending_manual_resume', 'blocked' );
		$target = is_array( $checkpoint ) && isset( $checkpoint['target_identity'] ) && is_array( $checkpoint['target_identity'] )
			? self::bounded_identity( $checkpoint['target_identity'] )
			: array();
		$current = ! empty( $target ) ? self::current_identity() : array();
		$identity_match = ! empty( $target ) && self::identity_matches( $target, $current );
		$convergence_pending = 'self_update' === $source && in_array( $state, $barrier_states, true );
		$quiet_period_active = $convergence_pending && $not_before > $now;
		$operator_action_required = $convergence_pending && in_array( $state, array( 'pending_manual_resume', 'blocked' ), true );

		// The quiet period is only the minimum delay. The actual protocol barrier is
		// identity/convergence bound and remains active until self-update safe
		// convergence leaves every pending/error state. A terminal safe-phase error
		// must remain fail-closed until an operator repairs/resumes convergence.
		$active = $convergence_pending;
		$retryable = $active && ! $operator_action_required;
		$retry_after = $retryable ? ( $quiet_period_active ? max( 1, $not_before - $now ) : 5 ) : 0;
		return array(
			'contract' => 'mad4b.runtime-restart-grace.v2',
			'active' => $active,
			'state' => $state,
			'convergence_pending' => $convergence_pending,
			'quiet_period_active' => $quiet_period_active,
			'exact_runtime_identity_match' => $identity_match,
			'current_identity' => self::bounded_identity( $current ),
			'retryable' => $retryable,
			'retry_after_seconds' => $retry_after,
			'client_action' => 'blocked' === $state
				? 'operator_repair_runtime_convergence'
				: ( $operator_action_required ? 'operator_resume_runtime_convergence' : 'retry_after_runtime_convergence' ),
			'resume_not_before' => $not_before,
			'target_identity' => $target,
			'background_maintenance_deferred' => $active,
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation' => false,
		);
	}

	public static function maintenance_lease_status() {
		return class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' )
			? MAD4B_SCP_Runtime_Maintenance_Lease::status()
			: array(
				'contract' => 'mad4b.runtime-maintenance-lease.v1',
				'active' => false,
				'owner' => '',
				'expires_at' => 0,
				'hard_expires_at' => 0,
				'read_only' => true,
				'blocker' => 'maintenance_lease_coordinator_unavailable',
			);
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 12 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'resume_safe_phases' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_pending' ), 40 );
	}

	public static function register_abilities() {
		if ( self::$abilities_registered || ! function_exists( 'wp_register_ability' ) ) return;
		self::$abilities_registered = true;
		self::register_read(
			self::STATUS_ABILITY,
			'Get Runtime Convergence Status',
			'Inspect the generic post-deploy runtime convergence graph without mutation.',
			'status',
			array( 'type' => 'object', 'additionalProperties' => false )
		);
		self::register_read(
			self::PLAN_ABILITY,
			'Plan Runtime Convergence',
			'Build an exact dependency-ordered runtime convergence plan. Release update, owner authority and provider-write gates remain explicit.',
			'plan',
			array(
				'type' => 'object',
				'properties' => array(
					'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				),
				'required' => array( 'reason' ),
				'additionalProperties' => false,
			)
		);
		// Enrollment maintenance remains callable when ordinary governed-write
		// candidate binding is the stale component being diagnosed. The global
		// write-augmentation filter already excludes mcp.surface=enrollment, so do
		// not inspect the Ability registry or mutate hook state while that registry
		// itself is being materialized.
		wp_register_ability(
			self::APPLY_ABILITY,
				array(
					'label' => 'Converge Staging Runtime',
					'description' => 'Apply only exact-plan, idempotent Staging runtime convergence phases. Production, candidate binding and provider write certification are never auto-applied.',
					'category' => 'mad4b-governance',
					'execute_callback' => array( __CLASS__, 'apply' ),
					'permission_callback' => array( __CLASS__, 'can_apply' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
							'expected_plan_sha256' => array(
								'type' => 'string',
								'minLength' => 64,
								'maxLength' => 64,
								'pattern' => '^[A-Fa-f0-9]{64}$',
							),
							'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
						),
						'required' => array( 'reason', 'expected_plan_sha256', 'confirmation' ),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array(
							'public' => false,
							'type' => 'tool',
							'surface' => 'enrollment',
							'generic_remote_admin' => false,
							'production_mutation_allowed' => false,
						),
						'annotations' => array(
							'readonly' => false,
							'destructive' => false,
							'idempotent' => true,
						),
					),
				)
		);
	}

	private static function register_read( $name, $label, $description, $method, array $schema ) {
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $description,
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, $method ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => $schema,
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function can_apply( $input = null ) {
		unset( $input );
		if ( ! class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) || ! method_exists( 'MAD4B_SCP_Remote_Operation_Parity', 'can_execute' ) ) {
			return new WP_Error( 'mad4b_runtime_convergence_authority_unavailable', 'Runtime convergence requires the governed Staging enrollment authority.' );
		}
		return MAD4B_SCP_Remote_Operation_Parity::can_execute();
	}

	public static function status( $input = array() ) {
		unset( $input );
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$environment = isset( $profile['environment'] ) ? sanitize_key( (string) $profile['environment'] ) : ( class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' ) );
		$identity = self::current_identity();
		$update = class_exists( 'MAD4B_SCP_Self_Update' ) && method_exists( 'MAD4B_SCP_Self_Update', 'cached_status' ) ? MAD4B_SCP_Self_Update::cached_status() : array();
		$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status( true ) : array();
		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ? MAD4B_SCP_Skill_Runtime_Certification::current_status() : array();
		$write_authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::persisted_status() : array();
		$candidate_binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();
		$write_effective = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();
		$rest = class_exists( 'MAD4B_SCP_REST_Compatibility' ) ? MAD4B_SCP_REST_Compatibility::status() : array();
		$external_wpml = isset( $rest['external_wpml_acceptance'] ) && is_array( $rest['external_wpml_acceptance'] ) ? $rest['external_wpml_acceptance'] : array();
		$providers = class_exists( 'MAD4B_SCP_Provider_Closure_Matrix' ) ? MAD4B_SCP_Provider_Closure_Matrix::matrix() : array();
		$performance = class_exists( 'MAD4B_SCP_Admin_Query_Performance' ) ? MAD4B_SCP_Admin_Query_Performance::status() : array();
		$local_oauth = class_exists( 'MAD4B_SCP_Local_OAuth_Server', false ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'runtime_identity_status' )
			? MAD4B_SCP_Local_OAuth_Server::runtime_identity_status()
			: array();
		$local_oauth_required = ! empty( $local_oauth['configured'] );
		$local_oauth_store_ready = ! $local_oauth_required || ! empty( $local_oauth['oauth_store_ready'] );

		$update_target = isset( $update['native_wordpress_update']['target'] ) && is_array( $update['native_wordpress_update']['target'] ) ? $update['native_wordpress_update']['target'] : array();
		$target_sha = isset( $update_target['source_commit_sha'] ) ? strtolower( trim( (string) $update_target['source_commit_sha'] ) ) : '';
		$current_sha = isset( $identity['source_commit_sha'] ) ? strtolower( trim( (string) $identity['source_commit_sha'] ) ) : '';
		$runtime_identity_complete = ! empty( $identity['source_commit_sha'] ) && ! empty( $identity['build_fingerprint'] ) && ! empty( $identity['package_manifest_digest'] );
		// A missing cached release target means "no update-channel observation", not
		// "runtime unknown". Exact on-disk identity is sufficient for local runtime
		// convergence; update freshness remains a separate advisory concern.
		$deployment_state = '' === $target_sha
			? ( $runtime_identity_complete ? 'ready' : 'unknown' )
			: ( '' !== $current_sha && hash_equals( $target_sha, $current_sha ) ? 'ready' : 'pending' );

		$write_enabled = ! empty( $profile['write_enabled'] );
		$skills_enabled = ! empty( $profile['skills_enabled'] );
		$acceptance_enabled = ! empty( $profile['acceptance_enabled'] );
		$wpml_required = $acceptance_enabled && ! empty( $rest['external_wpml_acceptance_required'] );
		$wpml_verified = ! $wpml_required || ! empty( $rest['external_wpml_acceptance_verified'] );
		$binding_required = ! empty( $candidate_binding['required'] );
		$binding_match = ! empty( $candidate_binding['match'] );
		$authority_ready = ! $write_enabled || ( ! empty( $write_authority['ready'] ) && $write_effective && ( ! $binding_required || $binding_match ) );

		$phases = array(
			'deployment' => self::phase( 'deployment', $deployment_state, true, array(), false, array(
				'current_identity' => $identity,
				'target_identity' => $update_target,
				'manifest_state' => isset( $update['native_wordpress_update']['manifest_state'] ) ? (string) $update['native_wordpress_update']['manifest_state'] : '',
				'cached_release_target_present' => '' !== $target_sha,
				'runtime_identity_complete' => $runtime_identity_complete,
				'readiness_basis' => '' === $target_sha && $runtime_identity_complete ? 'exact_current_runtime_identity' : 'cached_release_target',
			) ),
			'schema' => self::phase( 'schema', ! empty( $schema['ready'] ) ? 'ready' : 'pending', true, array( 'deployment' ), true, array(
				'expected_version' => isset( $schema['expected_version'] ) ? (int) $schema['expected_version'] : 0,
				'installed_version' => isset( $schema['installed_version'] ) ? (int) $schema['installed_version'] : 0,
				'integrity_token_valid' => ! empty( $schema['integrity_token_valid'] ),
				'physical_ready' => ! empty( $schema['physical_integrity']['ready'] ),
			) ),
			'local_oauth_store' => self::phase( 'local_oauth_store', $local_oauth_store_ready ? 'ready' : 'pending', $local_oauth_required, array( 'schema' ), true, array(
				'configured' => $local_oauth_required,
				'store_ready' => ! empty( $local_oauth['oauth_store_ready'] ),
				'installed_version' => isset( $local_oauth['oauth_store_version'] ) ? (int) $local_oauth['oauth_store_version'] : 0,
				'expected_version' => class_exists( 'MAD4B_SCP_Local_OAuth_Store', false ) ? (int) MAD4B_SCP_Local_OAuth_Store::VERSION : 0,
				'protocol_migration_allowed' => false,
			) ),
			'managed_skills' => self::phase( 'managed_skills', ! $skills_enabled || ! empty( $skills['ready'] ) ? 'ready' : 'pending', $skills_enabled, array( 'schema' ), true, array(
				'enabled' => $skills_enabled,
				'blockers' => isset( $skills['blockers'] ) && is_array( $skills['blockers'] ) ? array_values( $skills['blockers'] ) : array(),
				'provider_would_create' => isset( $skills['provider_inspection']['would_create'] ) && is_array( $skills['provider_inspection']['would_create'] ) ? array_values( $skills['provider_inspection']['would_create'] ) : array(),
				'provider_would_refresh' => isset( $skills['provider_inspection']['would_refresh'] ) && is_array( $skills['provider_inspection']['would_refresh'] ) ? array_values( $skills['provider_inspection']['would_refresh'] ) : array(),
			) ),
			'authority_binding' => self::phase( 'authority_binding', $authority_ready ? 'ready' : 'owner_gate', $write_enabled, array( 'deployment', 'schema' ), false, array(
				'write_enabled' => $write_enabled,
				'persisted_write_ready' => ! empty( $write_authority['ready'] ),
				'write_effective' => (bool) $write_effective,
				'candidate_binding_required' => $binding_required,
				'candidate_binding_match' => $binding_match,
				'current_source_commit_sha' => isset( $candidate_binding['current_source_commit_sha'] ) ? (string) $candidate_binding['current_source_commit_sha'] : '',
				'stored_source_commit_sha' => isset( $candidate_binding['stored_source_commit_sha'] ) ? (string) $candidate_binding['stored_source_commit_sha'] : '',
			) ),
			'external_acceptance' => self::phase( 'external_acceptance', $wpml_verified ? 'ready' : 'pending', $wpml_required, array( 'deployment', 'schema' ), false, array(
				'wpml_required' => $wpml_required,
				'external_verified' => $wpml_verified,
				'external_state' => isset( $rest['external_wpml_acceptance_state'] ) ? sanitize_key( (string) $rest['external_wpml_acceptance_state'] ) : '',
				'route_registered' => ! empty( $external_wpml['route_registered'] ),
				'response_status' => isset( $external_wpml['response_status'] ) ? (int) $external_wpml['response_status'] : 0,
				'internal_rest_state' => isset( $rest['wpml']['state'] ) ? sanitize_key( (string) $rest['wpml']['state'] ) : '',
			) ),
			'provider_closure' => self::phase( 'provider_closure', empty( $providers['provider_gated_count'] ) ? 'ready' : 'conditional_gate', false, array( 'schema', 'managed_skills' ), false, array(
				'provider_gated_count' => isset( $providers['provider_gated_count'] ) ? (int) $providers['provider_gated_count'] : 0,
				'closure_class_counts' => isset( $providers['closure_class_counts'] ) && is_array( $providers['closure_class_counts'] ) ? $providers['closure_class_counts'] : array(),
			) ),
			'performance_advisory' => self::phase( 'performance_advisory', ! empty( $performance['ready'] ) ? 'ready' : 'advisory', false, array( 'schema' ), false, self::bounded_performance( $performance ) ),
		);

		$context = array(
			'environment' => $environment,
			'identity' => $identity,
			'profile' => $profile,
		);
		$phases = apply_filters( 'mad4b_scp_runtime_convergence_phases', $phases, $context );
		$phases = self::normalize_phases( $phases );
		$order_result = self::topological_order( $phases );
		$graph_valid = ! is_wp_error( $order_result );
		$order = $graph_valid ? $order_result : array_keys( $phases );
		$required_blockers = $graph_valid ? array() : array( 'phase_graph:cycle_or_invalid_dependency' );
		foreach ( $order as $phase_id ) {
			$phase = $phases[ $phase_id ];
			if ( empty( $phase['required'] ) ) continue;
			if ( 'ready' !== $phase['state'] ) $required_blockers[] = $phase_id . ':' . $phase['state'];
		}
		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		$checkpoint_state = is_array( $checkpoint ) && isset( $checkpoint['state'] ) ? sanitize_key( (string) $checkpoint['state'] ) : '';
		$checkpoint_source = is_array( $checkpoint ) && isset( $checkpoint['source'] ) ? sanitize_key( (string) $checkpoint['source'] ) : '';
		$auto_pending = false;
		$gated_pending = false;
		foreach ( $phases as $phase ) {
			if ( empty( $phase['required'] ) || 'ready' === $phase['state'] ) continue;
			if ( ! empty( $phase['auto_safe'] ) ) $auto_pending = true;
			else $gated_pending = true;
		}
		$manual_resume_gate = 'pending_manual_resume' === $checkpoint_state && is_array( $checkpoint ) && ! empty( $checkpoint['resume_blocker'] );
		if ( empty( $required_blockers ) ) $autopilot_state = 'ready';
		elseif ( ! $graph_valid || 'blocked' === $checkpoint_state ) $autopilot_state = 'blocked';
		elseif ( $manual_resume_gate ) $autopilot_state = 'gated';
		elseif ( $auto_pending && 'plugin_activation' === $checkpoint_source ) $autopilot_state = 'bootstrapping';
		elseif ( ! $auto_pending && $gated_pending ) $autopilot_state = 'gated';
		elseif ( $auto_pending || in_array( $checkpoint_state, array( 'pending_restart', 'pending_safe_phases', 'pending_manual_resume', 'waiting_for_exact_runtime_restart' ), true ) ) $autopilot_state = 'converging';
		elseif ( $gated_pending ) $autopilot_state = 'gated';
		else $autopilot_state = 'blocked';
		return array(
			'contract' => self::CONTRACT,
			'state' => empty( $required_blockers ) ? 'ready' : 'convergence_required',
			'autopilot_state' => $autopilot_state,
			'ready' => empty( $required_blockers ),
			'environment' => $environment,
			'production_mutation_allowed' => false,
			'generic_shell' => false,
			'arbitrary_operations' => false,
			'current_identity' => $identity,
			'phase_order' => $order,
			'phase_graph_valid' => $graph_valid,
			'phase_graph_error' => $graph_valid ? '' : $order_result->get_error_code(),
			'phases' => $phases,
			'required_blockers' => $required_blockers,
			'checkpoint' => is_array( $checkpoint ) ? $checkpoint : array(),
			'dynamic_extension_filter' => 'mad4b_scp_runtime_convergence_phases',
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		$status = self::status();
		$release = class_exists( 'MAD4B_SCP_Self_Update' ) ? MAD4B_SCP_Self_Update::native_plan( array( 'reason' => $reason ) ) : new WP_Error( 'mad4b_runtime_convergence_self_update_unavailable', 'Control Plane release planner is unavailable.' );
		$actions = array();
		$gated = array();

		if ( is_wp_error( $release ) ) {
			$gated[] = self::action( 'deployment', 'diagnose_release_channel', 'mad4b/control-plane-native-plan', false, true, array( 'error_code' => $release->get_error_code() ) );
		} else {
			$release_blockers = isset( $release['blockers'] ) && is_array( $release['blockers'] ) ? $release['blockers'] : array();
			if ( ! empty( $release['eligible'] ) ) {
				$gated[] = self::action( 'deployment', 'apply_certified_release', 'mad4b/control-plane-native-apply', false, true, array(
					'expected_plan_sha256' => isset( $release['plan_sha256'] ) ? (string) $release['plan_sha256'] : '',
					'target' => isset( $release['target'] ) ? $release['target'] : array(),
					'restart_boundary' => true,
				) );
			} elseif ( ! in_array( 'already_on_exact_source_commit', $release_blockers, true ) ) {
				$gated[] = self::action( 'deployment', 'resolve_release_blockers', 'mad4b/control-plane-native-plan', false, true, array( 'blockers' => $release_blockers ) );
			}
		}

		$phases = isset( $status['phases'] ) && is_array( $status['phases'] ) ? $status['phases'] : array();
		if ( isset( $phases['schema'] ) && 'ready' !== $phases['schema']['state'] ) {
			$actions[] = self::action( 'schema', 'reconcile_schema', self::APPLY_ABILITY, true, false );
		}
		if ( isset( $phases['local_oauth_store'] ) && 'ready' !== $phases['local_oauth_store']['state'] ) {
			$actions[] = self::action( 'local_oauth_store', 'reconcile_local_oauth_store', self::APPLY_ABILITY, true, false );
		}
		if ( isset( $phases['managed_skills'] ) && 'ready' !== $phases['managed_skills']['state'] ) {
			$actions[] = self::action( 'managed_skills', 'reconcile_managed_skills', self::APPLY_ABILITY, true, false );
		}
		if ( isset( $phases['authority_binding'] ) && 'ready' !== $phases['authority_binding']['state'] ) {
			$gated[] = self::action( 'authority_binding', 'owner_rebind_exact_candidate', 'mad4b/staging-write-authority-convergence-handshake', false, true );
		}
		if ( isset( $phases['external_acceptance'] ) && 'ready' !== $phases['external_acceptance']['state'] ) {
			$gated[] = self::action( 'external_acceptance', 'refresh_external_acceptance_evidence', 'mad4b/rest-compatibility-status', false, false );
		}
		if ( isset( $phases['performance_advisory']['details'] ) && is_array( $phases['performance_advisory']['details'] ) ) {
			$performance_details = $phases['performance_advisory']['details'];
			if ( ! empty( $performance_details['reconciliation_required'] ) ) {
				$gated[] = self::action( 'performance_advisory', 'reconcile_stale_performance_job', 'mad4b/admin-query-performance-reconcile', false, false, array( 'blind_retry_allowed' => false ) );
			} elseif ( empty( $performance_details['ready'] ) && ! empty( $performance_details['maintenance_executor_ready'] ) ) {
				$gated[] = self::action( 'performance_advisory', 'queue_bounded_performance_indexes', 'mad4b/admin-query-performance-apply', false, false, array( 'synchronous_ddl' => false ) );
			}
		}

		$plan = array(
			'contract' => 'mad4b.runtime-convergence-plan.v1',
			'reason' => $reason,
			'current_identity' => isset( $status['current_identity'] ) ? $status['current_identity'] : array(),
			'phase_order' => isset( $status['phase_order'] ) ? $status['phase_order'] : array(),
			'auto_safe_actions' => $actions,
			'gated_actions' => $gated,
			'release_plan' => is_wp_error( $release ) ? array( 'error_code' => $release->get_error_code() ) : $release,
			'production_mutation_allowed' => false,
			'candidate_binding_auto_refresh' => class_exists( 'MAD4B_SCP_Post_Update_Continuation' )
				&& ( function_exists( 'wp_get_environment_type' ) ? 'staging' === sanitize_key( (string) ( class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : wp_get_environment_type() ) ) : false )
				&& ( static function () {
					$status = MAD4B_SCP_Post_Update_Continuation::status();
					return ! empty( $status['active'] ) && MAD4B_SCP_Post_Update_Continuation::CLASS_ZERO === ( isset( $status['classification'] ) ? (string) $status['classification'] : '' );
				} )(),
			'candidate_binding_auto_refresh_policy' => 'post_update_zero_delta_continuation_only',
			'provider_write_auto_certification' => false,
			'raw_sql_breakglass' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['write_binding'] = array( 'expected_plan_sha256' => $plan['plan_sha256'], 'confirmation' => self::CONFIRMATION );
		return $plan;
	}

	public static function apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) {
			return new WP_Error( 'mad4b_runtime_convergence_confirmation_required', 'Exact Staging runtime convergence confirmation is required.' );
		}
		$expected = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) return new WP_Error( 'mad4b_runtime_convergence_plan_digest_required', 'A reviewed runtime convergence plan digest is required.' );
		$plan = self::plan( array( 'reason' => isset( $input['reason'] ) ? (string) $input['reason'] : '' ) );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['plan_sha256'] ) || ! hash_equals( $plan['plan_sha256'], $expected ) ) {
			return new WP_Error( 'mad4b_runtime_convergence_plan_changed', 'Runtime convergence plan changed since review.', array( 'current_plan_sha256' => isset( $plan['plan_sha256'] ) ? $plan['plan_sha256'] : '', 'expected_plan_sha256' => $expected ) );
		}
		foreach ( isset( $plan['gated_actions'] ) && is_array( $plan['gated_actions'] ) ? $plan['gated_actions'] : array() as $action ) {
			if ( isset( $action['phase'], $action['action'] ) && 'deployment' === $action['phase'] && 'apply_certified_release' === $action['action'] ) {
				return new WP_Error( 'mad4b_runtime_convergence_release_update_required', 'Install the exact certified release first; runtime convergence resumes after the restart boundary.', array( 'release_action' => $action, 'plan_sha256' => $expected ) );
			}
		}
		$result = self::run_safe_phases( 'explicit_remote', $plan );
		if ( is_wp_error( $result ) ) return $result;
		$result['plan_sha256'] = $expected;
		$result['gated_actions'] = isset( $plan['gated_actions'] ) ? $plan['gated_actions'] : array();
		$result['production_mutation_performed'] = false;
		$result['candidate_binding_mutation_performed'] = false;
		$result['provider_write_certification_performed'] = false;
		return $result;
	}

	public static function mark_activation_pending() {
		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		if ( 'staging' !== $environment ) return array( 'scheduled' => false, 'state' => 'observe_only_non_staging', 'environment' => $environment );
		$previous_checkpoint = get_option( self::CHECKPOINT_OPTION, null );
		$existing = is_array( $previous_checkpoint ) ? $previous_checkpoint : array();
		$existing_source = is_array( $existing ) && isset( $existing['source'] ) ? sanitize_key( (string) $existing['source'] ) : '';
		$existing_state = is_array( $existing ) && isset( $existing['state'] ) ? sanitize_key( (string) $existing['state'] ) : '';
		if ( 'self_update' === $existing_source && 'blocked' === $existing_state ) {
			return array(
				'scheduled' => false,
				'state' => 'self_update_blocked_checkpoint_preserved',
				'last_error_code' => isset( $existing['last_error_code'] ) ? sanitize_key( (string) $existing['last_error_code'] ) : '',
				'target_identity' => isset( $existing['target_identity'] ) && is_array( $existing['target_identity'] ) ? self::bounded_identity( $existing['target_identity'] ) : array(),
				'production_mutation' => false,
			);
		}
		if ( 'self_update' === $existing_source && in_array( $existing_state, array( 'pending_restart', 'pending_safe_phases', 'pending_manual_resume', 'waiting_for_exact_runtime_restart' ), true ) ) {
			$scheduled = self::schedule_resume( self::maintenance_not_before() );
			return array(
				'scheduled' => (bool) $scheduled,
				'state' => 'self_update_checkpoint_preserved',
				'target_identity' => isset( $existing['target_identity'] ) && is_array( $existing['target_identity'] ) ? self::bounded_identity( $existing['target_identity'] ) : array(),
				'production_mutation' => false,
			);
		}
		$identity = self::current_identity();
		$identity_complete = ! empty( $identity['source_commit_sha'] ) && ! empty( $identity['build_fingerprint'] ) && ! empty( $identity['package_manifest_digest'] );
		if ( ! $identity_complete ) return array( 'scheduled' => false, 'state' => 'activation_identity_incomplete', 'environment' => $environment );
		$checkpoint = array(
			'contract' => self::CONTRACT,
			'state' => 'pending_safe_phases',
			'source' => 'plugin_activation',
			'target_identity' => self::bounded_identity( $identity ),
			'automatic_retry_allowed' => true,
			'transient_retry_count' => 0,
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'production_mutation' => false,
		);
		update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		$stored = get_option( self::CHECKPOINT_OPTION, array() );
		$stored_source = is_array( $stored ) && isset( $stored['source'] ) ? sanitize_key( (string) $stored['source'] ) : '';
		if ( ! is_array( $stored ) || 'plugin_activation' !== $stored_source || empty( $stored['target_identity'] ) || ! self::identity_matches( $checkpoint['target_identity'], $stored['target_identity'] ) ) {
			$checkpoint_restore_ok = self::restore_checkpoint_snapshot( $previous_checkpoint );
			return array(
				'scheduled' => false,
				'state' => 'checkpoint_persist_failed',
				'persist_phase' => 'pending_safe_phases',
				'checkpoint_restore_ok' => $checkpoint_restore_ok,
				'target_identity' => $checkpoint['target_identity'],
				'production_mutation' => false,
			);
		}
		$scheduled = self::schedule_resume();
		if ( ! $scheduled ) {
			$checkpoint['state'] = 'pending_manual_resume';
			$checkpoint['resume_blocker'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'wp_cron_disabled' : 'wp_cron_unavailable';
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
			$stored = get_option( self::CHECKPOINT_OPTION, array() );
			if ( ! self::post_update_checkpoint_matches( $checkpoint, $stored ) ) {
				$checkpoint_restore_ok = self::restore_checkpoint_snapshot( $previous_checkpoint );
				return array(
					'scheduled' => false,
					'state' => 'checkpoint_persist_failed',
					'persist_phase' => 'pending_manual_resume',
					'checkpoint_restore_ok' => $checkpoint_restore_ok,
					'resume_blocker' => $checkpoint['resume_blocker'],
					'target_identity' => $checkpoint['target_identity'],
					'production_mutation' => false,
				);
			}
		}
		return array(
			'scheduled' => (bool) $scheduled,
			'state' => $scheduled ? 'pending_safe_phases' : 'pending_manual_resume',
			'target_identity' => $checkpoint['target_identity'],
			'production_mutation' => false,
		);
	}

	private static function restore_checkpoint_snapshot( $snapshot ) {
		if ( null === $snapshot ) {
			delete_option( self::CHECKPOINT_OPTION );
			return null === get_option( self::CHECKPOINT_OPTION, null );
		}
		update_option( self::CHECKPOINT_OPTION, $snapshot, false );
		return serialize( $snapshot ) === serialize( get_option( self::CHECKPOINT_OPTION, null ) );
	}

	private static function post_update_checkpoint_matches( array $expected, $stored ) {
		if ( ! is_array( $stored ) ) return false;
		foreach ( array( 'contract', 'state', 'source', 'channel', 'update_plan_sha256' ) as $field ) {
			$left = isset( $expected[ $field ] ) ? (string) $expected[ $field ] : '';
			$right = isset( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
			if ( ! hash_equals( $left, $right ) ) return false;
		}
		foreach ( array( 'resume_not_before', 'quiet_period_seconds' ) as $field ) {
			if ( absint( isset( $expected[ $field ] ) ? $expected[ $field ] : 0 ) !== absint( isset( $stored[ $field ] ) ? $stored[ $field ] : 0 ) ) return false;
		}
		if ( (bool) ( isset( $expected['production_mutation'] ) ? $expected['production_mutation'] : false )
			!== (bool) ( isset( $stored['production_mutation'] ) ? $stored['production_mutation'] : false ) ) return false;
		if ( empty( $expected['target_identity'] ) || ! is_array( $expected['target_identity'] )
			|| empty( $stored['target_identity'] ) || ! is_array( $stored['target_identity'] )
			|| ! self::identity_matches( $expected['target_identity'], $stored['target_identity'] ) ) return false;
		if ( isset( $expected['resume_blocker'] ) ) {
			$expected_blocker = sanitize_key( (string) $expected['resume_blocker'] );
			$stored_blocker = isset( $stored['resume_blocker'] ) ? sanitize_key( (string) $stored['resume_blocker'] ) : '';
			if ( ! hash_equals( $expected_blocker, $stored_blocker ) ) return false;
		}
		if ( ! empty( $expected['continuation'] ) ) {
			if ( empty( $stored['continuation'] ) || ! is_array( $stored['continuation'] ) ) return false;
			foreach ( array( 'contract', 'permit_id', 'permit_digest', 'classification', 'state' ) as $field ) {
				$left = isset( $expected['continuation'][ $field ] ) ? (string) $expected['continuation'][ $field ] : '';
				$right = isset( $stored['continuation'][ $field ] ) ? (string) $stored['continuation'][ $field ] : '';
				if ( ! hash_equals( $left, $right ) ) return false;
			}
			if ( (int) ( isset( $expected['continuation']['generation'] ) ? $expected['continuation']['generation'] : 0 )
				!== (int) ( isset( $stored['continuation']['generation'] ) ? $stored['continuation']['generation'] : -1 ) ) return false;
		}
		return true;
	}

	public static function mark_post_update_pending( array $target, $channel = '', $plan_sha256 = '', array $continuation = array() ) {
		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		if ( 'staging' !== $environment ) return array( 'scheduled' => false, 'state' => 'ignored_non_staging' );
		$previous_checkpoint = get_option( self::CHECKPOINT_OPTION, null );
		$checkpoint = array(
			'contract' => self::CONTRACT,
			'state' => 'pending_restart',
			'source' => 'self_update',
			'channel' => sanitize_key( (string) $channel ),
			'resume_not_before' => time() + self::POST_UPDATE_QUIET_SECONDS,
			'quiet_period_seconds' => self::POST_UPDATE_QUIET_SECONDS,
			'target_identity' => self::bounded_identity( $target ),
			'update_plan_sha256' => strtolower( trim( (string) $plan_sha256 ) ),
			'continuation' => empty( $continuation ) ? array() : array(
				'contract' => isset( $continuation['contract'] ) ? (string) $continuation['contract'] : '',
				'permit_id' => isset( $continuation['permit_id'] ) ? (string) $continuation['permit_id'] : '',
				'permit_digest' => isset( $continuation['permit_digest'] ) ? (string) $continuation['permit_digest'] : '',
				'generation' => isset( $continuation['generation'] ) ? (int) $continuation['generation'] : 0,
				'classification' => isset( $continuation['classification'] ) ? (string) $continuation['classification'] : '',
				'state' => isset( $continuation['state'] ) ? sanitize_key( (string) $continuation['state'] ) : '',
			),
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'production_mutation' => false,
		);
		update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		$stored = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! self::post_update_checkpoint_matches( $checkpoint, $stored ) ) {
			$checkpoint_restore_ok = self::restore_checkpoint_snapshot( $previous_checkpoint );
			return array(
				'scheduled' => false,
				'state' => 'checkpoint_persist_failed',
				'persist_phase' => 'pending_restart',
				'checkpoint_restore_ok' => $checkpoint_restore_ok,
				'target_identity' => $checkpoint['target_identity'],
				'production_mutation' => false,
			);
		}
		$scheduled = self::schedule_resume( isset( $checkpoint['resume_not_before'] ) ? absint( $checkpoint['resume_not_before'] ) : 0 );
		if ( ! $scheduled ) {
			$checkpoint['state'] = 'pending_manual_resume';
			$checkpoint['resume_blocker'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'wp_cron_disabled' : 'wp_cron_unavailable';
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
			$stored = get_option( self::CHECKPOINT_OPTION, array() );
			if ( ! self::post_update_checkpoint_matches( $checkpoint, $stored ) ) {
				$checkpoint_restore_ok = self::restore_checkpoint_snapshot( $previous_checkpoint );
				return array(
					'scheduled' => false,
					'state' => 'checkpoint_persist_failed',
					'persist_phase' => 'pending_manual_resume',
					'checkpoint_restore_ok' => $checkpoint_restore_ok,
					'resume_blocker' => $checkpoint['resume_blocker'],
					'target_identity' => $checkpoint['target_identity'],
					'production_mutation' => false,
				);
			}
		}
		return array(
			'scheduled' => (bool) $scheduled,
			'state' => $scheduled ? 'pending_restart' : 'pending_manual_resume',
			'resume_blocker' => $scheduled ? '' : $checkpoint['resume_blocker'],
			'target_identity' => $checkpoint['target_identity'],
		);
	}

	/** Quarantine an already-persisted self-update checkpoint after a terminal apply failure. */
	public static function block_post_update( $reason, array $extra = array() ) {
		$reason = sanitize_key( (string) $reason );
		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! is_array( $checkpoint ) || 'self_update' !== sanitize_key( (string) ( $checkpoint['source'] ?? '' ) ) ) {
			return new WP_Error( 'mad4b_runtime_convergence_checkpoint_missing', 'Self-update convergence checkpoint is unavailable for quarantine.' );
		}
		$checkpoint['state'] = 'blocked';
		$checkpoint['last_error_code'] = '' !== $reason ? $reason : 'self_update_terminal_failure';
		$checkpoint['retry_policy'] = 'explicit_resume_required';
		$checkpoint['automatic_retry_allowed'] = false;
		$checkpoint['updated_at'] = gmdate( 'c' );
		foreach ( array( 'rollback_ok', 'audit_error_code' ) as $field ) {
			if ( array_key_exists( $field, $extra ) ) $checkpoint[ $field ] = $extra[ $field ];
		}
		update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		$stored = get_option( self::CHECKPOINT_OPTION, array() );
		return is_array( $stored )
			&& 'blocked' === sanitize_key( (string) ( $stored['state'] ?? '' ) )
			&& hash_equals( $checkpoint['last_error_code'], sanitize_key( (string) ( $stored['last_error_code'] ?? '' ) ) )
			? $stored
			: new WP_Error( 'mad4b_runtime_convergence_checkpoint_block_failed', 'Self-update convergence checkpoint could not be quarantined.' );
	}

	public static function maybe_schedule_pending() {
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : '' );
		if ( 'staging' !== $environment ) return;
		// Third-party wp-admin pages are pure request-serving surfaces. They must
		// never inspect build provenance, mutate checkpoints, schedule convergence,
		// or revive failed lifecycle jobs. Governed update/plugin lifecycle, MAD4B
		// operator pages, Cron/CLI and ordinary front-end traffic remain triggers.
		if ( ! self::convergence_trigger_allowed() ) return;

		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		$state = is_array( $checkpoint ) && isset( $checkpoint['state'] ) ? sanitize_key( (string) $checkpoint['state'] ) : '';
		$not_before = is_array( $checkpoint ) && isset( $checkpoint['resume_not_before'] ) ? absint( $checkpoint['resume_not_before'] ) : 0;
		if ( $not_before > time() ) {
			self::schedule_resume( $not_before );
			return;
		}

		// A cron run can observe the old runtime during an update boundary and park
		// the checkpoint here. Do not spin/retry while identity still mismatches.
		// Once a later request sees the exact target identity, convert the checkpoint
		// back to a schedulable safe-phase state.
		if ( 'waiting_for_exact_runtime_restart' === $state && is_array( $checkpoint ) ) {
			$target = isset( $checkpoint['target_identity'] ) && is_array( $checkpoint['target_identity'] ) ? $checkpoint['target_identity'] : array();
			$current = self::current_identity();
			if ( ! self::identity_matches( $target, $current ) ) return;
			$checkpoint['state'] = 'pending_safe_phases';
			$checkpoint['restart_identity_now_matches'] = true;
			$checkpoint['current_identity'] = $current;
			$checkpoint['updated_at'] = gmdate( 'c' );
			unset( $checkpoint['resume_blocker'] );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
			$state = 'pending_safe_phases';
		}

		if ( 'blocked' === $state ) {
			$last_error = isset( $checkpoint['last_error_code'] ) ? sanitize_key( (string) $checkpoint['last_error_code'] ) : '';
			$retry_count = isset( $checkpoint['transient_retry_count'] ) ? absint( $checkpoint['transient_retry_count'] ) : 0;
			$policy = self::worker_error_policy( $last_error );
			$decision = isset( $policy['decision'] ) ? (string) $policy['decision'] : 'REVIEW_REQUIRED';
			if ( 'DEFER' !== $decision || $retry_count >= self::MAX_TRANSIENT_RETRIES ) return;
			$checkpoint['state'] = 'pending_safe_phases';
			$checkpoint['retry_policy'] = 'automatic_bounded_retry';
			$checkpoint['automatic_retry_allowed'] = true;
			$checkpoint['auto_reconcile_decision'] = $decision;
			$checkpoint['auto_reconcile_policy_id'] = isset( $policy['policy_id'] ) ? sanitize_key( (string) $policy['policy_id'] ) : '';
			$checkpoint['auto_reconcile_policy_source'] = isset( $policy['policy_source'] ) ? sanitize_key( (string) $policy['policy_source'] ) : '';
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
			$state = 'pending_safe_phases';
		}
		if ( ! in_array( $state, array( 'pending_restart', 'pending_safe_phases', 'pending_manual_resume' ), true ) ) {
			$detected = self::detect_lightweight_runtime_drift();
			if ( ! empty( $detected['detected'] ) && ! empty( $detected['identity_complete'] ) ) {
				$checkpoint = array(
					'contract' => self::CONTRACT,
					'state' => 'pending_safe_phases',
					'source' => 'lightweight_runtime_drift_detector',
					'target_identity' => $detected['identity'],
					'drift_reasons' => $detected['reasons'],
					'created_at' => gmdate( 'c' ),
					'updated_at' => gmdate( 'c' ),
					'production_mutation' => false,
				);
				update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
				$state = 'pending_safe_phases';
			}
		}
		if ( ! in_array( $state, array( 'pending_restart', 'pending_safe_phases', 'pending_manual_resume' ), true ) ) return;
		if ( ! self::schedule_resume() && is_array( $checkpoint ) ) {
			$checkpoint['state'] = 'pending_manual_resume';
			$checkpoint['resume_blocker'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'wp_cron_disabled' : 'wp_cron_unavailable';
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		}
	}

	private static function convergence_trigger_allowed() {
		// Passive Connection/ChatGPT admin surfaces must remain passive even when
		// WordPress is executed through a CLI harness (for example runtime CI).
		// Ordinary CLI commands do not carry these admin page slugs and therefore
		// retain convergence authority below.
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			$passive_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lifecycle classification only.
			if ( in_array( $passive_page, array( 'mad4b-control-plane-connection', 'mad4b-control-plane-chatgpt' ), true ) ) return false;
		}
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;
		// The dedicated CRON_HOOK executes resume_safe_phases() directly. The
		// generic wp-cron.php request must not run init-time drift detection or
		// schedule work merely because Site Health/WordPress spawned loopback cron.
		if ( class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy', false )
			&& MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface() ) return false;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return false;
		// Third-party REST traffic is request-serving work. At init time REST_REQUEST
		// may not be defined yet, so classify both rest_route and the request URI.
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- routing observation only.
		$rest_prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
		$uri_path = '' !== $uri && function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( $uri, PHP_URL_PATH ) : '';
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| '' !== trim( $route )
			|| ( '' !== $uri_path && false !== strpos( '/' . ltrim( $uri_path, '/' ), '/' . $rest_prefix . '/' ) ) ) return false;
		if ( ! is_admin() ) return true;
		global $pagenow;
		$screen = isset( $pagenow ) ? sanitize_key( (string) $pagenow ) : '';
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lifecycle classification only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return self::admin_page_convergence_allowed( $page, $screen, $action );
	}

	private static function admin_page_convergence_allowed( $page, $screen, $action ) {
		$page = sanitize_key( (string) $page );
		$screen = sanitize_key( (string) $screen );
		$action = sanitize_key( (string) $action );
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath() ) return false;
		// Passive GET/HEAD Control Plane screens were rejected above. POST/admin-post
		// lifecycle actions and explicit Connection > Endpoints diagnostics continue.
		if ( 0 === strpos( $page, 'mad4b-control-plane' ) || 'mad4b-approval-decisions' === $page ) return true;
		if ( in_array( $screen, array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' ), true ) ) return true;
		return in_array( $action, array( 'upload-plugin', 'install-plugin', 'update-plugin', 'activate', 'deactivate', 'delete-selected' ), true );
	}

	private static function detect_lightweight_runtime_drift() {
		$current_version = defined( 'MAD4B_SCP_VERSION' ) ? trim( (string) MAD4B_SCP_VERSION ) : '';
		$stored_version = trim( (string) get_option( 'mad4b_scp_version', '' ) );
		$schema_version = class_exists( 'MAD4B_SCP_Schema' ) ? (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) : 0;
		$expected_schema = class_exists( 'MAD4B_SCP_Schema' ) ? (int) MAD4B_SCP_Schema::VERSION : 0;
		$reasons = array();
		if ( '' !== $current_version && ! hash_equals( $current_version, $stored_version ) ) $reasons[] = 'plugin_version_drift';
		if ( $expected_schema > 0 && $schema_version < $expected_schema ) $reasons[] = 'schema_version_drift';

		// The common no-drift path is options/constants only: no file read, REST
		// initialization, provider discovery, schema probe or database repair.
		if ( empty( $reasons ) ) {
			return array(
				'detected' => false,
				'reasons' => array(),
				'identity' => array(),
				'identity_complete' => false,
				'option_reads_only' => true,
				'bounded_provenance_file_read' => false,
				'filesystem_scan_performed' => false,
				'database_schema_probe_performed' => false,
			);
		}

		// Only observed drift justifies one bounded provenance-file read so the
		// queued convergence job can be exact-build fenced.
		$identity = self::current_identity();
		$identity_complete = ! empty( $identity['source_commit_sha'] )
			&& ! empty( $identity['build_fingerprint'] )
			&& ! empty( $identity['package_manifest_digest'] );
		return array(
			'detected' => true,
			'reasons' => $reasons,
			'identity' => $identity,
			'identity_complete' => $identity_complete,
			'option_reads_only' => false,
			'bounded_provenance_file_read' => true,
			'filesystem_scan_performed' => false,
			'database_schema_probe_performed' => false,
		);
	}

	private static function schedule_resume( $not_before = 0 ) {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) return false;
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) return false;
		$minimum = max( time() + 5, absint( $not_before ), self::maintenance_not_before() );
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $next && (int) $next >= $minimum ) return true;
		if ( false !== $next && function_exists( 'wp_clear_scheduled_hook' ) ) wp_clear_scheduled_hook( self::CRON_HOOK );
		$scheduled = wp_schedule_single_event( $minimum, self::CRON_HOOK, array(), true );
		if ( ! is_wp_error( $scheduled ) && false !== $scheduled ) return true;
		$next = wp_next_scheduled( self::CRON_HOOK );
		return false !== $next && (int) $next >= $minimum;
	}

	private static function is_transient_error_code( $code ) {
		return in_array( sanitize_key( (string) $code ), array(
			'mad4b_runtime_convergence_busy',
			'mad4b_runtime_convergence_lock_failed',
		), true );
	}

	/**
	 * Resolve worker disposition through the declarative auto-reconcile registry.
	 * The fallback preserves the legacy retry contract if the registry is unavailable.
	 */
	private static function worker_error_policy( $error_code ) {
		$error_code = sanitize_key( (string) $error_code );
		if ( class_exists( 'MAD4B_SCP_Auto_Reconcile_Scenarios', false )
			&& method_exists( 'MAD4B_SCP_Auto_Reconcile_Scenarios', 'classify_worker_error' ) ) {
			$policy = MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error( $error_code );
			if ( is_array( $policy ) && ! empty( $policy['decision'] ) ) return $policy;
		}
		return array(
			'contract' => 'mad4b.auto-reconcile-scenarios.v1',
			'error_code' => $error_code,
			'decision' => self::is_transient_error_code( $error_code ) ? 'DEFER' : 'REVIEW_REQUIRED',
			'policy_id' => self::is_transient_error_code( $error_code ) ? 'legacy_transient_fallback' : 'legacy_review_fallback',
			'policy_source' => 'runtime_fallback',
			'mutation_allowed' => false,
			'authority_expansion_allowed' => false,
			'zero_delta_required_for_rebind' => true,
			'authorizing' => false,
		);
	}

	public static function resume_safe_phases() {
		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! is_array( $checkpoint ) || empty( $checkpoint ) ) return;
		if ( 'staging' !== ( class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : '' ) ) ) return;
		$not_before = self::maintenance_not_before();
		if ( $not_before > time() ) {
			self::schedule_resume( $not_before );
			return;
		}
		$target = isset( $checkpoint['target_identity'] ) && is_array( $checkpoint['target_identity'] ) ? $checkpoint['target_identity'] : array();
		$current = self::current_identity();
		if ( ! self::identity_matches( $target, $current ) ) {
			$checkpoint['state'] = 'waiting_for_exact_runtime_restart';
			$checkpoint['current_identity'] = $current;
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
			return;
		}
		$result = self::run_safe_phases( 'post_update_cron', array() );
		if ( is_wp_error( $result ) ) {
			$error_code = sanitize_key( (string) $result->get_error_code() );
			$retry_count = isset( $checkpoint['transient_retry_count'] ) ? absint( $checkpoint['transient_retry_count'] ) : 0;
			$policy = self::worker_error_policy( $error_code );
			$decision = isset( $policy['decision'] ) ? (string) $policy['decision'] : 'REVIEW_REQUIRED';
			$checkpoint['last_error_code'] = $error_code;
			$checkpoint['auto_reconcile_decision'] = $decision;
			$checkpoint['auto_reconcile_policy_id'] = isset( $policy['policy_id'] ) ? sanitize_key( (string) $policy['policy_id'] ) : '';
			$checkpoint['auto_reconcile_policy_source'] = isset( $policy['policy_source'] ) ? sanitize_key( (string) $policy['policy_source'] ) : '';
			if ( 'DEFER' === $decision && $retry_count < self::MAX_TRANSIENT_RETRIES ) {
				$checkpoint['state'] = 'pending_safe_phases';
				$checkpoint['transient_retry_count'] = $retry_count + 1;
				$checkpoint['retry_policy'] = 'automatic_bounded_retry';
				$checkpoint['automatic_retry_allowed'] = true;
				$checkpoint['updated_at'] = gmdate( 'c' );
				update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
				self::schedule_resume();
				return;
			}
			$checkpoint['state'] = 'blocked';
			$checkpoint['retry_policy'] = 'explicit_resume_required';
			$checkpoint['automatic_retry_allowed'] = false;
			$checkpoint['auto_reconcile_terminal_reason'] = 'DEFER' === $decision ? 'bounded_defer_exhausted' : ( 'HARD_BLOCK' === $decision ? 'hard_block_repair_required' : 'explicit_review_required' );
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		}
	}

	private static function run_safe_phases( $source, array $plan ) {
		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) return $lock;
		$existing_checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		$changed = is_array( $existing_checkpoint ) && isset( $existing_checkpoint['changed_safe_phases'] ) && is_array( $existing_checkpoint['changed_safe_phases'] )
			? array_values( array_unique( array_map( 'sanitize_key', $existing_checkpoint['changed_safe_phases'] ) ) )
			: array();
		$schema_changed_this_slice = false;
		try {
			$lease_refresh = self::refresh_lock( $lock );
			if ( is_wp_error( $lease_refresh ) ) return $lease_refresh;
			$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status( true ) : array();
			if ( empty( $schema['ready'] ) ) {
				$lease_refresh = self::refresh_lock( $lock );
				if ( is_wp_error( $lease_refresh ) ) return $lease_refresh;
				$result = MAD4B_SCP_Schema::install_or_upgrade();
				if ( is_wp_error( $result ) ) return $result;
				$schema = MAD4B_SCP_Schema::status( true );
				if ( empty( $schema['ready'] ) ) return new WP_Error( 'mad4b_runtime_convergence_schema_readback_failed', 'Schema convergence completed without a ready physical readback.' );
				$schema_changed_this_slice = true;
				$changed[] = 'schema';
				$changed = array_values( array_unique( $changed ) );
			}
			if ( class_exists( 'MAD4B_SCP_Schema_Lifecycle' ) && method_exists( 'MAD4B_SCP_Schema_Lifecycle', 'mark_current_package_applied' ) ) {
				MAD4B_SCP_Schema_Lifecycle::mark_current_package_applied( 'runtime_convergence' );
			}
			if ( class_exists( 'MAD4B_SCP_Local_OAuth_Server', false ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'converge_store_for_lifecycle' ) ) {
				$oauth_store = MAD4B_SCP_Local_OAuth_Server::converge_store_for_lifecycle();
				if ( is_wp_error( $oauth_store ) ) return $oauth_store;
				if ( is_array( $oauth_store ) && ! empty( $oauth_store['changed'] ) ) {
					$changed[] = 'local_oauth_store';
					$changed = array_values( array_unique( $changed ) );
				}
			}
			if ( class_exists( 'MAD4B_SCP_MCP_Runtime_Recovery', false ) && MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' ) ) {
				$mcp_recovery = MAD4B_SCP_MCP_Runtime_Recovery::run( $lock );
				if ( is_wp_error( $mcp_recovery ) ) return $mcp_recovery;
				$changed[] = 'mcp_runtime_bootstrap';
			}
			$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
			$skills_pending = false;
			$skills_persisted = array();
			if ( ! empty( $profile['skills_enabled'] ) && class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ) {
				$skills = MAD4B_SCP_Skill_Runtime_Certification::current_status();
				$skills_pending = empty( $skills['ready'] );
				if ( $schema_changed_this_slice && 'post_update_cron' === sanitize_key( (string) $source ) && $skills_pending ) {
					return self::yield_safe_phases( $source, $changed, 'managed_skills' );
				}
				if ( $skills_pending ) {
					if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) MAD4B_SCP_Adapter_Registry::instance()->register_defaults();
					$seed = MAD4B_SCP_Skill_Seeder::reconcile();
					if ( is_wp_error( $seed ) ) return $seed;
					$provider = MAD4B_SCP_Skill_Provider_Discovery::reconcile();
					if ( is_wp_error( $provider ) ) return $provider;
					$skills = MAD4B_SCP_Skill_Runtime_Certification::current_status();
					if ( empty( $skills['ready'] ) ) return new WP_Error( 'mad4b_runtime_convergence_skills_readback_failed', 'Managed Skill convergence did not reach ready readback.', array( 'blockers' => isset( $skills['blockers'] ) ? $skills['blockers'] : array() ) );
					$changed[] = 'managed_skills';
					$changed = array_values( array_unique( $changed ) );
				}

				// A healthy live Skill graph still needs build-bound persisted evidence
				// after every package replacement. This is explicit lifecycle work and is
				// never performed by passive/protocol status reads.
				$observed = MAD4B_SCP_Skill_Runtime_Certification::observe( true );
				if ( ! is_array( $observed ) || empty( $observed['ready'] ) ) {
					return new WP_Error( 'mad4b_runtime_convergence_skills_persist_failed', 'Managed Skills are live-ready but current-build certification could not be persisted.' );
				}
				$skills_persisted = MAD4B_SCP_Skill_Runtime_Certification::persisted_status();
				if ( empty( $skills_persisted['ready'] ) || empty( $skills_persisted['build_identity_current'] ) ) {
					return new WP_Error( 'mad4b_runtime_convergence_skills_persisted_identity_stale', 'Managed Skills persisted certification is not bound to the exact current build.', array(
						'stale_reasons' => isset( $skills_persisted['stale_reasons'] ) ? $skills_persisted['stale_reasons'] : array(),
					) );
				}
				$continuation_status = class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ? MAD4B_SCP_Post_Update_Continuation::status() : array();
				$continuation_target = isset( $continuation_status['target_identity'] ) && is_array( $continuation_status['target_identity'] ) ? $continuation_status['target_identity'] : array();
				if ( ! empty( $continuation_status['active'] ) && ! empty( $continuation_target ) ) {
					foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
						if ( empty( $skills_persisted[ $field ] ) || empty( $continuation_target[ $field ] ) || ! hash_equals( (string) $continuation_target[ $field ], (string) $skills_persisted[ $field ] ) ) {
							return new WP_Error( 'mad4b_runtime_convergence_skills_target_identity_mismatch', 'Persisted Skill certification does not match the continuation target.', array( 'field' => $field ) );
						}
					}
				}
				$changed[] = 'managed_skills_certification';
				$changed = array_values( array_unique( $changed ) );
			}

			$continuation_result = array();
			$continuation_status = class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ? MAD4B_SCP_Post_Update_Continuation::status() : array();
			$observed_continuation = array();
			$binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status() : array();
			if ( ! empty( $binding['stored_bound'] ) && empty( $binding['match'] ) && empty( $continuation_status['active'] )
				&& ! in_array( $continuation_status['state'] ?? '', array( 'blocked', 'owner_gate', 'executing' ), true )
				&& class_exists( 'MAD4B_SCP_Self_Update' ) && method_exists( 'MAD4B_SCP_Self_Update', 'observed_release_target' )
				&& method_exists( 'MAD4B_SCP_Post_Update_Continuation', 'prepare_observed_update' ) ) {
				$trusted_target = MAD4B_SCP_Self_Update::observed_release_target();
				$observed_continuation = is_wp_error( $trusted_target ) ? $trusted_target : MAD4B_SCP_Post_Update_Continuation::prepare_observed_update( $trusted_target, $lock );
				$continuation_status = MAD4B_SCP_Post_Update_Continuation::status();
			}
			if ( ! empty( $continuation_status['active'] ) && in_array( isset( $continuation_status['state'] ) ? (string) $continuation_status['state'] : '', array( 'exact_readback_verified', 'pending_convergence' ), true ) ) {
				$continuation_result = MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind( $lock );
				if ( is_wp_error( $continuation_result ) ) return $continuation_result;
				$changed[] = 'post_update_continuation';
				$changed = array_values( array_unique( $changed ) );
			}
			$baseline = class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) && method_exists( 'MAD4B_SCP_Post_Update_Continuation', 'capture_ready_baseline' ) ? MAD4B_SCP_Post_Update_Continuation::capture_ready_baseline( $lock ) : array();

			$lease_refresh = self::refresh_lock( $lock );
			if ( is_wp_error( $lease_refresh ) ) return $lease_refresh;
			$status = self::status();
			$final_continuation = class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ? MAD4B_SCP_Post_Update_Continuation::status() : array();
			$continuation_state = isset( $final_continuation['state'] ) ? sanitize_key( (string) $final_continuation['state'] ) : '';
			$checkpoint_state = empty( $status['required_blockers'] ) ? 'completed' : 'awaiting_gated_phases';
			if ( 'blocked' === $continuation_state ) $checkpoint_state = 'authority_blocked';
			$prior_source = is_array( $existing_checkpoint ) && isset( $existing_checkpoint['source'] ) ? sanitize_key( (string) $existing_checkpoint['source'] ) : '';
			$checkpoint_source = 'self_update' === $prior_source ? 'self_update' : sanitize_key( (string) $source );
			$checkpoint = array(
				'contract' => self::CONTRACT,
				'state' => $checkpoint_state,
				'source' => $checkpoint_source,
				'last_execution_source' => sanitize_key( (string) $source ),
				'current_identity' => self::current_identity(),
				'changed_safe_phases' => $changed,
				'required_blockers' => isset( $status['required_blockers'] ) ? $status['required_blockers'] : array(),
				'continuation' => $final_continuation,
				'observed_continuation' => is_wp_error( $observed_continuation ) ? array( 'state' => 'EXTERNAL_ACTION_REQUIRED', 'error_code' => $observed_continuation->get_error_code() ) : $observed_continuation,
				'authority_baseline' => is_wp_error( $baseline ) ? array( 'state' => 'NOT_OBSERVED', 'error_code' => $baseline->get_error_code() ) : $baseline,
				'updated_at' => gmdate( 'c' ),
				'production_mutation' => false,
				'candidate_binding_mutation' => is_array( $continuation_result ) && 'completed' === ( isset( $continuation_result['state'] ) ? (string) $continuation_result['state'] : '' ),
				'provider_write_certification' => false,
			);
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
			if ( defined( 'MAD4B_SCP_VERSION' ) ) update_option( 'mad4b_scp_version', (string) MAD4B_SCP_VERSION, false );
			if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
				MAD4B_SCP_Audit::record( self::APPLY_ABILITY, array(
					'source' => $source,
					'changed_safe_phases' => $changed,
					'plan_sha256' => isset( $plan['plan_sha256'] ) ? (string) $plan['plan_sha256'] : '',
					'production_mutation_performed' => false,
					'candidate_binding_mutation_performed' => ! empty( $checkpoint['candidate_binding_mutation'] ),
				), 'ok' );
			}
			return array(
				'contract' => 'mad4b.runtime-convergence-apply.v1',
				'state' => $checkpoint['state'],
				'changed_safe_phases' => $changed,
				'readback' => $status,
				'continuation' => $final_continuation,
				'continuation_result' => $continuation_result,
				'checkpoint' => $checkpoint,
			);
		} finally {
			self::release_lock( $lock );
		}
	}

	private static function yield_safe_phases( $source, array $changed, $next_phase ) {
		$current = self::current_identity();
		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! is_array( $checkpoint ) ) $checkpoint = array();
		$checkpoint['contract'] = self::CONTRACT;
		$checkpoint['state'] = 'pending_safe_phases';
		$prior_source = isset( $checkpoint['source'] ) ? sanitize_key( (string) $checkpoint['source'] ) : '';
		$execution_source = sanitize_key( (string) $source );
		// Keep the lifecycle provenance stable across sliced post-update work.
		// Restart gating keys off source=self_update; replacing it with the Cron
		// execution source would reopen MCP between two still-pending safe phases.
		$checkpoint['source'] = 'self_update' === $prior_source ? 'self_update' : $execution_source;
		$checkpoint['last_execution_source'] = $execution_source;
		$checkpoint['target_identity'] = $current;
		$checkpoint['current_identity'] = $current;
		$checkpoint['changed_safe_phases'] = array_values( array_unique( array_map( 'sanitize_key', $changed ) ) );
		$checkpoint['next_safe_phase'] = sanitize_key( (string) $next_phase );
		$checkpoint['maintenance_sliced'] = true;
		$checkpoint['updated_at'] = gmdate( 'c' );
		$checkpoint['production_mutation'] = false;
		update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		$scheduled = self::schedule_resume( time() + 5 );
		return array(
			'contract' => 'mad4b.runtime-convergence-apply.v1',
			'state' => 'pending_safe_phases',
			'changed_safe_phases' => $checkpoint['changed_safe_phases'],
			'next_safe_phase' => $checkpoint['next_safe_phase'],
			'maintenance_sliced' => true,
			'scheduled' => (bool) $scheduled,
			'checkpoint' => $checkpoint,
		);
	}

	private static function acquire_lock() {
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' ) ) return new WP_Error( 'mad4b_runtime_convergence_lock_failed', 'Runtime maintenance lease coordinator is unavailable.' );
		$lease = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'runtime_convergence' );
		if ( ! is_wp_error( $lease ) ) return $lease;
		$code = sanitize_key( (string) $lease->get_error_code() );
		if ( 'mad4b_runtime_maintenance_busy' === $code ) return new WP_Error( 'mad4b_runtime_convergence_busy', $lease->get_error_message(), $lease->get_error_data() );
		return new WP_Error( 'mad4b_runtime_convergence_lock_failed', $lease->get_error_message(), array( 'cause' => $code ) );
	}

	private static function refresh_lock( $token ) {
		return class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' )
			? MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $token, 'runtime_convergence' )
			: new WP_Error( 'mad4b_runtime_convergence_lock_failed', 'Runtime maintenance lease coordinator is unavailable.' );
	}

	private static function release_lock( $token ) {
		if ( class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' ) ) MAD4B_SCP_Runtime_Maintenance_Lease::release( $token, 'runtime_convergence' );
	}

	private static function current_identity() {
		$identity = array( 'version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '', 'source_commit_sha' => '', 'build_fingerprint' => '', 'package_manifest_digest' => '', 'artifact_identity' => '' );
		$path = defined( 'MAD4B_SCP_DIR' ) ? rtrim( (string) MAD4B_SCP_DIR, "/\\" ) . '/MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' !== $path && is_file( $path ) && is_readable( $path ) && ! is_link( $path ) ) {
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $row ) ) {
				if ( isset( $row['control_plane_version'] ) && '' !== trim( (string) $row['control_plane_version'] ) ) $identity['version'] = trim( (string) $row['control_plane_version'] );
				foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) if ( isset( $row[ $field ] ) ) $identity[ $field ] = strtolower( trim( (string) $row[ $field ] ) );
				if ( isset( $row['artifact_identity'] ) ) $identity['artifact_identity'] = trim( (string) $row['artifact_identity'] );
			}
		}
		return self::bounded_identity( $identity );
	}

	private static function bounded_identity( array $identity ) {
		return array(
			'version' => isset( $identity['version'] ) ? substr( sanitize_text_field( (string) $identity['version'] ), 0, 64 ) : '',
			'source_commit_sha' => isset( $identity['source_commit_sha'] ) && preg_match( '/^[a-f0-9]{40}$/', strtolower( trim( (string) $identity['source_commit_sha'] ) ) ) ? strtolower( trim( (string) $identity['source_commit_sha'] ) ) : '',
			'build_fingerprint' => isset( $identity['build_fingerprint'] ) && preg_match( '/^[a-f0-9]{64}$/', strtolower( trim( (string) $identity['build_fingerprint'] ) ) ) ? strtolower( trim( (string) $identity['build_fingerprint'] ) ) : '',
			'package_manifest_digest' => isset( $identity['package_manifest_digest'] ) && preg_match( '/^[a-f0-9]{64}$/', strtolower( trim( (string) $identity['package_manifest_digest'] ) ) ) ? strtolower( trim( (string) $identity['package_manifest_digest'] ) ) : '',
			'artifact_identity' => isset( $identity['artifact_identity'] ) ? substr( sanitize_text_field( trim( (string) $identity['artifact_identity'] ) ), 0, 255 ) : '',
		);
	}

	private static function identity_matches( array $expected, array $actual ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( empty( $expected[ $field ] ) || empty( $actual[ $field ] ) || ! hash_equals( (string) $expected[ $field ], (string) $actual[ $field ] ) ) return false;
		}
		if ( ! empty( $expected['artifact_identity'] ) ) {
			if ( empty( $actual['artifact_identity'] ) || ! hash_equals( (string) $expected['artifact_identity'], (string) $actual['artifact_identity'] ) ) return false;
		}
		return true;
	}

	private static function phase( $id, $state, $required, array $dependencies, $auto_safe, array $details = array() ) {
		return array(
			'id' => sanitize_key( (string) $id ),
			'state' => sanitize_key( (string) $state ),
			'required' => (bool) $required,
			'dependencies' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $dependencies ) ) ) ),
			'auto_safe' => (bool) $auto_safe,
			'details' => $details,
		);
	}

	private static function normalize_phases( $phases ) {
		if ( ! is_array( $phases ) ) return array();
		$out = array();
		foreach ( array_slice( $phases, 0, 100, true ) as $key => $phase ) {
			$id = sanitize_key( is_array( $phase ) && ! empty( $phase['id'] ) ? (string) $phase['id'] : (string) $key );
			if ( '' === $id || ! is_array( $phase ) ) continue;
			$deps = isset( $phase['dependencies'] ) && is_array( $phase['dependencies'] ) ? $phase['dependencies'] : array();
			$out[ $id ] = self::phase(
				$id,
				isset( $phase['state'] ) ? (string) $phase['state'] : 'unknown',
				! empty( $phase['required'] ),
				$deps,
				! empty( $phase['auto_safe'] ),
				isset( $phase['details'] ) && is_array( $phase['details'] ) ? $phase['details'] : array()
			);
		}
		return $out;
	}

	private static function topological_order( array $phases ) {
		$order = array();
		$temporary = array();
		$permanent = array();
		$cycle = false;
		$invalid_dependency = false;
		$visit = function ( $id ) use ( &$visit, &$order, &$temporary, &$permanent, &$cycle, &$invalid_dependency, $phases ) {
			if ( isset( $permanent[ $id ] ) ) return;
			if ( ! isset( $phases[ $id ] ) ) { $invalid_dependency = true; return; }
			if ( isset( $temporary[ $id ] ) ) { $cycle = true; return; }
			$temporary[ $id ] = true;
			foreach ( $phases[ $id ]['dependencies'] as $dep ) $visit( $dep );
			unset( $temporary[ $id ] );
			$permanent[ $id ] = true;
			$order[] = $id;
		};
		foreach ( array_keys( $phases ) as $id ) $visit( $id );
		if ( $cycle ) return new WP_Error( 'mad4b_runtime_convergence_phase_cycle', 'Runtime convergence phase graph contains a dependency cycle.' );
		if ( $invalid_dependency ) return new WP_Error( 'mad4b_runtime_convergence_phase_dependency_missing', 'Runtime convergence phase graph references a missing dependency.' );
		return array_values( array_unique( $order ) );
	}

	private static function action( $phase, $action, $ability, $auto_safe, $human_gate, array $extra = array() ) {
		return array_merge( array(
			'phase' => sanitize_key( (string) $phase ),
			'action' => sanitize_key( (string) $action ),
			'ability' => (string) $ability,
			'auto_safe' => (bool) $auto_safe,
			'human_gate' => (bool) $human_gate,
		), $extra );
	}

	private static function bounded_performance( $performance ) {
		if ( ! is_array( $performance ) ) return array();
		$indexes = isset( $performance['indexes'] ) && is_array( $performance['indexes'] ) ? $performance['indexes'] : array();
		$missing = array();
		foreach ( $indexes as $key => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['present'] ) ) continue;
			$missing[] = sanitize_key( (string) $key );
			if ( count( $missing ) >= 32 ) break;
		}
		$job = isset( $performance['maintenance_job'] ) && is_array( $performance['maintenance_job'] ) ? $performance['maintenance_job'] : array();
		$health = isset( $performance['maintenance_job_health'] ) && is_array( $performance['maintenance_job_health'] ) ? $performance['maintenance_job_health'] : array();
		return array(
			'ready' => ! empty( $performance['ready'] ),
			'index_version' => isset( $performance['index_version'] ) ? (int) $performance['index_version'] : 0,
			'missing_index_count' => count( $missing ),
			'missing_indexes' => $missing,
			'maintenance_job_status' => isset( $job['status'] ) ? sanitize_key( (string) $job['status'] ) : '',
			'reconciliation_required' => ! empty( $health['reconciliation_required'] ),
			'maintenance_executor_ready' => ! empty( $performance['maintenance_executor_ready'] ),
			'wp_cron_disabled' => ! empty( $performance['wp_cron_disabled'] ),
			'automatic_apply' => false,
			'blind_retry_allowed' => false,
		);
	}

	private static function digest( $value ) {
		$encoded = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}

MAD4B_SCP_Runtime_Convergence::boot();
