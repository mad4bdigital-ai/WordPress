<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governs the approval-plan mutation used to bootstrap a one-time exact ticket.
 *
 * approval-plan is itself a database mutation (it creates a pending ticket), so
 * on the exact Staging Plugin it must require the same NHI + mad4b-write grant +
 * budget path. It is the one intentional exception to "write requires an
 * already-approved ticket", because requiring a ticket to create the ticket
 * would deadlock the approval workflow. It can only create PENDING tickets for
 * the dedicated Staging agent and mad4b-write target operations; approval remains
 * a separate human-administrator action.
 */
final class MAD4B_SCP_Staging_Write_Planning_Guard {
	const CONTRACT = 'mad4b.staging-write-planning-guard.v2';
	const ABILITY = 'mad4b/approval-plan';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Governance_Abilities registers at wp_abilities_api_init:30. This filter is
		// installed before that event and wraps only the approval planner.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'govern_registration' ), 85, 2 );

		// Local OAuth intentionally issues only mad4b:read. The planner receives one
		// exact synthetic scope after cryptographic bearer verification so it can
		// create the first pending ticket without widening the bearer to other writes.
		add_filter( 'mad4b_scp_authenticated_subject_context', array( __CLASS__, 'add_remote_planner_scope' ), 60 );

		// The planner itself cannot require a ticket to create a ticket. This late
		// override applies only to a verified remote bearer and only after strict
		// self-agent/mad4b-write/target validation succeeds.
		add_filter( 'mad4b_scp_low_impact_requires_approval', array( __CLASS__, 'planner_approval_exception' ), 110, 4 );
	}

	public static function govern_registration( $args, $name ) {
		if ( ! is_array( $args ) || self::ABILITY !== (string) $name ) return $args;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return $args;
		if ( ! isset( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) return $args;

		$original = $args['permission_callback'];
		$args['permission_callback'] = static function ( $input = null ) use ( $original ) {
			$granted = call_user_func( $original, $input );
			if ( is_wp_error( $granted ) || ! $granted ) return $granted;

			// Ordinary wp-admin planning remains a human-admin operation. The extra
			// NHI bootstrap contract is required only when the planner is invoked over
			// the governed MCP write path.
			$current = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
			if ( ! in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true ) ) return true;

			$target_guard = MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $input );
			if ( is_wp_error( $target_guard ) ) return $target_guard;
			if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'Governed Staging mutation authority is required to create an approval plan.' );
			if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );

			// Central authorization resolves the active ChatGPT transport back to the
			// dedicated mad4b-write server, then enforces exact NHI grant + budget.
			// planner_approval_exception() prevents only the self-referential ticket.
			$decision = MAD4B_SCP_Authorization::authorize_mutation( self::ABILITY, 'mad4b-admin', 'core', $input );
			return is_wp_error( $decision ) ? $decision : true;
		};

		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_governed_write_authority'] = MAD4B_SCP_Staging_Write_Authority::CONTRACT;
		$args['meta']['mcp']['mad4b_approval_bootstrap_operation'] = true;
		$args['meta']['mcp']['mad4b_creates_pending_ticket_only'] = true;
		$args['meta']['mcp']['mad4b_target_server'] = 'mad4b-write';
		$args['meta']['mcp']['mad4b_breakglass_target_allowed'] = false;
		return $args;
	}

	public static function add_remote_planner_scope( $context ) {
		if ( ! is_array( $context ) ) return $context;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return $context;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $context;
		$current = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		if ( ! in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true ) ) return $context;

		$scopes = isset( $context['token_scopes'] ) && is_array( $context['token_scopes'] ) ? $context['token_scopes'] : array();
		$scopes[] = 'ability:' . self::ABILITY;
		$context['token_scopes'] = array_values( array_unique( $scopes ) );
		return $context;
	}

	public static function planner_approval_exception( $required, $ability_name, $provider, $input ) {
		if ( self::ABILITY !== (string) $ability_name ) return (bool) $required;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return (bool) $required;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return (bool) $required;
		$current = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		if ( ! in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true ) ) return (bool) $required;
		$guard = self::validate_remote_plan_input( $input );
		return is_wp_error( $guard ) ? (bool) $required : false;
	}

	/**
	 * Remote bootstrap is deliberately narrower than the general admin planner.
	 * It may only create a mutation-class pending ticket for this exact Staging
	 * agent, on mad4b-write, targeting a mounted non-breakglass write ability.
	 */
	public static function validate_remote_plan_input( $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_remote_plan_input_invalid', 'Remote approval planning requires an object input.' );
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) {
			return new WP_Error( 'mad4b_remote_plan_authority_not_ready', 'Governed Staging write authority is not ready.' );
		}

		$status = MAD4B_SCP_Staging_Write_Authority::status();
		$expected_agent = isset( $status['agent_public_id'] ) ? (string) $status['agent_public_id'] : '';
		$requested_agent = isset( $input['agent_public_id'] ) ? (string) $input['agent_public_id'] : '';
		if ( '' === $expected_agent || ! hash_equals( $expected_agent, $requested_agent ) ) {
			return new WP_Error( 'mad4b_remote_plan_agent_mismatch', 'Remote approval planning is restricted to the dedicated Staging write agent.' );
		}

		$server_id = isset( $input['server_id'] ) ? sanitize_key( (string) $input['server_id'] ) : '';
		if ( 'mad4b-write' !== $server_id ) return new WP_Error( 'mad4b_remote_plan_server_denied', 'Remote approval planning may target only mad4b-write.' );

		$target_ability = isset( $input['ability'] ) ? trim( (string) $input['ability'] ) : '';
		if ( '' === $target_ability || self::ABILITY === $target_ability || 'mad4b/database-raw-query' === $target_ability ) {
			return new WP_Error( 'mad4b_remote_plan_target_denied', 'Remote approval planning cannot target itself or breakglass.' );
		}
		if ( ! MAD4B_SCP_Staging_Write_Authority::is_write_ability( $target_ability ) ) {
			return new WP_Error( 'mad4b_remote_plan_target_not_write', 'Remote approval planning target is not in the certified Staging write inventory.' );
		}

		$expected_provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $target_ability );
		if ( null === $expected_provider ) return new WP_Error( 'mad4b_remote_plan_target_unmounted', 'Remote approval target is not mounted on mad4b-write.' );
		if ( isset( $input['provider'] ) && '' !== trim( (string) $input['provider'] ) && sanitize_key( (string) $input['provider'] ) !== $expected_provider ) {
			return new WP_Error( 'mad4b_remote_plan_provider_mismatch', 'Remote approval target provider does not match the certified mad4b-write mount.' );
		}

		$operation_input = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		if ( ! class_exists( 'MAD4B_SCP_Impact_Policy' ) || 'mutation' !== MAD4B_SCP_Impact_Policy::ticket_class_for( $target_ability, $expected_provider, $operation_input ) ) {
			return new WP_Error( 'mad4b_remote_plan_ticket_class_denied', 'Remote approval bootstrap may create mutation-class tickets only; breakglass/recovery remains excluded.' );
		}
		if ( isset( $input['ticket_class'] ) && '' !== trim( (string) $input['ticket_class'] ) && 'mutation' !== sanitize_key( (string) $input['ticket_class'] ) ) {
			return new WP_Error( 'mad4b_remote_plan_ticket_class_denied', 'Remote approval bootstrap may create mutation-class tickets only.' );
		}
		return true;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'ability' => self::ABILITY,
			'remote_planner_requires_nhi' => true,
			'remote_planner_requires_exact_mad4b_write_grant' => true,
			'remote_planner_budgeted' => true,
			'remote_planner_requires_prior_ticket' => false,
			'remote_planner_scope' => 'ability:' . self::ABILITY,
			'target_agent' => 'dedicated_staging_write_agent_only',
			'target_server' => 'mad4b-write',
			'target_ticket_class' => 'mutation',
			'breakglass_target_allowed' => false,
			'creates_pending_ticket_only' => true,
			'auto_approves' => false,
		);
	}
}
