<?php
/** Exercise the shipped MU loader before regular plugins, in fresh PHP processes. */
$cases = array( 'implicit', 'explicit_constant', 'explicit_environment', 'explicit_filter', 'foreign_origin', 'invalid_uuid', 'invalid_revision', 'disabled', 'reenrollment', 'production', 'frontend', 'foreign_ajax', 'diagnostic', 'developer', 'developer_breakglass', 'tampered_autoloader', 'tampered_validator', 'preclaimed', 'local', 'development', 'explicit_staging', 'plain_route', 'subdirectory', 'custom_rest_prefix', 'diagnostic_get', 'diagnostic_array', 'adapter_inactive', 'control_plane_inactive', 'missing_manifest', 'missing_baseline', 'network_only' );
if ( ! isset( $argv[1] ) ) {
	foreach ( $cases as $case ) {
		passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $case ), $code );
		if ( $code ) exit( $code );
	}
	echo 'mad4b.mcp-mu-recovery-boundaries.v1: ' . count( $cases ) . '/' . count( $cases ) . ' PASS' . PHP_EOL;
	exit;
}
$case = $argv[1];
$root = sys_get_temp_dir() . '/mad4b-mu-boundary-' . getmypid();
function boundary_check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $GLOBALS['case'] . ': ' . $message ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function wp_normalize_path( $v ) { return str_replace( '\\', '/', $v ); }
function home_url( $v = '' ) { return 'https://staging.fixture.test' . $v; }
function wp_parse_url( $v, $part = -1 ) { return parse_url( $v, $part ); }
function wp_unslash( $v ) { return $v; }
function wp_get_environment_type() { return defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : ( getenv( 'WP_ENVIRONMENT_TYPE' ) ?: 'production' ); }
function apply_filters( $hook, $v, ...$args ) { return 'explicit_filter' === $GLOBALS['case'] && 'mad4b_scp_wordpress_environment_explicit' === $hook ? true : $v; }
function rest_get_url_prefix() { return 'custom_rest_prefix' === $GLOBALS['case'] ? 'api' : 'wp-json'; }
function has_action( $hook, $callback ) { return false; }
function get_option( $key, $default = null ) { return $GLOBALS['options'][ $key ] ?? $default; }
function boundary_remove( $p ) { if ( is_dir( $p ) && ! is_link( $p ) ) { foreach ( array_diff( scandir( $p ), array( '.', '..' ) ) as $f ) boundary_remove( $p . '/' . $f ); rmdir( $p ); } elseif ( file_exists( $p ) || is_link( $p ) ) unlink( $p ); }
register_shutdown_function( function () use ( $root ) { boundary_remove( $root ); } );
define( 'ABSPATH', $root . '/' );
define( 'WP_PLUGIN_DIR', $root . '/plugins' );
putenv( 'WP_ENVIRONMENT_TYPE' );
if ( 'explicit_staging' === $case ) define( 'WP_ENVIRONMENT_TYPE', 'staging' );
if ( 'explicit_constant' === $case ) define( 'WP_ENVIRONMENT_TYPE', 'production' );
if ( 'explicit_environment' === $case ) putenv( 'WP_ENVIRONMENT_TYPE=production' );
$profile = array( 'contract' => 'mad4b.site-profile.v2', 'version' => 2, 'site_uuid' => '17dbf7bc-3a50-47db-93d6-a38c2ab1fcc7', 'revision' => 2, 'environment' => 'staging', 'canonical_origin' => 'https://staging.fixture.test', 'features' => array( 'managed_runtime' => true ) );
if ( 'foreign_origin' === $case ) $profile['canonical_origin'] = 'https://production.fixture.test';
if ( 'invalid_uuid' === $case ) $profile['site_uuid'] = 'invalid';
if ( 'invalid_revision' === $case ) $profile['revision'] = 0;
if ( 'disabled' === $case ) $profile['features']['managed_runtime'] = false;
if ( 'reenrollment' === $case ) $profile['migration_requires_reenrollment'] = true;
if ( 'production' === $case ) $profile['environment'] = 'production';
if ( in_array( $case, array( 'local', 'development' ), true ) ) $profile['environment'] = $case;
$options = array( 'mad4b_scp_site_profile_v2' => $profile, 'active_plugins' => array( 'mcp-adapter/mcp-adapter.php', 'mad4b-site-control-plane/mad4b-site-control-plane.php' ) );
if ( 'adapter_inactive' === $case ) $options['active_plugins'] = array( 'mad4b-site-control-plane/mad4b-site-control-plane.php' );
if ( 'control_plane_inactive' === $case ) $options['active_plugins'] = array( 'mcp-adapter/mcp-adapter.php' );
// Network-only activation is deliberately not mistaken for per-site activation.
if ( 'network_only' === $case ) $options['active_plugins'] = array();
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-chatgpt';
$_SERVER['REQUEST_METHOD'] = 'POST';
if ( 'frontend' === $case ) $_SERVER['REQUEST_URI'] = '/?page=mad4b-control-plane-connection';
if ( in_array( $case, array( 'foreign_ajax', 'diagnostic' ), true ) ) { $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php'; $_POST['action'] = 'diagnostic' === $case ? 'mad4b_connection_endpoint_diagnostic' : 'foreign_action'; }
if ( 'plain_route' === $case ) $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fmcp%2Fmad4b-chatgpt';
if ( 'subdirectory' === $case ) $_SERVER['REQUEST_URI'] = '/wordpress/wp-json/mcp/mad4b-chatgpt';
if ( 'custom_rest_prefix' === $case ) $_SERVER['REQUEST_URI'] = '/wordpress/api/mcp/mad4b-chatgpt';
if ( in_array( $case, array( 'diagnostic_get', 'diagnostic_array' ), true ) ) { $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php'; $_POST['action'] = 'diagnostic_array' === $case ? array( 'mad4b_connection_endpoint_diagnostic' ) : 'mad4b_connection_endpoint_diagnostic'; if ( 'diagnostic_get' === $case ) $_SERVER['REQUEST_METHOD'] = 'GET'; }
if ( 'developer' === $case ) $_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-developer';
if ( 'developer_breakglass' === $case ) $_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-developer-breakglass';
$source = dirname( __DIR__ );
mkdir( WP_PLUGIN_DIR . '/mad4b-site-control-plane/includes', 0777, true );
copy( $source . '/includes/class-mad4b-scp-site-profile.php', WP_PLUGIN_DIR . '/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php' );
require $source . '/includes/class-mad4b-scp-mcp-class-provenance.php';
$map = array(); $manifest = array();
foreach ( MAD4B_SCP_MCP_Class_Provenance::critical_classes() as $spec ) $map[ $spec['class'] ] = $spec['file'];
$map['WP\\MCP\\Autoloader'] = 'includes/Autoloader.php';
$map['WP\\MCP\\Plugin'] = 'includes/Plugin.php';
foreach ( $map as $symbol => $file ) {
	$cut = strrpos( $symbol, '\\' );
	$body = 'namespace ' . substr( $symbol, 0, $cut ) . '; class ' . substr( $symbol, $cut + 1 ) . ' {';
	if ( 'WP\\MCP\\Core\\McpAdapter' === $symbol ) $body .= ' public static function instance() { $GLOBALS["instance_armed"] = true; return new self; }';
	$body .= '}';
	$path = WP_PLUGIN_DIR . '/mcp-adapter/' . $file;
	if ( ! is_dir( dirname( $path ) ) ) mkdir( dirname( $path ), 0777, true );
	file_put_contents( $path, '<?php ' . $body );
	$manifest[ $file ] = hash_file( 'sha256', $path );
}
$autoload = WP_PLUGIN_DIR . '/mcp-adapter/vendor/autoload_packages.php';
if ( ! is_dir( dirname( $autoload ) ) ) mkdir( dirname( $autoload ), 0777, true );
file_put_contents( $autoload, '<?php spl_autoload_register( function( $c ) { if ( isset( $GLOBALS["map"][ $c ] ) ) require_once WP_PLUGIN_DIR . "/mcp-adapter/" . $GLOBALS["map"][ $c ]; } ); return true;' );
$manifest['vendor/autoload_packages.php'] = hash_file( 'sha256', $autoload );
mkdir( WP_PLUGIN_DIR . '/mad4b-site-control-plane/config', 0777, true );
file_put_contents( WP_PLUGIN_DIR . '/mad4b-site-control-plane/config/certified-providers.json', json_encode( array( 'providers' => array( 'mcp_adapter' => array( 'critical_files' => $manifest ) ) ) ) );
if ( 'missing_manifest' === $case ) unlink( WP_PLUGIN_DIR . '/mad4b-site-control-plane/config/certified-providers.json' );
if ( 'missing_baseline' === $case ) { unset( $manifest['vendor/autoload_packages.php'] ); file_put_contents( WP_PLUGIN_DIR . '/mad4b-site-control-plane/config/certified-providers.json', json_encode( array( 'providers' => array( 'mcp_adapter' => array( 'critical_files' => $manifest ) ) ) ) ); }
if ( 'tampered_autoloader' === $case ) file_put_contents( $autoload, '<?php $GLOBALS["tampered_executed"] = true; return true;' );
if ( 'tampered_validator' === $case ) file_put_contents( WP_PLUGIN_DIR . '/mcp-adapter/includes/Domain/Tools/McpToolValidator.php', '<?php $GLOBALS["tampered_executed"] = true;' );
if ( 'preclaimed' === $case ) eval( 'namespace WP\\MCP\\Domain\\Tools; class McpToolValidator {}' );
require $source . '/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';
$status = $GLOBALS['mad4b_scp_mcp_mu_bootstrap'];
if ( in_array( $case, array( 'explicit_constant', 'explicit_environment', 'explicit_filter', 'foreign_origin', 'invalid_uuid', 'invalid_revision', 'disabled', 'reenrollment', 'production', 'adapter_inactive', 'control_plane_inactive', 'network_only' ), true ) ) {
	boundary_check( ! $status['eligible'] && ! class_exists( 'WP\\MCP\\Core\\McpAdapter', false ), 'ineligible binding loaded a provider class' );
} elseif ( in_array( $case, array( 'frontend', 'foreign_ajax', 'diagnostic_get', 'diagnostic_array' ), true ) ) {
	boundary_check( $status['request_scope_bypassed'] && ! class_exists( 'WP\\MCP\\Core\\McpAdapter', false ), 'foreign request loaded MCP' );
} elseif ( in_array( $case, array( 'tampered_autoloader', 'tampered_validator', 'missing_manifest', 'missing_baseline' ), true ) ) {
	boundary_check( 'critical_class_baseline_mismatch' === $status['state'] && empty( $GLOBALS['tampered_executed'] ) && ! class_exists( 'WP\\MCP\\Core\\McpAdapter', false ), 'corrupt code executed before integrity validation' );
} elseif ( 'preclaimed' === $case ) {
	boundary_check( 'runtime_preclaimed_before_mu_bootstrap' === $status['state'] && ! $status['critical_class_set_pinned'], 'preclaimed symbol was replaced or certified' );
} else {
	boundary_check( $status['eligible'] && $profile['environment'] === $status['environment'] && wp_get_environment_type() === $status['wordpress_environment'], 'implicit production default did not use exact staging binding' );
	boundary_check( $status['critical_class_set_pinned'] && 11 === $status['critical_class_pin_count'], 'certified class set not pinned' );
	if ( 'diagnostic' === $case ) boundary_check( 'canonical_runtime_pinned_diagnostic_deferred' === $status['state'] && empty( $GLOBALS['instance_armed'] ), 'diagnostic armed singleton before authorization' );
	else boundary_check( ! empty( $GLOBALS['instance_armed'] ), 'owned protocol did not arm adapter' );
}
echo $case . ': PASS' . PHP_EOL;
