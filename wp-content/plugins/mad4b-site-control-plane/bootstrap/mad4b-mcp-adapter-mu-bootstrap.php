<?php
/**
 * MAD4B MCP Adapter Early Bootstrap.
 *
 * Enrolled non-production early loader that ensures the canonical MCP Adapter owns the
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
	'contract' => 'mad4b.mcp-adapter-mu-bootstrap.v5',
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
	'critical_class_baseline_ready' => false,
	'critical_class_set_pinned' => false,
	'critical_class_pin_count' => 0,
	'critical_class_pin_failed_symbol' => '',
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

// Reuse the same pure exact-origin/environment decision as the regular plugin.
// Raw WordPress production defaults are not explicit Production enrollment.
$mad4b_mcp_mu_binding_file = trailingslashit( WP_PLUGIN_DIR ) . 'mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php';
$mad4b_mcp_mu_binding = array( 'eligible' => false );
if ( is_readable( $mad4b_mcp_mu_binding_file ) ) {
	require_once $mad4b_mcp_mu_binding_file;
	$mad4b_mcp_mu_binding = MAD4B_SCP_Site_Profile::early_managed_runtime_binding();
}
$mad4b_mcp_mu_status['environment'] = $mad4b_mcp_mu_binding['environment'] ?? $mad4b_mcp_mu_status['environment'];
$mad4b_mcp_mu_status['wordpress_environment'] = $mad4b_mcp_mu_binding['wordpress_environment'] ?? 'unknown';
$mad4b_mcp_mu_status['wordpress_environment_explicit'] = ! empty( $mad4b_mcp_mu_binding['wordpress_environment_explicit'] );
$mad4b_mcp_mu_profile_enrolled = ! empty( $mad4b_mcp_mu_binding['eligible'] );

if ( $mad4b_mcp_mu_profile_enrolled ) {
	$mad4b_mcp_mu_active = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
	$mad4b_mcp_mu_active = is_array( $mad4b_mcp_mu_active ) ? array_values( array_map( 'strval', $mad4b_mcp_mu_active ) ) : array();
	$mad4b_mcp_mu_status['official_plugin_active'] = in_array( 'mcp-adapter/mcp-adapter.php', $mad4b_mcp_mu_active, true );
	$mad4b_mcp_mu_status['control_plane_active'] = in_array( 'mad4b-site-control-plane/mad4b-site-control-plane.php', $mad4b_mcp_mu_active, true );
	$mad4b_mcp_mu_status['eligible'] = $mad4b_mcp_mu_status['official_plugin_active'] && $mad4b_mcp_mu_status['control_plane_active'];

	if ( $mad4b_mcp_mu_status['eligible'] ) {
		$mad4b_mcp_mu_allowed_routes = array(
			'/mcp/mad4b-read',
			'/mcp/mad4b-chatgpt',
			'/mcp/mad4b-enrollment',
			'/mcp/mad4b-content',
			'/mcp/mad4b-write',
			'/mcp/mad4b-admin',
			'/mcp/mad4b-breakglass',
			'/mcp/mad4b-developer',
			'/mcp/mad4b-developer-breakglass',
		);
		$mad4b_mcp_mu_request_requires_mcp = defined( 'WP_CLI' ) && constant( 'WP_CLI' );
		$mad4b_mcp_mu_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.
		$mad4b_mcp_mu_query = '' !== $mad4b_mcp_mu_uri ? wp_parse_url( $mad4b_mcp_mu_uri, PHP_URL_QUERY ) : '';
		$mad4b_mcp_mu_parsed = array();
		if ( is_string( $mad4b_mcp_mu_query ) && '' !== $mad4b_mcp_mu_query ) {
			parse_str( $mad4b_mcp_mu_query, $mad4b_mcp_mu_parsed );
		}
		$mad4b_mcp_mu_path = '' !== $mad4b_mcp_mu_uri ? wp_parse_url( $mad4b_mcp_mu_uri, PHP_URL_PATH ) : '';
		$mad4b_mcp_mu_path = is_string( $mad4b_mcp_mu_path ) ? '/' . ltrim( rawurldecode( $mad4b_mcp_mu_path ), '/' ) : '';

		// Admin classification is path-bound, not a raw query-variable shortcut:
		// a front-end request carrying ?page=mad4b-control-plane-* must not be able
		// to arm the privileged MCP runtime.
		$mad4b_mcp_mu_page = isset( $mad4b_mcp_mu_parsed['page'] ) && is_string( $mad4b_mcp_mu_parsed['page'] )
			? sanitize_key( $mad4b_mcp_mu_parsed['page'] )
			: '';
		$mad4b_mcp_mu_admin_path = '' !== $mad4b_mcp_mu_path && 1 === preg_match( '#(?:^|/)wp-admin/admin\.php$#', $mad4b_mcp_mu_path );
		if ( ! $mad4b_mcp_mu_request_requires_mcp && $mad4b_mcp_mu_admin_path && '' !== $mad4b_mcp_mu_page && 0 === strpos( $mad4b_mcp_mu_page, 'mad4b-control-plane' ) ) {
			$mad4b_mcp_mu_request_requires_mcp = true;
		}

		// Routing only: pin certified classes early on the exact diagnostic POST.
		// The worker still owns nonce/capability/build checks and singleton arming.
		$mad4b_mcp_mu_diagnostic = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
			&& 1 === preg_match( '#(?:^|/)wp-admin/admin\\-ajax\\.php$#', $mad4b_mcp_mu_path )
			&& isset( $_POST['action'] ) && is_string( $_POST['action'] )
			&& 'mad4b_connection_endpoint_diagnostic' === $_POST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only.
		if ( $mad4b_mcp_mu_diagnostic ) $mad4b_mcp_mu_request_requires_mcp = true;

		$mad4b_mcp_mu_route = isset( $mad4b_mcp_mu_parsed['rest_route'] ) && is_string( $mad4b_mcp_mu_parsed['rest_route'] )
			? $mad4b_mcp_mu_parsed['rest_route']
			: '';
		if ( '' !== $mad4b_mcp_mu_route ) {
			$mad4b_mcp_mu_route = '/' . ltrim( rtrim( rawurldecode( $mad4b_mcp_mu_route ), '/' ), '/' );
		}
		if ( ! $mad4b_mcp_mu_request_requires_mcp && in_array( $mad4b_mcp_mu_route, $mad4b_mcp_mu_allowed_routes, true ) ) {
			$mad4b_mcp_mu_request_requires_mcp = true;
		}

		if ( ! $mad4b_mcp_mu_request_requires_mcp && '' !== $mad4b_mcp_mu_path ) {
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

		$mad4b_mcp_mu_status['request_requires_mcp_runtime'] = (bool) $mad4b_mcp_mu_request_requires_mcp;
		$mad4b_mcp_mu_status['request_route'] = substr( (string) $mad4b_mcp_mu_route, 0, 255 );
		if ( ! $mad4b_mcp_mu_request_requires_mcp ) {
			$mad4b_mcp_mu_status['request_scope_bypassed'] = true;
			$mad4b_mcp_mu_status['state'] = 'non_mad4b_request_bypassed';
		}
	}

	if ( $mad4b_mcp_mu_status['eligible'] && ! $mad4b_mcp_mu_status['request_scope_bypassed'] ) {
		$mad4b_mcp_mu_critical_classes = array(
			'WP\\MCP\\Core\\McpAdapter' => 'includes/Core/McpAdapter.php',
			'WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool' => 'includes/Domain/Tools/RegisterAbilityAsMcpTool.php',
			'WP\\MCP\\Domain\\Tools\\McpToolValidator' => 'includes/Domain/Tools/McpToolValidator.php',
			'WP\\MCP\\Domain\\Utils\\SchemaTransformer' => 'includes/Domain/Utils/SchemaTransformer.php',
			'WP\\MCP\\Domain\\Utils\\McpAnnotationMapper' => 'includes/Domain/Utils/McpAnnotationMapper.php',
			'WP\\MCP\\Domain\\Utils\\McpValidator' => 'includes/Domain/Utils/McpValidator.php',
			'WP\\McpSchema\\Server\\Tools\\DTO\\Tool' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/Tool.php',
			'WP\\McpSchema\\Server\\Tools\\DTO\\ToolInputSchema' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolInputSchema.php',
			'WP\\McpSchema\\Server\\Tools\\DTO\\ToolOutputSchema' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolOutputSchema.php',
			'WP\\McpSchema\\Server\\Tools\\DTO\\ToolAnnotations' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolAnnotations.php',
			'WP\\McpSchema\\Server\\Tools\\DTO\\ToolExecution' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolExecution.php',
		);
		$mad4b_mcp_mu_symbols = array_merge(
			array( 'WP\\MCP\\Autoloader', 'WP\\MCP\\Plugin' ),
			array_keys( $mad4b_mcp_mu_critical_classes )
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
		$mad4b_mcp_mu_baseline_file = trailingslashit( WP_PLUGIN_DIR ) . 'mad4b-site-control-plane/config/certified-providers.json';

		if ( ! $mad4b_mcp_mu_status['runtime_preclaimed'] ) {
			foreach ( array_merge( $mad4b_mcp_mu_pin_files, array( $mad4b_mcp_mu_autoloader ) ) as $mad4b_mcp_mu_required_file ) {
				if ( ! is_readable( $mad4b_mcp_mu_required_file ) ) {
					$mad4b_mcp_mu_status['state'] = 'official_adapter_file_unreadable';
					break;
				}
			}
		}

		// Validate every executable pin and the autoloader before require_once.
		// A corrupt entrypoint must not execute merely to report an integrity error.
		$mad4b_mcp_mu_baseline = is_readable( $mad4b_mcp_mu_baseline_file ) ? json_decode( (string) file_get_contents( $mad4b_mcp_mu_baseline_file ), true ) : array();
		$mad4b_mcp_mu_critical_hashes = $mad4b_mcp_mu_baseline['providers']['mcp_adapter']['critical_files'] ?? array();
		$mad4b_mcp_mu_status['critical_class_baseline_ready'] = is_array( $mad4b_mcp_mu_critical_hashes ) && ! empty( $mad4b_mcp_mu_critical_hashes );
		foreach ( array_merge( array_values( $mad4b_mcp_mu_critical_classes ), array( 'includes/Autoloader.php', 'includes/Plugin.php', 'vendor/autoload_packages.php' ) ) as $mad4b_mcp_mu_critical_relative ) {
			$mad4b_mcp_mu_expected_sha = $mad4b_mcp_mu_critical_hashes[ $mad4b_mcp_mu_critical_relative ] ?? '';
			$mad4b_mcp_mu_actual_sha = is_readable( $mad4b_mcp_mu_root . $mad4b_mcp_mu_critical_relative ) ? hash_file( 'sha256', $mad4b_mcp_mu_root . $mad4b_mcp_mu_critical_relative ) : '';
			if ( ! is_string( $mad4b_mcp_mu_expected_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $mad4b_mcp_mu_expected_sha )
				|| ! is_string( $mad4b_mcp_mu_actual_sha ) || ! hash_equals( $mad4b_mcp_mu_expected_sha, $mad4b_mcp_mu_actual_sha ) ) {
				$mad4b_mcp_mu_status['critical_class_baseline_ready'] = false;
				if ( ! $mad4b_mcp_mu_status['runtime_preclaimed'] ) $mad4b_mcp_mu_status['state'] = 'critical_class_baseline_mismatch';
				break;
			}
		}

		if ( ! $mad4b_mcp_mu_status['runtime_preclaimed'] && 'official_adapter_file_unreadable' !== $mad4b_mcp_mu_status['state'] && $mad4b_mcp_mu_status['critical_class_baseline_ready'] ) {
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
					$mad4b_mcp_mu_baseline = is_readable( $mad4b_mcp_mu_baseline_file ) ? json_decode( (string) file_get_contents( $mad4b_mcp_mu_baseline_file ), true ) : array();
					$mad4b_mcp_mu_critical_hashes = isset( $mad4b_mcp_mu_baseline['providers']['mcp_adapter']['critical_files'] ) && is_array( $mad4b_mcp_mu_baseline['providers']['mcp_adapter']['critical_files'] )
						? $mad4b_mcp_mu_baseline['providers']['mcp_adapter']['critical_files']
						: array();
					$mad4b_mcp_mu_status['critical_class_baseline_ready'] = ! empty( $mad4b_mcp_mu_critical_hashes );
					foreach ( $mad4b_mcp_mu_critical_classes as $mad4b_mcp_mu_critical_symbol => $mad4b_mcp_mu_critical_relative ) {
						$mad4b_mcp_mu_expected_sha = isset( $mad4b_mcp_mu_critical_hashes[ $mad4b_mcp_mu_critical_relative ] ) ? strtolower( (string) $mad4b_mcp_mu_critical_hashes[ $mad4b_mcp_mu_critical_relative ] ) : '';
						$mad4b_mcp_mu_critical_file = $mad4b_mcp_mu_root . $mad4b_mcp_mu_critical_relative;
						$mad4b_mcp_mu_actual_sha = is_readable( $mad4b_mcp_mu_critical_file ) ? hash_file( 'sha256', $mad4b_mcp_mu_critical_file ) : '';
						if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $mad4b_mcp_mu_expected_sha )
							|| ! is_string( $mad4b_mcp_mu_actual_sha )
							|| ! hash_equals( $mad4b_mcp_mu_expected_sha, strtolower( $mad4b_mcp_mu_actual_sha ) ) ) {
							$mad4b_mcp_mu_status['critical_class_baseline_ready'] = false;
							$mad4b_mcp_mu_status['critical_class_pin_failed_symbol'] = $mad4b_mcp_mu_critical_symbol;
							$mad4b_mcp_mu_status['state'] = 'critical_class_baseline_mismatch';
							break;
						}
					}
					if ( $mad4b_mcp_mu_status['critical_class_baseline_ready'] ) {
						$mad4b_mcp_mu_official_root = realpath( $mad4b_mcp_mu_root );
						foreach ( $mad4b_mcp_mu_critical_classes as $mad4b_mcp_mu_critical_symbol => $mad4b_mcp_mu_critical_relative ) {
							if ( ! class_exists( $mad4b_mcp_mu_critical_symbol ) ) {
								$mad4b_mcp_mu_status['critical_class_pin_failed_symbol'] = $mad4b_mcp_mu_critical_symbol;
								$mad4b_mcp_mu_status['state'] = 'critical_class_pin_failed';
								break;
							}
							try {
								$mad4b_mcp_mu_critical_reflection = new ReflectionClass( $mad4b_mcp_mu_critical_symbol );
								$mad4b_mcp_mu_critical_loaded = $mad4b_mcp_mu_critical_reflection->getFileName();
								$mad4b_mcp_mu_critical_loaded = $mad4b_mcp_mu_critical_loaded ? realpath( $mad4b_mcp_mu_critical_loaded ) : false;
								$mad4b_mcp_mu_critical_expected = realpath( $mad4b_mcp_mu_root . $mad4b_mcp_mu_critical_relative );
								if ( ! $mad4b_mcp_mu_critical_loaded || ! $mad4b_mcp_mu_critical_expected || ! hash_equals( wp_normalize_path( $mad4b_mcp_mu_critical_expected ), wp_normalize_path( $mad4b_mcp_mu_critical_loaded ) ) ) {
									$mad4b_mcp_mu_status['critical_class_pin_failed_symbol'] = $mad4b_mcp_mu_critical_symbol;
									$mad4b_mcp_mu_status['state'] = 'critical_class_source_not_official';
									break;
								}
								$mad4b_mcp_mu_status['critical_class_pin_count']++;
							} catch ( Throwable $mad4b_mcp_mu_critical_error ) {
								$mad4b_mcp_mu_status['critical_class_pin_failed_symbol'] = $mad4b_mcp_mu_critical_symbol;
								$mad4b_mcp_mu_status['state'] = 'critical_class_reflection_failed';
								break;
							}
						}
						$mad4b_mcp_mu_status['critical_class_set_pinned'] = $mad4b_mcp_mu_status['critical_class_pin_count'] === count( $mad4b_mcp_mu_critical_classes );
					}
					$mad4b_mcp_mu_status['canonical_symbols_pinned'] = $mad4b_mcp_mu_status['canonical_symbols_pinned'] && $mad4b_mcp_mu_status['critical_class_set_pinned'];
					if ( $mad4b_mcp_mu_status['canonical_symbols_pinned'] && $mad4b_mcp_mu_diagnostic ) {
						$mad4b_mcp_mu_status['state'] = 'canonical_runtime_pinned_diagnostic_deferred';
					} elseif ( $mad4b_mcp_mu_status['canonical_symbols_pinned'] ) {
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
	$mad4b_mcp_mu_binding_file,
	$mad4b_mcp_mu_binding,
	$mad4b_mcp_mu_diagnostic,
	$mad4b_mcp_mu_host,
	$mad4b_mcp_mu_active,
	$mad4b_mcp_mu_allowed_routes,
	$mad4b_mcp_mu_request_requires_mcp,
	$mad4b_mcp_mu_page,
	$mad4b_mcp_mu_admin_path,
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
	$mad4b_mcp_mu_critical_classes,
	$mad4b_mcp_mu_critical_symbol,
	$mad4b_mcp_mu_critical_relative,
	$mad4b_mcp_mu_baseline_file,
	$mad4b_mcp_mu_baseline,
	$mad4b_mcp_mu_critical_hashes,
	$mad4b_mcp_mu_expected_sha,
	$mad4b_mcp_mu_actual_sha,
	$mad4b_mcp_mu_critical_file,
	$mad4b_mcp_mu_critical_reflection,
	$mad4b_mcp_mu_critical_loaded,
	$mad4b_mcp_mu_critical_expected,
	$mad4b_mcp_mu_critical_error,
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