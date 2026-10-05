<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Adaptive, capability-scoped runtime convergence.
 *
 * This coordinator never creates Production authority and never auto-promotes
 * provider writes. It observes exact package/provider drift, schedules existing
 * idempotent convergence work, keeps structurally compatible reads available,
 * and reduces write drift to the narrowest affected capability. Authority
 * expansion, behavioral probes and high-risk promotion remain explicit gates.
 */
final class MAD4B_SCP_Adaptive_Runtime_Convergence {
	const CONTRACT = 'mad4b.adaptive-runtime-convergence.v1';
	const OPTION = 'mad4b_scp_adaptive_runtime_convergence_v1';
	const CRON_HOOK = 'mad4b_scp_adaptive_runtime_convergence';
	const STATUS_ABILITY = 'mad4b/adaptive-runtime-convergence-status';
	const OBSERVER_VERSION = 1;

	private static $booted = false;
	private static $registered = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		if ( class_exists( 'MAD4B_SCP_Runtime_Convergence' ) ) MAD4B_SCP_Runtime_Convergence::boot();

		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 30, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_lifecycle' ), 30, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_lifecycle' ), 30, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'reconcile' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 45 );
	}

	public static function register_ability() {
		if ( self::$registered || ! function_exists( 'wp_register_ability' ) ) return;
		self::$registered = true;
		wp_register_ability(
			self::STATUS_ABILITY,
			array(
				'label' => 'Adaptive Runtime Convergence Status',
				'description' => 'Read capability-scoped runtime drift and automatic convergence state. Read-compatible drift remains available while uncertified writes fail closed.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function on_upgrader_complete( $upgrader, $hook_extra ) {
		unset( $upgrader );
		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();
		if ( 'plugin' !== sanitize_key( (string) ( $hook_extra['type'] ?? '' ) ) ) return;
		$plugins = array();
		if ( ! empty( $hook_extra['plugin'] ) ) $plugins[] = sanitize_text_field( (string) $hook_extra['plugin'] );
		foreach ( (array) ( $hook_extra['plugins'] ?? array() ) as $plugin ) $plugins[] = sanitize_text_field( (string) $plugin );
		self::queue( 'wordpress_plugin_update', array_values( array_unique( array_filter( $plugins ) ) ) );
	}

	public static function on_plugin_lifecycle( $plugin, $network_wide = false ) {
		unset( $network_wide );
		self::queue( 'wordpress_plugin_lifecycle', array( sanitize_text_field( (string) $plugin ) ) );
	}

	public static function maybe_schedule() {
		if ( ! self::staging_allowed() || self::zero_touch_request() ) return;

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! empty( $stored['pending'] ) ) {
			self::schedule();
			return;
		}

		$current_version = defined( 'MAD4B_SCP_VERSION' ) ? trim( (string) MAD4B_SCP_VERSION ) : '';
		$observed_version = isset( $stored['control_plane_version'] ) ? trim( (string) $stored['control_plane_version'] ) : '';
		$legacy_version = trim( (string) get_option( 'mad4b_scp_version', '' ) );
		if ( '' !== $current_version && ( ( '' !== $observed_version && ! hash_equals( $current_version, $observed_version ) ) || ( '' === $observed_version && '' !== $legacy_version && ! hash_equals( $current_version, $legacy_version ) ) ) ) {
			self::queue( 'control_plane_identity_changed', array( 'mad4b-site-control-plane/mad4b-site-control-plane.php' ) );
		}
	}

	public static function status( $input = array() ) {
		unset( $input );
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$current = self::current_identity();
		$stored_identity = isset( $stored['current_identity'] ) && is_array( $stored['current_identity'] ) ? $stored['current_identity'] : array();

		return array(
			'contract' => self::CONTRACT,
			'state' => isset( $stored['state'] ) ? sanitize_key( (string) $stored['state'] ) : 'unobserved',
			'pending' => ! empty( $stored['pending'] ),
			'current_identity' => $current,
			'last_observed_identity' => $stored_identity,
			'identity_current' => ! empty( $stored_identity ) && self::identity_matches( $stored_identity, $current ),
			'provider_generation_fingerprint' => isset( $stored['provider_generation_fingerprint'] ) ? (string) $stored['provider_generation_fingerprint'] : '',
			'provider_drift' => isset( $stored['provider_drift'] ) && is_array( $stored['provider_drift'] ) ? $stored['provider_drift'] : array(),
			'authority' => isset( $stored['authority'] ) && is_array( $stored['authority'] ) ? $stored['authority'] : array(),
			'external_evidence_refresh' => isset( $stored['external_evidence_refresh'] ) ? (string) $stored['external_evidence_refresh'] : 'on_next_authenticated_initialize_tools_list',
			'auto_safe_actions' => isset( $stored['auto_safe_actions'] ) && is_array( $stored['auto_safe_actions'] ) ? $stored['auto_safe_actions'] : array(),
			'gated_actions' => isset( $stored['gated_actions'] ) && is_array( $stored['gated_actions'] ) ? $stored['gated_actions'] : array(),
			'blockers' => isset( $stored['blockers'] ) && is_array( $stored['blockers'] ) ? $stored['blockers'] : array(),
			'advisories' => isset( $stored['advisories'] ) && is_array( $stored['advisories'] ) ? $stored['advisories'] : array(),
			'last_reason' => isset( $stored['last_reason'] ) ? sanitize_key( (string) $stored['last_reason'] ) : '',
			'observed_at' => isset( $stored['observed_at'] ) ? (string) $stored['observed_at'] : '',
			'policy' => array(
				'version_drift_alone_is_breaking' => false,
				'read_compatible_drift_continues' => true,
				'uncertified_writes_fail_closed_per_capability' => true,
				'authority_expansion_auto_granted' => false,
				'high_risk_write_auto_promoted' => false,
				'production_mutation_allowed' => false,
			),
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function reconcile() {
		if ( ! self::staging_allowed() ) return array( 'state' => 'observe_only_non_staging', 'production_mutation' => false );

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$current = self::current_identity();
		if ( empty( $current['source_commit_sha'] ) || empty( $current['build_fingerprint'] ) || empty( $current['package_manifest_digest'] ) ) {
			return self::persist_result( $stored, array(
				'state' => 'blocked',
				'pending' => false,
				'blockers' => array( 'exact_runtime_identity_unavailable' ),
				'current_identity' => $current,
			) );
		}

		$auto = array();
		$gated = array();
		$blockers = array();
		$advisories = array();

		$previous_identity = isset( $stored['current_identity'] ) && is_array( $stored['current_identity'] ) ? $stored['current_identity'] : array();
		$identity_changed = empty( $previous_identity ) || ! self::identity_matches( $previous_identity, $current );
		if ( $identity_changed && class_exists( 'MAD4B_SCP_Runtime_Convergence' ) ) {
			$scheduled = MAD4B_SCP_Runtime_Convergence::mark_activation_pending();
			$auto[] = array(
				'action' => 'schedule_safe_runtime_convergence',
				'state' => is_array( $scheduled ) && ! empty( $scheduled['scheduled'] ) ? 'scheduled' : 'observed',
				'details' => is_array( $scheduled ) ? $scheduled : array(),
			);
		}

		if ( class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) {
			MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
		}
		$inventory = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' )
			? MAD4B_SCP_Provider_Compatibility_Certification::inventory()
			: array();
		$provider_drift = self::classify_provider_drift( $inventory );
		if ( ! empty( $provider_drift['breaking_read_contracts'] ) ) {
			$blockers[] = 'provider_breaking_read_contract';
			$gated[] = array( 'action' => 'provider_contract_repair', 'items' => $provider_drift['breaking_read_contracts'] );
		}
		if ( ! empty( $provider_drift['behavioral_recertification_candidates'] ) ) {
			$gated[] = array(
				'action' => 'behavioral_recertification',
				'items' => $provider_drift['behavioral_recertification_candidates'],
				'why_gated' => 'reversible_probe_requires_exact_target_and_one_time_approval',
			);
		}
		if ( ! empty( $provider_drift['owner_gated_write_candidates'] ) ) {
			$gated[] = array(
				'action' => 'owner_review_provider_write',
				'items' => $provider_drift['owner_gated_write_candidates'],
				'why_gated' => 'artifact_authority_or_high_risk_promotion',
			);
		}
		if ( ! empty( $provider_drift['read_compatible_drift'] ) ) {
			$advisories[] = 'provider_version_drift_read_compatible';
			$auto[] = array( 'action' => 'continue_structurally_compatible_reads', 'items' => $provider_drift['read_compatible_drift'] );
		}

		$authority = self::authority_drift();
		if ( ! empty( $authority['candidate_binding_match'] ) && ! empty( $authority['current_grant_snapshot_ready'] ) ) {
			$auto[] = array( 'action' => 'authority_already_current', 'state' => 'ready' );
		} elseif ( ! empty( $authority['authority_expansion_required'] ) ) {
			$gated[] = array(
				'action' => 'owner_review_authority_expansion',
				'missing_write_grants' => isset( $authority['missing_write_grants'] ) ? $authority['missing_write_grants'] : array(),
				'why_gated' => 'new_write_authority_is_never_created_implicitly',
			);
			$advisories[] = 'authority_expansion_waiting_for_owner';
		} elseif ( ! empty( $authority['candidate_rebind_required'] ) ) {
			$gated[] = array(
				'action' => 'zero_delta_candidate_rebind',
				'why_gated' => 'manual_replacement_without_pre_update_continuation_permit',
			);
			$advisories[] = 'candidate_binding_recovery_required';
		}

		$state = empty( $blockers ) ? ( empty( $gated ) ? 'self_converging' : 'healthy_with_gated_actions' ) : 'capability_scoped_degraded';
		$result = array(
			'state' => $state,
			'pending' => false,
			'current_identity' => $current,
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'provider_generation_fingerprint' => isset( $provider_drift['generation_fingerprint'] ) ? $provider_drift['generation_fingerprint'] : '',
			'provider_drift' => $provider_drift,
			'authority' => $authority,
			'external_evidence_refresh' => $identity_changed ? 'on_next_authenticated_initialize_tools_list' : 'current_or_session_bound',
			'auto_safe_actions' => $auto,
			'gated_actions' => $gated,
			'blockers' => array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) ),
			'advisories' => array_values( array_unique( array_map( 'sanitize_key', $advisories ) ) ),
			'observed_at' => gmdate( 'c' ),
			'last_reason' => isset( $stored['last_reason'] ) ? sanitize_key( (string) $stored['last_reason'] ) : 'scheduled_reconciliation',
			'production_mutation' => false,
		);
		return self::persist_result( $stored, $result );
	}

	private static function authority_drift() {
		$binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();
		$plan = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'reconciliation_plan' )
			? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan()
			: array();

		$missing = array();
		if ( is_array( $plan ) ) {
			foreach ( array( 'exact_missing_abilities', 'missing_abilities', 'exact_grants_missing' ) as $key ) {
				if ( isset( $plan[ $key ] ) && is_array( $plan[ $key ] ) ) {
					$missing = array_values( array_unique( array_map( 'strval', $plan[ $key ] ) ) );
					break;
				}
			}
			if ( empty( $missing ) && ! empty( $plan['exact_grants_missing_count'] ) ) $missing[] = '__count__:' . absint( $plan['exact_grants_missing_count'] );
		}

		return array(
			'candidate_binding_required' => ! empty( $binding['required'] ),
			'candidate_binding_match' => ! empty( $binding['match'] ),
			'candidate_rebind_required' => ! empty( $binding['required'] ) && empty( $binding['match'] ) && empty( $missing ),
			'current_grant_snapshot_ready' => is_array( $plan ) && ! empty( $plan['current_ready'] ),
			'authority_expansion_required' => ! empty( $missing ),
			'missing_write_grants' => $missing,
			'write_tool_count' => is_array( $plan ) && isset( $plan['write_tool_count'] ) ? (int) $plan['write_tool_count'] : 0,
			'write_inventory_fingerprint' => is_array( $plan ) && isset( $plan['write_inventory_fingerprint'] ) ? (string) $plan['write_inventory_fingerprint'] : '',
			'production_authority_created' => false,
		);
	}

	private static function classify_provider_drift( $inventory ) {
		$providers = is_array( $inventory ) && isset( $inventory['providers'] ) && is_array( $inventory['providers'] ) ? $inventory['providers'] : array();
		$read_compatible = array();
		$breaking_reads = array();
		$behavioral = array();
		$owner_gated = array();
		$fingerprint_rows = array();

		foreach ( $providers as $provider_id => $assessment ) {
			if ( ! is_array( $assessment ) ) continue;
			$provider_id = sanitize_key( (string) $provider_id );
			$compatibility = sanitize_key( (string) ( $assessment['compatibility_state'] ?? 'unknown' ) );
			$artifact = isset( $assessment['artifact'] ) && is_array( $assessment['artifact'] ) ? $assessment['artifact'] : array();
			$fingerprint_rows[ $provider_id ] = array(
				'compatibility_state' => $compatibility,
				'structural_fingerprint' => isset( $assessment['structural_fingerprint'] ) ? (string) $assessment['structural_fingerprint'] : '',
				'artifact_fingerprint' => isset( $artifact['runtime_artifact_fingerprint'] ) ? (string) $artifact['runtime_artifact_fingerprint'] : '',
			);

			$provider_read_blocked = false;
			$provider_read_seen = false;
			foreach ( (array) ( $assessment['capabilities'] ?? array() ) as $capability_id => $capability ) {
				if ( ! is_array( $capability ) || empty( $capability['surface_exposed'] ) ) continue;
				$risk = sanitize_key( (string) ( $capability['risk'] ?? 'unknown' ) );
				$entry = array(
					'provider_id' => $provider_id,
					'capability_id' => sanitize_key( (string) $capability_id ),
					'risk' => $risk,
					'abilities' => array_values( array_map( 'strval', (array) ( $capability['mounted_abilities'] ?? array() ) ) ),
					'artifact_fingerprint' => isset( $artifact['runtime_artifact_fingerprint'] ) ? (string) $artifact['runtime_artifact_fingerprint'] : '',
					'capability_contract_digest' => isset( $capability['capability_contract_digest'] ) ? (string) $capability['capability_contract_digest'] : '',
				);
				if ( 'read' === $risk ) {
					$provider_read_seen = true;
					if ( empty( $capability['read_eligible'] ) ) {
						$provider_read_blocked = true;
						$breaking_reads[] = $entry;
					}
					continue;
				}
				if ( ! empty( $capability['write_eligible'] ) ) continue;
				if ( 'bounded_write' === $risk && ! empty( $capability['structural_compatible'] ) && ! empty( $capability['reversible'] ) && ! empty( $capability['artifact_authority_bound'] ) ) {
					$behavioral[] = $entry;
				} else {
					$entry['reason'] = empty( $capability['artifact_authority_bound'] ) ? 'artifact_authority_required' : ( 'high_risk_write' === $risk ? 'high_risk_owner_promotion_required' : 'write_contract_not_auto_recertifiable' );
					$owner_gated[] = $entry;
				}
			}

			if ( ! $provider_read_blocked && ( $provider_read_seen || in_array( $compatibility, array( 'compatible_unattested', 'partially_compatible', 'certified' ), true ) ) && 'certified' !== $compatibility ) {
				$read_compatible[] = array(
					'provider_id' => $provider_id,
					'compatibility_state' => $compatibility,
					'artifact_fingerprint' => isset( $artifact['runtime_artifact_fingerprint'] ) ? (string) $artifact['runtime_artifact_fingerprint'] : '',
				);
			}
		}

		ksort( $fingerprint_rows, SORT_STRING );
		return array(
			'contract' => 'mad4b.capability-scoped-provider-drift.v1',
			'generation_fingerprint' => hash( 'sha256', wp_json_encode( $fingerprint_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'read_compatible_drift' => $read_compatible,
			'breaking_read_contracts' => $breaking_reads,
			'behavioral_recertification_candidates' => $behavioral,
			'owner_gated_write_candidates' => $owner_gated,
			'global_degrade_on_version_only' => false,
			'write_default' => 'fail_closed_per_capability',
		);
	}

	private static function queue( $reason, array $subjects = array() ) {
		if ( ! self::staging_allowed() ) return;
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored['contract'] = self::CONTRACT;
		$stored['observer_version'] = self::OBSERVER_VERSION;
		$stored['pending'] = true;
		$stored['state'] = 'queued';
		$stored['last_reason'] = sanitize_key( (string) $reason );
		$stored['subjects'] = array_slice( array_values( array_unique( array_filter( array_map( 'strval', $subjects ) ) ) ), 0, 50 );
		$stored['queued_at'] = gmdate( 'c' );
		$stored['production_mutation'] = false;
		update_option( self::OPTION, $stored, false );
		self::schedule();
	}

	private static function schedule() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) return false;
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) return false;
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $next ) return true;
		$result = wp_schedule_single_event( time() + 5, self::CRON_HOOK, array(), true );
		return ! is_wp_error( $result ) && false !== $result;
	}

	private static function persist_result( array $previous, array $result ) {
		$next = array_merge( $previous, $result );
		$next['contract'] = self::CONTRACT;
		$next['observer_version'] = self::OBSERVER_VERSION;
		$next['production_mutation'] = false;
		update_option( self::OPTION, $next, false );
		return array_merge( $next, array(
			'mutation_performed' => true,
			'mutation_scope' => 'staging_adaptive_convergence_checkpoint_only',
			'production_mutation_performed' => false,
			'authority_created' => false,
			'provider_write_certification_performed' => false,
		) );
	}

	private static function current_identity() {
		if ( class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) {
			$build = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			if ( is_array( $build ) ) {
				return array(
					'source_commit_sha' => strtolower( (string) ( $build['source_commit_sha'] ?? '' ) ),
					'build_fingerprint' => strtolower( (string) ( $build['build_fingerprint'] ?? '' ) ),
					'package_manifest_digest' => strtolower( (string) ( $build['package_manifest_digest'] ?? '' ) ),
					'artifact_identity' => (string) ( $build['artifact_identity'] ?? '' ),
				);
			}
		}
		return array();
	}

	private static function identity_matches( array $left, array $right ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			if ( empty( $left[ $field ] ) || empty( $right[ $field ] ) || ! hash_equals( (string) $left[ $field ], (string) $right[ $field ] ) ) return false;
		}
		return true;
	}

	private static function staging_allowed() {
		$environment = class_exists( 'MAD4B_SCP_Environment' )
			? sanitize_key( (string) MAD4B_SCP_Environment::effective() )
			: ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : '' );
		return 'staging' === $environment;
	}

	private static function zero_touch_request() {
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return true;
		if ( class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy', false ) && MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface() ) return true;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return true;
		return false;
	}
}
