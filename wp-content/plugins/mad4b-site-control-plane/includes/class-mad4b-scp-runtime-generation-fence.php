<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fences stale long-lived workers and mixed runtime generations.
 *
 * Captured generation material is non-authorizing. Any drift can only deny or
 * require recycle/re-approval; it cannot make a mutation eligible.
 */
final class MAD4B_SCP_Runtime_Generation_Fence {
	const CONTRACT = 'mad4b.runtime-generation-fence.v1';

	public static function material() {
		$boot_runtime_sha = defined( 'MAD4B_SCP_BOOT_RUNTIME_FILE_SHA256' ) ? strtolower( (string) MAD4B_SCP_BOOT_RUNTIME_FILE_SHA256 ) : '';
		$disk_runtime_sha = defined( 'MAD4B_SCP_FILE' ) && is_readable( MAD4B_SCP_FILE ) ? strtolower( (string) hash_file( 'sha256', MAD4B_SCP_FILE ) ) : '';
		$boot_provenance_sha = defined( 'MAD4B_SCP_BOOT_PROVENANCE_SHA256' ) ? strtolower( (string) MAD4B_SCP_BOOT_PROVENANCE_SHA256 ) : '';
		$provenance_path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' : '';
		$disk_provenance_sha = '' !== $provenance_path && is_readable( $provenance_path ) ? strtolower( (string) hash_file( 'sha256', $provenance_path ) ) : '';
		$installed_schema = class_exists( 'MAD4B_SCP_Schema' ) ? (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) : -1;
		$supported_schema = class_exists( 'MAD4B_SCP_Schema' ) ? (int) MAD4B_SCP_Schema::VERSION : 0;
		$persisted_registry_sha = class_exists( 'MAD4B_SCP_Persisted_Contract_Compatibility' )
			? MAD4B_SCP_Persisted_Contract_Compatibility::registry_sha256()
			: '';
		$policy_sha = class_exists( 'MAD4B_SCP_Policy_Resolution' ) && method_exists( 'MAD4B_SCP_Policy_Resolution', 'config_digest' )
			? (string) MAD4B_SCP_Policy_Resolution::config_digest()
			: '';
		$catalog_generation = class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' ) && method_exists( 'MAD4B_SCP_Ability_Catalog_Transport', 'wire_generation' )
			? (string) MAD4B_SCP_Ability_Catalog_Transport::wire_generation()
			: 'mad4b.ability-catalog-transport.v2:' . ( defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '' );
		$config_generation = self::config_generation_material();
		$config_generation_sha = is_wp_error( $config_generation ) ? '' : (string) $config_generation['generation_sha256'];
		$config_file_digests = is_wp_error( $config_generation ) ? array() : $config_generation['files'];
		$config_generation_error = is_wp_error( $config_generation ) ? (string) $config_generation->get_error_code() : '';

		return array(
			'contract' => self::CONTRACT,
			'runtime_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'boot_runtime_file_sha256' => $boot_runtime_sha,
			'disk_runtime_file_sha256' => $disk_runtime_sha,
			'boot_provenance_sha256' => $boot_provenance_sha,
			'disk_provenance_sha256' => $disk_provenance_sha,
			'schema_installed_version' => $installed_schema,
			'schema_supported_version' => $supported_schema,
			'persisted_contract_registry_sha256' => strtolower( (string) $persisted_registry_sha ),
			'policy_config_sha256' => strtolower( (string) $policy_sha ),
			'catalog_wire_generation' => $catalog_generation,
			'config_generation_sha256' => strtolower( (string) $config_generation_sha ),
			'config_file_digests' => $config_file_digests,
			'config_generation_error' => $config_generation_error,
		);
	}

