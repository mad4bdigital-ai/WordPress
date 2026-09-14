<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed producer for trusted behavioral receipts on drifted bounded writes.
 *
 * The provider write itself remains absent from mad4b-write until this wrapper
 * proves one exact, reversible, artifact-bound mutation and restores the exact
 * before-state. The wrapper is governed non-production only and still runs behind the canonical
 * NHI, one-time approval, budget and execution fence. High-risk writes are not
 * accepted here; they remain owned by the separate Provider Canary lifecycle.
 */
final class MAD4B_SCP_Provider_Behavioral_Recertification {
	const CONTRACT = 'mad4b.provider-behavioral-recertification.v1';
	const ABILITY = 'mad4b/provider-behavioral-recertify';
	const RECEIPT_OPTION = 'mad4b_scp_provider_behavioral_recertification_receipts_v1';
	const VERIFIER_ID = 'mad4b_behavioral_recertification_audit';
	const ISSUER_ID = 'mad4b_control_plane';
	const SIGNATURE_SCHEME = 'hmac-sha256-wp-auth-salt-v1';
	const RECEIPT_TTL = 21600;
	const MAX_RECEIPTS = 64;
	const MAX_TARGET_INPUT_BYTES = 32768;
	const MAX_STATE_BYTES = 262144;

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'mad4b_provider_behavioral_evidence_receipts', array( __CLASS__, 'provide_receipts' ), 20, 2 );
			add_filter( 'mad4b_provider_behavioral_evidence_verifiers', array( __CLASS__, 'provide_verifiers' ), 20, 2 );
		}
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Recertify Bounded Provider Behavior',
				'description' => 'Run one exact governed reversible probe for a structurally compatible bounded provider write, restore the exact before-state, and stage a trusted behavioral receipt after the one-time approval is terminally consumed.',
				'category' => 'mad4b-admin',
				'execute_callback' => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'can_run' ),
				'input_schema' => self::input_schema(),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'write',
						'mad4b_provider_behavioral_recertification' => self::CONTRACT,
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
				'target_input',
			),
			'additionalProperties' => false,
		);
	}

	public static function can_run( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return false;
		if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() ) {
			return new WP_Error( 'mad4b_provider_recertification_mutation_disabled', 'Governed mutation authority is not effective for this request.' );
		}
		$context = self::validate_context( is_array( $input ) ? $input : array() );
		return is_wp_error( $context ) ? $context : true;
	}

	/** Pure/read-only preflight. */
	public static function validate_context( array $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::nonproduction_governed( 'write' ) || ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) {
			return new WP_Error( 'mad4b_provider_recertification_nonproduction_only', 'Provider behavioral recertification requires an explicitly enrolled governed non-production site with write authority.' );
		}
		$audit = self::audit_ready();
		if ( is_wp_error( $audit ) ) return $audit;
		$candidate = self::current_candidate();
		if ( is_wp_error( $candidate ) ) return $candidate;

		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		$capability_id = isset( $input['capability_id'] ) ? strtolower( trim( (string) $input['capability_id'] ) ) : '';
		$target_ability = isset( $input['target_ability'] ) ? trim( (string) $input['target_ability'] ) : '';
		if ( '' === $provider || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider ) ) return new WP_Error( 'mad4b_provider_recertification_provider_invalid', 'An exact cataloged provider id is required.' );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $capability_id ) ) return new WP_Error( 'mad4b_provider_recertification_capability_invalid', 'An exact capability id is required.' );
		if ( ! preg_match( '/^[A-Za-z0-9._\\/-]{3,160}$/', $target_ability ) ) return new WP_Error( 'mad4b_provider_recertification_ability_invalid', 'An exact target ability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) || ! MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $provider ) ) {
			return new WP_Error( 'mad4b_provider_recertification_provider_not_cataloged', 'Provider is not governed by the capability catalog.' );
		}

		$expected_candidate = self::clean_sha40( isset( $input['expected_candidate_sha'] ) ? $input['expected_candidate_sha'] : '' );
		$expected_build = self::clean_digest( isset( $input['expected_build_fingerprint'] ) ? $input['expected_build_fingerprint'] : '' );
		if ( '' === $expected_candidate || ! hash_equals( $candidate['source_commit_sha'], $expected_candidate ) ) return new WP_Error( 'mad4b_provider_recertification_candidate_mismatch', 'Expected candidate SHA does not match the exact live governed build.' );
		if ( '' === $expected_build || ! hash_equals( $candidate['build_fingerprint'], $expected_build ) ) return new WP_Error( 'mad4b_provider_recertification_build_mismatch', 'Expected build fingerprint does not match the exact live governed build.' );

		$adapter = self::resolve_adapter( $provider );
		if ( is_wp_error( $adapter ) ) return $adapter;
		$status = MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $provider, $target_ability, $adapter );
		if ( empty( $status ) ) return new WP_Error( 'mad4b_provider_recertification_capability_not_found', 'Target ability is not bound to a governed capability for this provider.' );
		if ( $capability_id !== ( isset( $status['capability_id'] ) ? (string) $status['capability_id'] : '' ) ) return new WP_Error( 'mad4b_provider_recertification_capability_mismatch', 'Target ability does not belong to the requested capability.' );
		if ( 'bounded_write' !== ( isset( $status['risk'] ) ? (string) $status['risk'] : '' ) ) return new WP_Error( 'mad4b_provider_recertification_risk_invalid', 'Behavioral recertification accepts bounded_write capabilities only; high-risk writes remain on the canary lifecycle.' );
		if ( empty( $status['reversible'] ) || empty( $status['structural_compatible'] ) ) return new WP_Error( 'mad4b_provider_recertification_contract_ineligible', 'The capability must be structurally compatible and explicitly reversible before a behavioral probe.' );
		if ( ! empty( $status['write_eligible'] ) ) return new WP_Error( 'mad4b_provider_recertification_already_eligible', 'The capability is already write-eligible and does not require behavioral recertification.' );
		if ( MAD4B_SCP_Provider_Compatibility_Certification::ACTIVATION_SHADOW !== ( isset( $status['activation_stage'] ) ? (string) $status['activation_stage'] : '' ) ) return new WP_Error( 'mad4b_provider_recertification_stage_invalid', 'Bounded behavioral recertification may run only from shadow stage.' );
		if ( class_exists( 'MAD4B_SCP_Servers' ) && MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $target_ability ) ) return new WP_Error( 'mad4b_provider_recertification_target_mounted', 'The provider target must remain absent from normal mad4b-write while recertification is pending.' );

		$artifact = isset( $status['artifact']['runtime_artifact_fingerprint'] ) ? self::clean_digest( $status['artifact']['runtime_artifact_fingerprint'] ) : '';
		$contract_digest = isset( $status['capability_contract_digest'] ) ? self::clean_digest( $status['capability_contract_digest'] ) : '';
		$expected_artifact = self::clean_digest( isset( $input['expected_artifact_fingerprint'] ) ? $input['expected_artifact_fingerprint'] : '' );
		$expected_contract = self::clean_digest( isset( $input['expected_capability_contract_digest'] ) ? $input['expected_capability_contract_digest'] : '' );
		if ( '' === $artifact || '' === $expected_artifact || ! hash_equals( $artifact, $expected_artifact ) ) return new WP_Error( 'mad4b_provider_recertification_artifact_mismatch', 'Probe request is not bound to the current provider artifact.' );
		if ( '' === $contract_digest || '' === $expected_contract || ! hash_equals( $contract_digest, $expected_contract ) ) return new WP_Error( 'mad4b_provider_recertification_contract_mismatch', 'Probe request is not bound to the current capability contract.' );

		$rollback_contract = method_exists( $adapter, 'reversible_contract_for' ) ? (string) $adapter->reversible_contract_for( $target_ability ) : '';
		if ( '' === $rollback_contract ) return new WP_Error( 'mad4b_provider_recertification_rollback_contract_missing', 'The adapter has not declared a reversible contract for this exact ability.' );
		if ( ! empty( $status['rollback_contract'] ) && ! hash_equals( (string) $status['rollback_contract'], $rollback_contract ) ) return new WP_Error( 'mad4b_provider_recertification_rollback_contract_mismatch', 'Capability rollback contract does not match the adapter contract.' );

		$method = self::method_for_ability( $target_ability );
		if ( '' === $method || ! is_callable( array( $adapter, $method ) ) ) return new WP_Error( 'mad4b_provider_recertification_adapter_not_opted_in', 'The adapter does not expose the exact bounded writer required by this capability contract.' );
		foreach ( array( 'capture_reversible_state', 'read_reversible_state', 'restore_reversible_state' ) as $required_method ) {
			if ( ! is_callable( array( $adapter, $required_method ) ) ) return new WP_Error( 'mad4b_provider_recertification_adapter_not_reversible', 'The adapter does not implement the complete reversible probe contract.' );
		}

		$target_input = isset( $input['target_input'] ) && is_array( $input['target_input'] ) ? $input['target_input'] : null;
		if ( ! is_array( $target_input ) ) return new WP_Error( 'mad4b_provider_recertification_target_input_invalid', 'Probe target input must be an object.' );
		if ( isset( $target_input[ MAD4B_SCP_Staging_Write_Authority::APPROVAL_INPUT_KEY ] ) ) return new WP_Error( 'mad4b_provider_recertification_nested_approval_denied', 'Target input may not contain a nested MAD4B approval envelope.' );
		$target_json = self::bounded_json( $target_input, self::MAX_TARGET_INPUT_BYTES );
		if ( is_wp_error( $target_json ) ) return $target_json;

		return array(
			'provider_id' => $provider,
			'capability_id' => $capability_id,
			'target_ability' => $target_ability,
			'candidate' => $candidate,
			'artifact_fingerprint' => $artifact,
			'capability_contract_digest' => $contract_digest,
			'rollback_contract' => $rollback_contract,
			'target_input' => $target_input,
			'target_input_digest' => hash( 'sha256', $target_json ),
			'adapter' => $adapter,
			'method' => $method,
		);
	}

	public static function run( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$context = self::validate_context( $input );
		if ( is_wp_error( $context ) ) return $context;

		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) ) return $identity;
		$ticket_id = is_array( $identity ) && isset( $identity['approval_ticket_id'] ) ? strtolower( trim( (string) $identity['approval_ticket_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return new WP_Error( 'mad4b_provider_recertification_ticket_missing', 'An exact claimed one-time approval ticket is required for the behavioral probe.' );
		$ticket = class_exists( 'MAD4B_SCP_Approval_Tickets' ) ? MAD4B_SCP_Approval_Tickets::get( $ticket_id ) : null;
		if ( ! is_array( $ticket ) || 'executing' !== (string) ( isset( $ticket['status'] ) ? $ticket['status'] : '' ) ) return new WP_Error( 'mad4b_provider_recertification_ticket_not_claimed', 'Behavioral probe requires the approval ticket to be in executing state.' );
		if ( 'mad4b-write' !== sanitize_key( (string) ( isset( $ticket['server_id'] ) ? $ticket['server_id'] : '' ) ) || self::ABILITY !== (string) ( isset( $ticket['ability_name'] ) ? $ticket['ability_name'] : '' ) ) return new WP_Error( 'mad4b_provider_recertification_ticket_binding_invalid', 'Approval ticket is not bound to the behavioral recertification wrapper.' );

		$binding = MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id );
		if ( empty( $binding ) || ! hash_equals( $context['candidate']['source_commit_sha'], (string) ( isset( $binding['candidate_sha'] ) ? $binding['candidate_sha'] : '' ) ) || ! hash_equals( $context['candidate']['build_fingerprint'], (string) ( isset( $binding['build_fingerprint'] ) ? $binding['build_fingerprint'] : '' ) ) ) {
			return new WP_Error( 'mad4b_provider_recertification_ticket_candidate_mismatch', 'Claimed approval ticket is not bound to the current exact enrolled site candidate.' );
		}

		$audit_base = self::audit_summary_base( $context, $ticket_id );
		$attempt = MAD4B_SCP_Audit::record( 'mad4b/provider-behavioral-recertification-attempt', $audit_base, 'ok' );
		if ( is_wp_error( $attempt ) ) return $attempt;

		$probe = self::probe_and_restore( $context );
		if ( is_wp_error( $probe ) ) {
			$failure = $audit_base;
			$failure['reason_code'] = $probe->get_error_code();
			$failure['rollback_attempted'] = true;
			MAD4B_SCP_Audit::record( 'mad4b/provider-behavioral-recertification-failed', $failure, 'failed' );
			return $probe;
		}

		$summary = array_merge( $audit_base, array(
			'before_sha256' => $probe['before_sha256'],
			'observed_after_sha256' => $probe['after_sha256'],
			'restored_sha256' => $probe['restored_sha256'],
			'provider_result_digest' => $probe['provider_result_digest'],
			'behavioral_passed' => true,
			'rollback_passed' => true,
		) );
		$executed = MAD4B_SCP_Audit::record( 'mad4b/provider-behavioral-recertification-executed', $summary, 'ok' );
		if ( is_wp_error( $executed ) ) return $executed;

		$pending = self::pending_record( $context, $ticket_id, $executed, $probe );
		if ( is_wp_error( $pending ) ) return $pending;
		$saved = self::save_pending_record( $pending );
		if ( is_wp_error( $saved ) ) return $saved;

		if ( class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => $context['provider_id'],
			'capability_id' => $context['capability_id'],
			'target_ability' => $context['target_ability'],
			'probe_executed' => true,
			'behavioral_passed' => true,
			'rollback_verified' => true,
			'before_sha256' => $probe['before_sha256'],
			'observed_after_sha256' => $probe['after_sha256'],
			'restored_sha256' => $probe['restored_sha256'],
			'receipt_pending_approval_finalization' => true,
			'activation_granted' => false,
			'mutation_granted' => false,
			'authorizing' => false,
		);
	}

	private static function probe_and_restore( array $context ) {
		$adapter = $context['adapter'];
		$ability = $context['target_ability'];
		$input = $context['target_input'];
		$before = $adapter->capture_reversible_state( $ability, $input );
		if ( is_wp_error( $before ) ) return $before;
		$before = self::bounded_snapshot( $before );
		if ( is_wp_error( $before ) ) return $before;
		$before_hash = self::state_hash( $before['state'] );

		try {
			$result = call_user_func( array( $adapter, $context['method'] ), $input );
		} catch ( Throwable $error ) {
			return self::recover_after_probe_failure( $adapter, $ability, $before, $before_hash, new WP_Error( 'mad4b_provider_recertification_provider_exception', 'Bounded provider probe threw before successful verification.' ) );
		}
		if ( is_wp_error( $result ) ) return self::recover_after_probe_failure( $adapter, $ability, $before, $before_hash, $result );

		$after = $adapter->read_reversible_state( $ability, $before['target'] );
		if ( is_wp_error( $after ) || ! is_array( $after ) ) return self::recover_after_probe_failure( $adapter, $ability, $before, $before_hash, new WP_Error( 'mad4b_provider_recertification_readback_failed', 'Provider probe completed but bounded readback failed.' ) );
		$after_json = self::bounded_json( $after, self::MAX_STATE_BYTES );
		if ( is_wp_error( $after_json ) ) return self::recover_after_probe_failure( $adapter, $ability, $before, $before_hash, $after_json );
		$after_hash = hash( 'sha256', $after_json );
		if ( hash_equals( $before_hash, $after_hash ) ) return new WP_Error( 'mad4b_provider_recertification_no_effect', 'Behavioral probe produced no observable bounded state change; no compatibility receipt was issued.' );

		$restored = $adapter->restore_reversible_state( $ability, $before['target'], $before['state'], array( 'recertification' => true, 'provider' => $context['provider_id'], 'ability_name' => $ability ) );
		if ( is_wp_error( $restored ) || true !== $restored ) return new WP_Error( 'mad4b_provider_recertification_rollback_failed', 'Provider behavioral probe changed state but exact rollback failed. No compatibility receipt was issued.' );
		$final = $adapter->read_reversible_state( $ability, $before['target'] );
		if ( is_wp_error( $final ) || ! is_array( $final ) ) return new WP_Error( 'mad4b_provider_recertification_rollback_readback_failed', 'Rollback completed but exact readback could not be verified. No compatibility receipt was issued.' );
		$final_json = self::bounded_json( $final, self::MAX_STATE_BYTES );
		if ( is_wp_error( $final_json ) ) return $final_json;
		$restored_hash = hash( 'sha256', $final_json );
		if ( ! hash_equals( $before_hash, $restored_hash ) ) return new WP_Error( 'mad4b_provider_recertification_rollback_mismatch', 'Rollback readback did not match the exact captured before-state. No compatibility receipt was issued.' );

		$result_json = self::bounded_json( $result, self::MAX_STATE_BYTES );
		$result_digest = is_wp_error( $result_json ) ? hash( 'sha256', gettype( $result ) ) : hash( 'sha256', $result_json );
		return array( 'before_sha256' => $before_hash, 'after_sha256' => $after_hash, 'restored_sha256' => $restored_hash, 'provider_result_digest' => $result_digest );
	}

	private static function recover_after_probe_failure( $adapter, $ability, array $before, $before_hash, $error ) {
		$current = $adapter->read_reversible_state( $ability, $before['target'] );
		$current_hash = is_array( $current ) ? self::state_hash( $current ) : '';
		if ( '' !== $current_hash && hash_equals( $before_hash, $current_hash ) ) return $error;

		$restored = $adapter->restore_reversible_state( $ability, $before['target'], $before['state'], array( 'recertification_recovery' => true, 'ability_name' => $ability ) );
		if ( is_wp_error( $restored ) || true !== $restored ) return new WP_Error( 'mad4b_provider_recertification_failure_rollback_failed', 'Provider probe failed after a possible side effect and recovery rollback failed. No compatibility receipt was issued.', array( 'provider_error' => is_wp_error( $error ) ? $error->get_error_code() : 'probe_failed' ) );
		$final = $adapter->read_reversible_state( $ability, $before['target'] );
		$final_hash = is_array( $final ) ? self::state_hash( $final ) : '';
		if ( '' === $final_hash || ! hash_equals( $before_hash, $final_hash ) ) return new WP_Error( 'mad4b_provider_recertification_failure_rollback_unverified', 'Provider probe failed and recovery rollback could not be verified. No compatibility receipt was issued.' );
		return $error;
	}

	private static function pending_record( array $context, $ticket_id, array $audit_entry, array $probe ) {
		$issued_at = time();
		$expires_at = $issued_at + self::RECEIPT_TTL;
		$canonical = array(
			'contract' => MAD4B_SCP_Provider_Behavioral_Evidence::RECEIPT_CONTRACT,
			'provider_id' => $context['provider_id'],
			'capability_id' => $context['capability_id'],
			'artifact_fingerprint' => $context['artifact_fingerprint'],
			'capability_contract_digest' => $context['capability_contract_digest'],
			'verifier_id' => self::VERIFIER_ID,
			'issuer_id' => self::ISSUER_ID,
			'issued_at' => $issued_at,
			'expires_at' => $expires_at,
			'observations' => array( 'behavioral_passed' => true, 'rollback_passed' => true ),
		);
		$evidence_digest = self::stable_digest( $canonical );
		if ( '' === $evidence_digest ) return new WP_Error( 'mad4b_provider_recertification_receipt_digest_failed', 'Behavioral receipt digest could not be generated.' );
		$record = array_merge( $canonical, array(
			'evidence_digest' => $evidence_digest,
			'ticket_id' => $ticket_id,
			'audit_event_id' => isset( $audit_entry['event_id'] ) ? (string) $audit_entry['event_id'] : '',
			'audit_entry_hash' => isset( $audit_entry['entry_hash'] ) ? strtolower( (string) $audit_entry['entry_hash'] ) : '',
			'candidate_sha' => $context['candidate']['source_commit_sha'],
			'build_fingerprint' => $context['candidate']['build_fingerprint'],
			'target_ability' => $context['target_ability'],
			'target_input_digest' => $context['target_input_digest'],
			'probe_digest' => self::stable_digest( $probe ),
		) );
		if ( ! preg_match( '/^[A-Fa-f0-9-]{36}$/', $record['audit_event_id'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $record['audit_entry_hash'] ) || '' === $record['probe_digest'] ) return new WP_Error( 'mad4b_provider_recertification_audit_binding_invalid', 'Behavioral receipt could not bind to durable append-only audit evidence.' );
		$key = self::signing_key();
		if ( is_wp_error( $key ) ) return $key;
		$record['signature'] = hash_hmac( 'sha256', self::signature_message( $record ), $key );
		return $record;
	}

	private static function save_pending_record( array $record ) {
		$items = get_option( self::RECEIPT_OPTION, array() );
		$items = is_array( $items ) ? $items : array();
		$now = time();
		$kept = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['expires_at'] ) || (int) $item['expires_at'] < $now ) continue;
			$kept[] = $item;
		}
		$kept[] = $record;
		usort( $kept, static function ( $a, $b ) { return (int) ( isset( $b['issued_at'] ) ? $b['issued_at'] : 0 ) <=> (int) ( isset( $a['issued_at'] ) ? $a['issued_at'] : 0 ); } );
		$kept = array_slice( $kept, 0, self::MAX_RECEIPTS );
		$updated = update_option( self::RECEIPT_OPTION, $kept, false );
		$stored = get_option( self::RECEIPT_OPTION, array() );
		if ( false === $updated && ( ! is_array( $stored ) || ! self::record_present( $stored, $record['evidence_digest'] ) ) ) return new WP_Error( 'mad4b_provider_recertification_receipt_persist_failed', 'Behavioral receipt evidence could not be persisted after successful rollback.' );
		return true;
	}

	public static function provide_receipts( $receipts, $context ) {
		$receipts = is_array( $receipts ) ? $receipts : array();
		if ( ! is_array( $context ) ) return $receipts;
		$items = get_option( self::RECEIPT_OPTION, array() );
		if ( ! is_array( $items ) ) return $receipts;
		foreach ( array_slice( $items, 0, self::MAX_RECEIPTS ) as $record ) {
			if ( ! is_array( $record ) || ! self::record_matches_context( $record, $context ) ) continue;
			if ( ! self::record_is_terminally_authorized( $record ) ) continue;
			$receipts[] = $record;
		}
		return array_slice( $receipts, 0, MAD4B_SCP_Provider_Behavioral_Evidence::MAX_RECEIPTS );
	}

	public static function provide_verifiers( $verifiers, $context ) {
		$verifiers = is_array( $verifiers ) ? $verifiers : array();
		$verifiers[ self::VERIFIER_ID ] = array(
			'verifier_id' => self::VERIFIER_ID,
			'issuer_id' => self::ISSUER_ID,
			'scopes' => array( 'behavioral', 'rollback' ),
			'signature_scheme' => self::SIGNATURE_SCHEME,
			'trusted' => true,
			'read_only_verifier' => true,
			'authorizing' => false,
			'verify_callback' => array( __CLASS__, 'verify_receipt' ),
		);
		return $verifiers;
	}

	public static function verify_receipt( $receipt, $context, $canonical ) {
		if ( ! is_array( $receipt ) || ! is_array( $canonical ) ) return array( 'verified' => false, 'evidence_digest' => '' );
		$evidence_digest = self::clean_digest( isset( $receipt['evidence_digest'] ) ? $receipt['evidence_digest'] : '' );
		if ( '' === $evidence_digest || ! hash_equals( $evidence_digest, self::stable_digest( $canonical ) ) ) return array( 'verified' => false, 'evidence_digest' => $evidence_digest );
		if ( ! self::record_is_terminally_authorized( $receipt ) ) return array( 'verified' => false, 'evidence_digest' => $evidence_digest );
		$key = self::signing_key();
		if ( is_wp_error( $key ) ) return array( 'verified' => false, 'evidence_digest' => $evidence_digest );
		$signature = isset( $receipt['signature'] ) ? strtolower( trim( (string) $receipt['signature'] ) ) : '';
		$expected = hash_hmac( 'sha256', self::signature_message( $receipt ), $key );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) || ! hash_equals( $expected, $signature ) ) return array( 'verified' => false, 'evidence_digest' => $evidence_digest );
		if ( ! self::audit_entry_matches( $receipt ) ) return array( 'verified' => false, 'evidence_digest' => $evidence_digest );
		return array( 'verified' => true, 'evidence_digest' => $evidence_digest );
	}

	private static function record_is_terminally_authorized( array $record ) {
		if ( ! self::record_not_expired( $record ) ) return false;
		$ticket_id = isset( $record['ticket_id'] ) ? strtolower( trim( (string) $record['ticket_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) || ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) return false;
		$ticket = MAD4B_SCP_Approval_Tickets::get( $ticket_id );
		if ( ! is_array( $ticket ) || 'used' !== (string) ( isset( $ticket['status'] ) ? $ticket['status'] : '' ) ) return false;
		if ( 'mad4b-write' !== sanitize_key( (string) ( isset( $ticket['server_id'] ) ? $ticket['server_id'] : '' ) ) || self::ABILITY !== (string) ( isset( $ticket['ability_name'] ) ? $ticket['ability_name'] : '' ) ) return false;
		$binding = MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id );
		if ( empty( $binding ) || ! hash_equals( (string) ( isset( $record['candidate_sha'] ) ? $record['candidate_sha'] : '' ), (string) ( isset( $binding['candidate_sha'] ) ? $binding['candidate_sha'] : '' ) ) || ! hash_equals( (string) ( isset( $record['build_fingerprint'] ) ? $record['build_fingerprint'] : '' ), (string) ( isset( $binding['build_fingerprint'] ) ? $binding['build_fingerprint'] : '' ) ) ) return false;
		$candidate = self::current_candidate();
		return is_array( $candidate ) && hash_equals( $candidate['source_commit_sha'], (string) $record['candidate_sha'] ) && hash_equals( $candidate['build_fingerprint'], (string) $record['build_fingerprint'] );
	}

	private static function audit_entry_matches( array $record ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! class_exists( 'MAD4B_SCP_Audit' ) ) return false;
		$storage = MAD4B_SCP_Audit::storage_status();
		if ( ! is_array( $storage ) || empty( $storage['ready'] ) ) return false;
		$t = MAD4B_SCP_Schema::tables();
		if ( empty( $t['audit_events'] ) ) return false;
		$event_id = isset( $record['audit_event_id'] ) ? (string) $record['audit_event_id'] : '';
		$entry_hash = isset( $record['audit_entry_hash'] ) ? strtolower( (string) $record['audit_entry_hash'] ) : '';
		if ( ! preg_match( '/^[A-Fa-f0-9-]{36}$/', $event_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $entry_hash ) ) return false;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT event_id, entry_hash, ability, status FROM {$t['audit_events']} WHERE event_id = %s LIMIT 1", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return is_array( $row )
			&& hash_equals( $event_id, (string) $row['event_id'] )
			&& hash_equals( $entry_hash, strtolower( (string) $row['entry_hash'] ) )
			&& 'mad4b/provider-behavioral-recertification-executed' === (string) $row['ability']
			&& 'ok' === (string) $row['status'];
	}

	private static function record_matches_context( array $record, array $context ) {
		return isset( $context['provider_id'], $context['capability_id'], $context['artifact_fingerprint'], $context['capability_contract_digest'] )
			&& hash_equals( (string) $context['provider_id'], (string) ( isset( $record['provider_id'] ) ? $record['provider_id'] : '' ) )
			&& hash_equals( (string) $context['capability_id'], (string) ( isset( $record['capability_id'] ) ? $record['capability_id'] : '' ) )
			&& hash_equals( (string) $context['artifact_fingerprint'], (string) ( isset( $record['artifact_fingerprint'] ) ? $record['artifact_fingerprint'] : '' ) )
			&& hash_equals( (string) $context['capability_contract_digest'], (string) ( isset( $record['capability_contract_digest'] ) ? $record['capability_contract_digest'] : '' ) );
	}

	private static function record_not_expired( array $record ) {
		return isset( $record['issued_at'], $record['expires_at'] ) && (int) $record['issued_at'] > 0 && (int) $record['expires_at'] >= time() && (int) $record['expires_at'] > (int) $record['issued_at'] && (int) $record['expires_at'] - (int) $record['issued_at'] <= MAD4B_SCP_Provider_Behavioral_Evidence::MAX_RECEIPT_TTL;
	}

	private static function signature_message( array $record ) {
		$keys = array( 'evidence_digest', 'ticket_id', 'audit_event_id', 'audit_entry_hash', 'candidate_sha', 'build_fingerprint', 'probe_digest', 'provider_id', 'capability_id', 'artifact_fingerprint', 'capability_contract_digest', 'target_ability', 'target_input_digest', 'issued_at', 'expires_at' );
		$parts = array( self::CONTRACT );
		foreach ( $keys as $key ) $parts[] = isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ? (string) $record[ $key ] : '';
		return implode( "\n", $parts );
	}

	private static function signing_key() {
		if ( ! function_exists( 'wp_salt' ) ) return new WP_Error( 'mad4b_provider_recertification_signing_key_unavailable', 'WordPress signing key is unavailable.' );
		$key = (string) wp_salt( 'auth' );
		return strlen( $key ) >= 32 ? $key : new WP_Error( 'mad4b_provider_recertification_signing_key_invalid', 'WordPress signing key is too short.' );
	}

	private static function audit_summary_base( array $context, $ticket_id ) {
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => $context['provider_id'],
			'capability_id' => $context['capability_id'],
			'target_ability' => $context['target_ability'],
			'artifact_fingerprint' => $context['artifact_fingerprint'],
			'capability_contract_digest' => $context['capability_contract_digest'],
			'rollback_contract' => $context['rollback_contract'],
			'target_input_digest' => $context['target_input_digest'],
			'candidate_sha' => $context['candidate']['source_commit_sha'],
			'build_fingerprint' => $context['candidate']['build_fingerprint'],
			'approval_ticket_id' => $ticket_id,
		);
	}

	private static function resolve_adapter( $provider ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return new WP_Error( 'mad4b_provider_recertification_adapter_registry_unavailable', 'Adapter registry is unavailable.' );
		$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( $provider );
		if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base || ! method_exists( $adapter, 'provider_key' ) || ! hash_equals( $provider, (string) $adapter->provider_key() ) ) return new WP_Error( 'mad4b_provider_recertification_adapter_unavailable', 'No exact provider adapter is available for bounded recertification.' );
		return $adapter;
	}

	private static function method_for_ability( $ability ) {
		$parts = explode( '/', (string) $ability );
		$slug = (string) end( $parts );
		if ( ! preg_match( '/^[a-z][a-z0-9-]{1,79}$/', $slug ) ) return '';
		$method = str_replace( '-', '_', $slug );
		return preg_match( '/^[a-z][a-z0-9_]{1,79}$/', $method ) ? $method : '';
	}

	private static function current_candidate() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_provider_recertification_candidate_unavailable', 'Exact build provenance is unavailable.' );
		$status = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		$sha = is_array( $status ) && isset( $status['source_commit_sha'] ) ? self::clean_sha40( $status['source_commit_sha'] ) : '';
		$build = is_array( $status ) && isset( $status['build_fingerprint'] ) ? self::clean_digest( $status['build_fingerprint'] ) : '';
		if ( '' === $sha || '' === $build || empty( $status['manifest_present'] ) || empty( $status['manifest_valid'] ) || empty( $status['runtime_manifest_match'] ) || ! empty( $status['stale'] ) ) return new WP_Error( 'mad4b_provider_recertification_candidate_unavailable', 'Behavioral recertification requires an exact non-stale governed build provenance.' );
		return array( 'source_commit_sha' => $sha, 'build_fingerprint' => $build );
	}

	private static function audit_ready() {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return new WP_Error( 'mad4b_provider_recertification_audit_unavailable', 'Append-only audit service is unavailable.' );
		$status = MAD4B_SCP_Audit::storage_status();
		return is_array( $status ) && ! empty( $status['ready'] ) ? true : new WP_Error( 'mad4b_provider_recertification_audit_unavailable', 'Append-only audit storage must be ready before a provider probe.' );
	}

	private static function bounded_snapshot( $snapshot ) {
		if ( ! is_array( $snapshot ) || empty( $snapshot['target_type'] ) || ! isset( $snapshot['target_id'], $snapshot['target'], $snapshot['state'] ) || ! is_array( $snapshot['target'] ) || ! is_array( $snapshot['state'] ) ) return new WP_Error( 'mad4b_provider_recertification_snapshot_invalid', 'Adapter probe snapshot is incomplete.' );
		$bounded = self::bounded_json( array( 'target' => $snapshot['target'], 'state' => $snapshot['state'] ), self::MAX_STATE_BYTES );
		if ( is_wp_error( $bounded ) ) return $bounded;
		return $snapshot;
	}

	private static function bounded_json( $value, $max_bytes ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) return new WP_Error( 'mad4b_provider_recertification_json_invalid', 'Behavioral probe data could not be encoded.' );
		if ( strlen( $json ) > (int) $max_bytes ) return new WP_Error( 'mad4b_provider_recertification_data_too_large', 'Behavioral probe data exceeds the bounded size limit.' );
		return $json;
	}

	private static function state_hash( array $state ) {
		$json = self::bounded_json( $state, self::MAX_STATE_BYTES );
		return is_wp_error( $json ) ? '' : hash( 'sha256', $json );
	}

	private static function stable_digest( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function clean_digest( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function clean_sha40( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{40}$/', $value ) ? $value : '';
	}

	private static function record_present( array $items, $digest ) {
		foreach ( $items as $item ) if ( is_array( $item ) && isset( $item['evidence_digest'] ) && hash_equals( (string) $digest, (string) $item['evidence_digest'] ) ) return true;
		return false;
	}
}

/** Internal write-only projection for the recertification wrapper. */
final class MAD4B_SCP_Provider_Behavioral_Recertification_Adapter extends MAD4B_SCP_Adapter_Base {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ), 6 );
	}
	public static function register_with_registry( $registry ) { if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() ); }
	public function id() { return 'provider-behavioral-recertification'; }
	public function label() { return 'Provider Behavioral Recertification'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array(), 'write' => array( MAD4B_SCP_Provider_Behavioral_Recertification::ABILITY ) ); }
	public function register_abilities() { MAD4B_SCP_Provider_Behavioral_Recertification::register_ability(); }
	protected function certified_provider_key() { return 'core'; }
	protected function provider_certification( $available ) { return null; }
	protected function mutation_requires_certification() { return false; }
}
