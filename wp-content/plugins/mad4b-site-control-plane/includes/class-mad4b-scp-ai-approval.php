<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded AI approval for exact governed mutation tickets.
 *
 * This is not a generic auto-approve switch. It is a Staging-only standing
 * delegation for one configured AI Agent. Every decision is bound to the exact
 * pending ticket, payload, operation input, deterministic classification and
 * current Site Profile/build candidate. Production and Breakglass remain human-only.
 */
final class MAD4B_SCP_AI_Approval {
	const CONTRACT = 'mad4b.ai-approval.v1';
	const DELEGATION_CONTRACT = 'mad4b.ai-approval-standing-delegation.v1';
	const ABILITY = 'mad4b/approval-ai-decide';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 31 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'AI Agent Approval Decision',
				'description' => 'Approve or reject one exact pending governed mutation ticket under the Staging-only AI approval policy. Production and Breakglass are never auto-approved.',
				'category' => 'mad4b-admin',
				'execute_callback' => array( __CLASS__, 'decide' ),
				'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_admin' ) : '__return_false',
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'ticket_id' => array( 'type' => 'string', 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'decision' => array( 'type' => 'string', 'enum' => array( 'approve', 'reject' ) ),
						'expected_payload_sha256' => array( 'type' => 'string', 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_classification_sha256' => array( 'type' => 'string', 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'operation_input' => array( 'type' => 'object', 'additionalProperties' => true, 'default' => array() ),
						'review_note' => array( 'type' => 'string', 'maxLength' => 1000, 'default' => '' ),
					),
					'required' => array( 'ticket_id', 'decision', 'expected_payload_sha256', 'expected_classification_sha256', 'operation_input' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'write',
						'mad4b_ai_approval_standing_delegation' => self::DELEGATION_CONTRACT,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				),
			)
		);
	}

	public static function configured_agent_public_id() {
		$policy = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::review_policy() : array();
		if ( 'human_and_ai' !== ( isset( $policy['mode'] ) ? (string) $policy['mode'] : 'human_only' ) ) return '';
		$id = isset( $policy['ai_agent_public_id'] ) ? strtolower( trim( (string) $policy['ai_agent_public_id'] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $id ) ? $id : '';
	}

	public static function catalog_eligible() {
		if ( '' === self::configured_agent_public_id() ) return false;
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return false;
		return true;
	}

	private static function exact_input_status( $input ) {
		$blockers = array();
		if ( ! is_array( $input ) ) return array( 'blockers' => array( 'ai_approval_exact_input_required' ) );
		$ticket_id = isset( $input['ticket_id'] ) ? strtolower( trim( (string) $input['ticket_id'] ) ) : '';
		$decision = isset( $input['decision'] ) ? sanitize_key( (string) $input['decision'] ) : '';
		$payload = isset( $input['expected_payload_sha256'] ) ? strtolower( trim( (string) $input['expected_payload_sha256'] ) ) : '';
		$class_hash = isset( $input['expected_classification_sha256'] ) ? strtolower( trim( (string) $input['expected_classification_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) $blockers[] = 'ai_approval_ticket_invalid';
		if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) $blockers[] = 'ai_approval_decision_invalid';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $payload ) ) $blockers[] = 'ai_approval_payload_invalid';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $class_hash ) ) $blockers[] = 'ai_approval_classification_hash_invalid';
		if ( ! isset( $input['operation_input'] ) || ! is_array( $input['operation_input'] ) ) $blockers[] = 'ai_approval_operation_input_invalid';
		return array(
			'blockers' => $blockers,
			'ticket_id' => $ticket_id,
			'decision' => $decision,
			'payload_sha256' => $payload,
			'classification_sha256' => $class_hash,
			'operation_input' => isset( $input['operation_input'] ) && is_array( $input['operation_input'] ) ? $input['operation_input'] : array(),
		);
	}

	public static function delegation_status( $ability_name, $input = null, $identity = null ) {
		$blockers = array();
		$agent = array();
		$classification = array();
		$ticket = array();
		if ( self::ABILITY !== (string) $ability_name ) $blockers[] = 'ability_not_ai_approval';
		if ( ! self::catalog_eligible() ) $blockers[] = 'ai_approval_policy_not_eligible';
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) $blockers[] = 'ai_approval_write_authority_not_effective';
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && ! MAD4B_SCP_Staging_Write_Authority::is_write_ability( self::ABILITY ) ) $blockers[] = 'ai_approval_not_runtime_eligible';
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && '' !== MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input ) ) $blockers[] = 'ai_approval_prior_ticket_not_allowed';

		$exact = self::exact_input_status( $input );
		$blockers = array_merge( $blockers, $exact['blockers'] );
		if ( empty( $exact['blockers'] ) && class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) {
			$ticket = MAD4B_SCP_Approval_Tickets::get( $exact['ticket_id'] );
			if ( ! is_array( $ticket ) ) {
				$blockers[] = 'ai_approval_ticket_missing';
			} else {
				if ( 'pending' !== ( isset( $ticket['status'] ) ? (string) $ticket['status'] : '' ) ) $blockers[] = 'ai_approval_ticket_not_pending';
				if ( 'mutation' !== ( isset( $ticket['ticket_class'] ) ? (string) $ticket['ticket_class'] : '' ) ) $blockers[] = 'ai_approval_ticket_class_denied';
				if ( 'mad4b-write' !== sanitize_key( isset( $ticket['server_id'] ) ? (string) $ticket['server_id'] : '' ) ) $blockers[] = 'ai_approval_server_denied';
				if ( ! empty( $ticket['expires_at'] ) && strtotime( $ticket['expires_at'] . ' UTC' ) < time() ) $blockers[] = 'ai_approval_ticket_expired';
				if ( empty( $ticket['payload_sha256'] ) || ! hash_equals( strtolower( (string) $ticket['payload_sha256'] ), $exact['payload_sha256'] ) ) $blockers[] = 'ai_approval_payload_mismatch';

				$ability = isset( $ticket['ability_name'] ) ? (string) $ticket['ability_name'] : '';
				$provider = isset( $ticket['provider'] ) ? sanitize_key( (string) $ticket['provider'] ) : '';
				$classification = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::classify( $ability, $provider, $exact['operation_input'] ) : array();
				if ( empty( $classification['classification_sha256'] ) || ! hash_equals( (string) $classification['classification_sha256'], $exact['classification_sha256'] ) ) $blockers[] = 'ai_approval_classification_drift';
				if ( empty( $classification['ai_approval_eligible'] ) || 'ai_autonomous' !== ( isset( $classification['approval_lane'] ) ? (string) $classification['approval_lane'] : '' ) ) $blockers[] = 'ai_approval_operation_human_only';

				$executor_agent = ! empty( $ticket['agent_id'] ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::get_agent_by_id( (int) $ticket['agent_id'] ) : null;
				if ( ! is_array( $executor_agent ) || empty( $executor_agent['public_id'] ) ) {
					$blockers[] = 'ai_approval_executor_agent_missing';
				} else {
					$hash = MAD4B_SCP_Approval_Tickets::canonical_payload_hash(
						$executor_agent['public_id'],
						isset( $ticket['server_id'] ) ? $ticket['server_id'] : '',
						$ability,
						$provider,
						isset( $ticket['target_fingerprint'] ) ? $ticket['target_fingerprint'] : '',
						$exact['operation_input'],
						isset( $ticket['ticket_class'] ) ? $ticket['ticket_class'] : 'mutation'
					);
					if ( is_wp_error( $hash ) || ! hash_equals( (string) $ticket['payload_sha256'], (string) $hash ) ) $blockers[] = 'ai_approval_operation_payload_drift';
				}
			}
		}

		if ( null === $identity ) {
			$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
			if ( is_wp_error( $identity ) ) $identity = array();
		}
		if ( ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) {
			$blockers[] = 'ai_approval_oauth_identity_required';
		} elseif ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$blockers[] = 'ai_approval_agent_registry_unavailable';
		} else {
			$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
			if ( is_wp_error( $agent ) || empty( $agent['id'] ) || empty( $agent['public_id'] ) ) {
				$blockers[] = 'ai_approval_agent_unresolved';
				$agent = array();
			} else {
				$configured = self::configured_agent_public_id();
				if ( '' === $configured || ! hash_equals( $configured, strtolower( (string) $agent['public_id'] ) ) ) $blockers[] = 'ai_approval_agent_mismatch';
				if ( 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' ) || 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' ) ) $blockers[] = 'ai_approval_agent_ineligible';
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-write', self::ABILITY, 'approval-handoff' );
				if ( ! is_array( $grant ) || 'allow' !== ( isset( $grant['effect'] ) ? (string) $grant['effect'] : '' ) || 'staging' !== ( isset( $grant['environment'] ) ? (string) $grant['environment'] : '' ) ) $blockers[] = 'ai_approval_exact_nhi_grant_missing';
			}
		}

		$blockers = array_values( array_unique( $blockers ) );
		return array(
			'contract' => self::DELEGATION_CONTRACT,
			'ability' => self::ABILITY,
			'applicable' => self::ABILITY === (string) $ability_name,
			'allowed' => empty( $blockers ),
			'blockers' => $blockers,
			'configured_agent_public_id' => self::configured_agent_public_id(),
			'resolved_agent_public_id' => isset( $agent['public_id'] ) ? strtolower( (string) $agent['public_id'] ) : '',
			'ticket_id' => isset( $exact['ticket_id'] ) ? $exact['ticket_id'] : '',
			'classification' => $classification,
			'prior_human_approval_required' => false,
			'exact_nhi_grant_required' => true,
			'candidate_binding_required' => true,
			'budget_required' => true,
			'audit_required' => true,
			'production_authorized' => false,
			'breakglass_authorized' => false,
		);
	}

	public static function delegation_allowed( $ability_name, $input = null, $identity = null ) {
		$status = self::delegation_status( $ability_name, $input, $identity );
		return ! empty( $status['allowed'] );
	}

	public static function decide( $input ) {
		$status = self::delegation_status( self::ABILITY, $input );
		if ( empty( $status['allowed'] ) ) return new WP_Error( 'mad4b_ai_approval_denied', 'AI approval policy denied this decision.', array( 'blockers' => isset( $status['blockers'] ) ? $status['blockers'] : array() ) );
		$result = MAD4B_SCP_Approval_Tickets::decide_pending_by_ai(
			(string) $input['ticket_id'],
			(string) $input['decision'],
			(string) $input['expected_payload_sha256'],
			isset( $input['operation_input'] ) && is_array( $input['operation_input'] ) ? $input['operation_input'] : array(),
			(string) $input['expected_classification_sha256'],
			isset( $input['review_note'] ) ? (string) $input['review_note'] : ''
		);
		if ( is_wp_error( $result ) ) return $result;
		return array(
			'contract' => self::CONTRACT,
			'decision' => (string) $input['decision'],
			'ticket' => $result,
			'classification' => isset( $status['classification'] ) ? $status['classification'] : array(),
			'approver_type' => 'ai_agent',
			'approver_agent_public_id' => isset( $status['resolved_agent_public_id'] ) ? $status['resolved_agent_public_id'] : '',
			'human_approval_required' => false,
			'production_mutation' => false,
			'breakglass_authorized' => false,
		);
	}
}
