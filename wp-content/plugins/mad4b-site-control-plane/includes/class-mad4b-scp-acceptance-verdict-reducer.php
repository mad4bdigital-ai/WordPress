<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Acceptance_Verdict_Reducer {
	const CONTRACT = 'mad4b.acceptance-result.v1';
	private $allowed = array( 'PASS', 'FAIL', 'BLOCKED', 'INCOMPLETE_EVIDENCE', 'STALE', 'NOT_APPLICABLE' );

	public function reduce( array $evidence ) {
		$verdict = strtoupper( (string) ( isset( $evidence['verdict'] ) ? $evidence['verdict'] : 'BLOCKED' ) );
		if ( ! in_array( $verdict, $this->allowed, true ) ) $verdict = 'BLOCKED';
		$blocking = $this->reasons( isset( $evidence['blocking_reasons'] ) && is_array( $evidence['blocking_reasons'] ) ? $evidence['blocking_reasons'] : array() );
		$defects = $this->reasons( isset( $evidence['defect_reasons'] ) && is_array( $evidence['defect_reasons'] ) ? $evidence['defect_reasons'] : array() );
		$incomplete = $this->reasons( isset( $evidence['incomplete_evidence'] ) && is_array( $evidence['incomplete_evidence'] ) ? $evidence['incomplete_evidence'] : array() );
		if ( $blocking && 'BLOCKED' !== $verdict ) $verdict = 'BLOCKED';
		elseif ( $defects && 'PASS' === $verdict ) $verdict = 'FAIL';
		$verification = isset( $evidence['verification'] ) && is_array( $evidence['verification'] ) ? $evidence['verification'] : array();
		$semantic_verified = 'PASS' === $verdict && true === ( isset( $verification['semantic_parity_verified'] ) ? $verification['semantic_parity_verified'] : false );
		$browser_verified = true === ( isset( $verification['browser_runtime_parity_verified'] ) ? $verification['browser_runtime_parity_verified'] : false );
		$browser_status = $browser_verified ? 'PASS' : 'INCOMPLETE_EVIDENCE';
		$overall = 'PASS' === $verdict && ! $browser_verified ? 'INCOMPLETE_EVIDENCE' : $verdict;
		$tests = array();
		foreach ( array_slice( isset( $evidence['tests'] ) && is_array( $evidence['tests'] ) ? $evidence['tests'] : array(), 0, 64, true ) as $name => $status ) {
			$name = sanitize_key( (string) $name );
			$status = strtoupper( (string) $status );
			if ( '' !== $name && in_array( $status, $this->allowed, true ) ) $tests[ $name ] = $status;
		}
		return array(
			'contract' => self::CONTRACT,
			'verdict' => $verdict,
			'overall_evidence_status' => $overall,
			'classification' => isset( $evidence['classification'] ) ? sanitize_key( (string) $evidence['classification'] ) : '',
			'verification' => array(
				'semantic_parity_verified' => $semantic_verified,
				'browser_runtime_parity_verified' => $browser_verified,
				'browser_evidence_status' => $browser_status,
				'verified_through' => isset( $verification['verified_through'] ) ? sanitize_key( (string) $verification['verified_through'] ) : 'none',
			),
			'tests' => $tests,
			'blocking_reasons' => $blocking,
			'defect_reasons' => $defects,
			'incomplete_evidence' => $incomplete,
			'authorizing' => false,
			'read_only' => true,
		);
	}

	private function reasons( array $items ) {
		$out = array();
		foreach ( array_slice( $items, 0, 64 ) as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item ) $out[] = $item;
		}
		return array_values( array_unique( $out ) );
	}
}