	public static function status() {
		$material = self::material();
		$blockers = array();
		foreach ( array( 'boot_runtime_file_sha256', 'disk_runtime_file_sha256', 'persisted_contract_registry_sha256', 'config_generation_sha256' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $material[ $field ] ) ) $blockers[] = $field . '_invalid';
		}
		if ( empty( $blockers )
			&& ! hash_equals( (string) $material['boot_runtime_file_sha256'], (string) $material['disk_runtime_file_sha256'] ) ) {
			$blockers[] = 'runtime_main_file_changed_since_worker_boot';
		}
		$boot_provenance = (string) $material['boot_provenance_sha256'];
		$disk_provenance = (string) $material['disk_provenance_sha256'];
		if ( '' !== $boot_provenance || '' !== $disk_provenance ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $boot_provenance )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $disk_provenance )
				|| ! hash_equals( $boot_provenance, $disk_provenance ) ) {
				$blockers[] = 'runtime_provenance_changed_since_worker_boot';
			}
		}
		if ( (int) $material['schema_installed_version'] !== (int) $material['schema_supported_version'] ) {
			$blockers[] = (int) $material['schema_installed_version'] > (int) $material['schema_supported_version']
				? 'future_schema_downgrade_forbidden'
				: 'schema_upgrade_required';
		}
		if ( class_exists( 'MAD4B_SCP_Persisted_Contract_Compatibility' ) ) {
			$persisted = MAD4B_SCP_Persisted_Contract_Compatibility::status();
			if ( empty( $persisted['ready_for_write'] ) ) {
				foreach ( (array) $persisted['blockers'] as $blocker ) $blockers[] = 'persisted:' . (string) $blocker;
			}
		}
		$generation_sha = self::digest( $material );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $generation_sha ) ) $blockers[] = 'runtime_generation_digest_unavailable';

		return array(
			'contract' => self::CONTRACT,
			'generation_sha256' => $generation_sha,
			'material' => $material,
			'blockers' => array_values( array_unique( $blockers ) ),
			'ready' => empty( $blockers ),
			'worker_recycle_required' => ! empty( $blockers ),
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	public static function capture() {
		$status = self::status();
		if ( empty( $status['ready'] ) ) return self::error_from_status( $status );
		return array(
			'contract' => self::CONTRACT,
			'generation_sha256' => (string) $status['generation_sha256'],
			'material' => $status['material'],
			'authorizing' => false,
		);
	}

	public static function assert_current( array $expected = array() ) {
		$status = self::status();
		if ( empty( $status['ready'] ) ) return self::error_from_status( $status );
		if ( ! empty( $expected ) ) {
			$expected_contract = isset( $expected['contract'] ) ? (string) $expected['contract'] : '';
			$expected_sha = isset( $expected['generation_sha256'] ) ? strtolower( (string) $expected['generation_sha256'] ) : '';
			if ( self::CONTRACT !== $expected_contract || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_sha )
				|| ! hash_equals( $expected_sha, (string) $status['generation_sha256'] ) ) {
				return new WP_Error(
					'mad4b_runtime_generation_changed',
					'Runtime/schema/config generation changed after execution admission.',
					array(
						'worker_recycle_required' => true,
						'reapproval_required' => true,
						'blind_retry_allowed' => false,
						'authorizing' => false,
					)
				);
			}
		}
		return $status;
	}

	private static function config_generation_material() {
		$dir = defined( 'MAD4B_SCP_DIR' ) ? rtrim( (string) MAD4B_SCP_DIR, '/\\' ) . '/config' : '';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return new WP_Error( 'mad4b_runtime_config_generation_directory_unavailable', 'Runtime configuration directory is unavailable.' );
		}
		$files = glob( $dir . '/*.json' );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return new WP_Error( 'mad4b_runtime_config_generation_empty', 'No versioned runtime configuration files are available for generation fencing.' );
		}
		sort( $files, SORT_STRING );
		$digests = array();
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				return new WP_Error( 'mad4b_runtime_config_generation_file_unreadable', 'A versioned runtime configuration file is unreadable.' );
			}
			$sha = strtolower( (string) hash_file( 'sha256', $file ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
				return new WP_Error( 'mad4b_runtime_config_generation_digest_invalid', 'A runtime configuration digest could not be computed.' );
			}
			$digests[ basename( $file ) ] = $sha;
		}
		ksort( $digests, SORT_STRING );
		$generation_sha = self::digest( array(
			'contract' => 'mad4b.runtime-config-generation.v1',
			'files' => $digests,
		) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $generation_sha ) ) {
			return new WP_Error( 'mad4b_runtime_config_generation_digest_invalid', 'Runtime configuration generation digest is unavailable.' );
		}
		return array(
			'contract' => 'mad4b.runtime-config-generation.v1',
			'generation_sha256' => $generation_sha,
			'files' => $digests,
			'authorizing' => false,
		);
	}

	private static function error_from_status( array $status ) {
		return new WP_Error(
			'mad4b_runtime_worker_recycle_required',
			'Loaded worker/runtime generation is no longer safe for governed mutation.',
			array(
				'blockers' => isset( $status['blockers'] ) ? $status['blockers'] : array( 'runtime_generation_unavailable' ),
				'worker_recycle_required' => true,
				'reapproval_required' => true,
				'blind_retry_allowed' => false,
				'authorizing' => false,
			)
		);
	}

	private static function digest( $value ) {
		$json = self::stable_json( $value );
		return '' === $json ? '' : hash( 'sha256', $json );
	}

	private static function stable_json( $value ) {
		$normalized = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			$is_list = array() === $value || $keys === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) $out[] = self::canonicalize( $item );
				return $out;
			}
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		return $value;
	}
}
