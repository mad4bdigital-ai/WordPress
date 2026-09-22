<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Acceptance_Planner {
	const CONTRACT = 'mad4b.acceptance-plan.v1';
	private $registry;

	public function __construct( MAD4B_SCP_Acceptance_Provider_Registry $registry ) { $this->registry = $registry; }

	public function plan( $input ) {
		$request = $this->normalize_request( $input );
		if ( ! empty( $request['blocking_reasons'] ) ) return $this->blocked( $request, $request['blocking_reasons'] );
		$provider = $this->resolve_provider( $request['provider_id'] );
		if ( ! $provider ) return $this->blocked( $request, array( '' === $request['provider_id'] ? 'provider_id_required' : 'provider_unavailable' ) );
		$request['provider_id'] = $provider['provider_id'];
		try { $provider_plan = call_user_func( $provider['plan_callback'], array( 'profile_id' => $request['profile_id'], 'suite' => $request['suite'] ) ); }
		catch ( Throwable $error ) { return $this->blocked( $request, array( 'provider_plan_exception' ) ); }
		if ( ! is_array( $provider_plan ) ) return $this->blocked( $request, array( 'provider_plan_invalid' ) );
		if ( $provider['provider_id'] !== $this->clean_id( isset( $provider_plan['provider_id'] ) ? $provider_plan['provider_id'] : '' ) ) return $this->blocked( $request, array( 'provider_plan_identity_mismatch' ) );
		if ( ! array_key_exists( 'authorizing', $provider_plan ) || false !== $provider_plan['authorizing'] ) return $this->blocked( $request, array( 'provider_plan_authority_violation' ) );
		if ( ! array_key_exists( 'read_only', $provider_plan ) || true !== $provider_plan['read_only'] ) return $this->blocked( $request, array( 'provider_plan_not_read_only' ) );
		$state = isset( $provider_plan['state'] ) ? sanitize_key( (string) $provider_plan['state'] ) : 'blocked';
		if ( 'ready' === $state && $request['profile_id'] !== $this->clean_id( isset( $provider_plan['profile_id'] ) ? $provider_plan['profile_id'] : '' ) ) return $this->blocked( $request, array( 'provider_plan_profile_mismatch' ) );
		if ( ! in_array( $state, array( 'ready', 'blocked' ), true ) ) $state = 'blocked';
		$reasons = isset( $provider_plan['blocking_reasons'] ) && is_array( $provider_plan['blocking_reasons'] ) ? $this->bounded_reasons( $provider_plan['blocking_reasons'] ) : array();
		if ( 'blocked' === $state && empty( $reasons ) ) $reasons[] = 'provider_plan_blocked';
		return array(
			'contract' => self::CONTRACT,
			'state' => $state,
			'provider_id' => $provider['provider_id'],
			'provider_contract' => $provider['contract'],
			'profile_id' => $request['profile_id'],
			'suite' => $request['suite'],
			'plan_digest' => isset( $provider_plan['plan_digest'] ) ? substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $provider_plan['plan_digest'] ) ), 0, 64 ) : '',
			'provider_plan' => $provider_plan,
			'authorizing' => false,
			'read_only' => true,
			'blocking_reasons' => $reasons,
		);
	}

	private function resolve_provider( $provider_id ) {
		if ( '' !== $provider_id ) return $this->registry->resolve( $provider_id );
		$providers = $this->registry->all();
		return 1 === count( $providers ) ? reset( $providers ) : null;
	}

	private function normalize_request( $input ) {
		$input = is_array( $input ) ? $input : array();
		$unknown = array_diff( array_keys( $input ), array( 'provider_id', 'profile_id', 'suite' ) );
		$provider_raw = isset( $input['provider_id'] ) ? trim( (string) $input['provider_id'] ) : '';
		$provider_id = $this->clean_id( $provider_raw );
		$profile_id = $this->clean_id( isset( $input['profile_id'] ) ? $input['profile_id'] : '' );
		$suite = $this->clean_id( isset( $input['suite'] ) ? $input['suite'] : 'semantic' );
		$reasons = array();
		if ( $unknown ) $reasons[] = 'unsupported_request_fields';
		if ( '' !== $provider_raw && '' === $provider_id ) $reasons[] = 'provider_id_invalid';
		if ( '' === $profile_id ) $reasons[] = 'profile_id_required';
		if ( ! in_array( $suite, array( 'semantic', 'full_semantic' ), true ) ) $reasons[] = 'unsupported_suite';
		return array( 'provider_id' => $provider_id, 'profile_id' => $profile_id, 'suite' => $suite, 'blocking_reasons' => $reasons );
	}

	private function blocked( array $request, array $reasons ) {
		return array(
			'contract' => self::CONTRACT,
			'state' => 'blocked',
			'provider_id' => isset( $request['provider_id'] ) ? $request['provider_id'] : '',
			'provider_contract' => '',
			'profile_id' => isset( $request['profile_id'] ) ? $request['profile_id'] : '',
			'suite' => isset( $request['suite'] ) ? $request['suite'] : 'semantic',
			'plan_digest' => '',
			'provider_plan' => array(),
			'authorizing' => false,
			'read_only' => true,
			'blocking_reasons' => $this->bounded_reasons( $reasons ),
		);
	}

	private function bounded_reasons( array $reasons ) {
		$out = array();
		foreach ( array_slice( $reasons, 0, 32 ) as $reason ) {
			$reason = sanitize_key( (string) $reason );
			if ( '' !== $reason ) $out[] = $reason;
		}
		return array_values( array_unique( $out ) );
	}

	private function clean_id( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z0-9][a-z0-9._\-]{0,63}$/', $value ) ? $value : '';
	}
}
