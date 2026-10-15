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
	const CLASSIFY_ABILITY = 'mad4b/operation-classify';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 31 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::CLASSIFY_ABILITY ) ) {
			wp_register_ability(
				self::CLASSIFY_ABILITY,
				array(
					'label' => 'Classify Governed Operation',
					'description' => 'Classify one governed operation into operation type, risk tier and approval lane without creating or deciding a ticket.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'classify_operation' ),
					'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'ability' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
							'provider' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'default' => '' ),
							'input' => array( 'type' => 'object', 'additionalProperties' => true, 'default' => array() ),
						),
						'required' => array( 'ability' ),
						'additionalProperties' => false,
					),
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
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
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

	public static function classify_operation( $input ) {
		$ability = is_array( $input ) && isset( $input['ability'] ) ? trim( (string) $input['ability'] ) : '';
		if ( '' === $ability ) return new WP_Error( 'mad4b_operation_classify_ability_required', 'A governed ability is required for operation classification.' );
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability ) ) return new WP_Error( 'mad4b_operation_classify_ability_unknown', 'Operation classification requires a registered runtime ability.' );
		$provider = is_array( $input ) && isset( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : '';
		$mounted = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability ) : null;
		if ( '' === $provider && null !== $mounted ) $provider = sanitize_key( (string) $mounted );
		if ( '' === $provider ) $provider = 'core';
		if ( null !== $mounted && $provider !== sanitize_key( (string) $mounted ) ) return new WP_Error( 'mad4b_operation_classify_provider_mismatch', 'Requested provider does not match the current governed write mount.' );
		$operation_input = is_array( $input ) && isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		$classification = MAD4B_SCP_Impact_Policy::classify( $ability, $provider, $operation_input );
		$classification['read_only'] = true;
		$classification['mutation_performed'] = false;
		$classification['runtime_mounted_on_mad4b_write'] = null !== $mounted;
		return $classification;
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


	/**
	 * Trust only a verified on-request bearer context. A caller-supplied
	 * scope string or persistent review policy cannot mint this delegation.
	 * OAuth consent is the advance owner decision; subsequent exact tickets
	 * are decided by the AI agent and attributed as AI (approved_by=0).
	 */
	public static function oauth_auto_all_delegation_active( $identity = null ) {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
			|| ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			|| ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return false;
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return false;
		$site = MAD4B_SCP_Site_Profile::status();
		if ( ! is_array( $site ) || empty( $site['origin_match'] ) || empty( $site['environment_match'] ) ) return false;
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AI_AUTO_ALL_SCOPE )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) return false;
		if ( null === $identity ) $identity = class_exists( 'MAD4B_SCP_Identity_Context' )
			? MAD4B_SCP_Identity_Context::current() : array();
		// The scope must be issued by this site's own OAuth server. A
		// different trusted issuer must not mint the same scope name and
		// inherit this owner's ChatGPT delegation.
		$local_issuer = MAD4B_SCP_Local_OAuth_Server::issuer();
		$local_fp = MAD4B_SCP_OAuth_Resource_Bridge::issuer_fingerprint_for_issuer( $local_issuer );
		if ( ! is_array( $identity ) || empty( $identity['issuer_fingerprint'] )
			|| ! is_string( $local_fp ) || '' === $local_fp
			|| ! hash_equals( $local_fp, (string) $identity['issuer_fingerprint'] ) ) return false;
		if ( is_wp_error( $identity ) || ! is_array( $identity )
			|| empty( $identity['authenticated'] )
			|| 'oauth2_bearer' !== ( $identity['auth_method'] ?? '' )
			|| ! current_user_can( 'manage_options' )
			|| (int) ( $identity['wp_user_id'] ?? 0 ) < 1
			|| (int) $identity['wp_user_id'] !== (int) get_current_user_id()
			|| ! in_array( MAD4B_SCP_OAuth_Resource_Bridge::AI_AUTO_ALL_SCOPE, (array) ( $identity['token_scopes'] ?? array() ), true ) ) return false;
		return true;
	}

	/**
	 * Lightweight, non-authorizing status for wp-admin dashboards.
	 *
	 * A wp-admin cookie session cannot prove what scopes a DIFFERENT remote
	 * ChatGPT bearer currently holds. Never present support or consent policy
	 * as a live OAuth approval. Do not cache bearer state across requests.
	 */
	public static function dashboard_projection() {
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' )
			? MAD4B_SCP_Site_Profile::current_environment() : 'unknown';
		$standing_configured = self::catalog_eligible();
		$oauth_supported = 'staging' === $environment
			&& class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
			&& class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			&& method_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', 'scopes_for_resource' )
			&& in_array( MAD4B_SCP_OAuth_Resource_Bridge::AI_AUTO_ALL_SCOPE,
				MAD4B_SCP_OAuth_Resource_Bridge::scopes_for_resource(
					MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() ), true );
		$current = $oauth_supported && self::oauth_auto_all_delegation_active();
		return array(
			'contract' => 'mad4b.ai-approval-admin-projection.v1',
			'environment' => $environment,
			'legacy_standing_policy_configured' => $standing_configured,
			'optional_owner_oauth_scope' => $oauth_supported ? MAD4B_SCP_OAuth_Resource_Bridge::AI_AUTO_ALL_SCOPE : '',
			'oauth_scope_supported' => $oauth_supported,
			'oauth_current_request_verified' => $current,
			'remote_chatgpt_token_grant_status' => $current ? 'verified_this_request' : 'not_observed_in_admin_request',
			'reconsent_required_for_new_scope' => $oauth_supported,
			'delegation_applies_to' => 'governed_exact_staging_approval_tickets',
			'brand_asset_ai_review_separately_configured' => true,
			'original_owner_human_attestation_separate' => true,
			'raw_sql_breakglass_included' => false,
			'production_ai_auto_approval_included' => false,
			'mutation_performed' => false,
			'read_only' => true,
		);
	}

	/** Shared dashboard advisory; it never issues or refreshes OAuth tokens. */
	public static function render_admin_status() {
		$state = self::dashboard_projection();
		$standing = ! empty( $state['legacy_standing_policy_configured'] );
		$available = ! empty( $state['oauth_scope_supported'] );
		$observed = ! empty( $state['oauth_current_request_verified'] );
		echo '<details class="mad4b-ai-approval-mode-readback" style="margin:10px 0;padding:8px 12px;border:1px solid #c3c4c7;border-radius:4px">';
		echo '<summary><strong>' . esc_html__( 'AI approval authority', 'mad4b-site-control-plane' ) . '</strong> · ' .
			esc_html( $observed ? 'Owner OAuth auto-approval verified for this request' : ( $available ? 'Optional owner OAuth auto-approval supported — current token unverified here' : 'Owner OAuth auto-approval unavailable for this environment' ) ) . '</summary>';
		echo '<p>' . esc_html__( 'Standing Agent approval policy:', 'mad4b-site-control-plane' ) . ' <strong>' .
			esc_html( $standing ? 'configured (operation-level grants still apply)' : 'not configured' ) . '</strong></p>';
		if ( $available ) {
			echo '<p>' . esc_html__( 'Optional OAuth scope:', 'mad4b-site-control-plane' ) . ' <code>' . esc_html( $state['optional_owner_oauth_scope'] ) . '</code>. ' .
				esc_html__( 'Only fresh consent explicitly requested by the ChatGPT client can enable this scope. A WordPress admin session cannot verify scopes already granted to a remote ChatGPT session.', 'mad4b-site-control-plane' ) . '</p>';
		}
		echo '<p>' . esc_html__( 'AI decisions are recorded as AI decisions, not human reviews. Original brand ownership attestation, operation grants, one-time ticket identity, and separate Production/Breakglass rules are unchanged.', 'mad4b-site-control-plane' ) . '</p>';
		echo '</details>';
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
		$executor_agent = array();
		$bounded_developer_ticket = false;
		if ( null === $identity ) $identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) ) $identity = array();
		$oauth_auto_all = self::oauth_auto_all_delegation_active( $identity );
		if ( self::ABILITY !== (string) $ability_name ) $blockers[] = 'ability_not_ai_approval';
		if ( ! $oauth_auto_all && ! self::catalog_eligible() ) $blockers[] = 'ai_approval_policy_not_eligible';
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
				$ability = isset( $ticket['ability_name'] ) ? (string) $ticket['ability_name'] : '';
				$provider = isset( $ticket['provider'] ) ? sanitize_key( (string) $ticket['provider'] ) : '';
				$ticket_server = sanitize_key( isset( $ticket['server_id'] ) ? (string) $ticket['server_id'] : '' );
				$bounded_developer_ticket = 'mad4b-developer' === $ticket_server && in_array( $ability, array( 'mad4b/developer-workspace-apply', 'mad4b/developer-workspace-promote' ), true ) && 'core' === $provider;
				if ( 'mad4b-write' !== $ticket_server && ! $bounded_developer_ticket ) $blockers[] = 'ai_approval_server_denied';
				if ( 'mad4b/developer-workspace-promote' === $ability ) {
					$operation = isset( $exact['operation_input'] ) && is_array( $exact['operation_input'] ) ? $exact['operation_input'] : array();
					$plan_sha = isset( $operation['expected_plan_sha256'] ) ? strtolower( trim( (string) $operation['expected_plan_sha256'] ) ) : '';
					$workspace_sha = isset( $operation['expected_workspace_manifest_sha256'] ) ? strtolower( trim( (string) $operation['expected_workspace_manifest_sha256'] ) ) : '';
					$installed_sha = isset( $operation['expected_installed_manifest_sha256'] ) ? strtolower( trim( (string) $operation['expected_installed_manifest_sha256'] ) ) : '';
					$expected_absent = ! empty( $operation['expected_absent'] );
					if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $plan_sha ) ) $blockers[] = 'ai_approval_developer_promotion_plan_digest_invalid';
					if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $workspace_sha ) ) $blockers[] = 'ai_approval_developer_promotion_workspace_digest_invalid';
					if ( $expected_absent ) {
						if ( '' !== $installed_sha ) $blockers[] = 'ai_approval_developer_promotion_absent_target_digest_conflict';
					} elseif ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $installed_sha ) ) {
						$blockers[] = 'ai_approval_developer_promotion_installed_digest_invalid';
					}
				}
				if ( ! empty( $ticket['expires_at'] ) && strtotime( $ticket['expires_at'] . ' UTC' ) < time() ) $blockers[] = 'ai_approval_ticket_expired';
				if ( empty( $ticket['payload_sha256'] ) || ! hash_equals( strtolower( (string) $ticket['payload_sha256'] ), $exact['payload_sha256'] ) ) $blockers[] = 'ai_approval_payload_mismatch';
				$classification = class_exists( 'MAD4B_SCP_Impact_Policy' ) ? MAD4B_SCP_Impact_Policy::classify( $ability, $provider, $exact['operation_input'] ) : array();
				if ( empty( $classification['classification_sha256'] ) || ! hash_equals( (string) $classification['classification_sha256'], $exact['classification_sha256'] ) ) $blockers[] = 'ai_approval_classification_drift';
				// Explicit owner OAuth auto:all delegates ticket decisions across
				// the governed Staging catalog, including high-risk/human lanes.
				// Execution remains under the same exact grant, candidate and
				// provider/runtime gates; Production/Breakglass stay separate.
				if ( ! $oauth_auto_all && ( empty( $classification['ai_approval_eligible'] ) || 'ai_autonomous' !== ( isset( $classification['approval_lane'] ) ? (string) $classification['approval_lane'] : '' ) ) ) $blockers[] = 'ai_approval_operation_human_only';
				if ( $oauth_auto_all && 'mad4b-write' !== $ticket_server && ! $bounded_developer_ticket ) $blockers[] = 'ai_approval_oauth_auto_all_server_denied';
				if ( $oauth_auto_all && in_array( $ability, array( 'context/remote-owner-review-apply', 'mad4b/approval-plan', 'mad4b/approval-ai-decide', 'mad4b/database-raw-query' ), true ) ) $blockers[] = 'ai_approval_reserved_human_or_governance_operation';
				if ( $oauth_auto_all && ( 'breakglass' === ( $classification['ticket_class'] ?? '' ) || 'exceptional' === ( $classification['operation_type'] ?? '' ) ) ) $blockers[] = 'ai_approval_breakglass_scope_is_separate';

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
				if ( ! $oauth_auto_all ) {
					if ( '' === $configured || ! hash_equals( $configured, strtolower( (string) $agent['public_id'] ) ) ) $blockers[] = 'ai_approval_agent_mismatch';
				} else {
					$site_agent_slug = method_exists( 'MAD4B_SCP_Site_Profile', 'agent_slug' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) : '';
					if ( '' === $site_agent_slug || $site_agent_slug !== sanitize_key( (string) ( $agent['slug'] ?? '' ) ) ) $blockers[] = 'ai_approval_oauth_agent_not_site_owned';
				}
				if ( 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' ) || 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' ) ) $blockers[] = 'ai_approval_agent_ineligible';
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-write', self::ABILITY, 'core' );
				if ( ! is_array( $grant ) || 'allow' !== ( isset( $grant['effect'] ) ? (string) $grant['effect'] : '' ) || 'staging' !== ( isset( $grant['environment'] ) ? (string) $grant['environment'] : '' ) ) $blockers[] = 'ai_approval_exact_nhi_grant_missing';
				if ( $bounded_developer_ticket && is_array( $executor_agent ) && ! empty( $executor_agent['public_id'] ) && hash_equals( strtolower( (string) $executor_agent['public_id'] ), strtolower( (string) $agent['public_id'] ) ) ) {
					$blockers[] = 'ai_approval_developer_executor_separation_required';
				}
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
			'authorization_mode' => $oauth_auto_all ? 'owner_oauth_scope_auto_all' : 'standing_agent_policy',
			'owner_oauth_delegation_verified' => $oauth_auto_all,
			'resolved_agent_public_id' => isset( $agent['public_id'] ) ? strtolower( (string) $agent['public_id'] ) : '',
			'ticket_id' => isset( $exact['ticket_id'] ) ? $exact['ticket_id'] : '',
			'classification' => $classification,
			'prior_human_approval_required' => false,
			'exact_nhi_grant_required' => true,
			'developer_executor_separation_required' => true,
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
