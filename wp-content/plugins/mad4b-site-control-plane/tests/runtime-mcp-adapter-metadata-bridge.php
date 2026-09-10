<?php

$path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $path ) || '' === $path ) {
	fwrite( STDERR, "MAD4B_TEST_WP_PATH is required.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_ADMIN' ) ) define( 'WP_ADMIN', true );
$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';

require rtrim( $path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( 'MAD4B_SCP_MCP_Adapter_Metadata_Bridge' ) ) {
	fwrite( STDERR, "Metadata bridge class is missing.\n" );
	exit( 1 );
}

$status = MAD4B_SCP_MCP_Adapter_Metadata_Bridge::status();
if ( empty( $status['eligible'] ) || empty( $status['hook_registered'] ) || empty( $status['admin_request_only'] ) ) {
	fwrite( STDERR, 'Metadata bridge is not active on exact governed Staging admin: ' . wp_json_encode( $status ) . "\n" );
	exit( 1 );
}
if ( ! isset( $status['fallback_priority'] ) || PHP_INT_MAX !== (int) $status['fallback_priority'] ) {
	fwrite( STDERR, "Metadata bridge is not registered as the final fallback.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

$wordpress_org_requests = 0;
add_filter(
	'pre_http_request',
	static function ( $pre, $parsed_args, $url ) use ( &$wordpress_org_requests ) {
		if ( false !== strpos( (string) $url, 'api.wordpress.org/plugins/info/1.2' ) ) {
			$wordpress_org_requests++;
			return new WP_Error( 'mad4b_test_unexpected_http', 'MCP Adapter metadata escaped the local bridge.' );
		}
		return $pre;
	},
	PHP_INT_MIN,
	3
);

$result = plugins_api(
	'plugin_information',
	array(
		'slug' => 'mcp-adapter',
		'fields' => array(
			'short_description' => true,
			'icons' => true,
		),
	)
);

if ( is_wp_error( $result ) || ! is_object( $result ) ) {
	fwrite( STDERR, 'plugins_api did not return local MCP Adapter metadata: ' . wp_json_encode( $result ) . "\n" );
	exit( 1 );
}

$expected = array(
	'name' => 'MCP Adapter',
	'slug' => 'mcp-adapter',
	'version' => '0.6.1',
);
foreach ( $expected as $field => $value ) {
	if ( ! isset( $result->{$field} ) || $value !== (string) $result->{$field} ) {
		fwrite( STDERR, "Unexpected {$field} in local metadata.\n" );
		exit( 1 );
	}
}
if ( ! isset( $result->short_description ) || '' === trim( (string) $result->short_description ) || ! isset( $result->icons ) || ! is_array( $result->icons ) ) {
	fwrite( STDERR, "Dependency metadata is incomplete.\n" );
	exit( 1 );
}
if ( 0 !== $wordpress_org_requests ) {
	fwrite( STDERR, "WordPress.org plugin-information request was not short-circuited.\n" );
	exit( 1 );
}

// A real provider registered at a normal priority must win. The MAD4B bridge
// is only the last false-sentinel fallback before Core would call WordPress.org.
$provider_result = (object) array( 'provider' => 'existing-provider' );
$provider_callback = static function ( $pre, $action, $args ) use ( $provider_result ) {
	if ( false === $pre && 'plugin_information' === (string) $action && is_object( $args ) && isset( $args->slug ) && 'mcp-adapter' === (string) $args->slug ) {
		return $provider_result;
	}
	return $pre;
};
add_filter( 'plugins_api', $provider_callback, 10, 3 );
$preserved_provider = plugins_api( 'plugin_information', array( 'slug' => 'mcp-adapter' ) );
remove_filter( 'plugins_api', $provider_callback, 10 );
if ( $provider_result !== $preserved_provider ) {
	fwrite( STDERR, "Metadata bridge replaced an existing provider response.\n" );
	exit( 1 );
}

$other_slug = MAD4B_SCP_MCP_Adapter_Metadata_Bridge::filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) );
if ( false !== $other_slug ) {
	fwrite( STDERR, "Bridge changed another plugin slug.\n" );
	exit( 1 );
}

$other_action = MAD4B_SCP_MCP_Adapter_Metadata_Bridge::filter_plugin_information( false, 'query_plugins', (object) array( 'slug' => 'mcp-adapter' ) );
if ( false !== $other_action ) {
	fwrite( STDERR, "Bridge changed another plugins_api action.\n" );
	exit( 1 );
}

$existing = (object) array( 'sentinel' => 'preserve-me' );
$preserved = MAD4B_SCP_MCP_Adapter_Metadata_Bridge::filter_plugin_information( $existing, 'plugin_information', (object) array( 'slug' => 'mcp-adapter' ) );
if ( $existing !== $preserved ) {
	fwrite( STDERR, "Bridge replaced a pre-existing direct result.\n" );
	exit( 1 );
}

$status = MAD4B_SCP_MCP_Adapter_Metadata_Bridge::status();
foreach ( array( 'production_changed', 'other_plugin_api_requests_changed', 'outbound_http_changed', 'credentials_changed', 'installation_or_update_state_changed' ) as $key ) {
	if ( ! array_key_exists( $key, $status ) || false !== $status[ $key ] ) {
		fwrite( STDERR, "Forbidden side effect reported by {$key}.\n" );
		exit( 1 );
	}
}
if ( 1 !== (int) $status['short_circuit_count'] ) {
	fwrite( STDERR, 'Bridge short-circuited outside the exact fallback path: ' . wp_json_encode( $status ) . "\n" );
	exit( 1 );
}

echo "mad4b-mcp-adapter-metadata-bridge-runtime: PASS\n";
