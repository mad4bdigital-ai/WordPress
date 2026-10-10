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
