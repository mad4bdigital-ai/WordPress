<?php
/**
 * Plugin Name: MAD4B MCP Adapter Early Bootstrap
 * Description: Staging-only early loader that ensures the canonical MCP Adapter owns the runtime before normal plugins load.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$mad4b_mcp_mu_status = array(
	'contract' => 'mad4b.mcp-adapter-mu-bootstrap.v1',
	'executed' => true,
	'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
	'host' => '',
	'eligible' => false,
	'state' => 'ineligible',
	'official_plugin_active' => false,
	'control_plane_active' => false,
	'runtime_preclaimed' => false,
	'runtime_from_official_plugin' => false,
	'runtime_source' => 'unavailable',
);

if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
	$mad4b_mcp_mu_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$mad4b_mcp_mu_status['host'] = is_string( $mad4b_mcp_mu_host ) ? strtolower( rtrim( trim( $mad4b_mcp_mu_host ), '.' ) ) : '';
}

if ( 'staging' === $mad4b_mcp_mu_status['environment'] && 'staging.egypttourgates.com' === $mad4b_mcp_mu_status['host'] ) {
	$mad4b_mcp_mu_active = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
	$mad4b_mcp_mu_active = is_array( $mad4b_mcp_mu_active ) ? array_values( array_map( 'strval', $mad4b_mcp_mu_active ) ) : array();
	$mad4b_mcp_mu_status['official_plugin_active'] = in_array( 'mcp-adapter/mcp-adapter.php', $mad4b_mcp_mu_active, true );
	$mad4b_mcp_mu_status['control_plane_active'] = in_array( 'mad4b-site-control-plane/mad4b-site-control-plane.php', $mad4b_mcp_mu_active, true );
	$mad4b_mcp_mu_status['eligible'] = $mad4b_mcp_mu_status['official_plugin_active'] && $mad4b_mcp_mu_status['control_plane_active'];

	if ( $mad4b_mcp_mu_status['eligible'] ) {
		$mad4b_mcp_mu_class = '\\WP\\MCP\\Core\\McpAdapter';
		if ( class_exists( $mad4b_mcp_mu_class, false ) ) {
			$mad4b_mcp_mu_status['runtime_preclaimed'] = true;
			$mad4b_mcp_mu_status['state'] = 'runtime_preclaimed_before_mu_bootstrap';
		} else {
			$mad4b_mcp_mu_official = trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter/mcp-adapter.php';
			if ( ! is_readable( $mad4b_mcp_mu_official ) ) {
				$mad4b_mcp_mu_status['state'] = 'official_adapter_file_unreadable';
			} else {
				require_once $mad4b_mcp_mu_official;
				$mad4b_mcp_mu_status['state'] = class_exists( $mad4b_mcp_mu_class, false ) ? 'official_adapter_loaded' : 'official_adapter_class_missing_after_bootstrap';
			}
		}

		if ( class_exists( $mad4b_mcp_mu_class, false ) ) {
			try {
				$mad4b_mcp_mu_reflection = new ReflectionClass( $mad4b_mcp_mu_class );
				$mad4b_mcp_mu_file = $mad4b_mcp_mu_reflection->getFileName();
				$mad4b_mcp_mu_resolved = $mad4b_mcp_mu_file ? realpath( $mad4b_mcp_mu_file ) : false;
				$mad4b_mcp_mu_plugins_root = realpath( WP_PLUGIN_DIR );
				$mad4b_mcp_mu_official_root = realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' );
				if ( $mad4b_mcp_mu_resolved && $mad4b_mcp_mu_plugins_root ) {
					$mad4b_mcp_mu_normalized = wp_normalize_path( $mad4b_mcp_mu_resolved );
					$mad4b_mcp_mu_plugins = rtrim( wp_normalize_path( $mad4b_mcp_mu_plugins_root ), '/' ) . '/';
					$mad4b_mcp_mu_status['runtime_source'] = 0 === strpos( $mad4b_mcp_mu_normalized, $mad4b_mcp_mu_plugins ) ? ltrim( substr( $mad4b_mcp_mu_normalized, strlen( $mad4b_mcp_mu_plugins ) ), '/' ) : 'outside-wp-plugin-dir';
					if ( $mad4b_mcp_mu_official_root ) {
						$mad4b_mcp_mu_official_prefix = rtrim( wp_normalize_path( $mad4b_mcp_mu_official_root ), '/' ) . '/';
						$mad4b_mcp_mu_status['runtime_from_official_plugin'] = 0 === strpos( $mad4b_mcp_mu_normalized, $mad4b_mcp_mu_official_prefix );
					}
				}
			} catch ( Throwable $mad4b_mcp_mu_error ) {
				$mad4b_mcp_mu_status['runtime_source'] = 'reflection-unavailable';
			}
		}

		if ( 'official_adapter_loaded' === $mad4b_mcp_mu_status['state'] && empty( $mad4b_mcp_mu_status['runtime_from_official_plugin'] ) ) {
			$mad4b_mcp_mu_status['state'] = 'runtime_not_official_after_bootstrap';
		}
	}
}

$GLOBALS['mad4b_scp_mcp_mu_bootstrap'] = $mad4b_mcp_mu_status;
unset( $mad4b_mcp_mu_status, $mad4b_mcp_mu_host, $mad4b_mcp_mu_active, $mad4b_mcp_mu_class, $mad4b_mcp_mu_official, $mad4b_mcp_mu_reflection, $mad4b_mcp_mu_file, $mad4b_mcp_mu_resolved, $mad4b_mcp_mu_plugins_root, $mad4b_mcp_mu_official_root, $mad4b_mcp_mu_normalized, $mad4b_mcp_mu_plugins, $mad4b_mcp_mu_official_prefix, $mad4b_mcp_mu_error );