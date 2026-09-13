<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Acceptance_Runner {
	const CONTRACT = 'mad4b.acceptance-run.v1';
	private $registry;
	private $planner;
	private $reducer;

	public function __construct( MAD4B_SCP_Acceptance_Provider_Registry $registry, MAD4B_SCP_Acceptance_Planner $planner, MAD4B_SCP_Acceptance_Verdict_Reducer $reducer ) {
		$this->registry = $registry;
		$this->planner = $planner;
		$this->reducer = $reducer;
	}

	public function run( $input ) {
		$plan = $this->planner->plan( $input );
		if ( 'ready' !== (string) ( isset( $plan['state'] ) ? $plan['state'] : '' ) ) return $this->blocked_from_plan( $plan );
		$provider = $this->registry->resolve( $plan['provider_id'] );
		if ( ! $provider ) return $this->blocked_from_plan( $plan, array( 'provider_unavailable_after_plan' ) );
		$request = array( 'profile_id' => $plan['profile_id'], 'suite' => $plan['suite'] );
		try { $evidence = call_user_func( $provider['run_callback'], $request ); }
		catch ( Throwable $error ) { return $this->blocked_from_plan( $plan, array( 'provider_run_exception' ) ); }
		if ( ! is_array( $evidence ) ) return $this->blocked_from_plan( $plan, array( 'provider_evidence_invalid' ) );
		$authority_violation = $this->authority_violation( $evidence );
		if ( $authority_violation ) return $this->blocked_from_plan( $plan, $authority_violation );
		if ( $provider['provider_id'] !== $this->clean_id( isset( $evidence['provider_id'] ) ? $evidence['provider_id'] : '' ) ) return $this->blocked_from_plan( $plan, array( 'provider_evidence_identity_mismatch' ) );
		if ( $provider['contract'] !== (string) ( isset( $evidence['contract'] ) ? $evidence['contract'] : '' ) ) return $this->blocked_from_plan( $plan, array( 'provider_evidence_contract_mismatch' ) );
		if ( $plan['profile_id'] !== $this->clean_id( isset( $evidence['profile_id'] ) ? $evidence['profile_id'] : '' ) ) return $this->blocked_from_plan( $plan, array( 'provider_evidence_profile_mismatch' ) );
		$evidence_digest = isset( $evidence['plan_digest'] ) ? strtolower( (string) $evidence['plan_digest'] ) : '';
		if ( '' !== (string) $plan['plan_digest'] && ! hash_equals( (string) $plan['plan_digest'], $evidence_digest ) ) return $this->blocked_from_plan( $plan, array( 'provider_evidence_plan_mismatch' ) );
		$result = $this->reducer->reduce( $evidence );
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => $provider['provider_id'],
			'provider_contract' => $provider['contract'],
			'profile_id' => $plan['profile_id'],
			'suite' => $plan['suite'],
			'plan_digest' => isset( $plan['plan_digest'] ) ? $plan['plan_digest'] : '',
			'provider_evidence' => $evidence,
			'result' => $result,
			'authority' => $this->authority(),
			'effects' => $this->effects(),
			'authorizing' => false,
			'read_only' => true,
		);
	}

	private function authority_violation( array $evidence ) {
		$reasons = array();
		$authority = isset( $evidence['authority'] ) && is_array( $evidence['authority'] ) ? $evidence['authority'] : array();
		foreach ( array( 'authorizing', 'persistent_mutation', 'profile_mutation', 'seo_publication', 'production_activation' ) as $key ) {
			if ( ! array_key_exists( $key, $authority ) || false !== $authority[ $key ] ) $reasons[] = 'provider_authority_violation:' . $key;
		}
		$effects = isset( $evidence['effects'] ) && is_array( $evidence['effects'] ) ? $evidence['effects'] : array();
		foreach ( array( 'business_state_mutation', 'authority_mutation', 'seo_mutation', 'profile_mutation', 'observational_persistence' ) as $key ) {
			if ( ! array_key_exists( $key, $effects ) || false !== $effects[ $key ] ) $reasons[] = 'provider_effect_violation:' . $key;
		}
		return $reasons;
	}

	private function blocked_from_plan( array $plan, array $extra = array() ) {
		$reasons = array_values( array_unique( array_merge( isset( $plan['blocking_reasons'] ) && is_array( $plan['blocking_reasons'] ) ? $plan['blocking_reasons'] : array(), $extra ) ) );
		$evidence = array(
			'verdict' => 'BLOCKED',
			'classification' => 'acceptance_execution_blocked',
			'verification' => array( 'semantic_parity_verified' => false, 'browser_runtime_parity_verified' => false, 'verified_through' => 'none' ),
			'tests' => array( 'acceptance_plan' => 'BLOCKED' ),
			'blocking_reasons' => $reasons,
			'defect_reasons' => array(),
			'incomplete_evidence' => array( 'browser_runtime_not_observed' ),
		);
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => isset( $plan['provider_id'] ) ? $plan['provider_id'] : '',
			'provider_contract' => isset( $plan['provider_contract'] ) ? $plan['provider_contract'] : '',
			'profile_id' => isset( $plan['profile_id'] ) ? $plan['profile_id'] : '',
			'suite' => isset( $plan['suite'] ) ? $plan['suite'] : 'semantic',
			'plan_digest' => isset( $plan['plan_digest'] ) ? $plan['plan_digest'] : '',
			'provider_evidence' => array(),
			'result' => $this->reducer->reduce( $evidence ),
			'authority' => $this->authority(),
			'effects' => $this->effects(),
			'authorizing' => false,
			'read_only' => true,
		);
	}

	private function authority() { return array( 'authorizing' => false, 'persistent_mutation' => false, 'profile_mutation' => false, 'seo_publication' => false, 'production_activation' => false ); }
	private function effects() { return array( 'business_state_mutation' => false, 'authority_mutation' => false, 'seo_mutation' => false, 'profile_mutation' => false, 'observational_persistence' => false ); }
	private function clean_id( $value ) { $value = strtolower( trim( (string) $value ) ); return preg_match( '/^[a-z0-9][a-z0-9._\-]{0,63}$/', $value ) ? $value : ''; }
}
