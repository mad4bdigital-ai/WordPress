<?php
/**
 * MAD4B MCP Adapter Early Bootstrap.
 *
 * Enrolled non-production early loader that ensures the canonical MCP Adapter owns the
 * runtime before normal plugins load, but only for MAD4B-owned MCP requests,
 * explicit MAD4B Control Plane admin pages, and explicit MAD4B MCP WP-CLI opt-in. Unrelated WordPress
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
	'contract' => 'mad4b.mcp-adapter-mu-bootstrap.v6',
	'executed' => true,
	'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
	'host' => '',
	'eligible' => false,
	'state' => 'ineligible',
	'official_plugin_active' => false,
	'control_plane_active' => false,
	'official_plugin_file' => '',
	'adapter_version' => '',
	'adapter_profile_source' => '',
	'adapter_profile_ready' => false,
	'control_plane_plugin_file' => '',
	'plugin_identity_ambiguous' => false,
	'plugin_directory_discovery' => 'active_plugins_unique_main_file',
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
	'transaction_pending' => false,
	'diagnostic_mu_proof_valid' => false,
	'diagnostic_server_id' => '',
	'cli_request' => false,
	'cli_mcp_opt_in' => false,
);

if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
	$mad4b_mcp_mu_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$mad4b_mcp_mu_status['host'] = is_string( $mad4b_mcp_mu_host ) ? strtolower( rtrim( trim( $mad4b_mcp_mu_host ), '.' ) ) : '';
}

// Resolve active plugin roots by unique main-file identity. Directory names are
// not authority and may be renamed; ambiguous candidates remain fail-closed.
$mad4b_mcp_mu_active = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
$mad4b_mcp_mu_active = is_array( $mad4b_mcp_mu_active ) ? array_values( array_map( 'strval', $mad4b_mcp_mu_active ) ) : array();
$mad4b_mcp_mu_find_active = static function ( array $plugins, $main_file ) {
	$matches = array();
	foreach ( $plugins as $plugin_file ) {
		$plugin_file = trim( (string) $plugin_file );
		$plugin_file = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $plugin_file ) : str_replace( '\\', '/', $plugin_file );
		if ( '' === $plugin_file || '/' === substr( $plugin_file, 0, 1 ) ) continue;
		$segments = explode( '/', $plugin_file );
		if ( in_array( '..', $segments, true ) ) continue;
		if ( basename( $plugin_file ) === $main_file ) $matches[] = $plugin_file;
	}
	return array_values( array_unique( $matches ) );
};
$mad4b_mcp_mu_control_plane_matches = $mad4b_mcp_mu_find_active( $mad4b_mcp_mu_active, 'mad4b-site-control-plane.php' );
$mad4b_mcp_mu_adapter_matches = $mad4b_mcp_mu_find_active( $mad4b_mcp_mu_active, 'mcp-adapter.php' );
$mad4b_mcp_mu_control_plane_plugin = 1 === count( $mad4b_mcp_mu_control_plane_matches ) ? $mad4b_mcp_mu_control_plane_matches[0] : '';
$mad4b_mcp_mu_adapter_plugin = 1 === count( $mad4b_mcp_mu_adapter_matches ) ? $mad4b_mcp_mu_adapter_matches[0] : '';
$mad4b_mcp_mu_status['plugin_identity_ambiguous'] = count( $mad4b_mcp_mu_control_plane_matches ) > 1 || count( $mad4b_mcp_mu_adapter_matches ) > 1;
$mad4b_mcp_mu_status['control_plane_plugin_file'] = $mad4b_mcp_mu_control_plane_plugin;
$mad4b_mcp_mu_status['official_plugin_file'] = $mad4b_mcp_mu_adapter_plugin;
$mad4b_mcp_mu_control_plane_root = '' !== $mad4b_mcp_mu_control_plane_plugin
	? trailingslashit( WP_PLUGIN_DIR . '/' . dirname( $mad4b_mcp_mu_control_plane_plugin ) )
	: '';
$mad4b_mcp_mu_root = '' !== $mad4b_mcp_mu_adapter_plugin
	? trailingslashit( WP_PLUGIN_DIR . '/' . dirname( $mad4b_mcp_mu_adapter_plugin ) )
	: '';

$mad4b_mcp_mu_baseline_file = $mad4b_mcp_mu_control_plane_root . 'config/certified-providers.json';
$mad4b_mcp_mu_profiles_file = $mad4b_mcp_mu_control_plane_root . 'config/certified-provider-profiles.json';
$mad4b_mcp_mu_adapter_main = '' !== $mad4b_mcp_mu_root ? $mad4b_mcp_mu_root . 'mcp-adapter.php' : '';
$mad4b_mcp_mu_adapter_version = '';
if ( is_readable( $mad4b_mcp_mu_adapter_main ) ) {
	if ( function_exists( 'get_file_data' ) ) {
		$mad4b_mcp_mu_adapter_header = get_file_data( $mad4b_mcp_mu_adapter_main, array( 'Version' => 'Version' ), 'plugin' );
		if ( is_array( $mad4b_mcp_mu_adapter_header ) && isset( $mad4b_mcp_mu_adapter_header['Version'] ) ) {
			$mad4b_mcp_mu_adapter_version = trim( (string) $mad4b_mcp_mu_adapter_header['Version'] );
		}
	}
	if ( '' === $mad4b_mcp_mu_adapter_version ) {
		$mad4b_mcp_mu_adapter_head = file_get_contents( $mad4b_mcp_mu_adapter_main, false, null, 0, 16384 );
		if ( is_string( $mad4b_mcp_mu_adapter_head ) && preg_match( '/^[ \t*#@\/]*Version:\s*([^\r\n]+)/mi', $mad4b_mcp_mu_adapter_head, $mad4b_mcp_mu_adapter_match ) ) {
			$mad4b_mcp_mu_adapter_version = trim( (string) $mad4b_mcp_mu_adapter_match[1] );
		}
	}
}
$mad4b_mcp_mu_status['adapter_version'] = substr( sanitize_text_field( $mad4b_mcp_mu_adapter_version ), 0, 64 );

$mad4b_mcp_mu_baseline_catalog = is_readable( $mad4b_mcp_mu_baseline_file )
	? json_decode( (string) file_get_contents( $mad4b_mcp_mu_baseline_file ), true )
	: array();
$mad4b_mcp_mu_profiles_catalog = is_readable( $mad4b_mcp_mu_profiles_file )
	? json_decode( (string) file_get_contents( $mad4b_mcp_mu_profiles_file ), true )
	: array();
$mad4b_mcp_mu_base_profile = isset( $mad4b_mcp_mu_baseline_catalog['providers']['mcp_adapter'] ) && is_array( $mad4b_mcp_mu_baseline_catalog['providers']['mcp_adapter'] )
	? $mad4b_mcp_mu_baseline_catalog['providers']['mcp_adapter']
	: array();
$mad4b_mcp_mu_selected_profile = array();
if ( '' !== $mad4b_mcp_mu_adapter_version
	&& isset( $mad4b_mcp_mu_base_profile['version'] )
	&& hash_equals( (string) $mad4b_mcp_mu_base_profile['version'], $mad4b_mcp_mu_adapter_version ) ) {
	$mad4b_mcp_mu_selected_profile = $mad4b_mcp_mu_base_profile;
	$mad4b_mcp_mu_status['adapter_profile_source'] = 'certified-providers';
} elseif ( '' !== $mad4b_mcp_mu_adapter_version
	&& isset( $mad4b_mcp_mu_profiles_catalog['providers']['mcp_adapter'][ $mad4b_mcp_mu_adapter_version ] )
	&& is_array( $mad4b_mcp_mu_profiles_catalog['providers']['mcp_adapter'][ $mad4b_mcp_mu_adapter_version ] ) ) {
	$mad4b_mcp_mu_candidate_profile = $mad4b_mcp_mu_profiles_catalog['providers']['mcp_adapter'][ $mad4b_mcp_mu_adapter_version ];
	if ( isset( $mad4b_mcp_mu_candidate_profile['version'] )
		&& hash_equals( $mad4b_mcp_mu_adapter_version, (string) $mad4b_mcp_mu_candidate_profile['version'] ) ) {
		$mad4b_mcp_mu_selected_profile = $mad4b_mcp_mu_candidate_profile;
		$mad4b_mcp_mu_status['adapter_profile_source'] = 'certified-provider-profiles';
	}
}
$mad4b_mcp_mu_critical_hashes = isset( $mad4b_mcp_mu_selected_profile['critical_files'] ) && is_array( $mad4b_mcp_mu_selected_profile['critical_files'] )
	? $mad4b_mcp_mu_selected_profile['critical_files']
	: array();

// Reuse the same pure exact-origin/environment decision as the regular plugin.
// Raw WordPress production defaults are not explicit Production enrollment.
$mad4b_mcp_mu_binding_file = '' !== $mad4b_mcp_mu_control_plane_root
	? $mad4b_mcp_mu_control_plane_root . 'includes/class-mad4b-scp-site-profile.php'
	: '';
$mad4b_mcp_mu_binding = array( 'eligible' => false );
if ( is_readable( $mad4b_mcp_mu_binding_file ) ) {
	require_once $mad4b_mcp_mu_binding_file;
	$mad4b_mcp_mu_binding = MAD4B_SCP_Site_Profile::early_managed_runtime_binding();
}
$mad4b_mcp_mu_status['environment'] = $mad4b_mcp_mu_binding['environment'] ?? $mad4b_mcp_mu_status['environment'];
$mad4b_mcp_mu_status['wordpress_environment'] = $mad4b_mcp_mu_binding['wordpress_environment'] ?? 'unknown';
$mad4b_mcp_mu_status['wordpress_environment_explicit'] = ! empty( $mad4b_mcp_mu_binding['wordpress_environment_explicit'] );
$mad4b_mcp_mu_profile_enrolled = ! empty( $mad4b_mcp_mu_binding['eligible'] );

// Any unresolved filesystem transaction blocks provider execution before normal
// plugins load. The database option is authoritative; persistent object-cache
// state is evicted before this safety read so Redis/Memcached acceleration cannot
// hide an in-flight filesystem transaction.
if ( function_exists( 'wp_cache_delete' ) ) {
	wp_cache_delete( 'mad4b_scp_mcp_mu_refresh_transaction_v1', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}
$mad4b_mcp_mu_transaction = function_exists( 'get_option' )
	? get_option( 'mad4b_scp_mcp_mu_refresh_transaction_v1', null )
	: null;
if ( null !== $mad4b_mcp_mu_transaction ) {
	$mad4b_mcp_mu_status['transaction_pending'] = true;
	$mad4b_mcp_mu_valid_transaction = is_array( $mad4b_mcp_mu_transaction )
		&& ! empty( $mad4b_mcp_mu_transaction )
		&& 'mad4b.mcp-mu-filesystem-transaction.v1' === ( $mad4b_mcp_mu_transaction['contract'] ?? '' )
		&& isset( $mad4b_mcp_mu_transaction['transaction_id'] )
		&& is_string( $mad4b_mcp_mu_transaction['transaction_id'] )
		&& 1 === preg_match( '/^[a-f0-9]{32}$/D', strtolower( $mad4b_mcp_mu_transaction['transaction_id'] ) );
	$mad4b_mcp_mu_status['state'] = $mad4b_mcp_mu_valid_transaction ? 'managed_mu_transaction_pending' : 'managed_mu_transaction_invalid';
	$mad4b_mcp_mu_profile_enrolled = false;
}

if ( $mad4b_mcp_mu_profile_enrolled ) {
	$mad4b_mcp_mu_status['official_plugin_active'] = '' !== $mad4b_mcp_mu_adapter_plugin;
	$mad4b_mcp_mu_status['control_plane_active'] = '' !== $mad4b_mcp_mu_control_plane_plugin;
	$mad4b_mcp_mu_status['eligible'] = ! $mad4b_mcp_mu_status['plugin_identity_ambiguous']
		&& $mad4b_mcp_mu_status['official_plugin_active']
		&& $mad4b_mcp_mu_status['control_plane_active'];

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
		$mad4b_mcp_mu_is_cli = defined( 'WP_CLI' ) && constant( 'WP_CLI' );
		$mad4b_mcp_mu_cli_opt_in = $mad4b_mcp_mu_is_cli && (
			( defined( 'MAD4B_SCP_MCP_CLI_REQUEST' ) && constant( 'MAD4B_SCP_MCP_CLI_REQUEST' ) )
			|| '1' === (string) getenv( 'MAD4B_SCP_MCP_CLI_REQUEST' )
		);
		$mad4b_mcp_mu_status['cli_request'] = (bool) $mad4b_mcp_mu_is_cli;
		$mad4b_mcp_mu_status['cli_mcp_opt_in'] = (bool) $mad4b_mcp_mu_cli_opt_in;
		$mad4b_mcp_mu_request_requires_mcp = (bool) $mad4b_mcp_mu_cli_opt_in;
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
		if ( ! $mad4b_mcp_mu_is_cli && ! $mad4b_mcp_mu_request_requires_mcp && $mad4b_mcp_mu_admin_path && '' !== $mad4b_mcp_mu_page && 0 === strpos( $mad4b_mcp_mu_page, 'mad4b-control-plane' ) ) {
			$mad4b_mcp_mu_request_requires_mcp = true;
		}

		// Routing only: pin certified classes early on the exact diagnostic POST.
		// The worker still owns nonce/capability/build checks and singleton arming.
		$mad4b_mcp_mu_proof = isset( $_POST['mu_proof'] ) && is_string( $_POST['mu_proof'] )
			? strtolower( trim( wp_unslash( $_POST['mu_proof'] ) ) )
			: '';
		$mad4b_mcp_mu_expected_proof = method_exists( 'MAD4B_SCP_Site_Profile', 'diagnostic_mu_proof' )
			? MAD4B_SCP_Site_Profile::diagnostic_mu_proof()
			: '';
		$mad4b_mcp_mu_status['diagnostic_mu_proof_valid'] = '' !== $mad4b_mcp_mu_expected_proof
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $mad4b_mcp_mu_proof )
			&& hash_equals( $mad4b_mcp_mu_expected_proof, $mad4b_mcp_mu_proof );
		$mad4b_mcp_mu_diagnostic_server_id = isset( $_POST['server_id'] ) && is_string( $_POST['server_id'] )
			? trim( wp_unslash( $_POST['server_id'] ) )
			: '';
		$mad4b_mcp_mu_diagnostic = ! $mad4b_mcp_mu_is_cli
			&& 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
			&& 1 === preg_match( '#(?:^|/)wp-admin/admin\\-ajax\\.php$#', $mad4b_mcp_mu_path )
			&& isset( $_POST['action'] ) && is_string( $_POST['action'] )
			&& 'mad4b_connection_endpoint_diagnostic' === $_POST['action']
			&& $mad4b_mcp_mu_status['diagnostic_mu_proof_valid']
			&& in_array( '/mcp/' . $mad4b_mcp_mu_diagnostic_server_id, $mad4b_mcp_mu_allowed_routes, true ); // routing proof only; worker still owns auth.
		if ( $mad4b_mcp_mu_diagnostic ) {
			$mad4b_mcp_mu_status['diagnostic_server_id'] = $mad4b_mcp_mu_diagnostic_server_id;
			$mad4b_mcp_mu_request_requires_mcp = true;
		}

		$mad4b_mcp_mu_route = isset( $mad4b_mcp_mu_parsed['rest_route'] ) && is_string( $mad4b_mcp_mu_parsed['rest_route'] )
			? $mad4b_mcp_mu_parsed['rest_route']
			: '';
		if ( '' !== $mad4b_mcp_mu_route ) {
			$mad4b_mcp_mu_route = '/' . ltrim( rtrim( rawurldecode( $mad4b_mcp_mu_route ), '/' ), '/' );
		}
		if ( ! $mad4b_mcp_mu_is_cli && ! $mad4b_mcp_mu_request_requires_mcp && in_array( $mad4b_mcp_mu_route, $mad4b_mcp_mu_allowed_routes, true ) ) {
			$mad4b_mcp_mu_request_requires_mcp = true;
		}

		if ( ! $mad4b_mcp_mu_is_cli && ! $mad4b_mcp_mu_request_requires_mcp && '' !== $mad4b_mcp_mu_path ) {
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
		$mad4b_mcp_mu_critical_classes = array();
		$mad4b_mcp_mu_profile_runtime_classes = isset( $mad4b_mcp_mu_selected_profile['runtime_classes'] ) && is_array( $mad4b_mcp_mu_selected_profile['runtime_classes'] )
			? $mad4b_mcp_mu_selected_profile['runtime_classes']
			: array();
		foreach ( $mad4b_mcp_mu_profile_runtime_classes as $mad4b_mcp_mu_runtime_spec ) {
			if ( ! is_array( $mad4b_mcp_mu_runtime_spec ) ) continue;
			$mad4b_mcp_mu_runtime_symbol = isset( $mad4b_mcp_mu_runtime_spec['class'] ) ? trim( (string) $mad4b_mcp_mu_runtime_spec['class'] ) : '';
			$mad4b_mcp_mu_runtime_relative = isset( $mad4b_mcp_mu_runtime_spec['file'] )
				? ltrim( str_replace( '\\', '/', (string) $mad4b_mcp_mu_runtime_spec['file'] ), '/' )
				: '';
			if ( '' === $mad4b_mcp_mu_runtime_symbol
				|| '' === $mad4b_mcp_mu_runtime_relative
				|| false !== strpos( $mad4b_mcp_mu_runtime_relative, '../' )
				|| '/' === substr( $mad4b_mcp_mu_runtime_relative, 0, 1 ) ) continue;
			$mad4b_mcp_mu_critical_classes[ $mad4b_mcp_mu_runtime_symbol ] = $mad4b_mcp_mu_runtime_relative;
		}

		// The baseline 0.6.1 contract predates explicit runtime_classes. Preserve
		// exactly that certified class set only for the exact baseline version;
		// newer versions must provide their own complete class profile.
		if ( empty( $mad4b_mcp_mu_critical_classes )
			&& '0.6.1' === $mad4b_mcp_mu_adapter_version
			&& isset( $mad4b_mcp_mu_base_profile['version'] )
			&& hash_equals( '0.6.1', (string) $mad4b_mcp_mu_base_profile['version'] ) ) {
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
		}
		$mad4b_mcp_mu_status['adapter_profile_ready'] = ! empty( $mad4b_mcp_mu_selected_profile )
			&& ! empty( $mad4b_mcp_mu_critical_hashes )
			&& ! empty( $mad4b_mcp_mu_critical_classes );
		if ( ! $mad4b_mcp_mu_status['adapter_profile_ready'] ) {
			$mad4b_mcp_mu_status['state'] = 'exact_adapter_profile_unavailable';
		}

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

		// Validate every executable pin and the autoloader before require_once
		// against the exact installed-version profile selected above.
		$mad4b_mcp_mu_status['critical_class_baseline_ready'] = ! empty( $mad4b_mcp_mu_status['adapter_profile_ready'] );
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
					$mad4b_mcp_mu_status['critical_class_baseline_ready'] = ! empty( $mad4b_mcp_mu_status['adapter_profile_ready'] ) && ! empty( $mad4b_mcp_mu_critical_hashes );
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
	$mad4b_mcp_mu_transaction,
	$mad4b_mcp_mu_find_active,
	$mad4b_mcp_mu_control_plane_matches,
	$mad4b_mcp_mu_adapter_matches,
	$mad4b_mcp_mu_control_plane_plugin,
	$mad4b_mcp_mu_adapter_plugin,
	$mad4b_mcp_mu_control_plane_root,
	$mad4b_mcp_mu_diagnostic,
	$mad4b_mcp_mu_diagnostic_server_id,
	$mad4b_mcp_mu_proof,
	$mad4b_mcp_mu_expected_proof,
	$mad4b_mcp_mu_is_cli,
	$mad4b_mcp_mu_cli_opt_in,
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
	$mad4b_mcp_mu_profiles_file,
	$mad4b_mcp_mu_adapter_main,
	$mad4b_mcp_mu_adapter_version,
	$mad4b_mcp_mu_adapter_header,
	$mad4b_mcp_mu_adapter_head,
	$mad4b_mcp_mu_adapter_match,
	$mad4b_mcp_mu_baseline_catalog,
	$mad4b_mcp_mu_profiles_catalog,
	$mad4b_mcp_mu_base_profile,
	$mad4b_mcp_mu_selected_profile,
	$mad4b_mcp_mu_candidate_profile,
	$mad4b_mcp_mu_profile_runtime_classes,
	$mad4b_mcp_mu_runtime_spec,
	$mad4b_mcp_mu_runtime_symbol,
	$mad4b_mcp_mu_runtime_relative,
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