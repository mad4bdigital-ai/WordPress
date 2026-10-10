<?php
/**
 * Staging Autopilot: read-only MCP assistant handoff and administrator opt-in.
 *
 * Diagnostics never create grants, edit config, download packages, or promote
 * a deployment. Only the nonce-protected Site Profile administrator route may
 * request a guarded, local WordPress Staging bootstrap correction.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Staging_Autopilot {
	const CONTRACT = 'mad4b.staging-autopilot.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 9 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		$name = 'mad4b/site-autopilot-status';
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability( $name, array(
			'label' => 'MAD4B Staging Autopilot Status and Assistant Handoff',
			'description' => 'Read bounded next actions for Site Profile, WordPress environment and host/authority prerequisites; never execute a mutation.',
			'category' => 'mad4b-governance',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
			'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	/**
	 * Pure policy reducer: no database, filesystem, privilege or host calls.
	 * Caller may ask for a separate bounded local file preflight afterwards.
	 */
	public static function decision( array $site ) {
		$desired = (string) ( $site['configured_environment'] ?? '' );
		$wordpress = (string) ( $site['wordpress_environment'] ?? 'unknown' );
		$mode = (string) ( $site['environment_sync_mode'] ?? 'profile_only' );
		$valid = ! empty( $site['configured'] ) && ! empty( $site['authority_ready'] )
			&& ! empty( $site['origin_match'] ) && ! empty( $site['environment_match'] )
			&& ! empty( $site['profile_environment_authoritative'] )
			&& empty( $site['mutation_pending_audit'] );
		$state = 'blocked_profile_identity';
		$next = 'inspect_site_profile';
		$actor = 'site_administrator';
		if ( 'staging' !== $desired ) {
			$state = 'observe_only_not_staging';
			$next = 'none';
			$actor = 'none';
		} elseif ( ! $valid ) {
			$state = 'blocked_profile_identity';
			$next = 'review_exact_site_profile';
		} elseif ( 'staging' === $wordpress ) {
			$state = 'already_aligned';
			$next = 'none';
			$actor = 'none';
		} elseif ( ! empty( $site['wordpress_environment_explicit'] ) ) {
			$state = 'blocked_explicit_host_environment';
			$next = 'host_operator_reconcile_explicit_environment';
			$actor = 'host_operator';
		} elseif ( 'production' !== $wordpress || empty( $site['implicit_nonproduction_override_confirmed'] ) ) {
			$state = 'blocked_unattested_or_unrecognized_environment';
			$next = 'review_exact_nonproduction_attestation';
		} elseif ( 'profile_only' === $mode ) {
			$state = 'admin_opt_in_required';
			$next = 'enable_staging_autopilot';
		} elseif ( 'host_managed' === $mode ) {
			$state = 'admin_reconcile_available';
			$next = 'retry_staging_autopilot';
		} else {
			$state = 'blocked_unknown_sync_mode';
			$next = 'review_exact_site_profile';
		}
		$admin_allowed = in_array( $state, array( 'admin_opt_in_required', 'admin_reconcile_available' ), true );
		return array(
			'contract' => self::CONTRACT,
			'state' => $state,
			'desired_wordpress_environment' => 'staging' === $desired ? 'staging' : '',
			'observed_wordpress_environment' => $wordpress,
			'profile_mode' => $mode,
			'next_action_id' => $next,
			'responsible_actor' => $actor,
			'admin_autopilot_action_allowed' => $admin_allowed,
			'admin_action' => array(
				'action' => 'mad4b_site_profile_enable_staging_autopilot',
				'method' => 'POST',
				'requires_wordpress_admin_session' => true,
				'requires_nonce' => true,
				'requires_exact_profile_revision_and_digest' => true,
				'remote_mcp_execution_allowed' => false,
			),
			'host_deployment_binding_configured' => ! empty( $site['deployment_binding_configured'] ),
			'host_clone_protection_ready' => ! empty( $site['same_origin_clone_protection'] ),
			'next_staging_write_authority_check' => 'mad4b/staging-write-authority-convergence-handshake',
			'developer_host_prerequisite_check' => 'mad4b/full-staging-authority-handshake',
			// Stable, ordered assistant workflow. Each lane is an independent
			// evidence/approval boundary; a read MUST NOT become an apply.
			'assistant_workflow' => array(
				array(
					'step_id' => 'site_profile_environment',
					'state' => $state,
					'actor' => $actor,
					'next_action_id' => $next,
					'local_admin_consent_required' => $admin_allowed,
					'remote_write_allowed' => false,
				),
				array(
					'step_id' => 'host_deployment_binding',
					'state' => ! empty( $site['same_origin_clone_protection'] )
						? 'exact_host_binding_present' : 'requires_host_operator_review',
					'actor' => 'host_operator',
					'next_action_id' => ! empty( $site['same_origin_clone_protection'] )
						? 'none' : 'provision_unique_host_deployment_binding',
					'remote_write_allowed' => false,
				),
				array(
					'step_id' => 'staging_write_authority',
					'state' => 'readiness_not_evaluated_in_autopilot',
					'actor' => 'site_owner',
					'read_ability' => 'mad4b/staging-write-authority-convergence-handshake',
					'next_action_id' => 'review_exact_write_grants_and_candidate_binding',
					'explicit_owner_approval_required' => true,
					'remote_write_allowed' => false,
				),
				array(
					'step_id' => 'developer_host_prerequisites',
					'state' => 'readiness_not_evaluated_in_autopilot',
					'actor' => 'host_operator',
					'read_ability' => 'mad4b/full-staging-authority-handshake',
					'next_action_id' => 'review_resource_and_network_isolation',
					'host_prerequisites_auto_install' => false,
					'remote_write_allowed' => false,
				),
				array(
					'step_id' => 'release_and_staging_acceptance',
					'state' => 'native_live_acceptance_not_evaluated',
					'actor' => 'staging_operator',
					'next_action_id' => 'run_fresh_staging_acceptance_for_exact_head',
					'production_promotion_allowed' => false,
					'remote_write_allowed' => false,
				),
			),
			'write_grants_auto_apply' => false,
			'host_prerequisites_auto_install' => false,
			'plugin_updates_auto_apply' => false,
			'new_request_verification_required' => $admin_allowed,
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_allowed' => false,
			'authorizing' => false,
		);
	}


	/**
	 * Combine independent read-only observations into an ordered, fail-closed
	 * assistant handoff. This cannot create host secrets, grants or approvals.
	 * Any stale Site Profile revision invalidates an earlier apply envelope.
	 */
	public static function automation_plan( array $site, array $write = array(), array $skills = array(), array $developer = array(), array $host_identity = array() ) {
		$decision = self::decision( $site );
		$environment_aligned = 'staging' === (string) ( $site['configured_environment'] ?? '' )
			&& 'staging' === (string) ( $site['wordpress_environment'] ?? '' )
			&& ! empty( $site['wordpress_environment_explicit'] )
			&& empty( $site['wordpress_profile_mismatch'] )
			&& ! empty( $site['origin_match'] ) && ! empty( $site['environment_match'] );
		$profile_valid = ! empty( $site['configured'] ) && ! empty( $site['authority_ready'] )
			&& ! empty( $site['profile_environment_authoritative'] )
			&& empty( $site['mutation_pending_audit'] );
		$binding_configured = ! empty( $site['deployment_binding_configured'] );
		$binding_bound = ! empty( $site['deployment_binding_bound'] );
		$binding_match = ! empty( $site['deployment_binding_match'] );
		$binding_ready = $binding_configured && $binding_bound && $binding_match
			&& ! empty( $site['same_origin_clone_protection'] );
		$live_host_verified = ! empty( $host_identity['verified'] )
			&& 'fresh_host_identity_verified' === (string) ( $host_identity['state'] ?? '' );
		// Never combine independent roots silently. New mode uses the EXISTING
		// Runner Ed25519 root; an installed legacy host secret is migration drift.
		$two_sources = $live_host_verified && $binding_configured;
		$active_source = $two_sources ? 'conflict'
			: ( $live_host_verified ? 'enrolled_host_runner' : ( $binding_ready ? 'legacy_host_binding' : 'none' ) );
		$write_observed = array_key_exists( 'ready', $write );
		$write_ready = $write_observed && ! empty( $write['ready'] );
		$skills_observed = array_key_exists( 'ready', $skills );
		$skills_ready = $skills_observed && ! empty( $skills['ready'] );
		$execution = isset( $developer['execution'] ) && is_array( $developer['execution'] ) ? $developer['execution'] : array();
		$developer_observed = array_key_exists( 'execution_ready', $execution );
		$developer_ready = $developer_observed && ! empty( $execution['execution_ready'] );
		$host_blockers = isset( $execution['blockers'] ) && is_array( $execution['blockers'] ) ? array_values( $execution['blockers'] ) : array();
		$write_blockers = isset( $write['current_readiness_blockers'] ) && is_array( $write['current_readiness_blockers'] )
			? array_values( $write['current_readiness_blockers'] ) : array();
		$next = 'none';
		$actor = 'none';
		$state = 'awaiting_exact_head_native_acceptance';
		if ( ! $environment_aligned || ! $profile_valid ) {
			$next = (string) $decision['next_action_id'];
			$actor = (string) $decision['responsible_actor'];
			$state = 'blocked_site_environment_or_identity';
			if ( 'none' === $next ) {
				$next = 'review_site_environment_identity';
				$actor = 'site_administrator';
			}
		} elseif ( $two_sources ) {
			$actor = 'host_operator';
			$state = 'blocked_multiple_host_identity_roots';
			$next = 'resolve_host_identity_source_conflict_without_cloning';
		} elseif ( $live_host_verified && ! $binding_ready ) {
			$actor = 'host_operator';
			$state = 'fresh_host_identity_verified_legacy_consumers_pending';
			$next = 'migrate_legacy_host_operations_to_signed_proof';
		} elseif ( ! $binding_ready ) {
			$actor = 'host_operator';
			$state = 'blocked_host_deployment_binding';
			$next = ! $binding_configured ? 'provision_unique_host_deployment_binding'
				: ( ! $binding_match ? 'stop_and_review_deployment_binding_drift'
					: 'save_exact_site_profile_to_bind_host_secret' );
			if ( $binding_configured && ! $binding_bound && $binding_match ) $actor = 'site_administrator';
		} elseif ( ! $write_ready ) {
			$next = 'review_exact_write_only_convergence_handshake';
			$actor = 'site_owner';
			$state = $write_observed ? 'blocked_write_authority_not_current' : 'write_authority_not_evaluated';
		} elseif ( ! $skills_ready ) {
			$next = 'review_exact_managed_skills_reconciliation';
			$actor = 'staging_operator';
			$state = $skills_observed ? 'blocked_managed_skills_not_current' : 'managed_skills_not_evaluated';
		} elseif ( ! $developer_ready ) {
			$next = 'inspect_staging_host_sandbox_and_process_limits';
			$actor = 'host_operator';
			$state = $developer_observed ? 'blocked_developer_host_prerequisites' : 'developer_execution_not_evaluated';
		}
		$lanes = $decision['assistant_workflow'];
		$lanes[0]['state'] = $environment_aligned ? 'explicit_staging_aligned' : (string) $decision['state'];
		$lanes[0]['ready'] = $environment_aligned && $profile_valid;
		$lanes[1]['state'] = $two_sources ? 'conflicting_identity_roots' : ( $live_host_verified ? 'signed_existing_host_root' : ( $binding_ready ? 'bound_to_exact_host'
			: ( ! $binding_configured ? 'missing_host_secret' : ( ! $binding_match ? 'binding_drift' : 'host_secret_not_bound_to_profile' ) ) );
		$lanes[1]['ready'] = $binding_ready && ! $two_sources;
		$lanes[1]['signed_host_evidence_verified'] = $live_host_verified;
		$lanes[1]['active_identity_source'] = $active_source;
		$lanes[1]['next_action_id'] = $two_sources ? 'resolve_host_identity_source_conflict_without_cloning'
			: ( $live_host_verified && ! $binding_ready ? 'migrate_legacy_host_operations_to_signed_proof'
			: ( $binding_ready ? 'none' : ( ! $binding_configured ? 'provision_unique_host_deployment_binding'
			: ( ! $binding_match ? 'stop_and_review_deployment_binding_drift' : 'save_exact_site_profile_to_bind_host_secret' ) ) ) );
		$lanes[1]['host_secret_generated_by_wordpress'] = false;
		$lanes[2]['state'] = ! $write_observed ? 'not_evaluated' : ( $write_ready ? 'exact_current_authority_ready' : 'exact_current_authority_blocked' );
		$lanes[2]['ready'] = $write_ready;
		$lanes[2]['observed'] = $write_observed;
		$lanes[2]['blockers'] = $write_blockers;
		$lanes[2]['next_action_id'] = $write_ready ? 'none' : 'review_exact_write_only_convergence_handshake';
		$lanes[2]['skills_state'] = ! $skills_observed ? 'not_evaluated' : ( $skills_ready ? 'ready' : 'blocked' );
		$lanes[2]['skills_next_action'] = $skills_ready ? 'none' : 'review_exact_managed_skills_reconciliation';
		$lanes[3]['state'] = ! $developer_observed ? 'not_evaluated' : ( $developer_ready ? 'sandbox_execution_ready' : 'sandbox_execution_blocked' );
		$lanes[3]['ready'] = $developer_ready;
		$lanes[3]['observed'] = $developer_observed;
		$lanes[3]['blockers'] = $host_blockers;
		$lanes[3]['next_action_id'] = $developer_ready ? 'none' : 'inspect_staging_host_sandbox_and_process_limits';
		$lanes[4]['state'] = 'native_live_acceptance_not_evaluated';
		$lanes[4]['ready'] = false;
		// Never interpret read-time projection as a release certificate.
		return array(
			'contract' => 'mad4b.staging-autopilot-automation-plan.v1',
			'state' => $state,
			'next_action_id' => $next,
			'responsible_actor' => $actor,
			'profile_identity' => array(
				'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
				'revision' => (int) ( $site['revision'] ?? 0 ),
				'profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
			),
			'environment' => array(
				'wordpress_explicit_staging_aligned' => $environment_aligned,
				'profile_identity_ready' => $profile_valid,
				'host_sync_state' => (string) ( $site['environment_sync_state'] ?? 'unknown' ),
				'host_sync_blocker_is_independent_of_wordpress_environment' => $environment_aligned && ! $binding_ready,
			),
			'host_binding' => array(
				'configured' => $binding_configured,
				'bound' => $binding_bound,
				'match' => $binding_match,
				'clone_protection_ready' => $binding_ready && ! $two_sources,
				'signed_host_identity_verified' => $live_host_verified,
				'active_identity_source' => $active_source,
				'legacy_operations_support_dynamic_identity' => false,
				'identity_source_conflict' => $two_sources,
				'secret_read_or_generated' => false,
			),
			'observations' => array(
				'write_observed' => $write_observed,
				'write_ready' => $write_ready,
				'skills_observed' => $skills_observed,
				'skills_ready' => $skills_ready,
				'developer_host_observed' => $developer_observed,
				'developer_execution_ready' => $developer_ready,
				'host_blockers' => $host_blockers,
			),
			'assistant_workflow' => $lanes,
			'execution_policy' => array(
				'read_observation_automatic' => true,
				'one_host_identity_root_only' => true,
				'signed_host_proof_not_equivalent_to_legacy_hmac_secret' => true,
				'wp_config_mutation_requires_local_admin_save' => true,
				'host_secret_provisioning_requires_host_operator' => true,
				'write_only_convergence_requires_exact_owner_approval' => true,
				'managed_skills_remote_reconcile_requires_step_up' => true,
				'developer_isolation_requires_independent_host_acceptance' => true,
				'fresh_plan_required_after_profile_save' => true,
				'unattended_host_install_allowed' => false,
				'unattended_write_grant_allowed' => false,
				'developer_or_breakglass_auto_enable_allowed' => false,
				'production_mutation_allowed' => false,
			),
			'completion_certified' => false,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	public static function status( $input = array() ) {
		if ( null === $input ) $input = array();
		if ( ! is_array( $input ) || ! empty( $input ) ) {
			return new WP_Error( 'mad4b_autopilot_input_invalid', 'Autopilot diagnostics accept no caller-controlled arguments.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
			return new WP_Error( 'mad4b_autopilot_profile_unavailable', 'Site Profile is unavailable.' );
		}
		$site = MAD4B_SCP_Site_Profile::status();
		$report = self::decision( is_array( $site ) ? $site : array() );

		// Explicit on-demand observations: local, read-only, exact-current truth.
		// If a component is absent, show NOT_EVALUATED instead of a false PASS.
		// The Full handshake computes the governed Write inventory once.
		// Do not duplicate that potentially expensive projection per MCP read.
		$full = class_exists( 'MAD4B_SCP_Full_Staging_Authority' )
			? MAD4B_SCP_Full_Staging_Authority::status() : array();
		$skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' )
			? MAD4B_SCP_Skill_Runtime_Certification::current_status() : array();
		$developer = is_array( $full ) && isset( $full['developer'] ) && is_array( $full['developer'] )
			? $full['developer'] : array();
		$write_from_full = is_array( $full ) && isset( $full['write'] ) && is_array( $full['write'] )
			? $full['write'] : array();
		$write_observation = array_key_exists( 'ready', $write_from_full )
			? array( 'ready' => ! empty( $write_from_full['ready'] ),
				'current_readiness_blockers' => isset( $write_from_full['current_readiness_blockers'] ) ? $write_from_full['current_readiness_blockers'] : array() )
			: array();
		$host_identity = class_exists( 'MAD4B_SCP_Host_Identity_Live' )
			? MAD4B_SCP_Host_Identity_Live::observe( $site ) : array();
		$report['host_identity'] = $host_identity;
		$report['automation_plan'] = self::automation_plan(
			is_array( $site ) ? $site : array(),
			$write_observation,
			is_array( $skills ) ? $skills : array(),
			$developer,
			is_array( $host_identity ) ? $host_identity : array()
		);
		$report['site_profile_read_ability'] = 'mad4b/site-profile-status';
		$report['environment_sync_verification_ability'] = 'mad4b/host-environment-sync-verification';
		// File checks are on-demand only; normal requests and chat discovery
		// never parse wp-config.php or expose its contents/absolute path.
		if ( 'admin_reconcile_available' === $report['state'] ) {
			if ( ! class_exists( 'MAD4B_SCP_WP_Config_Environment_Sync' ) ) {
				require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wp-config-environment-sync.php';
			}
			$report['local_config_preflight'] = MAD4B_SCP_WP_Config_Environment_Sync::preflight_readonly( $site );
			if ( 'eligible_for_admin_save' !== $report['local_config_preflight']['state'] ) {
				$report['state'] = 'blocked_local_config_preflight';
				$report['next_action_id'] = 'inspect_local_config_preflight';
				$report['admin_autopilot_action_allowed'] = false;
			}
		}
		return $report;
	}
}
