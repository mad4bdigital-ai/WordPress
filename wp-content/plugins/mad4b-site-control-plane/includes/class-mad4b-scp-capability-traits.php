<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing semantic capability trait registry/resolver.
 *
 * Capability presence alone never proves semantic equivalence. Unknown traits
 * fail closed for requirements that depend on them.
 */
final class MAD4B_SCP_Capability_Traits {
	const CONTRACT = 'mad4b.capability-traits.v1';
	const PROFILE_CONTRACT = 'mad4b.capability-profile.v1';
	const RESOLUTION_CONTRACT = 'mad4b.capability-trait-resolution.v1';

	private static $catalog = null;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read(
			'mad4b/capability-trait-profile',
			'Capability Trait Profile',
			array( __CLASS__, 'profile_ability' ),
			array(
				'type' => 'object',
				'properties' => array(
					'provider_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
					'capability_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
				),
				'required' => array( 'provider_id', 'capability_id' ),
				'additionalProperties' => false,
			)
		);
		self::register_read(
			'mad4b/capability-trait-resolve',
			'Capability Trait Resolver',
			array( __CLASS__, 'resolve' ),
			array(
				'type' => 'object',
				'properties' => array(
					'capability_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
					'required_traits' => array( 'type' => 'object', 'additionalProperties' => true ),
					'release_ring' => array( 'type' => 'string', 'enum' => array( 'shadow', 'canary', 'active' ) ),
					'require_certified' => array( 'type' => 'boolean' ),
					'preferred_provider' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				),
				'required' => array( 'capability_id' ),
				'additionalProperties' => false,
			)
		);
	}

	private static function register_read( $name, $label, $callback, array $schema ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' from fail-closed semantic capability traits. This ability is non-authorizing.',
				'category' => 'mad4b-read',
				'execute_callback' => $callback,
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => $schema,
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

	public static function clear_cache() { self::$catalog = null; }

	private static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = MAD4B_SCP_DIR . 'config/provider-capability-contracts.json';
		if ( ! is_readable( $path ) ) return self::$catalog = array( 'providers' => array() );
		$raw = file_get_contents( $path );
		$data = false === $raw ? null : json_decode( $raw, true );
		self::$catalog = is_array( $data ) ? $data : array( 'providers' => array() );
		return self::$catalog;
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}

	private static function fingerprint( array $value ) {
		return hash( 'sha256', self::stable_json( $value ) );
	}

	private static function normalize_traits( array $capability ) {
		$traits = isset( $capability['traits'] ) && is_array( $capability['traits'] ) ? $capability['traits'] : array();
		$defaults = array(
			'idempotency_model' => 'unknown',
			'cancellation' => 'unknown',
			'resume' => 'unknown',
			'durable_wait' => 'unknown',
			'retry_semantics' => 'unknown',
			'ordering_guarantees' => 'unknown',
			'max_runtime_seconds' => 'unknown',
			'max_payload_bytes' => 'unknown',
			'callback_model' => 'unknown',
			'execution_history_retention' => 'unknown',
			'concurrency_model' => 'unknown',
			'compensation_support' => 'unknown',
			'local_or_remote' => 'unknown',
			'evidence_strength' => 'unknown',
		);
		foreach ( $defaults as $key => $fallback ) if ( ! array_key_exists( $key, $traits ) ) $traits[ $key ] = $fallback;
		ksort( $traits, SORT_STRING );
		return $traits;
	}

	public static function profile( $provider_id, $capability_id ) {
		$provider_id = sanitize_key( (string) $provider_id );
		$capability_id = sanitize_key( str_replace( '.', '_', (string) $capability_id ) );
		$catalog = self::catalog();
		$providers = isset( $catalog['providers'] ) && is_array( $catalog['providers'] ) ? $catalog['providers'] : array();
		$decl = isset( $providers[ $provider_id ] ) && is_array( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : array();
		$capabilities = isset( $decl['capabilities'] ) && is_array( $decl['capabilities'] ) ? $decl['capabilities'] : array();
		$match_id = '';
		$capability = array();
		foreach ( $capabilities as $id => $row ) {
			if ( $capability_id !== sanitize_key( str_replace( '.', '_', (string) $id ) ) ) continue;
			$match_id = (string) $id;
			$capability = is_array( $row ) ? $row : array();
			break;
		}
		if ( '' === $match_id ) {
			return array(
				'contract' => self::PROFILE_CONTRACT,
				'provider_id' => $provider_id,
				'capability_id' => str_replace( '_', '.', $capability_id ),
				'found' => false,
				'eligible' => false,
				'reason_code' => 'capability_not_cataloged',
				'authorizing' => false,
			);
		}
		$traits = self::normalize_traits( $capability );
		$profile = array(
			'contract' => self::PROFILE_CONTRACT,
			'provider_id' => $provider_id,
			'capability_id' => $match_id,
			'risk' => isset( $capability['risk'] ) ? sanitize_key( (string) $capability['risk'] ) : 'unknown',
			'reversible' => ! empty( $capability['reversible'] ),
			'rollback_contract' => isset( $capability['rollback_contract'] ) ? sanitize_text_field( (string) $capability['rollback_contract'] ) : '',
			'traits' => $traits,
			'unknown_traits' => array_values( array_keys( array_filter( $traits, static function( $v ) { return 'unknown' === $v; } ) ) ),
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$profile['profile_fingerprint'] = self::fingerprint( $profile );
		$profile['certification'] = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' )
			? MAD4B_SCP_Provider_Compatibility_Certification::capability_certification( array( 'provider_id' => $provider_id, 'capability_id' => $match_id ) )
			: array( 'available' => false, 'reason_code' => 'certification_service_unavailable' );
		return $profile;
	}

	public static function profile_ability( $input ) {
		$input = is_array( $input ) ? $input : array();
		return self::profile(
			isset( $input['provider_id'] ) ? $input['provider_id'] : '',
			isset( $input['capability_id'] ) ? $input['capability_id'] : ''
		);
	}

	private static function trait_match( $actual, $requirement ) {
		if ( 'unknown' === $actual || null === $actual ) return array( false, 'trait_unknown' );
		if ( is_array( $requirement ) ) {
			if ( array_key_exists( 'equals', $requirement ) ) return array( $actual === $requirement['equals'], 'trait_mismatch' );
			if ( isset( $requirement['in'] ) && is_array( $requirement['in'] ) ) return array( in_array( $actual, $requirement['in'], true ), 'trait_mismatch' );
			if ( array_key_exists( 'gte', $requirement ) ) {
				if ( ! is_numeric( $actual ) || ! is_numeric( $requirement['gte'] ) ) return array( false, 'trait_not_numeric' );
				return array( (float) $actual >= (float) $requirement['gte'], 'trait_mismatch' );
			}
			if ( array_key_exists( 'lte', $requirement ) ) {
				if ( ! is_numeric( $actual ) || ! is_numeric( $requirement['lte'] ) ) return array( false, 'trait_not_numeric' );
				return array( (float) $actual <= (float) $requirement['lte'], 'trait_mismatch' );
			}
			return array( false, 'requirement_operator_invalid' );
		}
		return array( $actual === $requirement, 'trait_mismatch' );
	}

	private static function certification_ring_status( array $profile, $ring, $require_certified ) {
		$ring = sanitize_key( (string) $ring );
		$cert = isset( $profile['certification'] ) && is_array( $profile['certification'] ) ? $profile['certification'] : array();
		$caps = isset( $cert['capabilities'] ) && is_array( $cert['capabilities'] ) ? $cert['capabilities'] : array();
		$status = ! empty( $caps ) ? reset( $caps ) : array();
		$status = is_array( $status ) ? $status : array();
		$level = isset( $status['certification_level'] ) ? (string) $status['certification_level'] : 'UNKNOWN';
		$activation = isset( $status['activation_stage'] ) ? sanitize_key( (string) $status['activation_stage'] ) : 'shadow';
		$risk = isset( $profile['risk'] ) ? (string) $profile['risk'] : 'unknown';
		$read_eligible = ! empty( $status['read_eligible'] );
		$write_eligible = ! empty( $status['write_eligible'] );
		$canary_eligible = ! empty( $status['canary_eligible'] );
		$known = 1 === count( $caps ) && ! in_array( $level, array( 'UNKNOWN', 'QUARANTINED' ), true );
		$eligible = true;
		$reason = '';
		if ( $require_certified && ! $known ) {
			$eligible = false;
			$reason = 'certification_required';
		} elseif ( '' !== $ring ) {
			if ( ! $known ) {
				$eligible = false;
				$reason = 'release_ring_certification_unknown';
			} elseif ( 'shadow' === $ring ) {
				$eligible = true;
			} elseif ( 'canary' === $ring ) {
				$eligible = 'read' === $risk ? $read_eligible : $canary_eligible;
				if ( ! $eligible ) $reason = 'provider_not_canary_eligible';
			} elseif ( 'active' === $ring ) {
				$eligible = 'active' === $activation && ( 'read' === $risk ? $read_eligible : $write_eligible );
				if ( ! $eligible ) $reason = 'provider_not_active_eligible';
			} else {
				$eligible = false;
				$reason = 'release_ring_invalid';
			}
		}
		return array(
			'eligible' => $eligible,
			'reason_code' => $reason,
			'release_ring' => $ring,
			'certification_level' => $level,
			'activation_stage' => $activation,
			'read_eligible' => $read_eligible,
			'write_eligible' => $write_eligible,
			'canary_eligible' => $canary_eligible,
			'certification_fingerprint' => empty( $cert ) ? '' : self::fingerprint( $cert ),
		);
	}

	public static function resolve( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$capability_id = isset( $input['capability_id'] ) ? (string) $input['capability_id'] : '';
		$required = isset( $input['required_traits'] ) && is_array( $input['required_traits'] ) ? $input['required_traits'] : array();
		$release_ring = isset( $input['release_ring'] ) ? sanitize_key( (string) $input['release_ring'] ) : '';
		$require_certified = ! empty( $input['require_certified'] );
		$preferred_provider = isset( $input['preferred_provider'] ) ? sanitize_key( (string) $input['preferred_provider'] ) : '';
		if ( '' === trim( $capability_id ) ) {
			return array(
				'contract' => self::RESOLUTION_CONTRACT,
				'eligible' => array(),
				'rejected' => array(),
				'selected_provider' => '',
				'non_authorizing' => true,
				'reason_code' => 'capability_id_required',
			);
		}
		$catalog = self::catalog();
		$providers = isset( $catalog['providers'] ) && is_array( $catalog['providers'] ) ? array_keys( $catalog['providers'] ) : array();
		sort( $providers, SORT_STRING );
		$eligible = array();
		$rejected = array();
		foreach ( $providers as $provider_id ) {
			$profile = self::profile( $provider_id, $capability_id );
			if ( empty( $profile['found'] ) && isset( $profile['found'] ) ) continue;
			$violations = array();
			foreach ( $required as $trait => $requirement ) {
				if ( ! array_key_exists( $trait, $profile['traits'] ) ) {
					$violations[] = array( 'trait' => $trait, 'reason_code' => 'trait_not_declared' );
					continue;
				}
				list( $match, $reason ) = self::trait_match( $profile['traits'][ $trait ], $requirement );
				if ( ! $match ) $violations[] = array( 'trait' => $trait, 'reason_code' => $reason, 'actual' => $profile['traits'][ $trait ] );
			}
			$ring_status = self::certification_ring_status( $profile, $release_ring, $require_certified );
			if ( ! $ring_status['eligible'] ) {
				$violations[] = array( 'trait' => 'provider_certification', 'reason_code' => $ring_status['reason_code'] );
			}
			$row = array(
				'provider_id' => $provider_id,
				'capability_id' => $profile['capability_id'],
				'profile_fingerprint' => $profile['profile_fingerprint'],
				'certification_fingerprint' => $ring_status['certification_fingerprint'],
				'certification_level' => $ring_status['certification_level'],
				'activation_stage' => $ring_status['activation_stage'],
				'release_ring' => $release_ring,
				'traits' => $profile['traits'],
				'violations' => $violations,
			);
			if ( empty( $violations ) ) $eligible[] = $row; else $rejected[] = $row;
		}
		$selected_provider = 1 === count( $eligible ) ? (string) $eligible[0]['provider_id'] : '';
		$raw_ambiguous = count( $eligible ) > 1;
		$preference_applied = false;
		$preference_reason = '';
		if ( $raw_ambiguous && '' !== $preferred_provider ) {
			$matches = array_values( array_filter( $eligible, static function( $row ) use ( $preferred_provider ) {
				return isset( $row['provider_id'] ) && hash_equals( $preferred_provider, (string) $row['provider_id'] );
			} ) );
			if ( 1 === count( $matches ) ) {
				$selected_provider = $preferred_provider;
				$preference_applied = true;
			} else {
				$preference_reason = 'preferred_provider_not_eligible';
			}
		}
		$resolution = array(
			'contract' => self::RESOLUTION_CONTRACT,
			'capability_id' => $capability_id,
			'required_traits' => $required,
			'release_ring' => $release_ring,
			'require_certified' => $require_certified,
			'preferred_provider' => $preferred_provider,
			'eligible' => $eligible,
			'rejected' => $rejected,
			'selected_provider' => $selected_provider,
			'ambiguous' => $raw_ambiguous && '' === $selected_provider,
			'raw_ambiguous' => $raw_ambiguous,
			'preference_applied' => $preference_applied,
			'preference_reason' => $preference_reason,
			'implicit_tie_breaking' => false,
			'non_authorizing' => true,
			'mutation_performed' => false,
		);
		$resolution['resolution_fingerprint'] = self::fingerprint( $resolution );
		return $resolution;
	}
}

MAD4B_SCP_Capability_Traits::boot();
