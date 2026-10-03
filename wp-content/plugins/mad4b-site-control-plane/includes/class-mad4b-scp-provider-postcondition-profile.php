<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Provider_Postcondition_Profile {
	const CONFIG_CONTRACT = 'mad4b.provider-postcondition-profiles.v1';
	const PROFILE_CONTRACT = 'mad4b.provider-postcondition-profile.v1';
	const OBSERVATION_CONTRACT = 'mad4b.provider-postcondition-observation.v1';
	const DECISION_CONTRACT = 'mad4b.provider-postcondition-recovery-decision.v1';
	private static $config = null;

	public static function clear_cache() { self::$config = null; }

	public static function configured_abilities() {
		$c = self::config();
		if ( is_wp_error( $c ) ) return array();
		$names = array_keys( isset( $c['profiles'] ) && is_array( $c['profiles'] ) ? $c['profiles'] : array() );
		sort( $names, SORT_STRING );
		return $names;
	}

	public static function profile( $ability_name ) {
		$c = self::config();
		if ( is_wp_error( $c ) ) return $c;
		$ability_name = (string) $ability_name;
		$row = isset( $c['profiles'][ $ability_name ] ) && is_array( $c['profiles'][ $ability_name ] ) ? $c['profiles'][ $ability_name ] : array();
		if ( empty( $row ) ) return self::unsupported_profile( $ability_name, 'profile_missing' );
		$ready = false;
		$blockers = array();
		if ( 'mutation_manager' === ( $row['reader_type'] ?? '' ) ) {
			$ready = class_exists( 'MAD4B_SCP_Mutation_Manager' ) && method_exists( 'MAD4B_SCP_Mutation_Manager', 'postcondition_state' );
			if ( ! $ready ) $blockers[] = 'mutation_manager_reader_unavailable';
		} elseif ( 'adapter' === ( $row['reader_type'] ?? '' ) ) {
			$adapter_id = sanitize_key( (string) ( $row['adapter_id'] ?? '' ) );
			$adapter = class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( $adapter_id ) : null;
			if ( ! $adapter && class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
				MAD4B_SCP_Adapter_Registry::instance()->register_defaults();
				$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( $adapter_id );
			}
			if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) {
				$blockers[] = 'adapter_unavailable';
			} else {
				$contract = $adapter->reversible_contract_for( $ability_name );
				if ( '' === $contract ) $blockers[] = 'reversible_contract_missing';
				try {
					$reader = new ReflectionMethod( $adapter, 'read_reversible_state' );
					if ( 'MAD4B_SCP_Adapter_Base' === $reader->getDeclaringClass()->getName() ) $blockers[] = 'reader_not_overridden';
				} catch ( Throwable $e ) {
					$blockers[] = 'reader_reflection_failed';
				}
				$ready = empty( $blockers );
			}
		} else {
			$blockers[] = 'reader_type_invalid';
		}
		$profile = array(
			'contract' => self::PROFILE_CONTRACT,
			'ability_name' => $ability_name,
			'reader_type' => isset( $row['reader_type'] ) ? (string) $row['reader_type'] : '',
			'adapter_id' => isset( $row['adapter_id'] ) ? sanitize_key( (string) $row['adapter_id'] ) : '',
			'reader_id' => isset( $row['reader_id'] ) ? sanitize_key( (string) $row['reader_id'] ) : '',
			'reader_method' => isset( $row['reader_method'] ) ? (string) $row['reader_method'] : '',
			'freshness_seconds' => max( 1, (int) ( $row['freshness_seconds'] ?? $c['default_freshness_seconds'] ) ),
			'reader_certified' => (bool) $ready,
			'blockers' => array_values( array_unique( $blockers ) ),
			'authorizing' => false,
		);
		$profile['profile_sha256'] = self::digest( $profile );
		return $profile;
	}

	public static function profile_for_adapter( $adapter, $ability_name ) {
		$profile = self::profile( $ability_name );
		if ( is_wp_error( $profile ) || empty( $profile['reader_certified'] ) ) return $profile;
		if ( 'adapter' !== (string) $profile['reader_type'] ) return $profile;
		if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base || ! hash_equals( (string) $profile['adapter_id'], sanitize_key( (string) $adapter->id() ) ) ) {
			$profile['reader_certified'] = false;
			$profile['blockers'][] = 'active_adapter_mismatch';
			$profile['profile_sha256'] = self::digest( $profile );
		}
		return $profile;
	}

	public static function observe_with_adapter( $adapter, $ability_name, array $target, $before_sha256, $expected_after_sha256 = '', $observed_at_epoch = null ) {
		$profile = self::profile_for_adapter( $adapter, $ability_name );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( empty( $profile['reader_certified'] ) ) return self::unknown_observation( $profile, 'reader_not_certified', $observed_at_epoch );
		$state = $adapter->read_reversible_state( $ability_name, $target );
		if ( is_wp_error( $state ) || ! is_array( $state ) ) return self::unknown_observation( $profile, is_wp_error( $state ) ? $state->get_error_code() : 'reader_invalid_state', $observed_at_epoch );
		return self::decision_from_hashes( $profile, $before_sha256, $expected_after_sha256, self::state_hash( $state ), $observed_at_epoch );
	}

	public static function decision_from_hashes( array $profile, $before_sha256, $expected_after_sha256, $observed_sha256, $observed_at_epoch = null ) {
		$now = self::now_epoch();
		$observed_at = null === $observed_at_epoch ? $now : (int) $observed_at_epoch;
		$fresh = $observed_at > 0 && $observed_at <= $now + self::future_skew() && ( $now - $observed_at ) <= (int) ( $profile['freshness_seconds'] ?? 0 );
		$before = strtolower( trim( (string) $before_sha256 ) );
		$after = strtolower( trim( (string) $expected_after_sha256 ) );
		$observed = strtolower( trim( (string) $observed_sha256 ) );
		$valid_observed = 1 === preg_match( '/^[a-f0-9]{64}$/', $observed );
		$state = 'unknown';
		if ( ! empty( $profile['reader_certified'] ) && $fresh && $valid_observed ) {
			if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $after ) && hash_equals( $after, $observed ) ) $state = 'committed';
			elseif ( 1 === preg_match( '/^[a-f0-9]{64}$/', $before ) && hash_equals( $before, $observed ) ) $state = 'no_effect';
		}
		$eligible = 'no_effect' === $state && ! empty( $profile['reader_certified'] ) && $fresh;
		$observation = array(
			'contract' => self::OBSERVATION_CONTRACT,
			'profile_sha256' => isset( $profile['profile_sha256'] ) ? (string) $profile['profile_sha256'] : '',
			'ability_name' => isset( $profile['ability_name'] ) ? (string) $profile['ability_name'] : '',
			'reader_certified' => ! empty( $profile['reader_certified'] ),
			'fresh' => $fresh,
			'observed_at_epoch' => $observed_at,
			'observed_sha256' => $observed,
			'postcondition_state' => $state,
			'reconciliation_required' => 'unknown' === $state,
			'blind_retry_allowed' => false,
			'retry_reclaim_eligible' => $eligible,
			'retry_requires_fresh_plan_and_authorization' => $eligible,
			'authorizing' => false,
		);
		$observation['observation_sha256'] = self::digest( $observation );
		return $observation;
	}

	public static function recovery_decision( array $observation ) {
		$state = isset( $observation['postcondition_state'] ) ? (string) $observation['postcondition_state'] : 'unknown';
		$eligible = 'no_effect' === $state && ! empty( $observation['reader_certified'] ) && ! empty( $observation['fresh'] );
		return array(
			'contract' => self::DECISION_CONTRACT,
			'postcondition_state' => $state,
			'reader_certified' => ! empty( $observation['reader_certified'] ),
			'fresh' => ! empty( $observation['fresh'] ),
			'retry_reclaim_eligible' => $eligible,
			'blind_retry_allowed' => false,
			'reconciliation_required' => 'unknown' === $state,
			'client_action' => 'committed' === $state ? 'consume_committed_result' : ( $eligible ? 'fresh_plan_and_authorization_required_before_retry' : 'reconcile_provider_state_before_any_retry' ),
			'authorizing' => false,
		);
	}

	public static function assert_reconciliation_transition( array $observation, $kind ) {
		$kind = sanitize_key( (string) $kind );
		if ( self::OBSERVATION_CONTRACT !== ( isset( $observation['contract'] ) ? (string) $observation['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_postcondition_observation_contract_invalid', 'Provider postcondition observation contract is invalid.' );
		}
		$expected_sha = isset( $observation['observation_sha256'] ) ? strtolower( trim( (string) $observation['observation_sha256'] ) ) : '';
		$material = $observation;
		unset( $material['observation_sha256'] );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_sha ) || ! hash_equals( $expected_sha, self::digest( $material ) ) ) {
			return new WP_Error( 'mad4b_postcondition_observation_integrity_invalid', 'Provider postcondition observation integrity check failed.' );
		}
		$ability = isset( $observation['ability_name'] ) ? (string) $observation['ability_name'] : '';
		$current = self::profile( $ability );
		if ( is_wp_error( $current ) ) return $current;
		if ( empty( $current['reader_certified'] ) ) {
			return new WP_Error( 'mad4b_postcondition_reader_uncertified', 'Mutation family has no currently certified postcondition reader.', array( 'ability_name' => $ability, 'blind_retry_allowed' => false, 'reconciliation_required' => true ) );
		}
		$observed_profile = isset( $observation['profile_sha256'] ) ? strtolower( trim( (string) $observation['profile_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $observed_profile ) || ! hash_equals( (string) $current['profile_sha256'], $observed_profile ) ) {
			return new WP_Error( 'mad4b_postcondition_profile_stale', 'Postcondition observation was produced under a different reader/profile generation.', array( 'ability_name' => $ability, 'blind_retry_allowed' => false, 'reconciliation_required' => true ) );
		}
		$observed_at = isset( $observation['observed_at_epoch'] ) ? (int) $observation['observed_at_epoch'] : 0;
		$now = self::now_epoch();
		if ( $observed_at < 1 || $observed_at > $now + self::future_skew() || ( $now - $observed_at ) > (int) $current['freshness_seconds'] || empty( $observation['fresh'] ) ) {
			return new WP_Error( 'mad4b_postcondition_observation_stale', 'Postcondition observation is outside the certified freshness window.', array( 'ability_name' => $ability, 'blind_retry_allowed' => false, 'reconciliation_required' => true ) );
		}
		$state = isset( $observation['postcondition_state'] ) ? sanitize_key( (string) $observation['postcondition_state'] ) : 'unknown';
		$required = 'idempotency_completion' === $kind ? 'committed' : 'no_effect';
		if ( ! hash_equals( $required, $state ) ) {
			return new WP_Error( 'mad4b_postcondition_transition_unproven', 'Certified postcondition does not prove the requested durable transition.', array( 'kind' => $kind, 'required_state' => $required, 'observed_state' => $state, 'blind_retry_allowed' => false, 'reconciliation_required' => true ) );
		}
		$decision = self::recovery_decision( $observation );
		if ( 'no_effect' === $required && empty( $decision['retry_reclaim_eligible'] ) ) {
			return new WP_Error( 'mad4b_postcondition_retry_reclaim_ineligible', 'No-effect observation is not eligible for retry/reclaim.', array( 'blind_retry_allowed' => false, 'reconciliation_required' => true ) );
		}
		if ( ! empty( $decision['blind_retry_allowed'] ) ) {
			return new WP_Error( 'mad4b_postcondition_blind_retry_forbidden', 'Provider postcondition policy may not authorize blind mutation retry.' );
		}
		$decision['observation_sha256'] = $expected_sha;
		$decision['profile_sha256'] = (string) $current['profile_sha256'];
		$decision['ability_name'] = $ability;
		return $decision;
	}

	private static function unknown_observation( array $profile, $cause, $observed_at_epoch ) {
		$observation = self::decision_from_hashes( $profile, '', '', '', $observed_at_epoch );
		$observation['cause'] = sanitize_key( (string) $cause );
		$observation['postcondition_state'] = 'unknown';
		$observation['reconciliation_required'] = true;
		$observation['retry_reclaim_eligible'] = false;
		$observation['blind_retry_allowed'] = false;
		$observation['observation_sha256'] = self::digest( $observation );
		return $observation;
	}

	private static function unsupported_profile( $ability_name, $cause ) {
		$p = array(
			'contract' => self::PROFILE_CONTRACT,
			'ability_name' => (string) $ability_name,
			'reader_type' => '',
			'adapter_id' => '',
			'reader_id' => '',
			'reader_method' => '',
			'freshness_seconds' => 0,
			'reader_certified' => false,
			'blockers' => array( sanitize_key( (string) $cause ) ),
			'authorizing' => false,
		);
		$p['profile_sha256'] = self::digest( $p );
		return $p;
	}

	private static function config() {
		if ( null !== self::$config ) return self::$config;
		$path = dirname( __DIR__ ) . '/config/provider-postcondition-profiles.json';
		if ( ! is_readable( $path ) ) return self::$config = new WP_Error( 'mad4b_postcondition_profiles_missing', 'Provider postcondition profile catalog is unavailable.' );
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || self::CONFIG_CONTRACT !== ( $data['contract'] ?? '' ) || ! isset( $data['profiles'] ) || ! is_array( $data['profiles'] ) ) return self::$config = new WP_Error( 'mad4b_postcondition_profiles_invalid', 'Provider postcondition profile catalog is invalid.' );
		return self::$config = $data;
	}

	private static function state_hash( array $state ) { return hash( 'sha256', self::json( self::sort_value( $state ) ) ); }
	private static function digest( $value ) { return hash( 'sha256', self::json( self::sort_value( $value ) ) ); }
	private static function json( $value ) { return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $k => $v ) $value[ $k ] = self::sort_value( $v );
		return $value;
	}

	private static function now_epoch(){return class_exists('MAD4B_SCP_Time_Policy')?MAD4B_SCP_Time_Policy::now_epoch():time();}
	private static function future_skew(){return class_exists('MAD4B_SCP_Time_Policy')?MAD4B_SCP_Time_Policy::future_skew_seconds('reconciliation_observation'):5;}
}
