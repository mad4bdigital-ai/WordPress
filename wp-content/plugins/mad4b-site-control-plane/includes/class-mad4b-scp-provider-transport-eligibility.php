<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic provider transport eligibility precedence.
 *
 * This class is not an authority source. It only combines independently owned
 * facts and makes the strongest deny observable before provider mutation
 * transport begins. Circuit-breaker state is intentionally evaluated last.
 */
final class MAD4B_SCP_Provider_Transport_Eligibility {
	const CONTRACT = 'mad4b.provider-transport-eligibility.v1';
	const R0 = 'R0_DISPOSABLE';
	const R1 = 'R1_CANARY_STAGING';
	const R2 = 'R2_SELECTED_STAGING';
	const R3 = 'R3_GENERAL_STAGING';
	const R4 = 'R4_PRODUCTION_ELIGIBLE';

	private static $precedence = array(
		'kill_switch',
		'authority',
		'quarantine',
		'certification',
		'release_ring',
		'breaker',
	);

	public static function precedence() { return self::$precedence; }

	/**
	 * Non-authorizing snapshot of an exact provider capability's static gates.
	 * Caller authorization and circuit-breaker admission are intentionally NOT
	 * evaluated in a read request; neither a green snapshot nor a stale record
	 * is permission to dispatch or replay a mutation.
	 */
	public static function inspect_target_certification( $surface, $target ) {
		$surface = sanitize_key( (string) $surface );
		$target = trim( (string) $target );
		$blocked = array(
			'contract' => 'mad4b.provider-transport-static-inspection.v1',
			'target' => $target, 'applicable' => false,
			'evidence_available' => false, 'static_certification_ready' => false,
			'release_ring_eligible' => false, 'authorization_checked' => false,
			'breaker_checked' => false, 'transport_eligible' => null,
			'authorizing' => false, 'mutation_performed' => false,
			'next_action' => 'inspect_exact_provider_capability_evidence',
		);
		if ( '' === $target || ! in_array( $surface, array( 'write', 'content', 'admin' ), true ) )
			return array_merge( $blocked, array( 'reason_code' => 'invalid_exact_target' ) );
		if ( ! class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' )
			|| ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) )
			return array_merge( $blocked, array( 'reason_code' => 'provider_inspection_unavailable' ) );
		$context = MAD4B_SCP_Provider_Circuit_Breaker::target_context( $surface, $target );
		if ( is_wp_error( $context ) )
			return array_merge( $blocked, array(
				'reason_code' => sanitize_key( (string) $context->get_error_code() ),
			) );
		if ( ! is_array( $context ) || empty( $context['applicable'] ) )
			return array_merge( $blocked, array( 'reason_code' => 'provider_gate_not_applicable' ) );
		$provider = isset( $context['provider_id'] ) ? sanitize_key( (string) $context['provider_id'] ) : '';
		if ( '' === $provider ) return array_merge( $blocked, array( 'reason_code' => 'provider_identity_missing' ) );
		$status = MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $provider, $target );
		if ( ! is_array( $status ) || empty( $status ) )
			return array_merge( $blocked, array(
				'applicable' => true, 'provider_id' => $provider,
				'reason_code' => 'capability_not_cataloged',
			) );
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' )
			? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : 'unknown';
		$level = isset( $status['certification_level'] ) ? (string) $status['certification_level'] : '';
		$ring = self::release_ring_for_status( $status, $environment );
		$quarantined = defined( 'MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_QUARANTINED' )
			&& $level === MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_QUARANTINED;
		$certified = ! $quarantined && ! empty( $status['structural_compatible'] )
			&& ! empty( $status['artifact_authority_bound'] )
			&& in_array( $level, array(
				MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_BOUNDED_WRITE,
				MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_REVERSIBLE_WRITE,
				MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_FULL,
			), true );
		$ring_allowed = self::release_ring_allows_normal_mutation( $ring, $environment );
		$reason = $quarantined ? 'provider_capability_quarantined'
			: ( ! $certified ? 'provider_capability_not_write_certified'
			: ( ! $ring_allowed ? 'provider_release_ring_ineligible' : 'static_gates_satisfied_only' ) );
		return array_merge( $blocked, array(
			'applicable' => true, 'evidence_available' => true,
			'provider_id' => $provider,
			'environment' => $environment,
			'certification_level' => sanitize_key( $level ),
			'activation_stage' => isset( $status['activation_stage'] )
				? sanitize_key( (string) $status['activation_stage'] ) : '',
			'behavioral_evidence_state' => isset( $status['behavioral_evidence']['state'] )
				? sanitize_key( (string) $status['behavioral_evidence']['state'] ) : '',
			'artifact_authority_bound' => ! empty( $status['artifact_authority_bound'] ),
			'structural_compatible' => ! empty( $status['structural_compatible'] ),
			'quarantined' => $quarantined,
			'static_certification_ready' => $certified,
			'release_ring' => $ring, 'release_ring_eligible' => $ring_allowed,
			'reason_code' => $reason,
			'next_action' => ! $certified ? 'certify_exact_provider_capability'
				: ( ! $ring_allowed ? 'review_exact_release_ring'
					: 'verify_separate_write_authority_and_breaker' ),
		) );
	}

	public static function resolve_facts( array $facts ) {
		$required = array(
			'kill_switch_active',
			'authority_allowed',
			'quarantined',
			'certification_allowed',
			'release_ring_allowed',
			'breaker_allowed',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $facts ) || ! is_bool( $facts[ $key ] ) ) {
				return new WP_Error( 'mad4b_provider_eligibility_fact_invalid', 'Provider eligibility fact is missing or invalid: ' . $key );
			}
		}
		$checks = array(
			'kill_switch' => ! empty( $facts['kill_switch_active'] ),
			'authority' => empty( $facts['authority_allowed'] ),
			'quarantine' => ! empty( $facts['quarantined'] ),
			'certification' => empty( $facts['certification_allowed'] ),
			'release_ring' => empty( $facts['release_ring_allowed'] ),
			'breaker' => empty( $facts['breaker_allowed'] ),
		);
		$reason_codes = array(
			'kill_switch' => 'mutation_kill_switch_active',
			'authority' => 'mutation_authority_denied',
			'quarantine' => 'provider_capability_quarantined',
			'certification' => 'provider_capability_not_write_certified',
			'release_ring' => 'provider_release_ring_ineligible',
			'breaker' => 'provider_circuit_breaker_blocked',
		);
		$matched = array();
		$decision = 'ALLOW';
		$reason = 'provider_transport_eligible';
		$blocking_gate = '';
		foreach ( self::$precedence as $level ) {
			$blocked = ! empty( $checks[ $level ] );
			$matched[] = array( 'precedence'=>$level, 'blocked'=>$blocked, 'reason_code'=>$reason_codes[ $level ] );
			if ( $blocked ) {
				$decision = 'DENY';
				$reason = $reason_codes[ $level ];
				$blocking_gate = $level;
				break;
			}
		}
		return array(
			'contract'=>self::CONTRACT,
			'decision'=>$decision,
			'reason_code'=>$reason,
			'blocking_gate'=>$blocking_gate,
			'client_action'=>'DENY' === $decision ? 'inspect_provider_transport_eligibility_then_replan' : 'none',
			'transport_eligible'=>'ALLOW' === $decision,
			'precedence_chain'=>self::$precedence,
			'matched_rules'=>$matched,
			'provider_id'=>isset($facts['provider_id'])?sanitize_key((string)$facts['provider_id']):'',
			'ability_name'=>isset($facts['ability_name'])?(string)$facts['ability_name']:'',
			'environment'=>isset($facts['environment'])?sanitize_key((string)$facts['environment']):'',
			'release_ring'=>isset($facts['release_ring'])?(string)$facts['release_ring']:'',
			'certification_level'=>isset($facts['certification_level'])?(string)$facts['certification_level']:'',
			'activation_stage'=>isset($facts['activation_stage'])?(string)$facts['activation_stage']:'',
			'authority_effect'=>'none',
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
	}

	public static function preflight_mutation( $surface, $target ) {
		$surface = sanitize_key( (string) $surface );
		$target = trim( (string) $target );
		if ( '' === $target ) return new WP_Error( 'mad4b_provider_eligibility_target_invalid', 'Provider transport eligibility requires an exact target Ability.' );
		if ( ! class_exists( 'MAD4B_SCP_Provider_Circuit_Breaker' ) || ! method_exists( 'MAD4B_SCP_Provider_Circuit_Breaker', 'target_context' ) ) {
			return new WP_Error( 'mad4b_provider_eligibility_context_unavailable', 'Provider target context is unavailable.' );
		}
		$context = MAD4B_SCP_Provider_Circuit_Breaker::target_context( $surface, $target );
		if ( is_wp_error( $context ) ) return $context;
		if ( empty( $context['applicable'] ) ) {
			return array(
				'contract'=>self::CONTRACT,'applicable'=>false,'transport_eligible'=>true,
				'authority_effect'=>'none','authorizing'=>false,'mutation_performed'=>false,
			);
		}
		if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) {
			return new WP_Error( 'mad4b_provider_eligibility_certification_unavailable', 'Provider capability certification is unavailable.' );
		}
		$provider = isset( $context['provider_id'] ) ? sanitize_key( (string) $context['provider_id'] ) : '';
		$status = MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $provider, $target );
		$gate = class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'mutation_gate_status' )
			? MAD4B_SCP_Policy::mutation_gate_status() : array();
		$environment = isset( $gate['environment'] ) ? sanitize_key( (string) $gate['environment'] )
			: ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : 'unknown' );
		$level = isset( $status['certification_level'] ) ? (string) $status['certification_level'] : '';
		$activation = isset( $status['activation_stage'] ) ? (string) $status['activation_stage'] : '';
		$quarantined = defined( 'MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_QUARANTINED' )
			&& MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_QUARANTINED === $level;
		$write_levels = array(
			MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_BOUNDED_WRITE,
			MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_REVERSIBLE_WRITE,
			MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_FULL,
		);
		$certification_allowed = ! $quarantined
			&& ! empty( $status['structural_compatible'] )
			&& ! empty( $status['artifact_authority_bound'] )
			&& in_array( $level, $write_levels, true );
		$release_ring = self::release_ring_for_status( $status, $environment );
		$release_allowed = self::release_ring_allows_normal_mutation( $release_ring, $environment );
		$facts = array(
			'kill_switch_active'=>! empty( $gate['explicit_kill_switch'] ),
			'authority_allowed'=>class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_mutate(),
			'quarantined'=>$quarantined,
			'certification_allowed'=>$certification_allowed,
			'release_ring_allowed'=>$release_allowed,
			'breaker_allowed'=>true,
			'provider_id'=>$provider,
			'ability_name'=>$target,
			'environment'=>$environment,
			'release_ring'=>$release_ring,
			'certification_level'=>$level,
			'activation_stage'=>$activation,
		);
		$resolution = self::resolve_facts( $facts );
		if ( is_wp_error( $resolution ) ) return $resolution;
		if ( empty( $resolution['transport_eligible'] ) ) {
			return new WP_Error(
				'mad4b_provider_transport_precedence_denied',
				'Provider mutation transport was not started. Blocking gate: ' . $resolution['blocking_gate'] . '. Blocker: ' . $resolution['reason_code'] . '. Inspect this independent provider gate before creating a fresh plan.',
				array_merge( $resolution, array(
					'provider_context'=>$context,
					'capability_status'=>$status,
					'blind_retry_allowed'=>false,
					'mutation_state'=>'not_started',
					'target_execution_entered'=>false,
					'reconciliation_required'=>false,
					'fresh_plan_required'=>true,
				) )
			);
		}
		$resolution['applicable'] = true;
		$resolution['provider_context'] = $context;
		$resolution['facts'] = $facts;
		return $resolution;
	}

	public static function finalize_breaker( array $preflight, array $breaker ) {
		if ( empty( $preflight['applicable'] ) ) return $preflight;
		$facts = isset( $preflight['facts'] ) && is_array( $preflight['facts'] ) ? $preflight['facts'] : array();
		if ( empty( $facts ) ) return new WP_Error( 'mad4b_provider_eligibility_preflight_missing', 'Provider eligibility preflight evidence is missing.' );
		$facts['breaker_allowed'] = ! empty( $breaker['transport_eligible'] );
		$resolution = self::resolve_facts( $facts );
		if ( is_wp_error( $resolution ) ) return $resolution;
		$resolution['applicable'] = true;
		$resolution['breaker'] = $breaker;
		if ( empty( $resolution['transport_eligible'] ) ) {
			return new WP_Error(
				'mad4b_provider_transport_precedence_denied',
				'Provider mutation transport was not started. Blocking gate: ' . $resolution['blocking_gate'] . '. Blocker: ' . $resolution['reason_code'] . '. Inspect the circuit breaker before creating a fresh plan.',
				array_merge( $resolution, array( 'blind_retry_allowed'=>false, 'mutation_state'=>'not_started', 'target_execution_entered'=>false, 'reconciliation_required'=>false, 'fresh_plan_required'=>true ) )
			);
		}
		return $resolution;
	}

	public static function release_ring_for_status( array $status, $environment ) {
		$declared = isset( $status['release_ring'] ) ? strtoupper( trim( (string) $status['release_ring'] ) ) : '';
		if ( in_array( $declared, array( self::R0,self::R1,self::R2,self::R3,self::R4 ), true ) ) return $declared;
		$activation = isset( $status['activation_stage'] ) ? strtolower( trim( (string) $status['activation_stage'] ) ) : '';
		$active_value = defined( 'MAD4B_SCP_Provider_Compatibility_Certification::ACTIVATION_ACTIVE' )
			? (string) MAD4B_SCP_Provider_Compatibility_Certification::ACTIVATION_ACTIVE : 'active';
		$canary_value = defined( 'MAD4B_SCP_Provider_Compatibility_Certification::ACTIVATION_CANARY' )
			? (string) MAD4B_SCP_Provider_Compatibility_Certification::ACTIVATION_CANARY : 'canary';
		if ( hash_equals( strtolower( $active_value ), $activation ) ) {
			// Compatibility bridge is conservative: ACTIVE may imply general
			// Staging eligibility, never Production eligibility.
			return self::R3;
		}
		if ( hash_equals( strtolower( $canary_value ), $activation ) ) return self::R1;
		return self::R0;
	}

	public static function release_ring_allows_normal_mutation( $ring, $environment ) {
		$ring = strtoupper( trim( (string) $ring ) );
		$environment = sanitize_key( (string) $environment );
		if ( 'production' === $environment ) return self::R4 === $ring;
		return in_array( $ring, array( self::R3, self::R4 ), true );
	}
}
