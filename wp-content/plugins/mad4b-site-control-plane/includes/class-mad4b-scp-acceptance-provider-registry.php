<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Acceptance_Provider_Registry {
	const CONTRACT = 'mad4b.acceptance-provider-registry.v1';
	const MAX_PROVIDERS = 32;

	public function all() {
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'mad4b_live_acceptance_providers', array() ) : array();
		$raw = is_array( $raw ) ? array_slice( $raw, 0, self::MAX_PROVIDERS, true ) : array();
		$providers = array();
		foreach ( $raw as $key => $candidate ) {
			$validated = $this->validate_provider( $key, $candidate );
			if ( ! empty( $validated['valid'] ) ) $providers[ $validated['provider_id'] ] = $validated;
		}
		ksort( $providers, SORT_STRING );
		return $providers;
	}

	public function inventory() {
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'mad4b_live_acceptance_providers', array() ) : array();
		$raw = is_array( $raw ) ? array_slice( $raw, 0, self::MAX_PROVIDERS, true ) : array();
		$items = array();
		foreach ( $raw as $key => $candidate ) {
			$validated = $this->validate_provider( $key, $candidate );
			$items[] = array(
				'provider_id' => isset( $validated['provider_id'] ) ? $validated['provider_id'] : $this->clean_id( $key ),
				'contract' => isset( $validated['contract'] ) ? $validated['contract'] : '',
				'valid' => ! empty( $validated['valid'] ),
				'blocking_reasons' => isset( $validated['blocking_reasons'] ) ? $validated['blocking_reasons'] : array( 'provider_invalid' ),
				'descriptor' => ! empty( $validated['valid'] ) ? $validated['descriptor'] : array(),
			);
		}
		usort( $items, static function ( $a, $b ) { return strcmp( (string) $a['provider_id'], (string) $b['provider_id'] ); } );
		return array(
			'contract' => self::CONTRACT,
			'authorizing' => false,
			'read_only' => true,
			'provider_count' => count( $items ),
			'providers' => $items,
		);
	}

	public function resolve( $provider_id ) {
		$provider_id = $this->clean_id( $provider_id );
		$providers = $this->all();
		return isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : null;
	}

	private function validate_provider( $key, $candidate ) {
		$reasons = array();
		$candidate = is_array( $candidate ) ? $candidate : array();
		$provider_id = $this->clean_id( isset( $candidate['provider_id'] ) ? $candidate['provider_id'] : $key );
		$contract = isset( $candidate['contract'] ) && is_scalar( $candidate['contract'] ) ? trim( (string) $candidate['contract'] ) : '';
		if ( '' === $provider_id ) $reasons[] = 'provider_id_invalid';
		if ( '' === $contract || strlen( $contract ) > 160 ) $reasons[] = 'provider_contract_invalid';
		foreach ( array( 'descriptor_callback', 'capabilities_callback', 'plan_callback', 'run_callback' ) as $callback_key ) {
			if ( ! isset( $candidate[ $callback_key ] ) || ! is_callable( $candidate[ $callback_key ] ) ) $reasons[] = $callback_key . '_unavailable';
		}
		if ( true !== ( isset( $candidate['read_only'] ) ? $candidate['read_only'] : null ) ) $reasons[] = 'provider_not_read_only';
		if ( false !== ( isset( $candidate['authorizing'] ) ? $candidate['authorizing'] : null ) ) $reasons[] = 'provider_authorizing';
		if ( false !== ( isset( $candidate['profile_mutation'] ) ? $candidate['profile_mutation'] : null ) ) $reasons[] = 'provider_profile_mutation';
		if ( false !== ( isset( $candidate['transport_owned_by_provider'] ) ? $candidate['transport_owned_by_provider'] : null ) ) $reasons[] = 'provider_transport_authority';

		$descriptor = array();
		if ( empty( $reasons ) ) {
			try { $descriptor = call_user_func( $candidate['descriptor_callback'] ); }
			catch ( Throwable $error ) { $reasons[] = 'provider_descriptor_exception'; }
		}
		if ( ! is_array( $descriptor ) ) { $descriptor = array(); $reasons[] = 'provider_descriptor_invalid'; }
		if ( $descriptor ) {
			if ( $provider_id !== $this->clean_id( isset( $descriptor['provider_id'] ) ? $descriptor['provider_id'] : '' ) ) $reasons[] = 'descriptor_provider_mismatch';
			if ( $contract !== (string) ( isset( $descriptor['contract'] ) ? $descriptor['contract'] : '' ) ) $reasons[] = 'descriptor_contract_mismatch';
			if ( true !== ( isset( $descriptor['read_only'] ) ? $descriptor['read_only'] : null ) ) $reasons[] = 'descriptor_not_read_only';
			if ( false !== ( isset( $descriptor['authorizing'] ) ? $descriptor['authorizing'] : null ) ) $reasons[] = 'descriptor_authorizing';
			if ( false !== ( isset( $descriptor['profile_mutation'] ) ? $descriptor['profile_mutation'] : null ) ) $reasons[] = 'descriptor_profile_mutation';
			if ( false !== ( isset( $descriptor['transport_owned_by_provider'] ) ? $descriptor['transport_owned_by_provider'] : null ) ) $reasons[] = 'descriptor_transport_authority';
			foreach ( $descriptor as $field => $value ) {
				if ( 0 === strpos( (string) $field, 'arbitrary_' ) && true === $value ) $reasons[] = 'descriptor_arbitrary_input_enabled:' . $this->clean_id( $field );
			}
			$effects = isset( $descriptor['effects'] ) && is_array( $descriptor['effects'] ) ? $descriptor['effects'] : array();
			foreach ( array( 'business_state_mutation', 'authority_mutation', 'seo_mutation', 'profile_mutation', 'observational_persistence' ) as $effect ) {
				if ( ! array_key_exists( $effect, $effects ) || false !== $effects[ $effect ] ) $reasons[] = 'descriptor_effect_not_read_only:' . $effect;
			}
		}
		$reasons = array_values( array_unique( $reasons ) );
		return array(
			'valid' => empty( $reasons ),
			'provider_id' => $provider_id,
			'contract' => $contract,
			'descriptor' => $descriptor,
			'blocking_reasons' => $reasons,
			'descriptor_callback' => isset( $candidate['descriptor_callback'] ) ? $candidate['descriptor_callback'] : null,
			'capabilities_callback' => isset( $candidate['capabilities_callback'] ) ? $candidate['capabilities_callback'] : null,
			'plan_callback' => isset( $candidate['plan_callback'] ) ? $candidate['plan_callback'] : null,
			'run_callback' => isset( $candidate['run_callback'] ) ? $candidate['run_callback'] : null,
		);
	}

	private function clean_id( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z0-9][a-z0-9._\-]{0,63}$/', $value ) ? $value : '';
	}
}
