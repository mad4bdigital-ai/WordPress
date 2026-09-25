<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic policy precedence reducer for governed mutation decisions.
 *
 * This class is not an authority source. Authorization assembles trusted facts;
 * this reducer combines them deterministically and produces explainable evidence.
 */
final class MAD4B_SCP_Policy_Resolution {
	const CONTRACT = 'mad4b.policy-resolution.v1';

	private static $precedence = array(
		'hard_deny',
		'kill_switch',
		'environment',
		'authority',
		'certification',
		'quality_release',
		'approval',
		'grant',
		'feature',
	);

	public static function resolve( array $facts ) {
		$normalized = self::normalize_facts( $facts );
		if ( is_wp_error( $normalized ) ) return $normalized;

		$matched = array();
		$decision = 'ALLOW';
		$reason = 'policy_allow';

		$checks = array(
			'hard_deny' => array(
				'matched' => ! empty( $normalized['hard_deny'] ),
				'decision' => 'DENY',
				'reason' => 'hard_deny',
			),
			'kill_switch' => array(
				'matched' => ! empty( $normalized['kill_switch_active'] ),
				'decision' => 'DENY',
				'reason' => 'kill_switch_active',
			),
			'environment' => array(
				'matched' => empty( $normalized['environment_allowed'] ),
				'decision' => 'DENY',
				'reason' => 'environment_prohibited',
			),
			'authority' => array(
				'matched' => empty( $normalized['authority_allowed'] ),
				'decision' => 'DENY',
				'reason' => 'authority_prohibited',
			),
			'certification' => array(
				'matched' => empty( $normalized['certification_allowed'] ),
				'decision' => 'BLOCKED',
				'reason' => 'certification_blocked',
			),
			'quality_release' => array(
				'matched' => empty( $normalized['quality_release_allowed'] ),
				'decision' => 'BLOCKED',
				'reason' => 'quality_or_release_blocked',
			),
			'approval' => array(
				'matched' => ! empty( $normalized['approval_required'] ) && empty( $normalized['approval_satisfied'] ),
				'decision' => 'REQUIRE_APPROVAL',
				'reason' => 'approval_required',
			),
			'grant' => array(
				'matched' => empty( $normalized['grant_allowed'] ),
				'decision' => 'DENY',
				'reason' => 'grant_missing_or_denied',
			),
			'feature' => array(
				'matched' => empty( $normalized['feature_enabled'] ),
				'decision' => 'DEFER',
				'reason' => 'feature_not_enabled',
			),
		);

		foreach ( self::$precedence as $level ) {
			$row = $checks[ $level ];
			$matched[] = array(
				'precedence' => $level,
				'matched' => (bool) $row['matched'],
				'reason_code' => $row['reason'],
			);
			if ( $row['matched'] ) {
				$decision = $row['decision'];
				$reason = $row['reason'];
				break;
			}
		}

		$result = array(
			'contract' => self::CONTRACT,
			'decision' => $decision,
			'reason_code' => $reason,
			'operating_mode' => $normalized['operating_mode'],
			'environment' => $normalized['environment'],
			'capability' => $normalized['capability'],
			'target_fingerprint' => $normalized['target_fingerprint'],
			'policy_versions' => $normalized['policy_versions'],
			'required_approvals' => ! empty( $normalized['approval_required'] ) ? $normalized['required_approvals'] : array(),
			'matched_rules' => $matched,
			'precedence_chain' => self::$precedence,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$result['decision_sha256'] = self::digest( $result );
		return $result;
	}

	public static function tighten( array $base_facts, array $tightening ) {
		$allowed = array(
			'hard_deny',
			'kill_switch_active',
			'environment_allowed',
			'authority_allowed',
			'certification_allowed',
			'quality_release_allowed',
			'approval_required',
			'approval_satisfied',
			'grant_allowed',
			'feature_enabled',
		);
		$out = $base_facts;
		foreach ( $tightening as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) continue;
			$value = (bool) $value;
			if ( in_array( $key, array( 'hard_deny', 'kill_switch_active', 'approval_required' ), true ) ) {
				// Tightening may only turn these blockers on.
				if ( $value ) $out[ $key ] = true;
				continue;
			}
			// Tightening may only turn allow/satisfied/feature facts off.
			if ( ! $value ) $out[ $key ] = false;
		}
		return $out;
	}

	public static function precedence() {
		return self::$precedence;
	}

	private static function normalize_facts( array $facts ) {
		$required_bool = array(
			'hard_deny',
			'kill_switch_active',
			'environment_allowed',
			'authority_allowed',
			'certification_allowed',
			'quality_release_allowed',
			'approval_required',
			'approval_satisfied',
			'grant_allowed',
			'feature_enabled',
		);
		foreach ( $required_bool as $key ) {
			if ( ! array_key_exists( $key, $facts ) || ! is_bool( $facts[ $key ] ) ) {
				return new WP_Error( 'mad4b_policy_fact_invalid', 'Policy resolution fact is missing or invalid: ' . $key );
			}
		}
		$environment = sanitize_key( isset( $facts['environment'] ) ? (string) $facts['environment'] : '' );
		$capability = isset( $facts['capability'] ) ? trim( (string) $facts['capability'] ) : '';
		$target = isset( $facts['target_fingerprint'] ) ? strtolower( trim( (string) $facts['target_fingerprint'] ) ) : '';
		$mode = isset( $facts['operating_mode'] ) ? strtoupper( trim( (string) $facts['operating_mode'] ) ) : '';
		if ( '' === $environment || '' === $capability ) return new WP_Error( 'mad4b_policy_identity_invalid', 'Policy resolution environment/capability identity is incomplete.' );
		if ( '' !== $target && 1 !== preg_match( '/^[a-f0-9]{64}$/', $target ) ) return new WP_Error( 'mad4b_policy_target_invalid', 'Policy resolution target fingerprint is invalid.' );
		if ( ! in_array( $mode, array( 'ENTERPRISE_MULTI_OPERATOR', 'SINGLE_OWNER_HARDENED', 'EMERGENCY_RECOVERY' ), true ) ) {
			return new WP_Error( 'mad4b_policy_operating_mode_invalid', 'Policy resolution operating mode is invalid.' );
		}
		$versions = isset( $facts['policy_versions'] ) && is_array( $facts['policy_versions'] ) ? $facts['policy_versions'] : array();
		ksort( $versions, SORT_STRING );
		$required_approvals = isset( $facts['required_approvals'] ) && is_array( $facts['required_approvals'] ) ? array_values( $facts['required_approvals'] ) : array();
		sort( $required_approvals, SORT_STRING );

		return array_merge(
			array_intersect_key( $facts, array_flip( $required_bool ) ),
			array(
				'environment' => $environment,
				'capability' => $capability,
				'target_fingerprint' => $target,
				'operating_mode' => $mode,
				'policy_versions' => $versions,
				'required_approvals' => $required_approvals,
			)
		);
	}

	private static function digest( array $value ) {
		$value = self::sort_value( $value );
		unset( $value['decision_sha256'] );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}
