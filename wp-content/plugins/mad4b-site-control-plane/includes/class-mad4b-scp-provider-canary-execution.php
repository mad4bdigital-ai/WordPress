<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed execution wrapper for high-risk provider capabilities in CANARY.
 *
 * The target provider ability remains absent from the normal mad4b-write surface.
 * This core wrapper is the only remotely approvable operation. It revalidates the
 * exact live candidate, provider artifact, capability contract and trusted
 * behavioral receipt before delegating to an adapter that explicitly opts into
 * canary execution. Successful execution produces evidence only; it never grants
 * canary -> active promotion authority.
 */
final class MAD4B_SCP_Provider_Canary_Execution {
	const CONTRACT = 'mad4b.provider-canary-execution.v1';
	const EVIDENCE_CONTRACT = 'mad4b.provider-canary-execution-evidence.v1';
	const AUTHORIZED_EVIDENCE_CONTRACT = 'mad4b.provider-canary-authorized-evidence.v1';
	const ABILITY = 'mad4b/provider-canary-execute';
	const MAX_TARGET_INPUT_BYTES = 32768;
	const MAX_RESULT_BYTES = 16384;
	const MAX_SAFE_SUMMARY_BYTES = 4096;
	const MAX_DEPTH = 8;

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Execute Governed Provider Canary',
				'description' => 'Execute one exact high-risk provider capability while it is canary-eligible, through the normal MAD4B one-time approval authority. This produces canary evidence and never promotes the capability to active.',
				'category' => 'mad4b-admin',
				'execute_callback' => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'can_execute' ),
				'input_schema' => self::input_schema(),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						// Deliberately not admin/content. Authorization therefore declares
						// the dedicated mad4b-write authority for this wrapper.
						'surface' => 'write',
						'mad4b_provider_canary_execution' => self::CONTRACT,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				),
			)
		);
	}

	private static function input_schema() {
		$digest = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		return array(
			'type' => 'object',
			'properties' => array(
				'provider_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z0-9_-]+$' ),
				'capability_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[a-z0-9._-]+$' ),
				'target_ability' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 160, 'pattern' => '^[A-Za-z0-9._/-]+$' ),
				'expected_candidate_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
				'expected_build_fingerprint' => $digest,
				'expected_artifact_fingerprint' => $digest,
				'expected_capability_contract_digest' => $digest,
				'expected_canary_basis_digest' => $digest,
				'expected_behavioral_evidence_digest' => $digest,
				'target_input' => array( 'type' => 'object', 'additionalProperties' => true, 'default' => array() ),
			),
			'required' => array(
				'provider_id',
				'capability_id',
				'target_ability',
				'expected_candidate_sha',
				'expected_build_fingerprint',
				'expected_artifact_fingerprint',
				'expected_capability_contract_digest',
				'expected_canary_basis_digest',
				'target_input',
			),
			'additionalProperties' => false,
		);
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return false;
		if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_provider_canary_mutation_disabled', 'Governed mutation authority is not effective for this request.' );
		$context = self::validate_context( is_array( $input ) ? $input : array() );
		return is_wp_error( $context ) ? $context : true;
	}

	/**
	 * Pure/read-only guard used both at permission time and immediately before the
	 * adapter call. No approval, activation or provider state is mutated here.
	 */
	public static function validate_context( array $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'write' ) || ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) {
			return new WP_Error( 'mad4b_provider_canary_nonproduction_only', 'Provider canary execution requires an explicitly enrolled governed non-production site with write authority.' );
		}
		$audit_ready = self::audit_ready();
		if ( is_wp_error( $audit_ready ) ) return $audit_ready;
		$candidate = self::current_candidate();
		if ( is_wp_error( $candidate ) ) return $candidate;

		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		$capability_id = isset( $input['capability_id'] ) ? strtolower( trim( (string) $input['capability_id'] ) ) : '';
		$target_ability = isset( $input['target_ability'] ) ? trim( (string) $input['target_ability'] ) : '';
		if ( '' === $provider || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider ) ) return new WP_Error( 'mad4b_provider_canary_provider_invalid', 'Provider canary execution requires an exact cataloged provider id.' );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $capability_id ) ) return new WP_Error( 'mad4b_provider_canary_capability_invalid', 'Provider canary execution requires an exact capability id.' );
		if ( ! preg_match( '/^[A-Za-z0-9._\/-]{3,160}$/', $target_ability ) ) return new WP_Error( 'mad4b_provider_canary_ability_invalid', 'Provider canary execution requires an exact target ability.' );
		if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) || ! MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $provider ) ) return new WP_Error( 'mad4b_provider_canary_provider_not_cataloged', 'Provider is not governed by the capability catalog.' );

		$expected_candidate = self::clean_sha40( isset( $input['expected_candidate_sha'] ) ? $input['expected_candidate_sha'] : '' );
		$expected_build = self::clean_digest( isset( $input['expected_build_fingerprint'] ) ? $input['expected_build_fingerprint'] : '' );
		if ( '' === $expected_candidate || ! hash_equals( $candidate['source_commit_sha'], $expected_candidate ) ) return new WP_Error( 'mad4b_provider_canary_candidate_mismatch', 'Expected candidate SHA does not match the exact live governed build.' );
		if ( '' === $expected_build || ! hash_equals( $candidate['build_fingerprint'], $expected_build ) ) return new WP_Error( 'mad4b_provider_canary_build_mismatch', 'Expected build fingerprint does not match the exact live governed build.' );

		$adapter = self::resolve_adapter( $provider );
		if ( is_wp_error( $adapter ) ) return $adapter;
		$status = MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $provider, $target_ability, $adapter );
		if ( empty( $status ) ) return new WP_Error( 'mad4b_provider_canary_capability_not_found', 'Target ability is not bound to a governed capability for this provider.' );
		if ( $capability_id !== ( isset( $status['capability_id'] ) ? (string) $status['capability_id'] : '' ) ) return new WP_Error( 'mad4b_provider_canary_capability_mismatch', 'Target ability does not belong to the requested capability.' );
		if ( 'high_risk_write' !== ( isset( $status['risk'] ) ? (string) $status['risk'] : '' ) ) return new WP_Error( 'mad4b_provider_canary_risk_invalid', 'Canary wrapper accepts only capabilities classified as high_risk_write.' );
		if ( MAD4B_SCP_Provider_Compatibility_Certification::ACTIVATION_CANARY !== ( isset( $status['activation_stage'] ) ? (string) $status['activation_stage'] : '' ) ) return new WP_Error( 'mad4b_provider_canary_stage_invalid', 'High-risk capability is not currently in canary stage.' );
		if ( empty( $status['canary_eligible'] ) || ! empty( $status['write_eligible'] ) ) return new WP_Error( 'mad4b_provider_canary_eligibility_invalid', 'Capability is not an isolated canary-only candidate.' );
		if ( class_exists( 'MAD4B_SCP_Servers' ) && MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $target_ability ) ) return new WP_Error( 'mad4b_provider_canary_target_mounted', 'Canary target must remain absent from the normal mad4b-write projection.' );

		$artifact = isset( $status['artifact']['runtime_artifact_fingerprint'] ) ? self::clean_digest( $status['artifact']['runtime_artifact_fingerprint'] ) : '';
		$contract_digest = isset( $status['capability_contract_digest'] ) ? self::clean_digest( $status['capability_contract_digest'] ) : '';
		$canary_basis_digest = isset( $status['canary_basis_digest'] ) ? self::clean_digest( $status['canary_basis_digest'] ) : '';
		$canary_bootstrap_eligible = ! empty( $status['canary_bootstrap_eligible'] );
		$behavioral = isset( $status['behavioral_evidence'] ) && is_array( $status['behavioral_evidence'] ) ? $status['behavioral_evidence'] : array();
		$receipt = isset( $behavioral['accepted_receipt'] ) && is_array( $behavioral['accepted_receipt'] ) ? $behavioral['accepted_receipt'] : array();
		$behavioral_verified = ! empty( $behavioral['behavioral_verified'] ) && 'verified' === ( isset( $behavioral['state'] ) ? (string) $behavioral['state'] : '' );
		$behavioral_digest = isset( $receipt['evidence_digest'] ) ? self::clean_digest( $receipt['evidence_digest'] ) : '';
		$expected_artifact = self::clean_digest( isset( $input['expected_artifact_fingerprint'] ) ? $input['expected_artifact_fingerprint'] : '' );
		$expected_contract = self::clean_digest( isset( $input['expected_capability_contract_digest'] ) ? $input['expected_capability_contract_digest'] : '' );
		$expected_canary_basis = self::clean_digest( isset( $input['expected_canary_basis_digest'] ) ? $input['expected_canary_basis_digest'] : '' );
		$expected_behavioral = self::clean_digest( isset( $input['expected_behavioral_evidence_digest'] ) ? $input['expected_behavioral_evidence_digest'] : '' );
		if ( '' === $artifact || '' === $expected_artifact || ! hash_equals( $artifact, $expected_artifact ) ) return new WP_Error( 'mad4b_provider_canary_artifact_mismatch', 'Canary request is not bound to the current provider artifact.' );
		if ( '' === $contract_digest || '' === $expected_contract || ! hash_equals( $contract_digest, $expected_contract ) ) return new WP_Error( 'mad4b_provider_canary_contract_mismatch', 'Canary request is not bound to the current capability contract.' );
		if ( '' === $canary_basis_digest || '' === $expected_canary_basis || ! hash_equals( $canary_basis_digest, $expected_canary_basis ) ) return new WP_Error( 'mad4b_provider_canary_basis_mismatch', 'Canary request is not bound to the current exact artifact/structural bootstrap evidence.' );
		if ( ! $canary_bootstrap_eligible && ! $behavioral_verified ) return new WP_Error( 'mad4b_provider_canary_bootstrap_not_eligible', 'High-risk capability has neither exact canary bootstrap evidence nor current trusted behavioral evidence.' );
		if ( $behavioral_verified ) {
			if ( '' === $behavioral_digest || '' === $expected_behavioral || ! hash_equals( $behavioral_digest, $expected_behavioral ) ) return new WP_Error( 'mad4b_provider_canary_behavioral_evidence_mismatch', 'Canary request is not bound to the currently accepted behavioral evidence receipt.' );
		} elseif ( '' !== $expected_behavioral ) {
			return new WP_Error( 'mad4b_provider_canary_behavioral_evidence_mismatch', 'A behavioral evidence digest was supplied but no current trusted receipt exists.' );
		}
		if ( ! method_exists( $adapter, 'supports_canary_execution' ) || true !== $adapter->supports_canary_execution( $target_ability ) || ! method_exists( $adapter, 'execute_canary' ) ) return new WP_Error( 'mad4b_provider_canary_adapter_not_opted_in', 'Provider adapter has not explicitly opted this ability into governed canary execution.' );

		$target_input = isset( $input['target_input'] ) && is_array( $input['target_input'] ) ? $input['target_input'] : null;
		if ( ! is_array( $target_input ) ) return new WP_Error( 'mad4b_provider_canary_target_input_invalid', 'Canary target input must be an object.' );
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && isset( $target_input[ MAD4B_SCP_Staging_Write_Authority::APPROVAL_INPUT_KEY ] ) ) return new WP_Error( 'mad4b_provider_canary_nested_approval_denied', 'Target input may not contain a nested MAD4B approval envelope.' );
		$bounded = self::bounded_json( $target_input, self::MAX_TARGET_INPUT_BYTES );
		if ( is_wp_error( $bounded ) ) return $bounded;

		return array(
			'provider_id' => $provider,
			'capability_id' => $capability_id,
			'target_ability' => $target_ability,
			'candidate' => $candidate,
			'artifact_fingerprint' => $artifact,
			'capability_contract_digest' => $contract_digest,
			'canary_basis_digest' => $canary_basis_digest,
			'behavioral_evidence_digest' => $behavioral_digest,
			'target_input' => $target_input,
			'target_input_digest' => hash( 'sha256', $bounded ),
			'adapter' => $adapter,
		);
	}

	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$context = self::validate_context( $input );
		if ( is_wp_error( $context ) ) return $context;
		$audit_base = array(
			'contract' => self::CONTRACT,
			'provider_id' => $context['provider_id'],
			'capability_id' => $context['capability_id'],
			'target_ability' => $context['target_ability'],
			'candidate_sha' => $context['candidate']['source_commit_sha'],
			'build_fingerprint' => $context['candidate']['build_fingerprint'],
			'artifact_fingerprint' => $context['artifact_fingerprint'],
			'capability_contract_digest' => $context['capability_contract_digest'],
			'canary_basis_digest' => $context['canary_basis_digest'],
			'behavioral_evidence_digest' => $context['behavioral_evidence_digest'],
			'target_input_digest' => $context['target_input_digest'],
		);
		$attempt = self::audit( $audit_base, 'attempt' );
		if ( is_wp_error( $attempt ) ) return new WP_Error( 'mad4b_provider_canary_audit_preflight_failed', 'Canary execution is denied because the append-only attempt evidence could not be persisted.', array( 'reason_code' => $attempt->get_error_code() ) );

		try {
			$result = $context['adapter']->execute_canary( $context['target_ability'], $context['target_input'] );
		} catch ( Throwable $error ) {
			$failure = new WP_Error( 'mad4b_provider_canary_adapter_exception', 'Provider canary adapter threw before a verified result was available.' );
			self::audit( array_merge( $audit_base, array( 'reason_code' => $failure->get_error_code(), 'error_type' => get_class( $error ) ) ), 'failure' );
			return $failure;
		}
		if ( is_wp_error( $result ) ) {
			self::audit( array_merge( $audit_base, array( 'reason_code' => $result->get_error_code() ) ), 'failure' );
			return $result;
		}

		$observation = self::observe_result( $context['adapter'], $context['target_ability'], $result );
		$evidence = array_merge(
			$audit_base,
			array(
				'contract' => self::EVIDENCE_CONTRACT,
				'executed' => true,
				'executed_at' => time(),
				'target_result_digest' => $observation['digest'],
				'target_result_bounded' => $observation['bounded'],
				'target_result_observation_complete' => $observation['complete'],
				'target_result_summary_digest' => $observation['summary_digest'],
				'authorizing' => false,
				'activation_granted' => false,
				'promotion_granted' => false,
			)
		);
		$evidence['evidence_digest'] = self::stable_digest( $evidence );

		$execution_audit = self::audit(
			array_merge(
				$audit_base,
				array(
					'evidence_contract' => self::EVIDENCE_CONTRACT,
					'evidence_digest' => $evidence['evidence_digest'],
					'target_result_digest' => $observation['digest'],
					'target_result_bounded' => $observation['bounded'],
					'target_result_observation_complete' => $observation['complete'],
					'promotion_granted' => false,
				)
			),
			'executed'
		);
		$audit_meta = self::audit_metadata( $execution_audit );

		return array(
			'contract' => self::CONTRACT,
			'canary_execution_evidence' => $evidence,
			'target_result_summary' => $observation['summary'],
			'target_result_disclosure' => 'adapter_safe_summary_only',
			'execution_audit' => $audit_meta,
			'activation_stage_after_execution' => 'canary',
			'promotion_granted' => false,
		);
	}

	/**
	 * Persist authorization correlation only after the one-time approval claim has
	 * been finalized as used. The provider callback never receives ticket/grant
	 * metadata; Authorization owns that control-plane truth and passes only the
	 * completed result back here for append-only correlation.
	 */
	public static function persist_authorized_evidence( array $claim, $result ) {
		if ( ! is_array( $result ) || self::CONTRACT !== ( isset( $result['contract'] ) ? (string) $result['contract'] : '' ) ) return true;
		$execution = isset( $result['canary_execution_evidence'] ) && is_array( $result['canary_execution_evidence'] ) ? $result['canary_execution_evidence'] : array();
		if ( self::EVIDENCE_CONTRACT !== ( isset( $execution['contract'] ) ? (string) $execution['contract'] : '' ) || empty( $execution['executed'] ) ) {
			return new WP_Error( 'mad4b_provider_canary_execution_evidence_invalid', 'Canary execution result is missing the canonical execution evidence required for durable authorization correlation.' );
		}
		if ( empty( $result['execution_audit']['persisted'] ) ) {
			return new WP_Error( 'mad4b_provider_canary_execution_evidence_not_durable', 'Canary side effect completed but its immediate append-only execution evidence was not persisted; promotion evidence is unavailable.' );
		}
		$ticket_id = isset( $claim['approval_ticket_id'] ) ? strtolower( trim( (string) $claim['approval_ticket_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return new WP_Error( 'mad4b_provider_canary_approval_correlation_missing', 'Canary execution requires the exact finalized approval ticket for durable evidence correlation.' );

		$authorized = array(
			'contract' => self::AUTHORIZED_EVIDENCE_CONTRACT,
			'execution_evidence_digest' => isset( $execution['evidence_digest'] ) ? self::clean_digest( $execution['evidence_digest'] ) : '',
			'execution_audit_event_id' => isset( $result['execution_audit']['event_id'] ) ? (string) $result['execution_audit']['event_id'] : '',
			'request_id' => isset( $claim['request_id'] ) ? substr( (string) $claim['request_id'], 0, 100 ) : '',
			'approval_ticket_id' => $ticket_id,
			'agent_public_id' => isset( $claim['agent_public_id'] ) ? (string) $claim['agent_public_id'] : '',
			'grant_id' => isset( $claim['grant_id'] ) ? (int) $claim['grant_id'] : 0,
			'server_id' => isset( $claim['server_id'] ) ? sanitize_key( (string) $claim['server_id'] ) : '',
			'authority_provider' => isset( $claim['provider'] ) ? sanitize_key( (string) $claim['provider'] ) : '',
			'impact' => isset( $claim['impact'] ) ? sanitize_key( (string) $claim['impact'] ) : '',
			'provider_id' => isset( $execution['provider_id'] ) ? (string) $execution['provider_id'] : '',
			'capability_id' => isset( $execution['capability_id'] ) ? (string) $execution['capability_id'] : '',
			'target_ability' => isset( $execution['target_ability'] ) ? (string) $execution['target_ability'] : '',
			'candidate_sha' => isset( $execution['candidate_sha'] ) ? (string) $execution['candidate_sha'] : '',
			'build_fingerprint' => isset( $execution['build_fingerprint'] ) ? (string) $execution['build_fingerprint'] : '',
			'artifact_fingerprint' => isset( $execution['artifact_fingerprint'] ) ? (string) $execution['artifact_fingerprint'] : '',
			'capability_contract_digest' => isset( $execution['capability_contract_digest'] ) ? (string) $execution['capability_contract_digest'] : '',
			'canary_basis_digest' => isset( $execution['canary_basis_digest'] ) ? (string) $execution['canary_basis_digest'] : '',
			'behavioral_evidence_digest' => isset( $execution['behavioral_evidence_digest'] ) ? (string) $execution['behavioral_evidence_digest'] : '',
			'target_input_digest' => isset( $execution['target_input_digest'] ) ? (string) $execution['target_input_digest'] : '',
			'target_result_digest' => isset( $execution['target_result_digest'] ) ? (string) $execution['target_result_digest'] : '',
			'target_result_observation_complete' => ! empty( $execution['target_result_observation_complete'] ),
			'executed_at' => isset( $execution['executed_at'] ) ? (int) $execution['executed_at'] : 0,
			'approval_finalized' => 'used',
			'authorizing' => false,
			'activation_granted' => false,
			'promotion_granted' => false,
		);
		if ( '' === $authorized['execution_evidence_digest'] ) return new WP_Error( 'mad4b_provider_canary_execution_digest_missing', 'Canary execution evidence digest is unavailable for authorization correlation.' );
		$authorized['authorization_binding_digest'] = self::stable_digest( $authorized );
		$entry = self::audit( $authorized, 'authorized_evidence' );
		if ( is_wp_error( $entry ) ) {
			return new WP_Error(
				'mad4b_provider_canary_authorized_evidence_persist_failed',
				'Canary side effect completed and the one-time approval is consumed, but the durable authorization-bound evidence could not be appended. Do not retry this execution.',
				array( 'approval_ticket_finalized' => 'used', 'execution_evidence_digest' => $authorized['execution_evidence_digest'], 'reason_code' => $entry->get_error_code(), 'promotion_granted' => false )
			);
		}
		return array(
			'contract' => self::AUTHORIZED_EVIDENCE_CONTRACT,
			'durable' => true,
			'execution_evidence_digest' => $authorized['execution_evidence_digest'],
			'authorization_binding_digest' => $authorized['authorization_binding_digest'],
			'approval_ticket_id' => $ticket_id,
			'request_id' => $authorized['request_id'],
			'audit_event_id' => isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '',
			'audit_sequence' => isset( $entry['sequence'] ) ? (int) $entry['sequence'] : 0,
			'audit_entry_hash' => isset( $entry['entry_hash'] ) ? (string) $entry['entry_hash'] : '',
			'authorizing' => false,
			'activation_granted' => false,
			'promotion_granted' => false,
		);
	}

	private static function audit_ready() {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) || ! method_exists( 'MAD4B_SCP_Audit', 'storage_status' ) ) return new WP_Error( 'mad4b_provider_canary_audit_unavailable', 'Append-only audit authority is unavailable for canary execution.' );
		$status = MAD4B_SCP_Audit::storage_status();
		if ( ! is_array( $status ) || empty( $status['ready'] ) ) return new WP_Error( 'mad4b_provider_canary_audit_unavailable', 'Append-only audit storage is not ready for canary execution.' );
		return true;
	}

	private static function observe_result( $adapter, $ability_name, $result ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $result );
		$valid_json = is_string( $encoded );
		$digest = $valid_json ? hash( 'sha256', $encoded ) : '';
		$bounded = $valid_json && strlen( $encoded ) <= self::MAX_RESULT_BYTES;
		$complete = $bounded && ( ! is_array( $result ) || self::depth_ok( $result, 0 ) );
		$summary = array();
		if ( is_object( $adapter ) && method_exists( $adapter, 'canary_result_summary' ) ) {
			try { $summary = $adapter->canary_result_summary( $ability_name, $result ); }
			catch ( Throwable $error ) { $summary = array(); }
		}
		if ( ! is_array( $summary ) ) $summary = array();
		$summary_json = self::bounded_json( $summary, self::MAX_SAFE_SUMMARY_BYTES );
		if ( is_wp_error( $summary_json ) ) { $summary = array(); $summary_json = '{}'; }
		return array(
			'digest' => $digest,
			'bounded' => $bounded,
			'complete' => $complete,
			'summary' => $summary,
			'summary_digest' => hash( 'sha256', $summary_json ),
		);
	}

	private static function audit_metadata( $entry ) {
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) return array( 'persisted' => false, 'event_id' => '', 'sequence' => 0, 'entry_hash' => '', 'reason_code' => is_wp_error( $entry ) ? $entry->get_error_code() : 'audit_entry_invalid' );
		return array(
			'persisted' => true,
			'event_id' => isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '',
			'sequence' => isset( $entry['sequence'] ) ? (int) $entry['sequence'] : 0,
			'entry_hash' => isset( $entry['entry_hash'] ) ? (string) $entry['entry_hash'] : '',
			'reason_code' => '',
		);
	}

	private static function current_candidate() {
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : 'unknown';
		$host = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_host() : '';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'write' ) ) return new WP_Error( 'mad4b_provider_canary_nonproduction_only', 'Provider canary execution requires an explicitly enrolled governed non-production site with write authority.' );
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_provider_canary_candidate_unavailable', 'Exact build provenance is unavailable.' );
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		$sha = is_array( $provenance ) && isset( $provenance['source_commit_sha'] ) ? self::clean_sha40( $provenance['source_commit_sha'] ) : '';
		$build = is_array( $provenance ) && isset( $provenance['build_fingerprint'] ) ? self::clean_digest( $provenance['build_fingerprint'] ) : '';
		$ready = is_array( $provenance )
			&& ! empty( $provenance['manifest_present'] )
			&& ! empty( $provenance['manifest_valid'] )
			&& ! empty( $provenance['runtime_manifest_match'] )
			&& empty( $provenance['stale'] )
			&& '' !== $sha
			&& '' !== $build;
		if ( ! $ready ) return new WP_Error( 'mad4b_provider_canary_candidate_unavailable', 'Exact current governed build provenance is not ready.' );
		return array( 'source_commit_sha' => $sha, 'build_fingerprint' => $build, 'environment' => $environment, 'host' => $host );
	}

	private static function resolve_adapter( $provider ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return new WP_Error( 'mad4b_provider_canary_adapter_registry_unavailable', 'Provider adapter registry is unavailable.' );
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		foreach ( $registry->all() as $adapter ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'provider_key' ) ) continue;
			if ( sanitize_key( (string) $adapter->provider_key() ) === $provider ) return $adapter;
		}
		return new WP_Error( 'mad4b_provider_canary_adapter_unavailable', 'No active adapter is bound to the requested provider.' );
	}

	private static function bounded_json( $value, $max_bytes ) {
		if ( is_array( $value ) && ! self::depth_ok( $value, 0 ) ) return new WP_Error( 'mad4b_provider_canary_input_too_deep', 'Canary payload exceeds the maximum nesting depth.' );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value );
		if ( ! is_string( $encoded ) ) return new WP_Error( 'mad4b_provider_canary_json_invalid', 'Canary payload could not be canonicalized.' );
		if ( strlen( $encoded ) > (int) $max_bytes ) return new WP_Error( 'mad4b_provider_canary_payload_too_large', 'Canary payload exceeds the bounded evidence limit.' );
		return $encoded;
	}

	private static function depth_ok( $value, $depth ) {
		if ( $depth > self::MAX_DEPTH ) return false;
		if ( ! is_array( $value ) ) return true;
		foreach ( $value as $item ) if ( is_array( $item ) && ! self::depth_ok( $item, $depth + 1 ) ) return false;
		return true;
	}

	private static function clean_digest( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function clean_sha40( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{40}$/', $value ) ? $value : '';
	}

	private static function stable_digest( $value ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function audit( array $summary, $status ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return new WP_Error( 'mad4b_provider_canary_audit_unavailable', 'Append-only audit authority is unavailable.' );
		return MAD4B_SCP_Audit::record( self::ABILITY, $summary, (string) $status );
	}
}
