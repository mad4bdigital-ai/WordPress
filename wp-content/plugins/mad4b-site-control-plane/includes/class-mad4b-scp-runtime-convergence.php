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
	const LOCK_OPTION = 'mad4b_scp_runtime_convergence_lock_v1';
	const CRON_HOOK = 'mad4b_scp_runtime_convergence_resume';
	const LOCK_TTL = 300;

	private static $booted = false;
	private static $abilities_registered = false;

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
		$environment = isset( $profile['environment'] ) ? sanitize_key( (string) $profile['environment'] ) : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
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

		$update_target = isset( $update['native_wordpress_update']['target'] ) && is_array( $update['native_wordpress_update']['target'] ) ? $update['native_wordpress_update']['target'] : array();
		$target_sha = isset( $update_target['source_commit_sha'] ) ? strtolower( trim( (string) $update_target['source_commit_sha'] ) ) : '';
		$current_sha = isset( $identity['source_commit_sha'] ) ? strtolower( trim( (string) $identity['source_commit_sha'] ) ) : '';
		$deployment_state = '' === $target_sha ? 'unknown' : ( '' !== $current_sha && hash_equals( $target_sha, $current_sha ) ? 'ready' : 'pending' );

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
			) ),
			'schema' => self::phase( 'schema', ! empty( $schema['ready'] ) ? 'ready' : 'pending', true, array( 'deployment' ), true, array(
				'expected_version' => isset( $schema['expected_version'] ) ? (int) $schema['expected_version'] : 0,
				'installed_version' => isset( $schema['installed_version'] ) ? (int) $schema['installed_version'] : 0,
				'integrity_token_valid' => ! empty( $schema['integrity_token_valid'] ),
				'physical_ready' => ! empty( $schema['physical_integrity']['ready'] ),
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
		return array(
			'contract' => self::CONTRACT,
			'state' => empty( $required_blockers ) ? 'ready' : 'convergence_required',
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
		if ( isset( $phases['managed_skills'] ) && 'ready' !== $phases['managed_skills']['state'] ) {
			$actions[] = self::action( 'managed_skills', 'reconcile_managed_skills', self::APPLY_ABILITY, true, false );
		}
		if ( isset( $phases['authority_binding'] ) && 'ready' !== $phases['authority_binding']['state'] ) {
			$gated[] = self::action( 'authority_binding', 'owner_rebind_exact_candidate', 'mad4b/full-staging-authority-handshake', false, true );
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
			'candidate_binding_auto_refresh' => false,
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

	public static function mark_post_update_pending( array $target, $channel = '', $plan_sha256 = '' ) {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'staging' !== $environment ) return array( 'scheduled' => false, 'state' => 'ignored_non_staging' );
		$checkpoint = array(
			'contract' => self::CONTRACT,
			'state' => 'pending_restart',
			'source' => 'self_update',
			'channel' => sanitize_key( (string) $channel ),
			'target_identity' => self::bounded_identity( $target ),
			'update_plan_sha256' => strtolower( trim( (string) $plan_sha256 ) ),
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'production_mutation' => false,
		);
		update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		$stored = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['target_identity'] ) || ! self::identity_matches( $checkpoint['target_identity'], $stored['target_identity'] ) ) {
			return array( 'scheduled' => false, 'state' => 'checkpoint_persist_failed', 'target_identity' => $checkpoint['target_identity'] );
		}
		$scheduled = self::schedule_resume();
		if ( ! $scheduled ) {
			$checkpoint['state'] = 'pending_manual_resume';
			$checkpoint['resume_blocker'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'wp_cron_disabled' : 'wp_cron_unavailable';
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		}
		return array(
			'scheduled' => (bool) $scheduled,
			'state' => $scheduled ? 'pending_restart' : 'pending_manual_resume',
			'resume_blocker' => $scheduled ? '' : $checkpoint['resume_blocker'],
			'target_identity' => $checkpoint['target_identity'],
		);
	}

	public static function maybe_schedule_pending() {
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : '';
		if ( 'staging' !== $environment ) return;
		// Third-party wp-admin pages are pure request-serving surfaces. They must
		// never inspect build provenance, mutate checkpoints, schedule convergence,
		// or revive failed lifecycle jobs. Governed update/plugin lifecycle, MAD4B
		// operator pages, Cron/CLI and ordinary front-end traffic remain triggers.
		if ( ! self::convergence_trigger_allowed() ) return;

		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		$state = is_array( $checkpoint ) && isset( $checkpoint['state'] ) ? sanitize_key( (string) $checkpoint['state'] ) : '';

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

		if ( 'blocked' === $state ) return;
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
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return true;
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

	private static function schedule_resume() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) return false;
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) return false;
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) return true;
		$scheduled = wp_schedule_single_event( time() + 5, self::CRON_HOOK, array(), true );
		return ! is_wp_error( $scheduled ) && false !== $scheduled;
	}

	public static function resume_safe_phases() {
		$checkpoint = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! is_array( $checkpoint ) || empty( $checkpoint ) ) return;
		if ( 'staging' !== ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : '' ) ) return;
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
			$checkpoint['state'] = 'blocked';
			$checkpoint['last_error_code'] = $result->get_error_code();
			$checkpoint['retry_policy'] = 'explicit_resume_required';
			$checkpoint['automatic_retry_allowed'] = false;
			$checkpoint['updated_at'] = gmdate( 'c' );
			update_option( self::CHECKPOINT_OPTION, $checkpoint, false );
		}
	}

	private static function run_safe_phases( $source, array $plan ) {
		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) return $lock;
		$changed = array();
		try {
			$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status( true ) : array();
			if ( empty( $schema['ready'] ) ) {
				$result = MAD4B_SCP_Schema::install_or_upgrade();
				if ( is_wp_error( $result ) ) return $result;
				$schema = MAD4B_SCP_Schema::status( true );
				if ( empty( $schema['ready'] ) ) return new WP_Error( 'mad4b_runtime_convergence_schema_readback_failed', 'Schema convergence completed without a ready physical readback.' );
				$changed[] = 'schema';
			}
			$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
			if ( ! empty( $profile['skills_enabled'] ) && class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ) {
				$skills = MAD4B_SCP_Skill_Runtime_Certification::current_status();
				if ( empty( $skills['ready'] ) ) {
					if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) MAD4B_SCP_Adapter_Registry::instance()->register_defaults();
					$seed = MAD4B_SCP_Skill_Seeder::reconcile();
					if ( is_wp_error( $seed ) ) return $seed;
					$provider = MAD4B_SCP_Skill_Provider_Discovery::reconcile();
					if ( is_wp_error( $provider ) ) return $provider;
					$skills = MAD4B_SCP_Skill_Runtime_Certification::current_status();
					if ( empty( $skills['ready'] ) ) return new WP_Error( 'mad4b_runtime_convergence_skills_readback_failed', 'Managed Skill convergence did not reach ready readback.', array( 'blockers' => isset( $skills['blockers'] ) ? $skills['blockers'] : array() ) );
					$changed[] = 'managed_skills';
				}
			}
			$status = self::status();
			$checkpoint = array(
				'contract' => self::CONTRACT,
				'state' => empty( $status['required_blockers'] ) ? 'completed' : 'awaiting_gated_phases',
				'source' => sanitize_key( (string) $source ),
				'current_identity' => self::current_identity(),
				'changed_safe_phases' => $changed,
				'required_blockers' => isset( $status['required_blockers'] ) ? $status['required_blockers'] : array(),
				'updated_at' => gmdate( 'c' ),
				'production_mutation' => false,
				'candidate_binding_mutation' => false,
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
					'candidate_binding_mutation_performed' => false,
				), 'ok' );
			}
			return array(
				'contract' => 'mad4b.runtime-convergence-apply.v1',
				'state' => $checkpoint['state'],
				'changed_safe_phases' => $changed,
				'readback' => $status,
				'checkpoint' => $checkpoint,
			);
		} finally {
			self::release_lock( $lock );
		}
	}

	private static function acquire_lock() {
		$now = time();
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && ! empty( $current['token'] ) && isset( $current['expires_at'] ) && (int) $current['expires_at'] > $now ) {
			return new WP_Error( 'mad4b_runtime_convergence_busy', 'Runtime convergence already has an active lease.' );
		}
		if ( is_array( $current ) && ! empty( $current ) ) delete_option( self::LOCK_OPTION );
		$token = strtolower( wp_generate_uuid4() );
		$lock = array( 'token' => $token, 'expires_at' => $now + self::LOCK_TTL, 'acquired_at' => gmdate( 'c' ) );
		if ( ! add_option( self::LOCK_OPTION, $lock, '', false ) ) return new WP_Error( 'mad4b_runtime_convergence_lock_failed', 'Unable to acquire the runtime convergence lease.' );
		return $token;
	}

	private static function release_lock( $token ) {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) ) delete_option( self::LOCK_OPTION );
	}

	private static function current_identity() {
		$identity = array( 'version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '', 'source_commit_sha' => '', 'build_fingerprint' => '', 'package_manifest_digest' => '' );
		$path = defined( 'MAD4B_SCP_DIR' ) ? rtrim( (string) MAD4B_SCP_DIR, "/\\" ) . '/MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' !== $path && is_file( $path ) && is_readable( $path ) && ! is_link( $path ) ) {
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $row ) ) {
				if ( isset( $row['control_plane_version'] ) && '' !== trim( (string) $row['control_plane_version'] ) ) $identity['version'] = trim( (string) $row['control_plane_version'] );
				foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) if ( isset( $row[ $field ] ) ) $identity[ $field ] = strtolower( trim( (string) $row[ $field ] ) );
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
		);
	}

	private static function identity_matches( array $expected, array $actual ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( empty( $expected[ $field ] ) || empty( $actual[ $field ] ) || ! hash_equals( (string) $expected[ $field ], (string) $actual[ $field ] ) ) return false;
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
