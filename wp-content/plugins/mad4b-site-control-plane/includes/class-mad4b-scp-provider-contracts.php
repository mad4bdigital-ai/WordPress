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

		return $result;
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
