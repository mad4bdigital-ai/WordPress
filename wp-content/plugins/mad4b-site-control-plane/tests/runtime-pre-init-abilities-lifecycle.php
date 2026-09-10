<?php
/**
 * Regression acceptance for WordPress 6.9+ lifecycle discipline.
 *
 * The live Staging debug log showed WP_Abilities_Registry::get_instance() being
 * invoked before init while MAD4B was active. Install an MU observer before
 * loading WordPress and fail if any request bootstrap initializes the Abilities
 * registry before init. The request is deliberately WPML-like/non-MCP.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: wp-load.php not found\n" );
	exit( 1 );
}

$mu_dir = $wp_path . '/wp-content/mu-plugins';
if ( ! is_dir( $mu_dir ) && ! mkdir( $mu_dir, 0775, true ) && ! is_dir( $mu_dir ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: cannot create MU directory\n" );
	exit( 1 );
}
$observer = $mu_dir . '/000-000-mad4b-pre-init-observer.php';
$observer_source = <<<'PHP'
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$GLOBALS['mad4b_ci_pre_init_ability_violations'] = array();
add_action( 'doing_it_wrong_run', static function ( $function_name, $message, $version ) {
	if ( did_action( 'init' ) > 0 ) return;
	$name = (string) $function_name;
	if ( false === strpos( $name, 'WP_Abilities_Registry' ) && false === strpos( (string) $message, 'Ability API should not be initialized before the init action has fired' ) ) return;
	$frames = array();
	foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 0, 24 ) as $frame ) {
		$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
		if ( '' !== $file && defined( 'WP_PLUGIN_DIR' ) ) {
			$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
			if ( 0 === strpos( $file, $plugins ) ) $file = 'wp-content/plugins/' . ltrim( substr( $file, strlen( $plugins ) ), '/' );
			elseif ( defined( 'WPMU_PLUGIN_DIR' ) ) {
				$mu = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
				if ( 0 === strpos( $file, $mu ) ) $file = 'wp-content/mu-plugins/' . ltrim( substr( $file, strlen( $mu ) ), '/' );
				else $file = basename( $file );
			} else $file = basename( $file );
		}
		$frames[] = array(
			'file' => $file,
			'line' => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
			'class' => isset( $frame['class'] ) ? (string) $frame['class'] : '',
			'function' => isset( $frame['function'] ) ? (string) $frame['function'] : '',
		);
	}
	$GLOBALS['mad4b_ci_pre_init_ability_violations'][] = array(
		'function' => $name,
		'message' => wp_strip_all_tags( (string) $message ),
		'version' => (string) $version,
		'trace' => $frames,
	);
}, PHP_INT_MIN, 3 );
PHP;
if ( false === file_put_contents( $observer, $observer_source ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: cannot write MU observer\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-json/wpml/v1/rest/status?test_get_parameter=1&cachebuster=ci';
$_GET['test_get_parameter'] = '1';
$_GET['cachebuster'] = 'ci';

require $wp_path . '/wp-load.php';
@unlink( $observer );

$violations = isset( $GLOBALS['mad4b_ci_pre_init_ability_violations'] ) && is_array( $GLOBALS['mad4b_ci_pre_init_ability_violations'] )
	? $GLOBALS['mad4b_ci_pre_init_ability_violations']
	: array();
if ( ! empty( $violations ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: Ability API initialized before init: ' . json_encode( $violations, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}
if ( did_action( 'init' ) < 1 ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: WordPress init did not complete\n" );
	exit( 1 );
}

echo 'mad4b.site-control-plane.pre-init-abilities-lifecycle.v1: PASS' . PHP_EOL;
