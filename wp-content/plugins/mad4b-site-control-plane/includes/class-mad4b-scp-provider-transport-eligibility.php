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
		foreach ( self::$precedence as $level ) {
			$blocked = ! empty( $checks[ $level ] );
			$matched[] = array( 'precedence'=>$level, 'blocked'=>$blocked, 'reason_code'=>$reason_codes[ $level ] );
			if ( $blocked ) {
				$decision = 'DENY';
				$reason = $reason_codes[ $level ];
				break;
			}
		}
		return array(
			'contract'=>self::CONTRACT,
			'decision'=>$decision,
			'reason_code'=>$reason,
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
				'Provider mutation transport is denied by the strongest current eligibility constraint.',
				array_merge( $resolution, array(
					'provider_context'=>$context,
					'capability_status'=>$status,
					'blind_retry_allowed'=>false,
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
				'Provider mutation transport is denied by the current circuit-breaker state.',
				array_merge( $resolution, array( 'blind_retry_allowed'=>false ) )
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
