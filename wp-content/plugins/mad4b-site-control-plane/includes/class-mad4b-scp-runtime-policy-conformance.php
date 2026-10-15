<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Verifies read-conformance receipts for Runtime Policy classification.
 *
 * Verification is non-authorizing. Receipts must bind to the exact graph,
 * descriptor, schemas, provider contract, current provider capability and
 * runtime artifact. Verifier callbacks must originate from the MAD4B code root.
 */
final class MAD4B_SCP_Runtime_Policy_Conformance {
	const CONTRACT = 'mad4b.runtime-policy-conformance-receipt.v2';
	const MAX_VERIFIERS = 16;

	public static function verify( $receipt, $ability_name, array $node, array $graph ) {
		$base = array(
			'verified' => false,
			'reason' => 'conformance_receipt_missing_or_invalid',
			'contract' => self::CONTRACT,
			'evidence_sha256' => '',
			'receipt_sha256' => '',
			'output_classification' => 'unknown',
			'issuer_contract' => '',
		);
		if ( ! is_array( $receipt ) || self::CONTRACT !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) return $base;

		$trusted_issuers = array(
			'mad4b.provider-compatibility-certification.v1',
			'mad4b.runtime-compatibility-profile.v1',
		);
		$issuer = isset( $receipt['issuer_contract'] ) ? (string) $receipt['issuer_contract'] : '';
		if ( ! in_array( $issuer, $trusted_issuers, true ) ) {
			$base['reason'] = 'conformance_issuer_untrusted';
			return $base;
		}

		$verifier_id = isset( $receipt['verifier_id'] ) ? sanitize_key( (string) $receipt['verifier_id'] ) : '';
		$issuer_id = isset( $receipt['issuer_id'] ) ? sanitize_key( (string) $receipt['issuer_id'] ) : '';
		$signature = isset( $receipt['signature'] ) && is_scalar( $receipt['signature'] ) ? trim( (string) $receipt['signature'] ) : '';
		$verifiers = self::trusted_verifiers( $ability_name, $node, $graph );
		if ( '' === $verifier_id || ! isset( $verifiers[ $verifier_id ] ) ) {
			$base['reason'] = 'conformance_verifier_untrusted';
			return $base;
		}
		$verifier = $verifiers[ $verifier_id ];
		if ( ! hash_equals( (string) $verifier['issuer_contract'], $issuer ) || '' === $issuer_id || ! hash_equals( (string) $verifier['issuer_id'], $issuer_id ) ) {
			$base['reason'] = 'conformance_verifier_issuer_binding_mismatch';
			return $base;
		}
		if ( '' === $signature || strlen( $signature ) > 1024 ) {
			$base['reason'] = 'conformance_signature_invalid';
			return $base;
		}

		$expected_provider_sha = '';
		$provider = isset( $node['execution_provider'] ) ? (string) $node['execution_provider'] : '';
		foreach ( isset( $graph['nodes']['providers'] ) && is_array( $graph['nodes']['providers'] ) ? $graph['nodes']['providers'] : array() as $provider_row ) {
			if ( is_array( $provider_row ) && $provider === ( isset( $provider_row['id'] ) ? (string) $provider_row['id'] : '' ) ) {
				$expected_provider_sha = isset( $provider_row['contract_sha256'] ) ? (string) $provider_row['contract_sha256'] : '';
				break;
			}
		}

		$checks = array(
			'ability_name' => (string) $ability_name,
			'graph_generation_sha256' => isset( $graph['generation_sha256'] ) ? (string) $graph['generation_sha256'] : '',
			'descriptor_generation_sha256' => isset( $node['descriptor_generation_sha256'] ) ? (string) $node['descriptor_generation_sha256'] : '',
			'input_schema_sha256' => isset( $node['input_schema_sha256'] ) ? (string) $node['input_schema_sha256'] : '',
			'output_schema_sha256' => isset( $node['output_schema_sha256'] ) ? (string) $node['output_schema_sha256'] : '',
		);
		foreach ( $checks as $field => $expected ) {
			$actual = isset( $receipt[ $field ] ) ? (string) $receipt[ $field ] : '';
			if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
				$base['reason'] = 'conformance_binding_mismatch:' . $field;
				return $base;
			}
		}

		if ( '' !== $provider ) {
			$actual_provider_sha = isset( $receipt['provider_contract_sha256'] ) ? (string) $receipt['provider_contract_sha256'] : '';
			if ( '' === $expected_provider_sha || ! hash_equals( $expected_provider_sha, $actual_provider_sha ) ) {
				$base['reason'] = 'conformance_provider_binding_mismatch';
				return $base;
			}
		}

