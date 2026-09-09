<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only admin evidence for MCP registration lifecycle/provenance. */
final class MAD4B_SCP_MCP_Registration_Diagnostics_Admin {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_notices', array( __CLASS__, 'render' ), 5 );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostics.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostics.
		if ( 'mad4b-control-plane-connection' !== $page || 'endpoints' !== $tab ) return;
		if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ) return;

		$status = MAD4B_SCP_MCP_Registration_Bridge::status();
		$errors = isset( $status['registration_errors'] ) && is_array( $status['registration_errors'] ) ? $status['registration_errors'] : array();
		$nonempty_errors = array_filter( $errors, static function ( $value ) { return '' !== (string) $value; } );
		$official = ! empty( $status['adapter_runtime_from_official_plugin'] );
		$missed = ! empty( $status['adapter_init_seen_before_bridge_boot'] );
		$hook_bound = ! empty( $status['server_hook_bound'] );
		$registered = empty( $nonempty_errors );
		foreach ( MAD4B_SCP_Servers::registration_status() as $entry ) {
			if ( empty( $entry['registered'] ) ) { $registered = false; break; }
		}
		$type = $registered && $official && ! $missed && $hook_bound ? 'success' : 'warning';

		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p><strong>' . esc_html__( 'MAD4B MCP registration diagnostics', 'mad4b-site-control-plane' ) . '</strong></p>';
		echo '<table class="widefat striped" style="max-width:1100px;margin:8px 0 12px"><tbody>';
		self::row( 'Bridge hook bound', $hook_bound ? 'yes' : 'no' );
		self::row( 'Adapter init happened before bridge boot', $missed ? 'yes' : 'no' );
		self::row( 'Adapter runtime from official plugin', $official ? 'yes' : 'no' );
		self::row( 'Adapter runtime source', isset( $status['adapter_runtime_source'] ) ? $status['adapter_runtime_source'] : '' );
		self::row( 'Adapter runtime version', isset( $status['adapter_runtime_version'] ) ? $status['adapter_runtime_version'] : '' );
		self::row( 'mcp_adapter_init count', isset( $status['mcp_adapter_init_count'] ) ? (string) (int) $status['mcp_adapter_init_count'] : '0' );
		self::row( 'Abilities init count', isset( $status['abilities_init_count'] ) ? (string) (int) $status['abilities_init_count'] : '0' );
		foreach ( $errors as $server_id => $error ) self::row( 'Registration error: ' . sanitize_key( (string) $server_id ), '' === (string) $error ? 'none' : sanitize_key( (string) $error ) );
		echo '</tbody></table></div>';
	}

	private static function row( $label, $value ) {
		echo '<tr><th style="width:330px">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
	}
}
