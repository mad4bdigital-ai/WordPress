<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WordPress-native lifecycle migration profile for Ability provenance.
 *
 * WordPress 7.1 exposes native invocation/permission/execute hooks but no public
 * callback getter. Reflection-backed provenance therefore remains authoritative
 * until native shadow evidence plus signed registration provenance proves exact
 * parity. This class never performs the cutover itself.
 */
final class MAD4B_SCP_WordPress_Lifecycle_Migration_Profile {
	const CONTRACT = 'mad4b.wordpress-lifecycle-migration-profile.v1';
	const CONFIG = 'config/wordpress-lifecycle-migration-profile.json';
	private static $profile = null;

	public static function profile() {
		if ( is_array( self::$profile ) ) return self::$profile;
		if ( ! defined( 'MAD4B_SCP_DIR' ) ) return new WP_Error( 'mad4b_lifecycle_migration_root_unavailable', 'Plugin root is unavailable.' );
		$path = MAD4B_SCP_DIR . self::CONFIG;
		if ( ! is_readable( $path ) ) return new WP_Error( 'mad4b_lifecycle_migration_profile_missing', 'WordPress lifecycle migration profile is unavailable.' );
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || self::CONTRACT !== ( isset($data['contract']) ? (string)$data['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_lifecycle_migration_profile_invalid', 'WordPress lifecycle migration profile is invalid.' );
		}
		foreach ( array( 'wordpress_native_hooks','legacy_reflection_surfaces','parity_requirements','cutover' ) as $key ) {
			if ( empty( $data[$key] ) || ! is_array( $data[$key] ) ) return new WP_Error( 'mad4b_lifecycle_migration_profile_invalid', 'WordPress lifecycle migration profile is incomplete.' );
		}
		self::$profile = $data;
		return self::$profile;
	}

	public static function reset_request_cache() { self::$profile = null; }

	public static function status() {
		$profile = self::profile();
		if ( is_wp_error( $profile ) ) return $profile;
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
		$native_hooks_available = '' !== $wp_version && version_compare( $wp_version, '7.1.0', '>=' );
		return array(
			'contract'=>self::CONTRACT,
			'wordpress_version'=>$wp_version,
			'native_hooks_available'=>$native_hooks_available,
			'native_hooks'=>$profile['wordpress_native_hooks'],
			'legacy_reflection_surfaces'=>$profile['legacy_reflection_surfaces'],
			'legacy_provenance_authoritative'=>true,
			'native_shadow_observation_enabled'=>$native_hooks_available,
			'public_callback_getter_available'=>false,
			'signed_registration_provenance_required'=>true,
			'parity_certified'=>false,
			'cutover_eligible'=>false,
			'automatic_cutover'=>false,
			'replacement_performed'=>false,
			'authority_effect'=>'none',
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
	}

	public static function evaluate_parity( array $evidence ) {
		$profile = self::profile();
		if ( is_wp_error( $profile ) ) return $profile;
		$required_bool = array(
			'invocation_identity',
			'pre_execute_observation',
			'permission_result',
			'execute_result',
			'registration_provenance_equivalent',
			'final_execution_boundary_equivalent',
			'fixed_dispatch_semantics_equivalent',
			'no_authority_widening',
		);
		$missing = array();
		foreach ( $required_bool as $key ) if ( empty( $evidence[$key] ) || true !== $evidence[$key] ) $missing[]=$key;
		$legacy = isset($evidence['legacy_behavior_sha256']) ? strtolower(trim((string)$evidence['legacy_behavior_sha256'])) : '';
		$native = isset($evidence['native_shadow_behavior_sha256']) ? strtolower(trim((string)$evidence['native_shadow_behavior_sha256'])) : '';
		$legacy_provenance = isset($evidence['legacy_provenance_sha256']) ? strtolower(trim((string)$evidence['legacy_provenance_sha256'])) : '';
		$native_provenance = isset($evidence['native_registration_provenance_sha256']) ? strtolower(trim((string)$evidence['native_registration_provenance_sha256'])) : '';
		foreach ( array( $legacy,$native,$legacy_provenance,$native_provenance ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
				return new WP_Error( 'mad4b_lifecycle_migration_parity_digest_invalid', 'Lifecycle parity requires exact SHA-256 behavior and provenance digests.' );
			}
		}
		$behavior_equal = hash_equals( $legacy, $native );
		$provenance_equal = hash_equals( $legacy_provenance, $native_provenance );
		if ( ! $behavior_equal ) $missing[]='behavior_digest_parity';
		if ( ! $provenance_equal ) $missing[]='registration_provenance_digest_parity';
		$missing=array_values(array_unique($missing)); sort($missing,SORT_STRING);
		$certified=empty($missing);
		return array(
			'contract'=>self::CONTRACT,
			'parity_certified'=>$certified,
			'cutover_eligible'=>$certified,
			'legacy_provenance_authoritative'=>true,
			'replacement_performed'=>false,
			'automatic_cutover'=>false,
			'missing_requirements'=>$missing,
			'legacy_behavior_sha256'=>$legacy,
			'native_shadow_behavior_sha256'=>$native,
			'legacy_provenance_sha256'=>$legacy_provenance,
			'native_registration_provenance_sha256'=>$native_provenance,
			'authority_effect'=>'none',
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
	}
}