		$provider_binding = self::current_provider_capability_binding( $provider, $ability_name );
		if ( empty( $provider_binding['verified'] ) ) {
			$base['reason'] = isset( $provider_binding['reason'] ) ? (string) $provider_binding['reason'] : 'conformance_provider_capability_binding_unavailable';
			return $base;
		}
		$binding_checks = array(
			'capability_id' => 'conformance_capability_id_binding_mismatch',
			'artifact_fingerprint' => 'conformance_artifact_fingerprint_binding_mismatch',
			'capability_contract_digest' => 'conformance_capability_contract_digest_binding_mismatch',
		);
		foreach ( $binding_checks as $field => $reason ) {
			$expected = isset( $provider_binding[ $field ] ) ? (string) $provider_binding[ $field ] : '';
			$actual = isset( $receipt[ $field ] ) ? (string) $receipt[ $field ] : '';
			if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
				$legacy_reason = 'conformance_' . $field . '_binding_mismatch';
				$base['reason'] = '' !== $reason ? $reason : $legacy_reason;
				return $base;
			}
		}

		if ( 'zero_effect_read_verified' !== ( isset( $receipt['result'] ) ? (string) $receipt['result'] : '' ) ) {
			$base['reason'] = 'conformance_result_not_zero_effect_read';
			return $base;
		}
		if ( 0 !== (int) ( isset( $receipt['observed_writes'] ) ? $receipt['observed_writes'] : -1 )
			|| 0 !== (int) ( isset( $receipt['observed_external_effects'] ) ? $receipt['observed_external_effects'] : -1 ) ) {
			$base['reason'] = 'conformance_effects_observed';
			return $base;
		}
		$output_classification = isset( $receipt['output_classification'] ) ? sanitize_key( (string) $receipt['output_classification'] ) : '';
		if ( 'public_bounded' !== $output_classification ) {
			$base['reason'] = 'conformance_output_not_public_bounded';
			return $base;
		}

		$evidence_sha = isset( $receipt['evidence_sha256'] ) ? (string) $receipt['evidence_sha256'] : '';
		$claimed = isset( $receipt['receipt_sha256'] ) ? (string) $receipt['receipt_sha256'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $evidence_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $claimed ) ) {
			$base['reason'] = 'conformance_digest_invalid';
			return $base;
		}
		$basis = $receipt;
		unset( $basis['receipt_sha256'] );
		$expected_receipt = self::digest( self::CONTRACT, $basis );
		if ( ! hash_equals( $expected_receipt, $claimed ) ) {
			$base['reason'] = 'conformance_receipt_digest_mismatch';
			return $base;
		}

		try {
			$verification = call_user_func(
				$verifier['verify_callback'],
				$receipt,
				array(
					'ability_name' => (string) $ability_name,
					'graph_generation_sha256' => isset( $graph['generation_sha256'] ) ? (string) $graph['generation_sha256'] : '',
					'provider_contract_sha256' => $expected_provider_sha,
					'capability_id' => (string) $provider_binding['capability_id'],
					'artifact_fingerprint' => (string) $provider_binding['artifact_fingerprint'],
					'capability_contract_digest' => (string) $provider_binding['capability_contract_digest'],
				)
			);
		} catch ( Throwable $error ) {
			$base['reason'] = 'conformance_verifier_exception';
			return $base;
		}
		if ( ! is_array( $verification ) || empty( $verification['verified'] ) ) {
			$base['reason'] = 'conformance_signature_verification_failed';
			return $base;
		}
		$verified_evidence = isset( $verification['evidence_sha256'] ) ? (string) $verification['evidence_sha256'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $verified_evidence ) || ! hash_equals( $evidence_sha, $verified_evidence ) ) {
			$base['reason'] = 'conformance_verifier_evidence_mismatch';
			return $base;
		}

		return array(
			'verified' => true,
			'reason' => 'verified',
			'contract' => self::CONTRACT,
			'evidence_sha256' => $evidence_sha,
			'receipt_sha256' => $claimed,
			'output_classification' => $output_classification,
			'issuer_contract' => $issuer,
			'issuer_id' => $issuer_id,
			'verifier_id' => $verifier_id,
			'signature_scheme' => $verifier['signature_scheme'],
			'verifier_provenance' => $verifier['verifier_provenance'],
			'provider_contract_sha256' => $expected_provider_sha,
			'capability_id' => (string) $provider_binding['capability_id'],
			'artifact_fingerprint' => (string) $provider_binding['artifact_fingerprint'],
			'capability_contract_digest' => (string) $provider_binding['capability_contract_digest'],
		);
	}

	private static function trusted_verifiers( $ability_name, array $node, array $graph ) {
		$raw = function_exists( 'apply_filters' )
			? apply_filters( 'mad4b_scp_runtime_policy_conformance_verifiers', array(), $ability_name, $node, $graph )
			: array();
		$raw = is_array( $raw ) ? array_slice( $raw, 0, self::MAX_VERIFIERS, true ) : array();
		$out = array();
		foreach ( $raw as $key => $candidate ) {
			if ( ! is_array( $candidate ) ) continue;
			$id = isset( $candidate['verifier_id'] ) ? sanitize_key( (string) $candidate['verifier_id'] ) : sanitize_key( (string) $key );
			$issuer_contract = isset( $candidate['issuer_contract'] ) ? (string) $candidate['issuer_contract'] : '';
			$issuer_id = isset( $candidate['issuer_id'] ) ? sanitize_key( (string) $candidate['issuer_id'] ) : '';
			$scheme = isset( $candidate['signature_scheme'] ) ? substr( trim( (string) $candidate['signature_scheme'] ), 0, 80 ) : '';
			$callback = isset( $candidate['verify_callback'] ) ? $candidate['verify_callback'] : null;
			if ( '' === $id || '' === $issuer_contract || '' === $issuer_id || '' === $scheme || ! is_callable( $callback ) ) continue;
			if ( true !== ( isset( $candidate['trusted'] ) ? $candidate['trusted'] : false ) ) continue;
			if ( true !== ( isset( $candidate['read_only_verifier'] ) ? $candidate['read_only_verifier'] : false ) ) continue;
			if ( false !== ( isset( $candidate['authorizing'] ) ? $candidate['authorizing'] : null ) ) continue;
			if ( ! self::callback_owned_by_control_plane( $callback ) ) continue;
			$out[ $id ] = array(
				'verifier_id' => $id,
				'issuer_contract' => $issuer_contract,
				'issuer_id' => $issuer_id,
				'signature_scheme' => $scheme,
				'verify_callback' => $callback,
				'verifier_provenance' => 'mad4b_control_plane_source',
			);
		}
		return $out;
	}

	private static function callback_owned_by_control_plane( $callback ) {
		try {
			if ( $callback instanceof Closure ) $reflection = new ReflectionFunction( $callback );
			elseif ( is_array( $callback ) && 2 === count( $callback ) ) $reflection = new ReflectionMethod( $callback[0], $callback[1] );
			elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				$reflection = new ReflectionMethod( $class, $method );
			} elseif ( is_string( $callback ) ) $reflection = new ReflectionFunction( $callback );
			elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) $reflection = new ReflectionMethod( $callback, '__invoke' );
			else return false;
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

	private static function current_provider_capability_binding( $provider, $ability_name ) {
		$base = array(
			'verified' => false,
			'reason' => 'conformance_provider_capability_binding_unavailable',
			'capability_id' => '',
			'artifact_fingerprint' => '',
			'capability_contract_digest' => '',
		);
		$provider = sanitize_key( (string) $provider );
		$ability_name = (string) $ability_name;
		if ( '' === $provider || '' === $ability_name
			|| ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' )
			|| ! method_exists( 'MAD4B_SCP_Provider_Compatibility_Certification', 'ability_status' ) ) {
			return $base;
		}
		$status = MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $provider, $ability_name );
		if ( ! is_array( $status ) || empty( $status ) ) {
			$base['reason'] = 'conformance_provider_capability_not_cataloged';
			return $base;
		}
		if ( 'read' !== ( isset( $status['risk'] ) ? sanitize_key( (string) $status['risk'] ) : '' )
			|| empty( $status['read_eligible'] ) || empty( $status['surface_exposed'] ) ) {
			$base['reason'] = 'conformance_provider_capability_not_read_eligible';
			return $base;
		}
		$artifact = isset( $status['artifact'] ) && is_array( $status['artifact'] ) ? $status['artifact'] : array();
		$capability_id = isset( $status['capability_id'] ) ? sanitize_key( (string) $status['capability_id'] ) : '';
		$artifact_fingerprint = isset( $artifact['runtime_artifact_fingerprint'] ) ? strtolower( (string) $artifact['runtime_artifact_fingerprint'] ) : '';
		$contract_digest = isset( $status['capability_contract_digest'] ) ? strtolower( (string) $status['capability_contract_digest'] ) : '';
		if ( '' === $capability_id
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $artifact_fingerprint )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $contract_digest ) ) {
			$base['reason'] = 'conformance_provider_capability_binding_invalid';
			return $base;
		}
		return array(
			'verified' => true,
			'reason' => 'verified',
			'capability_id' => $capability_id,
			'artifact_fingerprint' => $artifact_fingerprint,
			'capability_contract_digest' => $contract_digest,
		);
	}

	private static function digest( $contract, $value ) {
		if ( class_exists( 'MAD4B_SCP_Ability_Contract_Inspector' ) ) {
			$digest = MAD4B_SCP_Ability_Contract_Inspector::digest( $contract, $value );
			if ( ! is_wp_error( $digest ) ) return $digest;
		}
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
