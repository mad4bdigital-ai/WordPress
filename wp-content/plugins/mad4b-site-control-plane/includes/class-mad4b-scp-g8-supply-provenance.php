<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing, capability-local supply-chain inspection.
 * A self-asserted candidate is never a trust anchor. The only trusted provider
 * baseline here is an existing cryptographically verified Certification Pack.
 */
final class MAD4B_SCP_G8_Supply_Provenance {
	const CONTRACT = 'mad4b.g8-supply-provenance.v1';
	const MAX_DEPENDENCIES = 64;
	const MAX_MIRRORS = 8;

	private static function sha( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	private static function identifier( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_.-]{1,80}$/D', $value );
	}

	private static function channels() {
		return array( 'wordpress_org', 'vendor_official', 'mad4b_build', 'certified_mirror' );
	}

	private static function denied( $reason, $provider = '', $capability = '' ) {
		return array(
			'contract' => self::CONTRACT,
			'state' => 'QUARANTINED',
			'reason' => $reason,
			'provider_id' => self::identifier( $provider ) ? $provider : '',
			'capability_id' => self::identifier( $capability ) ? $capability : '',
			'quarantine_scope' => self::identifier( $provider ) && self::identifier( $capability ) ? $provider . ':' . $capability : '',
			'provider_wide_quarantine' => false,
			'credential_disclosed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	/** Passive local runtime source identity is not a signer attestation. */
	public static function runtime_status() {
		$runtime = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer', false )
			? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status() : array();
		$ready = is_array( $runtime ) && true === ( $runtime['identity_ready'] ?? false );
		$fields = array();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			$value = $ready && is_string( $runtime[ $field ] ?? null ) ? $runtime[ $field ] : '';
			if ( '' === $value ) $ready = false;
			$fields[ $field ] = substr( $value, 0, 192 );
		}
		return array(
			'contract' => self::CONTRACT,
			'state' => $ready ? 'LOCAL_IDENTITY_ONLY' : 'UNVERIFIED',
			'local_build_identity' => $fields,
			'signer_verified' => false,
			'dependency_provenance_verified' => false,
			'candidate_promotion_allowed' => false,
			'authorizing' => false,
		);
	}

	/**
	 * Compare a *candidate claim* to the signed source/dependency payload of an
	 * already registered provider_evidence pack. The existing pack verifier checks
	 * signature, restore epoch, generation, current provider artifact and revocation.
	 */
	public static function inspect( array $candidate, array $pack ) {
		$provider = $pack['provider_id'] ?? '';
		$capability = $pack['capability_id'] ?? '';
		if ( ! self::identifier( $provider ) || ! self::identifier( $capability ) )
			return self::denied( 'capability_scope_invalid' );
		if ( ! class_exists( 'MAD4B_SCP_G8_Record', false ) || ! MAD4B_SCP_G8_Record::staging() )
			return self::denied( 'enrolled_staging_required', $provider, $capability );
		if ( ! class_exists( 'MAD4B_SCP_Certification_Pack_Registry', false ) )
			return self::denied( 'signed_pack_verifier_missing', $provider, $capability );
		$verified = MAD4B_SCP_Certification_Pack_Registry::verify_pack( $pack );
		if ( is_wp_error( $verified ) )
			return self::denied( $verified->get_error_code(), $provider, $capability );
		if ( 'provider_evidence' !== ( $verified['pack_type'] ?? '' ) || ( $verified['provider_id'] ?? '' ) !== $provider
			|| ( $verified['capability_id'] ?? '' ) !== $capability )
			return self::denied( 'signed_pack_scope_invalid', $provider, $capability );

		$source = $verified['payload']['supply_provenance'] ?? null;
		if ( ! is_array( $source ) )
			return self::denied( 'signed_source_provenance_missing', $provider, $capability );
		$channel = $source['source_channel'] ?? '';
		$digest = $source['source_sha256'] ?? '';
		$sequence = $source['sequence'] ?? null;
		$deps = $source['dependencies'] ?? null;
		if ( ! in_array( $channel, self::channels(), true ) || ! self::sha( $digest )
			|| ! is_int( $sequence ) || $sequence < 1 || ! is_array( $deps ) || count( $deps ) > self::MAX_DEPENDENCIES )
			return self::denied( 'signed_source_profile_invalid', $provider, $capability );
		if ( ( $candidate['provider_id'] ?? '' ) !== $provider || ( $candidate['capability_id'] ?? '' ) !== $capability )
			return self::denied( 'foreign_candidate_scope', $provider, $capability );
		if ( ( $candidate['runtime_generation_sha256'] ?? '' ) !== ( $verified['runtime_generation']['generation_sha256'] ?? '' ) )
			return self::denied( 'candidate_generation_mismatch', $provider, $capability );
		if ( ( $candidate['source_channel'] ?? '' ) !== $channel )
			return self::denied( 'unexpected_source_channel', $provider, $capability );
		if ( ! self::sha( $candidate['source_sha256'] ?? null ) || ! hash_equals( $digest, $candidate['source_sha256'] ) )
			return self::denied( 'source_digest_mismatch', $provider, $capability );
		if ( ! is_int( $candidate['sequence'] ?? null ) || $candidate['sequence'] < $sequence )
			return self::denied( 'candidate_downgrade', $provider, $capability );
		if ( $candidate['sequence'] !== $sequence )
			return self::denied( 'unattested_candidate_sequence', $provider, $capability );
		$actual = $candidate['dependencies'] ?? null;
		if ( ! self::valid_dependencies( $deps ) || ! self::valid_dependencies( $actual ) )
			return self::denied( 'dependency_manifest_invalid', $provider, $capability );
		ksort( $deps, SORT_STRING ); ksort( $actual, SORT_STRING );
		if ( $deps !== $actual )
			return self::denied( 'dependency_substitution', $provider, $capability );
		// A candidate may not omit a mirror pin from the *signed* manifest.
		// Hashing only the candidate's self-reported mirrors would otherwise
		// make an empty mirror list a trivial bypass of mirror provenance.
		$trusted_mirrors = $source['mirror_sha256'] ?? array();
		$mirrors = $candidate['mirror_sha256'] ?? array();
		if ( ! is_array( $trusted_mirrors ) || ! is_array( $mirrors )
			|| count( $trusted_mirrors ) > self::MAX_MIRRORS || count( $mirrors ) > self::MAX_MIRRORS )
			return self::denied( 'mirror_manifest_invalid', $provider, $capability );
		foreach ( $trusted_mirrors as $mirror ) {
			if ( ! self::sha( $mirror ) || ! hash_equals( $digest, $mirror ) )
				return self::denied( 'signed_mirror_digest_invalid', $provider, $capability );
		}
		foreach ( $mirrors as $mirror ) {
			if ( ! self::sha( $mirror ) || ! hash_equals( $digest, $mirror ) )
				return self::denied( 'mirror_digest_mismatch', $provider, $capability );
		}
		if ( $mirrors !== $trusted_mirrors )
			return self::denied( 'mirror_pinset_mismatch', $provider, $capability );
		return array(
			'contract' => self::CONTRACT,
			'state' => 'PROVENANCE_MATCH',
			'reason' => 'signed_capability_source_and_dependencies_match',
			'provider_id' => $provider,
			'capability_id' => $capability,
			'runtime_generation_sha256' => $verified['runtime_generation']['generation_sha256'],
			'pack_sha256' => $verified['pack_sha256'],
			'candidate_source_sha256' => $digest,
			'dependency_count' => count( $deps ),
			'signer_verified_by' => 'MAD4B_SCP_Certification_Pack_Registry',
			'behavior_certified' => false,
			'grants_checked' => false,
			'candidate_promotion_allowed' => false,
			'provider_wide_quarantine' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	private static function valid_dependencies( $deps ) {
		if ( ! is_array( $deps ) || count( $deps ) > self::MAX_DEPENDENCIES ) return false;
		foreach ( $deps as $name => $sha ) {
			if ( ! self::identifier( $name ) || ! self::sha( $sha ) ) return false;
		}
		return true;
	}
}
