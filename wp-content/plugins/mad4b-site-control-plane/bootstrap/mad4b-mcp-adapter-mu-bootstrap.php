<?php
/**
 * MAD4B MCP Adapter Early Bootstrap.
 *
 * Staging-only early loader that ensures the canonical MCP Adapter owns the
 * runtime before normal plugins load, but only for MAD4B-owned MCP requests,
 * explicit MAD4B Control Plane admin pages, and WP-CLI. Unrelated WordPress
 * requests must retain the provider/host baseline and therefore never load or
 * instantiate the official MCP Adapter from MU scope.
 *
 * This source intentionally has no WordPress `Plugin Name:` header while it
 * lives under the regular plugin: WordPress loads PHP files placed directly in
 * WPMU_PLUGIN_DIR regardless of plugin headers, while the installer must not
 * discover this source as a second activatable plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$mad4b_mcp_mu_status = array(
	'contract' => 'mad4b.mcp-adapter-mu-bootstrap.v3',
	'executed' => true,
	'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
	'host' => '',
	'eligible' => false,
	'state' => 'ineligible',
	'official_plugin_active' => false,
	'control_plane_active' => false,
	'runtime_preclaimed' => false,
	'preclaimed_symbol' => '',
	'canonical_symbols_pinned' => false,
	'canonical_autoloader_loaded' => false,
	'adapter_instance_armed' => false,
	'adapter_init_hook' => '',
	'adapter_init_hook_bound' => false,
	'runtime_from_official_plugin' => false,
	'runtime_source' => 'unavailable',
	'request_requires_mcp_runtime' => false,
	'request_scope_bypassed' => false,
	'request_route' => '',
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
		$mad4b_mcp_mu_allowed_routes = array(
			'/mcp/mad4b-read',
			'/mcp/mad4b-chatgpt',
			'/mcp/mad4b-content',
			'/mcp/mad4b-write',
			'/mcp/mad4b-admin',
			'/mcp/mad4b-breakglass',
		);
		$mad4b_mcp_mu_request_requires_mcp = defined( 'WP_CLI' ) && constant( 'WP_CLI' );
		$mad4b_mcp_mu_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request classification only.
		if ( ! $mad4b_mcp_mu_request_requires_mcp && '' !== $mad4b_mcp_mu_page && 0 === strpos( $mad4b_mcp_mu_page, 'mad4b-control-plane' ) ) {
			$mad4b_mcp_mu_request_requires_mcp = true;
		}

		$mad4b_mcp_mu_route = '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request classification only.
			$mad4b_mcp_mu_route = wp_unslash( (string) $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request classification only.
		}
		$mad4b_mcp_mu_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.
		if ( '' === $mad4b_mcp_mu_route && '' !== $mad4b_mcp_mu_uri ) {
			$mad4b_mcp_mu_query = wp_parse_url( $mad4b_mcp_mu_uri, PHP_URL_QUERY );
			if ( is_string( $mad4b_mcp_mu_query ) && '' !== $mad4b_mcp_mu_query ) {
				$mad4b_mcp_mu_parsed = array();
				parse_str( $mad4b_mcp_mu_query, $mad4b_mcp_mu_parsed );
				if ( isset( $mad4b_mcp_mu_parsed['rest_route'] ) && is_string( $mad4b_mcp_mu_parsed['rest_route'] ) ) {
					$mad4b_mcp_mu_route = $mad4b_mcp_mu_parsed['rest_route'];
				}
			}
		}

		if ( '' !== $mad4b_mcp_mu_route ) {
			$mad4b_mcp_mu_route = '/' . ltrim( rtrim( rawurldecode( $mad4b_mcp_mu_route ), '/' ), '/' );
		}
		if ( ! $mad4b_mcp_mu_request_requires_mcp && in_array( $mad4b_mcp_mu_route, $mad4b_mcp_mu_allowed_routes, true ) ) {
			$mad4b_mcp_mu_request_requires_mcp = true;
		}

		if ( ! $mad4b_mcp_mu_request_requires_mcp && '' !== $mad4b_mcp_mu_uri ) {
			$mad4b_mcp_mu_path = wp_parse_url( $mad4b_mcp_mu_uri, PHP_URL_PATH );
			if ( is_string( $mad4b_mcp_mu_path ) && '' !== $mad4b_mcp_mu_path ) {
				$mad4b_mcp_mu_path = '/' . ltrim( rawurldecode( $mad4b_mcp_mu_path ), '/' );
				$mad4b_mcp_mu_rest_prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
				$mad4b_mcp_mu_needle = '/' . $mad4b_mcp_mu_rest_prefix . '/';
				$mad4b_mcp_mu_offset = strpos( $mad4b_mcp_mu_path, $mad4b_mcp_mu_needle );
				if ( false !== $mad4b_mcp_mu_offset ) {
					$mad4b_mcp_mu_path = '/' . ltrim( substr( $mad4b_mcp_mu_path, $mad4b_mcp_mu_offset + strlen( $mad4b_mcp_mu_needle ) ), '/' );
				}
				$mad4b_mcp_mu_path = '/' . ltrim( rtrim( $mad4b_mcp_mu_path, '/' ), '/' );
				if ( in_array( $mad4b_mcp_mu_path, $mad4b_mcp_mu_allowed_routes, true ) ) {
					$mad4b_mcp_mu_route = $mad4b_mcp_mu_path;
					$mad4b_mcp_mu_request_requires_mcp = true;
				}
			}
		}

		$mad4b_mcp_mu_status['request_requires_mcp_runtime'] = (bool) $mad4b_mcp_mu_request_requires_mcp;
		$mad4b_mcp_mu_status['request_route'] = substr( (string) $mad4b_mcp_mu_route, 0, 255 );
		if ( ! $mad4b_mcp_mu_request_requires_mcp ) {
			$mad4b_mcp_mu_status['request_scope_bypassed'] = true;
			$mad4b_mcp_mu_status['state'] = 'non_mad4b_request_bypassed';
		}
	}

	if ( $mad4b_mcp_mu_status['eligible'] && ! $mad4b_mcp_mu_status['request_scope_bypassed'] ) {
		$mad4b_mcp_mu_symbols = array(
			'WP\\MCP\\Autoloader',
			'WP\\MCP\\Core\\McpAdapter',
			'WP\\MCP\\Plugin',
		);
		foreach ( $mad4b_mcp_mu_symbols as $mad4b_mcp_mu_symbol ) {
			if ( class_exists( $mad4b_mcp_mu_symbol, false ) ) {
				$mad4b_mcp_mu_status['runtime_preclaimed'] = true;
				$mad4b_mcp_mu_status['preclaimed_symbol'] = $mad4b_mcp_mu_symbol;
				$mad4b_mcp_mu_status['state'] = 'runtime_preclaimed_before_mu_bootstrap';
				break;
			}
		}

		$mad4b_mcp_mu_root = trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter/';
		$mad4b_mcp_mu_pin_files = array(
			$mad4b_mcp_mu_root . 'includes/Autoloader.php',
			$mad4b_mcp_mu_root . 'includes/Core/McpAdapter.php',
			$mad4b_mcp_mu_root . 'includes/Plugin.php',
		);
		$mad4b_mcp_mu_autoloader = $mad4b_mcp_mu_root . 'vendor/autoload_packages.php';

		if ( ! $mad4b_mcp_mu_status['runtime_preclaimed'] ) {
			foreach ( array_merge( $mad4b_mcp_mu_pin_files, array( $mad4b_mcp_mu_autoloader ) ) as $mad4b_mcp_mu_required_file ) {
				if ( ! is_readable( $mad4b_mcp_mu_required_file ) ) {
					$mad4b_mcp_mu_status['state'] = 'official_adapter_file_unreadable';
					break;
				}
			}
		}

		if ( ! $mad4b_mcp_mu_status['runtime_preclaimed'] && 'official_adapter_file_unreadable' !== $mad4b_mcp_mu_status['state'] ) {
			foreach ( $mad4b_mcp_mu_pin_files as $mad4b_mcp_mu_pin_file ) require_once $mad4b_mcp_mu_pin_file;
			$mad4b_mcp_mu_status['canonical_symbols_pinned'] = class_exists( 'WP\\MCP\\Autoloader', false )
				&& class_exists( 'WP\\MCP\\Core\\McpAdapter', false )
				&& class_exists( 'WP\\MCP\\Plugin', false );
			if ( ! $mad4b_mcp_mu_status['canonical_symbols_pinned'] ) {
				$mad4b_mcp_mu_status['state'] = 'canonical_symbol_pin_failed';
			} else {
				$mad4b_mcp_mu_autoload_result = require_once $mad4b_mcp_mu_autoloader;
				$mad4b_mcp_mu_status['canonical_autoloader_loaded'] = false !== $mad4b_mcp_mu_autoload_result;
				if ( ! $mad4b_mcp_mu_status['canonical_autoloader_loaded'] ) {
					$mad4b_mcp_mu_status['state'] = 'canonical_autoloader_load_failed';
				} else {
					$mad4b_mcp_mu_adapter = \WP\MCP\Core\McpAdapter::instance();
					$mad4b_mcp_mu_status['adapter_instance_armed'] = is_object( $mad4b_mcp_mu_adapter );
					$mad4b_mcp_mu_status['adapter_init_hook'] = defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ? 'init' : 'rest_api_init';
					$mad4b_mcp_mu_status['adapter_init_hook_bound'] = false !== has_action( $mad4b_mcp_mu_status['adapter_init_hook'], array( $mad4b_mcp_mu_adapter, 'init' ) );
					$mad4b_mcp_mu_status['state'] = $mad4b_mcp_mu_status['adapter_instance_armed'] && $mad4b_mcp_mu_status['adapter_init_hook_bound']
						? 'canonical_runtime_pinned_adapter_hook_armed'
						: 'canonical_adapter_hook_arm_failed';
				}
			}
		}

		$mad4b_mcp_mu_class = '\\WP\\MCP\\Core\\McpAdapter';
		if ( class_exists( $mad4b_mcp_mu_class, false ) ) {
			try {
				$mad4b_mcp_mu_reflection = new ReflectionClass( $mad4b_mcp_mu_class );
				$mad4b_mcp_mu_file = $mad4b_mcp_mu_reflection->getFileName();
				$mad4b_mcp_mu_resolved = $mad4b_mcp_mu_file ? realpath( $mad4b_mcp_mu_file ) : false;
				$mad4b_mcp_mu_plugins_root = realpath( WP_PLUGIN_DIR );
				$mad4b_mcp_mu_official_root = realpath( $mad4b_mcp_mu_root );
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

		if ( 'canonical_runtime_pinned_adapter_hook_armed' === $mad4b_mcp_mu_status['state'] && empty( $mad4b_mcp_mu_status['runtime_from_official_plugin'] ) ) {
			$mad4b_mcp_mu_status['state'] = 'runtime_not_official_after_bootstrap';
		}
	}
}

$GLOBALS['mad4b_scp_mcp_mu_bootstrap'] = $mad4b_mcp_mu_status;
unset(
	$mad4b_mcp_mu_status,
	$mad4b_mcp_mu_host,
	$mad4b_mcp_mu_active,
	$mad4b_mcp_mu_allowed_routes,
	$mad4b_mcp_mu_request_requires_mcp,
	$mad4b_mcp_mu_page,
	$mad4b_mcp_mu_route,
	$mad4b_mcp_mu_uri,
	$mad4b_mcp_mu_query,
	$mad4b_mcp_mu_parsed,
	$mad4b_mcp_mu_path,
	$mad4b_mcp_mu_rest_prefix,
	$mad4b_mcp_mu_needle,
	$mad4b_mcp_mu_offset,
	$mad4b_mcp_mu_symbols,
	$mad4b_mcp_mu_symbol,
	$mad4b_mcp_mu_root,
	$mad4b_mcp_mu_pin_files,
	$mad4b_mcp_mu_pin_file,
	$mad4b_mcp_mu_autoloader,
	$mad4b_mcp_mu_autoload_result,
	$mad4b_mcp_mu_adapter,
	$mad4b_mcp_mu_required_file,
	$mad4b_mcp_mu_class,
	$mad4b_mcp_mu_reflection,
	$mad4b_mcp_mu_file,
	$mad4b_mcp_mu_resolved,
	$mad4b_mcp_mu_plugins_root,
	$mad4b_mcp_mu_official_root,
	$mad4b_mcp_mu_normalized,
	$mad4b_mcp_mu_plugins,
	$mad4b_mcp_mu_official_prefix,
	$mad4b_mcp_mu_error
);