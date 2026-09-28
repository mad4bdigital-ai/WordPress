<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact site/network activation-state preservation for governed plugin
 * replacement/update transactions.
 *
 * WordPress is_plugin_active() reports effective activation and therefore folds
 * network activation into site checks. This helper keeps the stored per-site
 * active_plugins state separate from network activation and can restore both
 * dimensions after a package transaction.
 */
final class MAD4B_SCP_Plugin_Activation_State {
	const CONTRACT = 'mad4b.plugin-activation-state.v1';

	public static function snapshot( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = ltrim( wp_normalize_path( sanitize_text_field( (string) $plugin_file ) ), '/' );
		if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) ) return new WP_Error( 'mad4b_plugin_activation_state_plugin_invalid', 'Plugin file identity is invalid.' );
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) return new WP_Error( 'mad4b_plugin_activation_state_plugin_missing', 'Plugin is not installed.' );
		$active_plugins = get_option( 'active_plugins', array() );
		$site_active = is_array( $active_plugins ) && in_array( $plugin_file, $active_plugins, true );
		$network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );
		return array(
			'contract' => self::CONTRACT,
			'plugin_file' => $plugin_file,
			'site_active' => (bool) $site_active,
			'network_active' => (bool) $network_active,
			'effective_active' => (bool) ( $site_active || $network_active ),
		);
	}

	public static function restore( $plugin_file, array $desired ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$desired_site = ! empty( $desired['site_active'] );
		$desired_network = ! empty( $desired['network_active'] );
		if ( $desired_network && ! is_multisite() ) return new WP_Error( 'mad4b_plugin_activation_state_network_unavailable', 'Cannot restore network activation outside WordPress multisite.' );

		$current = self::snapshot( $plugin_file );
		if ( is_wp_error( $current ) ) return $current;

		// Site membership cannot be changed through normal lifecycle APIs while
		// network activation makes the plugin effectively active. Temporarily
		// remove network activation only when exact site membership drifted.
		if ( ! empty( $current['network_active'] ) && ( ! $desired_network || (bool) $current['site_active'] !== $desired_site ) ) {
			deactivate_plugins( $plugin_file, true, true );
			$current = self::snapshot( $plugin_file );
			if ( is_wp_error( $current ) ) return $current;
		}

		if ( (bool) $current['site_active'] !== $desired_site ) {
			if ( $desired_site ) {
				$result = activate_plugin( $plugin_file, '', false, true );
				if ( is_wp_error( $result ) ) return $result;
			} else {
				deactivate_plugins( $plugin_file, true, false );
			}
			$current = self::snapshot( $plugin_file );
			if ( is_wp_error( $current ) ) return $current;
		}

		if ( (bool) $current['network_active'] !== $desired_network ) {
			if ( $desired_network ) {
				$result = activate_plugin( $plugin_file, '', true, true );
				if ( is_wp_error( $result ) ) return $result;
			} else {
				deactivate_plugins( $plugin_file, true, true );
			}
		}

		$after = self::snapshot( $plugin_file );
		if ( is_wp_error( $after ) ) return $after;
		if ( (bool) $after['site_active'] !== $desired_site || (bool) $after['network_active'] !== $desired_network ) {
			return new WP_Error(
				'mad4b_plugin_activation_state_restore_mismatch',
				'Plugin activation state could not be restored exactly.',
				array(
					'expected_site_active' => $desired_site,
					'expected_network_active' => $desired_network,
					'current_site_active' => (bool) $after['site_active'],
					'current_network_active' => (bool) $after['network_active'],
				)
			);
		}
		return $after;
	}
}
