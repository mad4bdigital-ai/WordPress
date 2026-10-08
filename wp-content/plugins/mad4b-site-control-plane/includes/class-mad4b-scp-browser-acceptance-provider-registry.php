<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Browser_Acceptance_Provider_Registry {
	const CONTRACT = 'mad4b.browser-acceptance-provider-registry.v1';
	const MAX_PROVIDERS = 32;

	public function all() {
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'mad4b_browser_acceptance_providers', array() ) : array();
		if ( ! is_array( $raw ) || count( $raw ) > self::MAX_PROVIDERS ) return array();
		$providers = array();
		$seen = array();
		foreach ( $raw as $key => $candidate ) {
			$validated = $this->validate_provider( $key, $candidate );
			if ( empty( $validated['valid'] ) ) continue;
			$id = $validated['provider_id'];
			if ( isset( $seen[ $id ] ) ) {
				unset( $providers[ $id ] ); // Do not select a duplicate ID by insertion order.
			} else {
				$providers[ $id ] = $validated;
			}
			$seen[ $id ] = true;
		}
		ksort( $providers, SORT_STRING );
		return $providers;
	}

	public function inventory() {
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'mad4b_browser_acceptance_providers', array() ) : array();
		if ( ! is_array( $raw ) || count( $raw ) > self::MAX_PROVIDERS ) return array(
			'contract' => self::CONTRACT, 'authorizing' => false, 'read_only' => true,
			'provider_count' => 0,
			'providers' => array( array(
				'provider_id' => '', 'contract' => '', 'valid' => false,
				'blocking_reasons' => array( 'provider_registry_invalid_or_capacity_exceeded' ),
				'descriptor' => array(),
			) ),
		);
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
		$counts = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['valid'] ) ) $counts[ $item['provider_id'] ] = isset( $counts[ $item['provider_id'] ] ) ? $counts[ $item['provider_id'] ] + 1 : 1;
		}
		foreach ( $items as &$item ) {
			if ( ! empty( $item['valid'] ) && $counts[ $item['provider_id'] ] > 1 ) {
				$item['valid'] = false;
				$item['blocking_reasons'][] = 'provider_id_duplicate';
				$item['descriptor'] = array();
			}
		}
		unset( $item );
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
		foreach ( array( 'descriptor_callback', 'capabilities_callback', 'plan_callback', 'result_callback' ) as $callback_key ) {
			if ( ! isset( $candidate[ $callback_key ] ) || ! is_callable( $candidate[ $callback_key ] ) ) $reasons[] = $callback_key . '_unavailable';
		}
		if ( true !== ( isset( $candidate['read_only'] ) ? $candidate['read_only'] : null ) ) $reasons[] = 'provider_not_read_only';
		if ( false !== ( isset( $candidate['authorizing'] ) ? $candidate['authorizing'] : null ) ) $reasons[] = 'provider_authorizing';
		if ( false !== ( isset( $candidate['transport_owned_by_provider'] ) ? $candidate['transport_owned_by_provider'] : null ) ) $reasons[] = 'provider_transport_authority';
		if ( 'external_browser_agent' !== (string) ( isset( $candidate['execution_mode'] ) ? $candidate['execution_mode'] : '' ) ) $reasons[] = 'provider_execution_mode_invalid';

		$descriptor = array();
		if ( empty( $reasons ) ) {
			try { $descriptor = call_user_func( $candidate['descriptor_callback'] ); }
			catch ( Throwable $error ) { $reasons[] = 'provider_descriptor_exception'; }
		}
		if ( ! is_array( $descriptor ) || ! $descriptor ) { $descriptor = array(); $reasons[] = 'provider_descriptor_invalid'; }
		if ( $descriptor ) {
			if ( $provider_id !== $this->clean_id( isset( $descriptor['provider_id'] ) ? $descriptor['provider_id'] : '' ) ) $reasons[] = 'descriptor_provider_mismatch';
			if ( $contract !== (string) ( isset( $descriptor['contract'] ) ? $descriptor['contract'] : '' ) ) $reasons[] = 'descriptor_contract_mismatch';
			if ( true !== ( isset( $descriptor['read_only'] ) ? $descriptor['read_only'] : null ) ) $reasons[] = 'descriptor_not_read_only';
			if ( false !== ( isset( $descriptor['authorizing'] ) ? $descriptor['authorizing'] : null ) ) $reasons[] = 'descriptor_authorizing';
			if ( false !== ( isset( $descriptor['transport_owned_by_provider'] ) ? $descriptor['transport_owned_by_provider'] : null ) ) $reasons[] = 'descriptor_transport_authority';
			if ( false !== ( isset( $descriptor['browser_engine_owned_by_provider'] ) ? $descriptor['browser_engine_owned_by_provider'] : null ) ) $reasons[] = 'descriptor_browser_engine_authority';
			if ( 'external_browser_agent' !== (string) ( isset( $descriptor['execution_mode'] ) ? $descriptor['execution_mode'] : '' ) ) $reasons[] = 'descriptor_execution_mode_invalid';
			foreach ( array( 'business_state_mutation', 'profile_mutation', 'seo_mutation', 'production_activation' ) as $effect ) {
				if ( ! array_key_exists( $effect, $descriptor ) || false !== $descriptor[ $effect ] ) $reasons[] = 'descriptor_effect_not_read_only:' . $effect;
			}
			foreach ( array( 'arbitrary_url_input', 'arbitrary_javascript_input' ) as $field ) {
				if ( ! array_key_exists( $field, $descriptor ) || false !== $descriptor[ $field ] ) $reasons[] = 'descriptor_arbitrary_input_not_disabled:' . $field;
			}
			foreach ( $descriptor as $field => $value ) {
				if ( 0 === strpos( (string) $field, 'arbitrary_' ) && false !== $value ) $reasons[] = 'descriptor_arbitrary_input_not_disabled:' . (string) $field;
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
			'result_callback' => isset( $candidate['result_callback'] ) ? $candidate['result_callback'] : null,
		);
	}

	private function clean_id( $value ) {
		if ( ! is_string( $value ) ) return '';
		return preg_match( '/^[a-z0-9][a-z0-9._\-]{0,63}$/D', $value ) ? $value : '';
	}
}
