<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Provider_Contracts {
	private static $installed_version_cache = array();
	private static $contracts = null;
	private static $profiles = null;
	private static $profile_catalog = null;

	public static function all() {
		if ( null !== self::$contracts ) return self::$contracts;
		$path = MAD4B_SCP_DIR . 'config/certified-providers.json';
		if ( ! is_readable( $path ) ) { self::$contracts = array(); return self::$contracts; }
		$raw = file_get_contents( $path );
		if ( false === $raw ) { self::$contracts = array(); return self::$contracts; }
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['providers'] ) || ! is_array( $data['providers'] ) ) { self::$contracts = array(); return self::$contracts; }
		self::$contracts = $data['providers'];
		return self::$contracts;
	}

	private static function profiles() {
		if ( null !== self::$profiles ) return self::$profiles;
		$path = MAD4B_SCP_DIR . 'config/certified-provider-profiles.json';
		if ( ! is_readable( $path ) ) { self::$profiles = array(); return self::$profiles; }
		$raw = file_get_contents( $path );
		if ( false === $raw ) { self::$profiles = array(); return self::$profiles; }
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['providers'] ) || ! is_array( $data['providers'] ) ) { self::$profiles = array(); return self::$profiles; }
		self::$profiles = $data['providers'];
		return self::$profiles;
	}

	public static function get( $provider ) {
		$contracts = self::all();
		return isset( $contracts[ $provider ] ) && is_array( $contracts[ $provider ] ) ? $contracts[ $provider ] : array();
	}

	/**
	 * Return one exact certified provider version without falling back to the
	 * repository baseline when the requested version is unknown.
	 *
	 * Runtime release-set planning uses this stricter resolver so a newly
	 * discovered upstream version can never inherit stale integrity hashes.
	 */
	public static function get_for_version( $provider, $version ) {
		$provider = sanitize_key( (string) $provider );
		$version = trim( (string) $version );
		if ( '' === $provider || '' === $version ) return array();

		$base = self::get( $provider );
		if ( empty( $base ) ) return array();
		if ( ! empty( $base['version'] ) && hash_equals( (string) $base['version'], $version ) ) return $base;

		$profiles = self::profiles();
		if ( empty( $profiles[ $provider ][ $version ] ) || ! self::valid_profile( $version, $profiles[ $provider ][ $version ] ) ) return array();

		$profile = $profiles[ $provider ][ $version ];
		$contract = array_replace_recursive( $base, $profile );
		$replace_fields = array(
			'critical_files',
			'runtime_classes',
			'runtime_symbols',
			'runtime_surface',
			'native_abilities',
			'verified_absent_abilities',
			'verified_contracts',
			'known_upstream_constraints',
			'native_mcp',
			'native_mcp_security',
		);
		foreach ( $replace_fields as $field ) if ( array_key_exists( $field, $profile ) ) $contract[ $field ] = $profile[ $field ];
		return $contract;
	}

	public static function profile_catalog() {
		if ( null !== self::$profile_catalog ) return self::$profile_catalog;
		$path = MAD4B_SCP_DIR . 'config/certified-provider-profiles.json';
		if ( ! is_readable( $path ) ) { self::$profile_catalog = array(); return self::$profile_catalog; }
		$raw = file_get_contents( $path );
		if ( false === $raw ) { self::$profile_catalog = array(); return self::$profile_catalog; }
		$data = json_decode( $raw, true );
		self::$profile_catalog = is_array( $data ) ? $data : array();
		return self::$profile_catalog;
	}

	public static function candidate_attestation( $provider, $installed_version = '' ) {
		$provider = sanitize_key( (string) $provider );
		$installed_version = trim( (string) $installed_version );
		$catalog = self::profile_catalog();
		$profiles = isset( $catalog['providers'][ $provider ] ) && is_array( $catalog['providers'][ $provider ] ) ? $catalog['providers'][ $provider ] : array();
		if ( '' !== $installed_version && isset( $profiles[ $installed_version ] ) && self::valid_profile( $installed_version, $profiles[ $installed_version ] ) ) {
			$profile = $profiles[ $installed_version ];
			return array(
				'known_candidate' => true,
				'attestation_required' => false,
				'attestation_state' => 'profile_certified',
				'version' => $installed_version,
				'archive_sha256' => isset( $profile['archive_sha256'] ) ? strtolower( (string) $profile['archive_sha256'] ) : '',
				'archive_bytes' => isset( $profile['archive_bytes'] ) ? (int) $profile['archive_bytes'] : 0,
				'archive_layout' => isset( $profile['archive_layout'] ) ? sanitize_key( (string) $profile['archive_layout'] ) : '',
				'mutation_policy' => 'certified_profile_subject_to_runtime_integrity',
				'contract' => isset( $catalog['contract'] ) ? (string) $catalog['contract'] : '',
			);
		}
		$policy = isset( $catalog['premium_provider_policy'][ $provider ] ) && is_array( $catalog['premium_provider_policy'][ $provider ] ) ? $catalog['premium_provider_policy'][ $provider ] : array();
		$observed = isset( $policy['observed_version'] ) ? trim( (string) $policy['observed_version'] ) : '';
		$repository_version = isset( $policy['repository_archive_version'] ) ? trim( (string) $policy['repository_archive_version'] ) : '';
		if ( '' === $installed_version || empty( $policy ) ) return array( 'known_candidate' => false, 'candidate_policy_available' => ! empty( $policy ) );
		if ( '' === $observed || ! hash_equals( $observed, $installed_version ) ) {
			return array(
				'known_candidate' => false,
				'candidate_policy_available' => true,
				'candidate_relation' => 'installed_version_not_exact_candidate',
				'installed_version' => $installed_version,
				'policy_observed_version' => $observed,
				'repository_archive_version' => $repository_version,
				'repository_archive_sha256' => isset( $policy['repository_archive_sha256'] ) ? strtolower( (string) $policy['repository_archive_sha256'] ) : '',
				'attestation_required' => false,
				'candidate_attestation_required' => ! empty( $policy['attestation_required'] ),
				'candidate_attestation_state' => isset( $policy['attestation_state'] ) ? sanitize_key( (string) $policy['attestation_state'] ) : 'unknown',
				'installed_version_eligible_for_candidate_attestation' => false,
				'mutation_policy' => isset( $policy['mutation_policy'] ) ? sanitize_key( (string) $policy['mutation_policy'] ) : 'fail_closed',
				'authorizing' => false,
			);
		}
		return array(
			'known_candidate' => true,
			'candidate_policy_available' => true,
			'candidate_relation' => 'exact_candidate_version',
			'attestation_required' => ! empty( $policy['attestation_required'] ),
			'attestation_state' => isset( $policy['attestation_state'] ) ? sanitize_key( (string) $policy['attestation_state'] ) : 'unknown',
			'attestation_contract' => isset( $policy['attestation_contract'] ) ? sanitize_text_field( (string) $policy['attestation_contract'] ) : '',
			'version' => $installed_version,
			'archive_sha256' => isset( $policy['repository_archive_sha256'] ) ? strtolower( (string) $policy['repository_archive_sha256'] ) : '',
			'archive_bytes' => isset( $policy['repository_archive_bytes'] ) ? (int) $policy['repository_archive_bytes'] : 0,
			'archive_layout' => isset( $policy['repository_archive_layout'] ) ? sanitize_key( (string) $policy['repository_archive_layout'] ) : '',
			'normalized_archive_sha256' => isset( $policy['normalized_archive_sha256'] ) ? strtolower( (string) $policy['normalized_archive_sha256'] ) : '',
			'mutation_policy' => isset( $policy['mutation_policy'] ) ? sanitize_key( (string) $policy['mutation_policy'] ) : 'fail_closed',
			'contract' => isset( $catalog['contract'] ) ? (string) $catalog['contract'] : '',
		);
	}


	private static function valid_profile( $version, $profile ) {
		if ( ! is_array( $profile ) || ! preg_match( '/^[0-9A-Za-z._-]+$/', (string) $version ) ) return false;
		if ( empty( $profile['version'] ) || ! hash_equals( (string) $version, (string) $profile['version'] ) ) return false;
		// Extra versions must carry their own complete runtime integrity authority.
		// Never let a version-only profile inherit old critical-file hashes and pass.
		if ( empty( $profile['critical_files'] ) || ! is_array( $profile['critical_files'] ) ) return false;

		$has_dynamic_surface = array_key_exists( 'runtime_surface', $profile ) || array_key_exists( 'runtime_symbols', $profile );
		if ( $has_dynamic_surface ) {
			$surface = isset( $profile['runtime_surface'] ) && is_array( $profile['runtime_surface'] ) ? $profile['runtime_surface'] : array();
			$symbols = isset( $profile['runtime_symbols'] ) && is_array( $profile['runtime_symbols'] ) ? $profile['runtime_symbols'] : array();
			if ( 'mad4b.mcp-runtime-surface.v1' !== ( $surface['contract'] ?? '' ) ) return false;
			if ( empty( $surface['fail_closed_on_unprofiled_symbol'] ) || empty( $symbols ) ) return false;
			if ( (int) ( $surface['symbol_count'] ?? 0 ) !== count( $symbols ) ) return false;
			$tree = isset( $surface['tree_sha256'] ) ? strtolower( (string) $surface['tree_sha256'] ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $tree ) ) return false;
			$seen = array();
			$files = array();
			foreach ( $symbols as $spec ) {
				if ( ! is_array( $spec ) ) return false;
				$symbol = isset( $spec['symbol'] ) ? trim( (string) $spec['symbol'] ) : '';
				$kind = isset( $spec['kind'] ) ? sanitize_key( (string) $spec['kind'] ) : '';
				$file = isset( $spec['file'] ) ? ltrim( str_replace( '\\', '/', (string) $spec['file'] ), '/' ) : '';
				$blob = isset( $spec['git_blob_sha1'] ) ? strtolower( trim( (string) $spec['git_blob_sha1'] ) ) : '';
				if ( '' === $symbol || isset( $seen[ $symbol ] ) ) return false;
				if ( ! in_array( $kind, array( 'class', 'interface', 'trait' ), true ) ) return false;
				if ( '' === $file || false !== strpos( $file, '../' ) || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $blob ) ) return false;
				$seen[ $symbol ] = true;
				$files[ $file ] = true;
			}
			if ( (int) ( $surface['file_count'] ?? 0 ) !== count( $files ) ) return false;
		}
		return true;
	}

	public static function certified_versions( $provider ) {
		$base = self::get( $provider );
		$versions = array();
		if ( ! empty( $base['components'] ) && is_array( $base['components'] ) ) {
			$composite = self::composite_version_string( $base['components'], false );
			if ( '' !== $composite ) $versions[] = $composite;
		} elseif ( ! empty( $base['version'] ) ) $versions[] = (string) $base['version'];
		$profiles = self::profiles();
		if ( ! empty( $profiles[ $provider ] ) && is_array( $profiles[ $provider ] ) ) {
			foreach ( $profiles[ $provider ] as $version => $profile ) {
				if ( self::valid_profile( $version, $profile ) ) $versions[] = (string) $version;
			}
		}
		return array_values( array_unique( $versions ) );
	}

	private static function contract_for_version( $provider, $version ) {
		$base = self::get( $provider );
		if ( empty( $base ) ) return array();
		$version = (string) $version;
		if ( '' === $version || ( isset( $base['version'] ) && hash_equals( (string) $base['version'], $version ) ) ) return $base;
		$profiles = self::profiles();
		if ( empty( $profiles[ $provider ][ $version ] ) || ! self::valid_profile( $version, $profiles[ $provider ][ $version ] ) ) return $base;
		$profile = $profiles[ $provider ][ $version ];
		$contract = array_replace_recursive( $base, $profile );

		// Security-sensitive collections are complete version-scoped declarations,
		// not deltas. Replacing them wholesale prevents stale baseline entries or
		// numeric-array tails from being inherited silently by a newer provider.
		$replace_fields = array(
			'critical_files',
			'runtime_classes',
			'runtime_symbols',
			'runtime_surface',
			'native_abilities',
			'verified_absent_abilities',
			'verified_contracts',
			'known_upstream_constraints',
			'native_mcp',
			'native_mcp_security',
		);
		foreach ( $replace_fields as $field ) if ( array_key_exists( $field, $profile ) ) $contract[ $field ] = $profile[ $field ];
		return $contract;
	}

	public static function required_providers() {
		$required = apply_filters( 'mad4b_scp_required_providers', array_keys( self::all() ) );
		if ( ! is_array( $required ) ) return array();
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $required ) ) ) );
	}

	public static function installed_version( $provider ) {
		$contract = self::get( $provider );
		if ( ! empty( $contract['components'] ) && is_array( $contract['components'] ) ) return self::composite_version_string( $contract['components'], true );
		return self::installed_version_for_contract( $contract );
	}

	private static function installed_version_for_contract( array $contract ) {
		if ( empty( $contract['plugin_file'] ) ) return '';
		$file = ltrim( str_replace( '\\', '/', (string) $contract['plugin_file'] ), '/' );
		if ( array_key_exists( $file, self::$installed_version_cache ) ) return self::$installed_version_cache[ $file ];
		$absolute = trailingslashit( WP_PLUGIN_DIR ) . $file;
		if ( is_readable( $absolute ) && function_exists( 'get_file_data' ) ) {
			$data = get_file_data( $absolute, array( 'Version' => 'Version' ), 'plugin' );
			if ( is_array( $data ) && ! empty( $data['Version'] ) ) return self::$installed_version_cache[ $file ] = trim( (string) $data['Version'] );
		}
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		return self::$installed_version_cache[ $file ] = ( isset( $plugins[ $file ] ) && ! empty( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '' );
	}

	private static function invalidate_installed_version_cache_for_contract( array $contract ) {
		if ( ! empty( $contract['plugin_file'] ) ) {
			$file = ltrim( str_replace( '\\', '/', (string) $contract['plugin_file'] ), '/' );
			unset( self::$installed_version_cache[ $file ] );
		}
		if ( ! empty( $contract['components'] ) && is_array( $contract['components'] ) ) {
			foreach ( $contract['components'] as $component ) {
				if ( is_array( $component ) ) self::invalidate_installed_version_cache_for_contract( $component );
			}
		}
	}

	private static function composite_version_string( array $components, $installed ) {
		$parts = array();
		ksort( $components, SORT_STRING );
		foreach ( $components as $key => $component ) {
			if ( ! is_array( $component ) ) continue;
			$version = $installed ? self::installed_version_for_contract( $component ) : ( isset( $component['version'] ) ? (string) $component['version'] : '' );
			if ( '' === $version ) continue;
			$parts[] = sanitize_key( (string) $key ) . '=' . $version;
		}
		return implode( ';', $parts );
	}

	private static function integrity_status( array $contract ) {
		$result = array(
			// Runtime integrity is a mandatory part of every certified provider contract.
			// There is no version-only mutation mode: a missing manifest must fail closed.
			'required' => true,
			'manifest_present' => ! empty( $contract['critical_files'] ) && is_array( $contract['critical_files'] ),
			'verified' => array(),
			'missing' => array(),
			'mismatched' => array(),
		);
		if ( empty( $contract['critical_files'] ) || ! is_array( $contract['critical_files'] ) || empty( $contract['plugin_file'] ) ) return $result;

		$plugin_file = str_replace( '\\', '/', (string) $contract['plugin_file'] );
		$plugin_dir = dirname( $plugin_file );
		$runtime_root = realpath( trailingslashit( WP_PLUGIN_DIR ) . $plugin_dir );
		if ( false === $runtime_root ) {
			$result['missing'][] = '__plugin_root__';
			return $result;
		}
		$root_normalized = rtrim( str_replace( '\\', '/', $runtime_root ), '/' );

		foreach ( $contract['critical_files'] as $relative => $expected_sha ) {
			$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '../' ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) $expected_sha ) ) {
				$result['mismatched'][ $relative ] = array( 'reason' => 'invalid_manifest_entry' );
				continue;
			}
			$candidate = realpath( trailingslashit( $runtime_root ) . $relative );
			if ( false === $candidate || ! is_file( $candidate ) ) {
				$result['missing'][] = $relative;
				continue;
			}
			$normalized = str_replace( '\\', '/', $candidate );
			if ( $normalized !== $root_normalized && 0 !== strpos( $normalized, $root_normalized . '/' ) ) {
				$result['mismatched'][ $relative ] = array( 'reason' => 'path_escape' );
				continue;
			}
			$actual_sha = hash_file( 'sha256', $candidate );
			if ( false === $actual_sha || ! hash_equals( strtolower( (string) $expected_sha ), strtolower( (string) $actual_sha ) ) ) {
				$result['mismatched'][ $relative ] = array( 'expected_sha256' => strtolower( (string) $expected_sha ), 'actual_sha256' => false === $actual_sha ? '' : strtolower( $actual_sha ) );
				continue;
			}
			$result['verified'][] = $relative;
		}
		return $result;
	}

	public static function runtime_identity_status( $provider, $available = null ) {
		$base_contract = self::get( $provider );
		if ( empty( $base_contract ) ) {
			return array(
				'provider' => $provider,
				'status' => 'uncertified_provider',
				'identity_contract_ok' => false,
				'runtime_integrity_verification_deferred' => true,
			);
		}

		// Connection/readiness hot paths need package identity, not a byte-for-byte
		// mutation certification. Composite providers keep their deep integrity
		// contract on runtime_status(); this projection only compares versions and
		// verifies that an integrity manifest is structurally declared.
		if ( ! empty( $base_contract['components'] ) && is_array( $base_contract['components'] ) ) {
			$components = array();
			$all_identity_ok = true;
			foreach ( $base_contract['components'] as $key => $component ) {
				if ( ! is_array( $component ) ) { $all_identity_ok = false; continue; }
				$expected = isset( $component['version'] ) ? (string) $component['version'] : '';
				$actual = self::installed_version_for_contract( $component );
				$manifest_present = ! empty( $component['critical_files'] ) && is_array( $component['critical_files'] );
				$identity_ok = '' !== $actual && '' !== $expected && hash_equals( $expected, $actual ) && $manifest_present;
				$all_identity_ok = $all_identity_ok && $identity_ok;
				$components[ sanitize_key( (string) $key ) ] = array(
					'certified_version' => $expected,
					'installed_version' => $actual,
					'critical_file_manifest_present' => $manifest_present,
					'identity_ok' => $identity_ok,
					'runtime_integrity_verification_deferred' => true,
				);
			}
			return array(
				'provider' => $provider,
				'status' => false === $available ? 'unavailable' : ( $all_identity_ok ? 'certified_identity' : 'component_identity_drift' ),
				'components' => $components,
				'identity_contract_ok' => false !== $available && $all_identity_ok,
				'runtime_integrity_verification_deferred' => true,
				'mutation_certified' => false,
			);
		}

		$actual = self::installed_version( $provider );
		$contract = self::contract_for_version( $provider, $actual );
		$expected = isset( $contract['version'] ) ? (string) $contract['version'] : '';
		$certified_versions = self::certified_versions( $provider );
		$manifest_present = ! empty( $contract['critical_files'] ) && is_array( $contract['critical_files'] );
		$version_ok = false !== $available && '' !== $actual && '' !== $expected && hash_equals( $expected, $actual ) && in_array( $actual, $certified_versions, true );
		return array(
			'provider' => $provider,
			'label' => isset( $contract['label'] ) ? $contract['label'] : $provider,
			'status' => false === $available || '' === $actual ? 'unavailable' : ( $version_ok ? 'certified_identity' : 'version_drift' ),
			'certified_version' => $expected,
			'certified_versions' => $certified_versions,
			'installed_version' => $actual,
			'contract_mode' => isset( $contract['contract_mode'] ) ? $contract['contract_mode'] : '',
			'certification_authority' => isset( $contract['certification_authority'] ) ? $contract['certification_authority'] : 'repository_baseline',
			'critical_file_manifest_present' => $manifest_present,
			'identity_contract_ok' => $version_ok && $manifest_present,
			'runtime_integrity_verification_deferred' => true,
			'mutation_certified' => false,
		);
	}

	public static function runtime_status( $provider, $available = null ) {
		$base_contract = self::get( $provider );
		// Deep certification/mutation truth must observe version drift that occurs
		// after an earlier request-local identity read. Keep hot identity paths
		// memoized, but force exact plugin-header refresh before deep runtime truth.
		if ( ! empty( $base_contract ) ) self::invalidate_installed_version_cache_for_contract( $base_contract );
		if ( empty( $base_contract ) ) return array( 'provider' => $provider, 'status' => 'uncertified_provider', 'runtime_contract_ok' => false );
		if ( ! empty( $base_contract['components'] ) && is_array( $base_contract['components'] ) ) {
			return self::composite_runtime_status( $provider, $base_contract, $available );
		}

		$actual = self::installed_version( $provider );
		$contract = self::contract_for_version( $provider, $actual );
		$expected = isset( $contract['version'] ) ? (string) $contract['version'] : '';
		$certified_versions = self::certified_versions( $provider );
		if ( false === $available || '' === $actual ) $status = 'unavailable';
		elseif ( '' !== $expected && hash_equals( $expected, $actual ) && in_array( $actual, $certified_versions, true ) ) $status = 'certified';
		else $status = 'version_drift';

		$result = array(
			'provider' => $provider,
			'label' => isset( $contract['label'] ) ? $contract['label'] : $provider,
			'status' => $status,
			'certified_version' => $expected,
			'certified_versions' => $certified_versions,
			'installed_version' => $actual,
			'contract_mode' => isset( $contract['contract_mode'] ) ? $contract['contract_mode'] : '',
			'certification_authority' => isset( $contract['certification_authority'] ) ? $contract['certification_authority'] : 'repository_baseline',
			'runtime_integrity' => self::integrity_status( $contract ),
		);
		$candidate_attestation = self::candidate_attestation( $provider, $actual );
		if ( ! empty( $candidate_attestation['known_candidate'] ) || ! empty( $candidate_attestation['candidate_policy_available'] ) ) $result['candidate_attestation'] = $candidate_attestation;

		if ( ! empty( $contract['native_abilities'] ) && is_array( $contract['native_abilities'] ) ) {
			$present = array(); $missing = array();
			foreach ( $contract['native_abilities'] as $ability_name ) {
				if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) $present[] = $ability_name;
				else $missing[] = $ability_name;
			}
			$result['native_abilities_present'] = $present;
			$result['native_abilities_missing'] = $missing;
		}

		if ( ! empty( $contract['verified_absent_abilities'] ) && is_array( $contract['verified_absent_abilities'] ) ) {
			$unexpected = array();
			foreach ( $contract['verified_absent_abilities'] as $ability_name ) if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) $unexpected[] = $ability_name;
			$result['newly_present_abilities'] = $unexpected;
		}

		$result['runtime_contract_ok'] = empty( self::violations_for_status( $result ) );
		return $result;
	}

	private static function composite_runtime_status( $provider, array $contract, $available = null ) {
		$components = array();
		$aggregate = array( 'required' => true, 'manifest_present' => true, 'verified' => array(), 'missing' => array(), 'mismatched' => array() );
		$all_certified = true;
		$any_installed = false;
		$expected_parts = array();
		$actual_parts = array();
		foreach ( $contract['components'] as $key => $component ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $component ) ) { $all_certified = false; continue; }
			$expected = isset( $component['version'] ) ? (string) $component['version'] : '';
			$actual = self::installed_version_for_contract( $component );
			$integrity = self::integrity_status( $component );
			$installed = '' !== $actual;
			$any_installed = $any_installed || $installed;
			$status = $installed && '' !== $expected && hash_equals( $expected, $actual ) ? 'certified' : ( $installed ? 'version_drift' : 'unavailable' );
			if ( 'certified' !== $status || ( ! empty( $integrity['required'] ) && ( empty( $integrity['manifest_present'] ) || ! empty( $integrity['missing'] ) || ! empty( $integrity['mismatched'] ) ) ) ) $all_certified = false;
			if ( '' !== $expected ) $expected_parts[] = $key . '=' . $expected;
			if ( '' !== $actual ) $actual_parts[] = $key . '=' . $actual;
			foreach ( (array) $integrity['verified'] as $item ) $aggregate['verified'][] = $key . ':' . $item;
			foreach ( (array) $integrity['missing'] as $item ) $aggregate['missing'][] = $key . ':' . $item;
			foreach ( (array) $integrity['mismatched'] as $item => $details ) $aggregate['mismatched'][ $key . ':' . $item ] = $details;
			if ( empty( $integrity['manifest_present'] ) ) $aggregate['manifest_present'] = false;
			$components[ $key ] = array(
				'label' => isset( $component['label'] ) ? (string) $component['label'] : $key,
				'plugin_file' => isset( $component['plugin_file'] ) ? (string) $component['plugin_file'] : '',
				'archive' => isset( $component['archive'] ) ? (string) $component['archive'] : '',
				'archive_sha256' => isset( $component['archive_sha256'] ) ? strtolower( (string) $component['archive_sha256'] ) : '',
				'certification_authority' => isset( $component['certification_authority'] ) ? (string) $component['certification_authority'] : '',
				'certified_version' => $expected,
				'installed_version' => $actual,
				'status' => $status,
				'runtime_integrity' => $integrity,
			);
		}
		sort( $expected_parts, SORT_STRING ); sort( $actual_parts, SORT_STRING ); ksort( $components, SORT_STRING );
		if ( false === $available || ! $any_installed ) $status = 'unavailable';
		elseif ( $all_certified ) $status = 'certified';
		else $status = 'component_drift';
		$result = array(
			'provider' => $provider,
			'label' => isset( $contract['label'] ) ? (string) $contract['label'] : $provider,
			'status' => $status,
			'certified_version' => implode( ';', $expected_parts ),
			'certified_versions' => array( implode( ';', $expected_parts ) ),
			'installed_version' => implode( ';', $actual_parts ),
			'contract_mode' => isset( $contract['contract_mode'] ) ? (string) $contract['contract_mode'] : 'composite_exact_packaged_components',
			'certification_authority' => isset( $contract['certification_authority'] ) ? (string) $contract['certification_authority'] : 'repository_composite_exact_archives',
			'components' => $components,
			'runtime_integrity' => $aggregate,
			'component_count' => count( $components ),
		);
		$result['runtime_contract_ok'] = empty( self::violations_for_status( $result ) );
		return $result;
	}

	public static function violations_for_status( array $status ) {
		$violations = array();
		if ( empty( $status['status'] ) || 'certified' !== $status['status'] ) $violations[] = empty( $status['status'] ) ? 'unknown_status' : (string) $status['status'];
		if ( ! empty( $status['candidate_attestation']['attestation_required'] ) ) {
			$violations[] = 'candidate_attestation_required';
			if ( ! empty( $status['candidate_attestation']['attestation_state'] ) ) $violations[] = sanitize_key( (string) $status['candidate_attestation']['attestation_state'] );
		}
		if ( ! empty( $status['native_abilities_missing'] ) ) $violations[] = 'native_abilities_missing';
		if ( ! empty( $status['newly_present_abilities'] ) ) $violations[] = 'verified_absent_ability_present';
		$integrity = isset( $status['runtime_integrity'] ) && is_array( $status['runtime_integrity'] ) ? $status['runtime_integrity'] : array();
		if ( ! empty( $integrity['required'] ) && empty( $integrity['manifest_present'] ) ) $violations[] = 'critical_file_manifest_missing';
		if ( ! empty( $integrity['missing'] ) ) $violations[] = 'critical_file_missing';
		if ( ! empty( $integrity['mismatched'] ) ) $violations[] = 'critical_file_hash_mismatch';
		return array_values( array_unique( $violations ) );
	}

	public static function runtime_violations( $provider, $available = null ) { return self::violations_for_status( self::runtime_status( $provider, $available ) ); }
	public static function mutation_allowed( $provider, $available = null ) { return self::get( $provider ) && empty( self::runtime_violations( $provider, $available ) ); }
	public static function mutation_guard( $provider, $available = null ) {
		$status = self::runtime_status( $provider, $available );
		$violations = self::violations_for_status( $status );
		if ( empty( $violations ) ) return true;
		return new WP_Error( 'mad4b_provider_mutation_not_certified', 'Provider mutation is denied until the exact runtime contract is certified.', array( 'provider' => $provider, 'violations' => $violations, 'runtime_status' => $status ) );
	}

	public static function runtime_inventory() { $items = array(); foreach ( self::all() as $provider => $contract ) $items[ $provider ] = self::runtime_status( $provider ); return $items; }
	public static function has_version_drift() { foreach ( self::runtime_inventory() as $status ) if ( isset( $status['status'] ) && 'version_drift' === $status['status'] ) return true; return false; }
}
