<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-canonicalization.php';
require_once __DIR__ . '/class-mad4b-scp-structural-redaction.php';

/** Shared evidence binding, not an authority or execution service. */
final class MAD4B_SCP_Adaptive_Operations_Context {
	const CONTRACT = 'mad4b.adaptive-operations-context.v1';

	public static function current() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return self::error( 'site_profile_unavailable' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return self::error( 'site_origin_not_bound' );
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) || ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ) return self::error( 'runtime_binding_unavailable' );
		$generation = MAD4B_SCP_Runtime_Generation_Fence::capture();
		if ( is_wp_error( $generation ) ) return $generation;
		$artifact = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( empty( $artifact['runtime_manifest_match'] ) ) return self::error( 'artifact_manifest_unverified' );
		$epoch = class_exists( 'MAD4B_SCP_Restore_Epoch' ) ? MAD4B_SCP_Restore_Epoch::status( false, true ) : array();
		if ( empty( $epoch['ready'] ) || empty( $epoch['epoch'] ) ) return self::error( 'restore_epoch_unverified' );
		$binding = array(
			'contract' => self::CONTRACT,
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'environment' => MAD4B_SCP_Site_Profile::current_environment(),
			'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
			'origin_sha256' => hash( 'sha256', MAD4B_SCP_Site_Profile::current_origin() ),
			'runtime_generation' => $generation['generation_sha256'],
			'artifact_sha256' => $artifact['package_manifest_digest'] ?? '',
			'restore_epoch' => (int) $epoch['epoch'],
			'external_record_sha256' => $epoch['external_record_sha256'] ?? '',
		);
		$valid = self::validate( $binding );
		return is_wp_error( $valid ) ? $valid : $binding;
	}

	public static function validate( array $binding ) {
		foreach ( array( 'site_uuid', 'environment' ) as $field ) {
			if ( ! isset( $binding[ $field ] ) || ! is_string( $binding[ $field ] ) || '' === $binding[ $field ] || strlen( $binding[ $field ] ) > 100 || ! preg_match( '/^[a-zA-Z0-9._:-]+$/D', $binding[ $field ] ) ) return self::error( 'binding_' . $field . '_missing' );
		}
		if ( ! isset( $binding['restore_epoch'] ) || ! is_int( $binding['restore_epoch'] ) || $binding['restore_epoch'] < 1 ) return self::error( 'binding_restore_epoch_missing' );
		foreach ( array( 'profile_digest', 'origin_sha256', 'runtime_generation', 'artifact_sha256', 'external_record_sha256' ) as $field ) {
			if ( ! self::sha( $binding[ $field ] ?? '' ) ) return self::error( 'binding_' . $field . '_invalid' );
		}
		if ( ! in_array( $binding['environment'], array( 'staging', 'development', 'local', 'production' ), true ) ) return self::error( 'binding_environment_invalid' );
		return true;
	}

	public static function assert_same( array $expected, array $current, $across_update = false ) {
		foreach ( array( $expected, $current ) as $binding ) { $v = self::validate( $binding ); if ( is_wp_error( $v ) ) return $v; }
		$keys = array( 'site_uuid', 'environment', 'profile_digest', 'origin_sha256', 'restore_epoch', 'external_record_sha256' );
		if ( ! $across_update ) $keys = array_merge( $keys, array( 'runtime_generation', 'artifact_sha256' ) );
		foreach ( $keys as $key ) if ( ! hash_equals( (string) $expected[ $key ], (string) $current[ $key ] ) ) return self::error( 'binding_' . $key . '_changed' );
		return true;
	}

	public static function digest( $contract, $value ) { return MAD4B_SCP_Canonicalization::digest( $contract, $value ); }
	public static function sha( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	public static function error( $reason ) { return new WP_Error( 'mad4b_adaptive_' . $reason, 'Exact current evidence is required; review or reconciliation must preserve existing authority.', array( 'reason' => $reason, 'authorizing' => false, 'blind_retry_allowed' => false ) ); }

	/** Site-local MAC uses the existing deployment key; it never grants anything. */
	public static function seal( $purpose, array $material ) {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! method_exists( 'MAD4B_SCP_Site_Profile', 'deployment_binding_proof' ) ) return self::error( 'binding_proof_unavailable' );
		$digest = self::digest( $purpose, $material );
		if ( is_wp_error( $digest ) ) return $digest;
		$proof = MAD4B_SCP_Site_Profile::deployment_binding_proof( $purpose, $digest );
		if ( is_wp_error( $proof ) || ! is_string( $proof ) || '' === $proof ) return self::error( 'binding_proof_failed' );
		return array( 'material' => $material, 'sha256' => $digest, 'proof' => $proof );
	}

	public static function unseal( $purpose, array $sealed ) {
		if ( ! isset( $sealed['material'], $sealed['sha256'], $sealed['proof'] ) || ! is_array( $sealed['material'] ) || ! self::sha( $sealed['sha256'] ) ) return self::error( 'sealed_evidence_missing' );
		$digest = self::digest( $purpose, $sealed['material'] );
		if ( is_wp_error( $digest ) || ! hash_equals( $sealed['sha256'], $digest ) ) return self::error( 'sealed_evidence_modified' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! method_exists( 'MAD4B_SCP_Site_Profile', 'verify_deployment_binding_proof' ) || ! MAD4B_SCP_Site_Profile::verify_deployment_binding_proof( $purpose, $digest, $sealed['proof'] ) ) return self::error( 'sealed_evidence_foreign' );
		return $sealed['material'];
	}
}
