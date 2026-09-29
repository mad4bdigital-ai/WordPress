<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical projection helpers for runtime truth.
 *
 * Source classes own facts. Composite/status classes may only project those
 * facts; they must not independently re-derive ready/verified/fresh state.
 */
final class MAD4B_SCP_Truth_Projection {
	const CONTRACT = 'mad4b.truth-projection.v1';

	public static function tri_state( $source, $key ) {
		if ( ! is_array( $source ) || ! array_key_exists( $key, $source ) ) return null;
		if ( null === $source[ $key ] ) return null;
		return (bool) $source[ $key ];
	}

	public static function canonical_external_wpml_receipt() {
		if ( class_exists( 'MAD4B_SCP_External_WPML_Acceptance_Finalizer' )
			&& method_exists( 'MAD4B_SCP_External_WPML_Acceptance_Finalizer', 'external_wpml_receipt_status' ) ) {
			$receipt = MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status();
		} elseif ( class_exists( 'MAD4B_SCP_WPML_Response_Contract' )
			&& method_exists( 'MAD4B_SCP_WPML_Response_Contract', 'receipt_status' ) ) {
			$receipt = MAD4B_SCP_WPML_Response_Contract::receipt_status();
		} else {
			$receipt = array();
		}
		if ( ! is_array( $receipt ) ) $receipt = array();

		$bounded = array();
		foreach ( array(
			'contract', 'authority_contract', 'observed', 'verified', 'success',
			'success_semantics', 'stale', 'state', 'route_registered',
			'response_status', 'classification', 'status', 'get_parameters',
			'observed_at', 'build_fingerprint', 'current_build_fingerprint',
		) as $key ) {
			if ( array_key_exists( $key, $receipt ) ) $bounded[ $key ] = $receipt[ $key ];
		}
		if ( ! array_key_exists( 'verified', $bounded ) ) $bounded['verified'] = false;
		if ( ! array_key_exists( 'stale', $bounded ) ) $bounded['stale'] = true;
		if ( ! isset( $bounded['state'] ) ) $bounded['state'] = 'pending_external_evidence';
		return $bounded;
	}

	public static function external_wpml( $required = true ) {
		$required = (bool) $required;
		$receipt = self::canonical_external_wpml_receipt();
		$verified = ! $required || ( ! empty( $receipt['verified'] ) && empty( $receipt['stale'] ) );
		return array(
			'contract' => self::CONTRACT,
			'required' => $required,
			'verified' => $verified,
			'state' => ! $required ? 'not_required' : ( $verified ? 'verified' : 'pending' ),
			'source_contract' => isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '',
			'receipt' => $receipt,
		);
	}

	public static function gate_effective_ready( array $gate ) {
		$freshness_required = array_key_exists( 'freshness_required', $gate )
			? ! empty( $gate['freshness_required'] )
			: array_key_exists( 'fresh', $gate );
		return ! empty( $gate['ready'] )
			&& ( ! $freshness_required || ! empty( $gate['fresh'] ) );
	}

	public static function gate( $ready, $state, $fresh, $contract, array $blockers = array(), $observed_at = '', $freshness_required = true ) {
		$gate = array(
			'state' => sanitize_key( (string) $state ),
			'ready' => (bool) $ready,
			'fresh' => (bool) $fresh,
			'freshness_required' => (bool) $freshness_required,
			'source_contract' => (string) $contract,
			'blockers' => array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) ),
			'observed_at' => '' !== (string) $observed_at ? (string) $observed_at : gmdate( 'c' ),
		);
		$gate['effective_ready'] = self::gate_effective_ready( $gate );
		return $gate;
	}
}
