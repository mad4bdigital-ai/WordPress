<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Provider_Contracts {
	private static $contracts = null;

	public static function all() {
		if ( null !== self::$contracts ) {
			return self::$contracts;
		}

		$path = MAD4B_SCP_DIR . 'config/certified-providers.json';
		if ( ! is_readable( $path ) ) {
			self::$contracts = array();
			return self::$contracts;
		}

		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			self::$contracts = array();
			return self::$contracts;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['providers'] ) || ! is_array( $data['providers'] ) ) {
			self::$contracts = array();
			return self::$contracts;
		}

		self::$contracts = $data['providers'];
		return self::$contracts;
	}

	public static function get( $provider ) {
		$contracts = self::all();
		return isset( $contracts[ $provider ] ) && is_array( $contracts[ $provider ] ) ? $contracts[ $provider ] : array();
	}

	public static function required_providers() {
		$required = array_keys( self::all() );
		$required = apply_filters( 'mad4b_scp_required_providers', $required );
		if ( ! is_array( $required ) ) {
			return array();
		}
		$required = array_filter( array_map( 'sanitize_key', $required ) );
		return array_values( array_unique( $required ) );
	}

	public static function installed_version( $provider ) {
		$contract = self::get( $provider );
		if ( empty( $contract['plugin_file'] ) ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$file = (string) $contract['plugin_file'];
		if ( isset( $plugins[ $file ] ) && ! empty( $plugins[ $file ]['Version'] ) ) {
			return (string) $plugins[ $file ]['Version'];
		}

		return '';
	}

	public static function runtime_status( $provider, $available = null ) {
		$contract = self::get( $provider );
		if ( empty( $contract ) ) {
			return array(
				'provider' => $provider,
				'status'   => 'uncertified_provider',
				'runtime_contract_ok' => false,
			);
		}

		$expected = isset( $contract['version'] ) ? (string) $contract['version'] : '';
		$actual = self::installed_version( $provider );
		if ( false === $available || '' === $actual ) {
			$status = 'unavailable';
		} elseif ( '' !== $expected && hash_equals( $expected, $actual ) ) {
			$status = 'certified';
		} else {
			$status = 'version_drift';
		}

		$result = array(
			'provider'          => $provider,
			'label'             => isset( $contract['label'] ) ? $contract['label'] : $provider,
			'status'            => $status,
			'certified_version' => $expected,
			'installed_version' => $actual,
			'contract_mode'     => isset( $contract['contract_mode'] ) ? $contract['contract_mode'] : '',
		);

		if ( ! empty( $contract['native_abilities'] ) && is_array( $contract['native_abilities'] ) ) {
			$present = array();
			$missing = array();
			foreach ( $contract['native_abilities'] as $ability_name ) {
				if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
					$present[] = $ability_name;
				} else {
					$missing[] = $ability_name;
				}
			}
			$result['native_abilities_present'] = $present;
			$result['native_abilities_missing'] = $missing;
		}

		if ( ! empty( $contract['verified_absent_abilities'] ) && is_array( $contract['verified_absent_abilities'] ) ) {
			$unexpected = array();
			foreach ( $contract['verified_absent_abilities'] as $ability_name ) {
				if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
					$unexpected[] = $ability_name;
				}
			}
			$result['newly_present_abilities'] = $unexpected;
		}

		$result['runtime_contract_ok'] = empty( self::violations_for_status( $result ) );
		return $result;
	}

	public static function violations_for_status( array $status ) {
		$violations = array();
		if ( empty( $status['status'] ) || 'certified' !== $status['status'] ) {
			$violations[] = empty( $status['status'] ) ? 'unknown_status' : (string) $status['status'];
		}
		if ( ! empty( $status['native_abilities_missing'] ) ) {
			$violations[] = 'native_abilities_missing';
		}
		if ( ! empty( $status['newly_present_abilities'] ) ) {
			$violations[] = 'verified_absent_ability_present';
		}
		return array_values( array_unique( $violations ) );
	}

	public static function runtime_violations( $provider, $available = null ) {
		return self::violations_for_status( self::runtime_status( $provider, $available ) );
	}

	public static function mutation_allowed( $provider, $available = null ) {
		if ( ! self::get( $provider ) ) {
			return false;
		}
		return empty( self::runtime_violations( $provider, $available ) );
	}

	public static function mutation_guard( $provider, $available = null ) {
		$status = self::runtime_status( $provider, $available );
		$violations = self::violations_for_status( $status );
		if ( empty( $violations ) ) {
			return true;
		}
		return new WP_Error(
			'mad4b_provider_mutation_not_certified',
			'Provider mutation is denied until the exact runtime contract is certified.',
			array(
				'provider' => $provider,
				'violations' => $violations,
				'runtime_status' => $status,
			)
		);
	}

	public static function runtime_inventory() {
		$items = array();
		foreach ( self::all() as $provider => $contract ) {
			$items[ $provider ] = self::runtime_status( $provider );
		}
		return $items;
	}

	public static function has_version_drift() {
		foreach ( self::runtime_inventory() as $status ) {
			if ( isset( $status['status'] ) && 'version_drift' === $status['status'] ) {
				return true;
			}
		}
		return false;
	}
}
