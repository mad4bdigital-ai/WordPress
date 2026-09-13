<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only behavioral evidence verifier for provider capabilities.
 *
 * Receipts are discovered from bounded sources and are never accepted as caller
 * booleans. Every accepted receipt is bound to the current provider, capability
 * contract and runtime artifact fingerprint. The verifier callback must itself
 * originate from the MAD4B Control Plane code root; third-party plugins cannot
 * become trusted merely by attaching a filter and declaring trusted=true.
 */
final class MAD4B_SCP_Provider_Behavioral_Evidence {
	const CONTRACT = 'mad4b.provider-behavioral-evidence.v1';
	const RECEIPT_CONTRACT = 'mad4b.provider-behavioral-evidence-receipt.v1';
	const MAX_RECEIPTS = 64;
	const MAX_VERIFIERS = 16;
	const MAX_RECEIPT_TTL = 604800; // Seven days.
	const CLOCK_SKEW = 300;

	public static function status( array $context ) {
		$context = self::normalize_context( $context );
		if ( ! empty( $context['blocking_reasons'] ) ) return self::empty_status( $context, $context['blocking_reasons'] );

		$verifiers = self::verifiers( $context );
		$receipts = self::receipts( $context );
		$accepted = array();
		$rejected = array();
		foreach ( $receipts as $receipt ) {
			$result = self::verify_receipt( $receipt, $context, $verifiers );
			if ( ! empty( $result['accepted'] ) ) $accepted[] = $result;
			else $rejected[] = $result;
		}
		usort( $accepted, static function ( $a, $b ) { return (int) $b['issued_at'] <=> (int) $a['issued_at']; } );
		$winner = isset( $accepted[0] ) ? $accepted[0] : array();
		$behavioral = ! empty( $winner['behavioral_verified'] );
		$rollback = ! empty( $winner['rollback_verified'] );
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => $context['provider_id'],
			'capability_id' => $context['capability_id'],
			'artifact_fingerprint' => $context['artifact_fingerprint'],
			'capability_contract_digest' => $context['capability_contract_digest'],
			'state' => $behavioral ? 'verified' : ( empty( $receipts ) ? 'missing' : 'unverified' ),
			'behavioral_verified' => $behavioral,
			'rollback_verified' => $rollback,
			'accepted_receipt' => $winner ? self::public_receipt( $winner ) : array(),
			'accepted_count' => count( $accepted ),
			'rejected_count' => count( $rejected ),
			'rejection_reasons' => self::rejection_reasons( $rejected ),
			'verifier_provenance_required' => 'mad4b_control_plane_source',
			'authorizing' => false,
			'activation_granted' => false,
			'mutation_granted' => false,
		);
	}

	private static function normalize_context( array $context ) {
		$provider = self::clean_id( isset( $context['provider_id'] ) ? $context['provider_id'] : '' );
		$capability = self::clean_capability( isset( $context['capability_id'] ) ? $context['capability_id'] : '' );
		$artifact = self::clean_digest( isset( $context['artifact_fingerprint'] ) ? $context['artifact_fingerprint'] : '' );
		$contract = self::clean_digest( isset( $context['capability_contract_digest'] ) ? $context['capability_contract_digest'] : '' );
		$risk = self::clean_id( isset( $context['risk'] ) ? $context['risk'] : '' );
		$reversible = ! empty( $context['reversible'] );
		$reasons = array();
		if ( '' === $provider ) $reasons[] = 'provider_id_invalid';
		if ( '' === $capability ) $reasons[] = 'capability_id_invalid';
		if ( '' === $artifact ) $reasons[] = 'artifact_fingerprint_invalid';
		if ( '' === $contract ) $reasons[] = 'capability_contract_digest_invalid';
		return array(
			'provider_id' => $provider,
			'capability_id' => $capability,
			'artifact_fingerprint' => $artifact,
			'capability_contract_digest' => $contract,
			'risk' => $risk,
			'reversible' => $reversible,
			'blocking_reasons' => $reasons,
		);
	}

	private static function verifiers( array $context ) {
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'mad4b_provider_behavioral_evidence_verifiers', array(), $context ) : array();
		$raw = is_array( $raw ) ? array_slice( $raw, 0, self::MAX_VERIFIERS, true ) : array();
		$result = array();
		foreach ( $raw as $key => $candidate ) {
			if ( ! is_array( $candidate ) ) continue;
			$id = self::clean_id( isset( $candidate['verifier_id'] ) ? $candidate['verifier_id'] : $key );
			$issuer = self::clean_id( isset( $candidate['issuer_id'] ) ? $candidate['issuer_id'] : '' );
			$callback = isset( $candidate['verify_callback'] ) ? $candidate['verify_callback'] : null;
			$scopes = array_values( array_unique( array_intersect( array_map( array( __CLASS__, 'clean_id' ), (array) ( isset( $candidate['scopes'] ) ? $candidate['scopes'] : array() ) ), array( 'behavioral', 'rollback' ) ) ) );
			$scheme = isset( $candidate['signature_scheme'] ) && is_scalar( $candidate['signature_scheme'] ) ? trim( (string) $candidate['signature_scheme'] ) : '';
			if ( '' === $id || '' === $issuer || ! is_callable( $callback ) || empty( $scopes ) || '' === $scheme || true !== ( isset( $candidate['trusted'] ) ? $candidate['trusted'] : false ) ) continue;
			if ( true !== ( isset( $candidate['read_only_verifier'] ) ? $candidate['read_only_verifier'] : false ) ) continue;
			if ( false !== ( isset( $candidate['authorizing'] ) ? $candidate['authorizing'] : null ) ) continue;
			if ( ! self::callback_owned_by_control_plane( $callback ) ) continue;
			$result[ $id ] = array(
				'verifier_id' => $id,
				'issuer_id' => $issuer,
				'scopes' => $scopes,
				'signature_scheme' => substr( $scheme, 0, 80 ),
				'verifier_provenance' => 'mad4b_control_plane_source',
				'verify_callback' => $callback,
			);
		}
		return $result;
	}

	private static function receipts( array $context ) {
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'mad4b_provider_behavioral_evidence_receipts', array(), $context ) : array();
		return is_array( $raw ) ? array_values( array_slice( $raw, 0, self::MAX_RECEIPTS ) ) : array();
	}

	private static function verify_receipt( $receipt, array $context, array $verifiers ) {
		$reasons = array();
		if ( ! is_array( $receipt ) ) return self::rejected( array( 'receipt_not_object' ) );
		$provider = self::clean_id( isset( $receipt['provider_id'] ) ? $receipt['provider_id'] : '' );
		$capability = self::clean_capability( isset( $receipt['capability_id'] ) ? $receipt['capability_id'] : '' );
		$artifact = self::clean_digest( isset( $receipt['artifact_fingerprint'] ) ? $receipt['artifact_fingerprint'] : '' );
		$contract_digest = self::clean_digest( isset( $receipt['capability_contract_digest'] ) ? $receipt['capability_contract_digest'] : '' );
		$verifier_id = self::clean_id( isset( $receipt['verifier_id'] ) ? $receipt['verifier_id'] : '' );
		$issuer_id = self::clean_id( isset( $receipt['issuer_id'] ) ? $receipt['issuer_id'] : '' );
		$issued_at = isset( $receipt['issued_at'] ) ? (int) $receipt['issued_at'] : 0;
		$expires_at = isset( $receipt['expires_at'] ) ? (int) $receipt['expires_at'] : 0;
		$signature = isset( $receipt['signature'] ) && is_scalar( $receipt['signature'] ) ? trim( (string) $receipt['signature'] ) : '';
		$evidence_digest = self::clean_digest( isset( $receipt['evidence_digest'] ) ? $receipt['evidence_digest'] : '' );
		$observations = isset( $receipt['observations'] ) && is_array( $receipt['observations'] ) ? $receipt['observations'] : array();
		if ( self::RECEIPT_CONTRACT !== (string) ( isset( $receipt['contract'] ) ? $receipt['contract'] : '' ) ) $reasons[] = 'receipt_contract_invalid';
		if ( $provider !== $context['provider_id'] ) $reasons[] = 'provider_binding_mismatch';
		if ( $capability !== $context['capability_id'] ) $reasons[] = 'capability_binding_mismatch';
		if ( $artifact !== $context['artifact_fingerprint'] ) $reasons[] = 'artifact_binding_mismatch';
		if ( $contract_digest !== $context['capability_contract_digest'] ) $reasons[] = 'capability_contract_binding_mismatch';
		if ( '' === $verifier_id || ! isset( $verifiers[ $verifier_id ] ) ) $reasons[] = 'trusted_verifier_unavailable';
		if ( '' === $issuer_id ) $reasons[] = 'issuer_id_invalid';
		if ( '' === $signature || strlen( $signature ) > 1024 ) $reasons[] = 'signature_invalid';
		if ( '' === $evidence_digest ) $reasons[] = 'evidence_digest_invalid';
		$now = time();
		if ( $issued_at <= 0 || $issued_at > $now + self::CLOCK_SKEW ) $reasons[] = 'issued_at_invalid';
		if ( $expires_at <= $issued_at || $expires_at < $now ) $reasons[] = 'receipt_expired';
		if ( $expires_at - $issued_at > self::MAX_RECEIPT_TTL ) $reasons[] = 'receipt_ttl_exceeds_policy';
		if ( ! array_key_exists( 'behavioral_passed', $observations ) || ! is_bool( $observations['behavioral_passed'] ) ) $reasons[] = 'behavioral_observation_invalid';
		if ( array_key_exists( 'rollback_passed', $observations ) && ! is_bool( $observations['rollback_passed'] ) ) $reasons[] = 'rollback_observation_invalid';
		if ( $reasons ) return self::rejected( $reasons, $issued_at, $verifier_id, $issuer_id );

		$verifier = $verifiers[ $verifier_id ];
		if ( $issuer_id !== $verifier['issuer_id'] ) return self::rejected( array( 'issuer_verifier_binding_mismatch' ), $issued_at, $verifier_id, $issuer_id );
		$canonical = array(
			'contract' => self::RECEIPT_CONTRACT,
			'provider_id' => $provider,
			'capability_id' => $capability,
			'artifact_fingerprint' => $artifact,
			'capability_contract_digest' => $contract_digest,
			'verifier_id' => $verifier_id,
			'issuer_id' => $issuer_id,
			'issued_at' => $issued_at,
			'expires_at' => $expires_at,
			'observations' => array(
				'behavioral_passed' => (bool) $observations['behavioral_passed'],
				'rollback_passed' => ! empty( $observations['rollback_passed'] ),
			),
		);
		$expected_digest = self::stable_digest( $canonical );
		if ( '' === $expected_digest || ! hash_equals( $expected_digest, $evidence_digest ) ) return self::rejected( array( 'evidence_digest_mismatch' ), $issued_at, $verifier_id, $issuer_id );
		try { $verification = call_user_func( $verifier['verify_callback'], $receipt, $context, $canonical ); }
		catch ( Throwable $error ) { return self::rejected( array( 'verifier_exception' ), $issued_at, $verifier_id, $issuer_id ); }
		if ( ! is_array( $verification ) || empty( $verification['verified'] ) ) return self::rejected( array( 'signature_verification_failed' ), $issued_at, $verifier_id, $issuer_id );
		$verified_digest = self::clean_digest( isset( $verification['evidence_digest'] ) ? $verification['evidence_digest'] : '' );
		if ( '' === $verified_digest || ! hash_equals( $evidence_digest, $verified_digest ) ) return self::rejected( array( 'verifier_digest_mismatch' ), $issued_at, $verifier_id, $issuer_id );

		$behavioral = true === $canonical['observations']['behavioral_passed'] && in_array( 'behavioral', $verifier['scopes'], true );
		$rollback = true === $canonical['observations']['rollback_passed'] && in_array( 'rollback', $verifier['scopes'], true );
		return array(
			'accepted' => $behavioral,
			'behavioral_verified' => $behavioral,
			'rollback_verified' => $rollback,
			'issued_at' => $issued_at,
			'expires_at' => $expires_at,
			'verifier_id' => $verifier_id,
			'issuer_id' => $issuer_id,
			'signature_scheme' => $verifier['signature_scheme'],
			'verifier_provenance' => $verifier['verifier_provenance'],
			'evidence_digest' => $evidence_digest,
			'rejection_reasons' => $behavioral ? array() : array( 'behavioral_scope_or_observation_missing' ),
		);
	}

	private static function callback_owned_by_control_plane( $callback ) {
		try {
			if ( $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				$reflection = new ReflectionMethod( $class, $method );
			} elseif ( is_string( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return false;
			}
			$file = $reflection->getFileName();
		} catch ( Throwable $error ) {
			return false;
		}
		$root = defined( 'MAD4B_SCP_DIR' ) ? realpath( MAD4B_SCP_DIR ) : false;
		$file = is_string( $file ) ? realpath( $file ) : false;
		if ( false === $root || false === $file ) return false;
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$file = str_replace( '\\', '/', $file );
		return $file === $root || 0 === strpos( $file, $root . '/' );
	}

	private static function rejected( array $reasons, $issued_at = 0, $verifier_id = '', $issuer_id = '' ) {
		return array(
			'accepted' => false,
			'behavioral_verified' => false,
			'rollback_verified' => false,
			'issued_at' => (int) $issued_at,
			'verifier_id' => (string) $verifier_id,
			'issuer_id' => (string) $issuer_id,
			'rejection_reasons' => array_values( array_unique( array_filter( array_map( 'strval', $reasons ) ) ) ),
		);
	}

	private static function public_receipt( array $receipt ) {
		return array(
			'issued_at' => isset( $receipt['issued_at'] ) ? (int) $receipt['issued_at'] : 0,
			'expires_at' => isset( $receipt['expires_at'] ) ? (int) $receipt['expires_at'] : 0,
			'verifier_id' => isset( $receipt['verifier_id'] ) ? (string) $receipt['verifier_id'] : '',
			'issuer_id' => isset( $receipt['issuer_id'] ) ? (string) $receipt['issuer_id'] : '',
			'signature_scheme' => isset( $receipt['signature_scheme'] ) ? (string) $receipt['signature_scheme'] : '',
			'verifier_provenance' => isset( $receipt['verifier_provenance'] ) ? (string) $receipt['verifier_provenance'] : '',
			'evidence_digest' => isset( $receipt['evidence_digest'] ) ? (string) $receipt['evidence_digest'] : '',
			'behavioral_verified' => ! empty( $receipt['behavioral_verified'] ),
			'rollback_verified' => ! empty( $receipt['rollback_verified'] ),
		);
	}

	private static function rejection_reasons( array $rejected ) {
		$reasons = array();
		foreach ( $rejected as $item ) foreach ( (array) ( isset( $item['rejection_reasons'] ) ? $item['rejection_reasons'] : array() ) as $reason ) $reasons[] = (string) $reason;
		$reasons = array_values( array_unique( array_filter( $reasons ) ) );
		sort( $reasons, SORT_STRING );
		return array_slice( $reasons, 0, 32 );
	}

	private static function empty_status( array $context, array $reasons ) {
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => isset( $context['provider_id'] ) ? $context['provider_id'] : '',
			'capability_id' => isset( $context['capability_id'] ) ? $context['capability_id'] : '',
			'state' => 'blocked',
			'behavioral_verified' => false,
			'rollback_verified' => false,
			'accepted_receipt' => array(),
			'accepted_count' => 0,
			'rejected_count' => 0,
			'rejection_reasons' => array_values( array_unique( $reasons ) ),
			'verifier_provenance_required' => 'mad4b_control_plane_source',
			'authorizing' => false,
			'activation_granted' => false,
			'mutation_granted' => false,
		);
	}

	private static function clean_id( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $value ) ? $value : '';
	}

	private static function clean_capability( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $value ) ? $value : '';
	}

	private static function clean_digest( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function stable_digest( $value ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}
}
