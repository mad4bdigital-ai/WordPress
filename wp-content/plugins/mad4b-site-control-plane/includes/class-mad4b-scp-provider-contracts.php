<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Provider_Contracts {
	private static $contracts = null;
	private static $profiles = null;

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

	public static function certified_versions( $provider ) {
		$base = self::get( $provider );
		$versions = array();
		if ( ! empty( $base['version'] ) ) $versions[] = (string) $base['version'];
		$profiles = self::profiles();
		if ( ! empty( $profiles[ $provider ] ) && is_array( $profiles[ $provider ] ) ) {
			foreach ( $profiles[ $provider ] as $version => $profile ) {
				if ( is_array( $profile ) && preg_match( '/^[0-9A-Za-z._-]+$/', (string) $version ) ) $versions[] = (string) $version;
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
		if ( empty( $profiles[ $provider ][ $version ] ) || ! is_array( $profiles[ $provider ][ $version ] ) ) return $base;
		$profile = $profiles[ $provider ][ $version ];
		if ( empty( $profile['version'] ) || ! hash_equals( $version, (string) $profile['version'] ) ) return $base;
		return array_replace_recursive( $base, $profile );
	}

	public static function required_providers() {
		$required = apply_filters( 'mad4b_scp_required_providers', array_keys( self::all() ) );
		if ( ! is_array( $required ) ) return array();
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $required ) ) ) );
	}

	public static function installed_version( $provider ) {
		$contract = self::get( $provider );
		if ( empty( $contract['plugin_file'] ) ) return '';
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$file = (string) $contract['plugin_file'];
		return isset( $plugins[ $file ] ) && ! empty( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';
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

	public static function runtime_status( $provider, $available = null ) {
		$base_contract = self::get( $provider );
		if ( empty( $base_contract ) ) return array( 'provider' => $provider, 'status' => 'uncertified_provider', 'runtime_contract_ok' => false );

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

	public static function violations_for_status( array $status ) {
		$violations = array();
		if ( empty( $status['status'] ) || 'certified' !== $status['status'] ) $violations[] = empty( $status['status'] ) ? 'unknown_status' : (string) $status['status'];
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
