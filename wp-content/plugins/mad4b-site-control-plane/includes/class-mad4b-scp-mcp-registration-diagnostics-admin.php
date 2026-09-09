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
		$conflict = class_exists( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard' ) ? MAD4B_SCP_MCP_Runtime_Conflict_Guard::status() : array();
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
		if ( ! empty( $conflict ) ) {
			self::row( 'Runtime conflict guard eligible', ! empty( $conflict['eligible'] ) ? 'yes' : 'no' );
			self::row( 'Official MCP Adapter active', ! empty( $conflict['official_plugin_active'] ) ? 'yes' : 'no' );
			self::row( 'Hostinger bundled adapter active', ! empty( $conflict['hostinger_bundle_active'] ) ? 'yes' : 'no' );
			self::row( 'Official loads before Hostinger in active_plugins', ! empty( $conflict['official_loads_before_hostinger'] ) ? 'yes' : 'no' );
			self::row( 'Runtime provenance mismatch', ! empty( $conflict['runtime_provenance_mismatch'] ) ? 'yes' : 'no' );
			self::row( 'Runtime from Hostinger bundle', ! empty( $conflict['runtime_from_hostinger_bundle'] ) ? 'yes' : 'no' );
			self::row( 'Collision risk detected', ! empty( $conflict['collision_risk_detected'] ) ? 'yes' : 'no' );
			self::row( 'Runtime repair applied', ! empty( $conflict['repair_applied'] ) ? 'yes' : 'no' );
			self::row( 'active_plugins order repair applied', ! empty( $conflict['load_order_repair_applied'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap present', ! empty( $conflict['mu_bootstrap_present'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap integrity', ! empty( $conflict['mu_bootstrap_integrity'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap executed this request', ! empty( $conflict['mu_bootstrap_executed'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap runtime state', isset( $conflict['mu_bootstrap_runtime_state'] ) ? sanitize_key( (string) $conflict['mu_bootstrap_runtime_state'] ) : '' );
			self::row( 'MU bootstrap runtime source', isset( $conflict['mu_bootstrap_runtime_source'] ) ? sanitize_text_field( (string) $conflict['mu_bootstrap_runtime_source'] ) : '' );
			self::row( 'Next request required', ! empty( $conflict['next_request_required'] ) ? 'yes' : 'no' );
			self::row( 'Runtime conflict guard state', isset( $conflict['state'] ) ? sanitize_key( (string) $conflict['state'] ) : '' );
			self::row( 'Runtime conflict guard blocker', isset( $conflict['blocker'] ) && '' !== (string) $conflict['blocker'] ? sanitize_key( (string) $conflict['blocker'] ) : 'none' );
		}
		foreach ( $errors as $server_id => $error ) self::row( 'Registration error: ' . sanitize_key( (string) $server_id ), '' === (string) $error ? 'none' : sanitize_key( (string) $error ) );
		echo '</tbody></table></div>';
	}

	private static function row( $label, $value ) {
		echo '<tr><th style="width:330px">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
	}
}
