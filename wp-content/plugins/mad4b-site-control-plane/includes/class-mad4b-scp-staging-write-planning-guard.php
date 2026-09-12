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
 *
 * The same class also owns the Staging-only canonical authorization identity for
 * mad4b/mutation-undo. Human reason text remains audit metadata; it is deliberately
 * excluded from approval identity so planning and execution cannot drift merely
 * because the operator wording changes. The target fingerprint is instead bound
 * to immutable mutation evidence plus the current exact Staging candidate.
 */
final class MAD4B_SCP_Staging_Write_Planning_Guard {
	const CONTRACT = 'mad4b.staging-write-planning-guard.v2';
	const ABILITY = 'mad4b/approval-plan';
	const UNDO_ABILITY = 'mad4b/mutation-undo';
	const UNDO_AUTHORIZATION_CONTRACT = 'mad4b.undo-authorization-target.v1';
	const UNDO_AUTHORIZATION_REASON = 'governed_mutation_undo';

	private static $booted = false;
	private static $undo_request_reason = '';

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		add_filter( 'wp_register_ability_args', array( __CLASS__, 'govern_registration' ), 85, 2 );
		// Restore the operator reason only inside the actual mutation callback. This
		// wrapper is intentionally inside the central execution boundary (190).
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_undo_before_execution_boundary' ), 180, 2 );
		// Normalize the authorization-facing input outside the central execution
		// boundary so permission preflights and execution claims see identical data.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_undo_after_execution_boundary' ), 210, 2 );
		add_filter( 'mad4b_scp_authorization_target_fingerprint', array( __CLASS__, 'undo_target_fingerprint' ), 100, 6 );
		add_filter( 'mad4b_scp_authenticated_subject_context', array( __CLASS__, 'add_remote_planner_scope' ), 60 );
		add_filter( 'mad4b_scp_low_impact_requires_approval', array( __CLASS__, 'planner_approval_exception' ), 110, 4 );
	}

	public static function govern_registration( $args, $name ) {
		if ( ! is_array( $args ) || self::ABILITY !== (string) $name ) return $args;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return $args;
		if ( ! isset( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) return $args;

		$original_permission = $args['permission_callback'];
		$args['permission_callback'] = static function ( $input = null ) use ( $original_permission ) {
			$granted = call_user_func( $original_permission, $input );
			if ( is_wp_error( $granted ) || ! $granted ) return $granted;
			if ( ! MAD4B_SCP_Staging_Write_Planning_Guard::is_remote_write_transport() ) return true;

			$input = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_remote_plan_input( $input );
			if ( is_wp_error( $input ) ) return $input;
			$target_guard = MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $input );
			if ( is_wp_error( $target_guard ) ) return $target_guard;
			if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'Governed Staging mutation authority is required to create an approval plan.' );
			if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );

			$decision = MAD4B_SCP_Authorization::authorize_mutation( self::ABILITY, 'mad4b-admin', 'core', $input );
			return is_wp_error( $decision ) ? $decision : true;
		};

		// Only remote governed planning receives exact live-candidate binding. The
		// low-level Approval_Tickets primitive remains reusable by local/CI paths.
		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original_execute = $args['execute_callback'];
			$args['execute_callback'] = static function ( $input = null ) use ( $original_execute ) {
				$remote = MAD4B_SCP_Staging_Write_Planning_Guard::is_remote_write_transport();
				if ( $remote ) {
					$input = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_remote_plan_input( $input );
					if ( is_wp_error( $input ) ) return $input;
					$guard = MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $input );
					if ( is_wp_error( $guard ) ) return $guard;
				}
				$result = call_user_func( $original_execute, $input );
				if ( is_wp_error( $result ) || ! $remote ) return $result;
				if ( ! is_array( $result ) || empty( $result['ticket_id'] ) ) return new WP_Error( 'mad4b_remote_plan_ticket_missing', 'Remote approval planning did not return a pending ticket.' );
				if ( ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) return new WP_Error( 'mad4b_remote_plan_binding_unavailable', 'Approval ticket candidate binding is unavailable.' );
				$binding = MAD4B_SCP_Approval_Tickets::bind_ticket_to_current_candidate( $result['ticket_id'] );
				return is_wp_error( $binding ) ? $binding : $result;
			};
		}

		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_governed_write_authority'] = MAD4B_SCP_Staging_Write_Authority::CONTRACT;
		$args['meta']['mcp']['mad4b_approval_bootstrap_operation'] = true;
		$args['meta']['mcp']['mad4b_creates_pending_ticket_only'] = true;
		$args['meta']['mcp']['mad4b_candidate_bound_pending_ticket'] = true;
		$args['meta']['mcp']['mad4b_target_server'] = 'mad4b-write';
		$args['meta']['mcp']['mad4b_breakglass_target_allowed'] = false;
		return $args;
	}

	/**
	 * Canonicalize the operation nested inside approval-plan. Only mutation-undo
	 * receives special treatment; every other mutation keeps exact-input binding.
	 */
	public static function canonicalize_remote_plan_input( $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_remote_plan_input_invalid', 'Remote approval planning requires an object input.' );
		$target_ability = isset( $input['ability'] ) ? trim( (string) $input['ability'] ) : '';
		if ( self::UNDO_ABILITY !== $target_ability ) return $input;
		$operation_input = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		$canonical = self::canonicalize_undo_authorization_input( $operation_input );
		if ( is_wp_error( $canonical ) ) return $canonical;
		$input['input'] = $canonical;
		return $input;
	}

	/**
	 * Authorization identity for undo is mutation identity, not operator prose.
	 * Keep the ticket envelope when present; Staging_Write_Authority strips it
	 * before central target/payload hashing as it already does for every write.
	 */
	public static function canonicalize_undo_authorization_input( $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_undo_authorization_input_invalid', 'Mutation undo authorization requires an object input.' );
		$mutation_id = isset( $input['mutation_id'] ) ? strtolower( trim( (string) $input['mutation_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36,64}$/', $mutation_id ) ) return new WP_Error( 'mad4b_undo_authorization_mutation_invalid', 'Mutation undo authorization requires an exact mutation id.' );
		$input['mutation_id'] = $mutation_id;
		$input['reason'] = self::UNDO_AUTHORIZATION_REASON;
		return $input;
	}

	public static function remember_undo_request_reason( $input ) {
		self::$undo_request_reason = is_array( $input ) && isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		return self::$undo_request_reason;
	}

	public static function restore_undo_execution_input( $input ) {
		if ( ! is_array( $input ) ) return $input;
		if ( '' !== self::$undo_request_reason ) $input['reason'] = self::$undo_request_reason;
		return $input;
	}

	public static function wrap_undo_before_execution_boundary( $args, $name ) {
		if ( ! is_array( $args ) || self::UNDO_ABILITY !== (string) $name ) return $args;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return $args;
		if ( ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) return $args;

		$original_execute = $args['execute_callback'];
		$args['execute_callback'] = static function ( $input = null ) use ( $original_execute ) {
			if ( ! MAD4B_SCP_Staging_Write_Planning_Guard::is_remote_write_transport() ) return call_user_func( $original_execute, $input );
			$restored = MAD4B_SCP_Staging_Write_Planning_Guard::restore_undo_execution_input( $input );
			return call_user_func( $original_execute, $restored );
		};
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_undo_reason_restore_inner'] = true;
		return $args;
	}

	public static function wrap_undo_after_execution_boundary( $args, $name ) {
		if ( ! is_array( $args ) || self::UNDO_ABILITY !== (string) $name ) return $args;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return $args;

		if ( isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
			$original_permission = $args['permission_callback'];
			$args['permission_callback'] = static function ( $input = null ) use ( $original_permission ) {
				if ( ! MAD4B_SCP_Staging_Write_Planning_Guard::is_remote_write_transport() ) return call_user_func( $original_permission, $input );
				MAD4B_SCP_Staging_Write_Planning_Guard::remember_undo_request_reason( $input );
				$canonical = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_undo_authorization_input( $input );
				if ( is_wp_error( $canonical ) ) return $canonical;
				return call_user_func( $original_permission, $canonical );
			};
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original_execute = $args['execute_callback'];
			$args['execute_callback'] = static function ( $input = null ) use ( $original_execute ) {
				if ( ! MAD4B_SCP_Staging_Write_Planning_Guard::is_remote_write_transport() ) return call_user_func( $original_execute, $input );
				MAD4B_SCP_Staging_Write_Planning_Guard::remember_undo_request_reason( $input );
				$canonical = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_undo_authorization_input( $input );
				if ( is_wp_error( $canonical ) ) return $canonical;
				try {
					return call_user_func( $original_execute, $canonical );
				} finally {
					MAD4B_SCP_Staging_Write_Planning_Guard::clear_undo_request_reason();
				}
			};
		}

		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_undo_authorization_contract'] = self::UNDO_AUTHORIZATION_CONTRACT;
		$args['meta']['mcp']['mad4b_undo_reason_is_audit_metadata'] = true;
		return $args;
	}

	public static function clear_undo_request_reason() {
		self::$undo_request_reason = '';
	}

	/**
	 * Strong target identity for undo. This is stable across operator reason text,
	 * but changes if the referenced immutable mutation envelope changes.
	 */
	public static function undo_target_fingerprint( $filtered, $ability_name, $provider, $input, $agent = array(), $identity = array() ) {
		if ( self::UNDO_ABILITY !== (string) $ability_name || 'core' !== sanitize_key( (string) $provider ) ) return $filtered;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return $filtered;
		if ( ! is_array( $input ) || ! class_exists( 'MAD4B_SCP_Mutation_Manager' ) ) return $filtered;
		$mutation_id = isset( $input['mutation_id'] ) ? strtolower( trim( (string) $input['mutation_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36,64}$/', $mutation_id ) ) return $filtered;
		$record = MAD4B_SCP_Mutation_Manager::get( $mutation_id );
		if ( ! is_array( $record ) ) return $filtered;

		$target = array(
			'contract' => self::UNDO_AUTHORIZATION_CONTRACT,
			'ability' => self::UNDO_ABILITY,
			'provider' => 'core',
			'mutation_id' => $mutation_id,
			'original_ability' => isset( $record['ability_name'] ) ? (string) $record['ability_name'] : '',
			'original_provider' => isset( $record['provider'] ) ? sanitize_key( (string) $record['provider'] ) : '',
			'target_type' => isset( $record['target_type'] ) ? (string) $record['target_type'] : '',
			'target_id' => isset( $record['target_id'] ) ? (string) $record['target_id'] : '',
			'after_sha256' => isset( $record['after_sha256'] ) ? strtolower( (string) $record['after_sha256'] ) : '',
			'rollback_payload_sha256' => isset( $record['rollback_payload_sha256'] ) ? strtolower( (string) $record['rollback_payload_sha256'] ) : '',
		);
		if ( '' === $target['original_ability'] || '' === $target['target_type'] || '' === $target['target_id'] ) return $filtered;
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $target['after_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $target['rollback_payload_sha256'] ) ) return $filtered;
		$json = wp_json_encode( $target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? $filtered : hash( 'sha256', $json );
	}

	public static function add_remote_planner_scope( $context ) {
		if ( ! is_array( $context ) ) return $context;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return $context;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $context;
		if ( ! self::is_remote_write_transport() ) return $context;

		$scopes = isset( $context['token_scopes'] ) && is_array( $context['token_scopes'] ) ? $context['token_scopes'] : array();
		$scopes[] = 'ability:' . self::ABILITY;
		$context['token_scopes'] = array_values( array_unique( $scopes ) );
		return $context;
	}

	public static function planner_approval_exception( $required, $ability_name, $provider, $input ) {
		if ( self::ABILITY !== (string) $ability_name ) return (bool) $required;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return (bool) $required;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return (bool) $required;
		if ( ! self::is_remote_write_transport() ) return (bool) $required;
		$canonical = self::canonicalize_remote_plan_input( $input );
		if ( is_wp_error( $canonical ) ) return (bool) $required;
		$guard = self::validate_remote_plan_input( $canonical );
		return is_wp_error( $guard ) ? (bool) $required : false;
	}

	public static function validate_remote_plan_input( $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_remote_plan_input_invalid', 'Remote approval planning requires an object input.' );
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return new WP_Error( 'mad4b_remote_plan_authority_not_ready', 'Governed Staging write authority is not ready.' );

		$status = MAD4B_SCP_Staging_Write_Authority::status();
		$expected_agent = isset( $status['agent_public_id'] ) ? (string) $status['agent_public_id'] : '';
		$requested_agent = isset( $input['agent_public_id'] ) ? (string) $input['agent_public_id'] : '';
		if ( '' === $expected_agent || ! hash_equals( $expected_agent, $requested_agent ) ) return new WP_Error( 'mad4b_remote_plan_agent_mismatch', 'Remote approval planning is restricted to the dedicated Staging write agent.' );

		$server_id = isset( $input['server_id'] ) ? sanitize_key( (string) $input['server_id'] ) : '';
		if ( 'mad4b-write' !== $server_id ) return new WP_Error( 'mad4b_remote_plan_server_denied', 'Remote approval planning may target only mad4b-write.' );

		$target_ability = isset( $input['ability'] ) ? trim( (string) $input['ability'] ) : '';
		if ( '' === $target_ability || self::ABILITY === $target_ability || 'mad4b/database-raw-query' === $target_ability ) return new WP_Error( 'mad4b_remote_plan_target_denied', 'Remote approval planning cannot target itself or breakglass.' );
		if ( ! MAD4B_SCP_Staging_Write_Authority::is_write_ability( $target_ability ) ) return new WP_Error( 'mad4b_remote_plan_target_not_write', 'Remote approval planning target is not in the certified Staging write inventory.' );

		$expected_provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $target_ability );
		if ( null === $expected_provider ) return new WP_Error( 'mad4b_remote_plan_target_unmounted', 'Remote approval target is not mounted on mad4b-write.' );
		if ( isset( $input['provider'] ) && '' !== trim( (string) $input['provider'] ) && sanitize_key( (string) $input['provider'] ) !== $expected_provider ) return new WP_Error( 'mad4b_remote_plan_provider_mismatch', 'Remote approval target provider does not match the certified mad4b-write mount.' );

		$operation_input = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		if ( self::UNDO_ABILITY === $target_ability ) {
			$undo_guard = self::validate_undo_plan_target( $operation_input );
			if ( is_wp_error( $undo_guard ) ) return $undo_guard;
		}
		if ( ! class_exists( 'MAD4B_SCP_Impact_Policy' ) || 'mutation' !== MAD4B_SCP_Impact_Policy::ticket_class_for( $target_ability, $expected_provider, $operation_input ) ) return new WP_Error( 'mad4b_remote_plan_ticket_class_denied', 'Remote approval bootstrap may create mutation-class tickets only; breakglass/recovery remains excluded.' );
		if ( isset( $input['ticket_class'] ) && '' !== trim( (string) $input['ticket_class'] ) && 'mutation' !== sanitize_key( (string) $input['ticket_class'] ) ) return new WP_Error( 'mad4b_remote_plan_ticket_class_denied', 'Remote approval bootstrap may create mutation-class tickets only.' );
		return true;
	}

	private static function validate_undo_plan_target( $operation_input ) {
		$canonical = self::canonicalize_undo_authorization_input( $operation_input );
		if ( is_wp_error( $canonical ) ) return $canonical;
		if ( ! class_exists( 'MAD4B_SCP_Mutation_Manager' ) ) return new WP_Error( 'mad4b_undo_plan_mutation_manager_unavailable', 'Undo approval planning requires current mutation evidence.' );
		$record = MAD4B_SCP_Mutation_Manager::get( $canonical['mutation_id'] );
		if ( ! is_array( $record ) ) return new WP_Error( 'mad4b_undo_plan_mutation_missing', 'Undo approval planning requires an existing mutation record.' );
		if ( empty( $record['reversible'] ) || ! in_array( isset( $record['status'] ) ? (string) $record['status'] : '', array( 'verified', 'verification_failed' ), true ) ) return new WP_Error( 'mad4b_undo_plan_not_reversible', 'Undo approval planning requires a reversible verified mutation envelope.' );
		if ( empty( $record['undo_expires_at'] ) || strtotime( $record['undo_expires_at'] . ' UTC' ) < time() ) return new WP_Error( 'mad4b_undo_plan_expired', 'Undo approval planning is denied after the recorded undo window expires.' );
		$after = isset( $record['after_sha256'] ) ? strtolower( (string) $record['after_sha256'] ) : '';
		$rollback = isset( $record['rollback_payload_sha256'] ) ? strtolower( (string) $record['rollback_payload_sha256'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $after ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $rollback ) ) return new WP_Error( 'mad4b_undo_plan_evidence_invalid', 'Undo approval planning requires intact after-state and rollback digests.' );
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
			'candidate_binding_required_for_remote_plan' => true,
			'undo_authorization_contract' => self::UNDO_AUTHORIZATION_CONTRACT,
			'undo_reason_is_audit_metadata' => true,
			'undo_target_identity' => 'immutable_mutation_envelope',
			'auto_approves' => false,
		);
	}

	private static function is_remote_write_transport() {
		$current = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		return in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true );
	}
}
